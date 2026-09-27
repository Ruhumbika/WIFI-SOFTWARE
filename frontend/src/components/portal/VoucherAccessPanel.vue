<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '../../api'
import { formatPhoneInput } from '../../utils/formatPhoneInput'
import VoucherCard from '../vouchers/VoucherCard.vue'
import SignalEye from './SignalEye.vue'
import { submitHotspotLogin } from '../../utils/hotspotLogin'
import { formatDate } from '../../utils/formatDate'
const props = defineProps<{ mode: 'redeem' | 'recovery'; compact?: boolean }>()
const emit = defineEmits<{ buy: [] }>()
const code = ref(''), pin = ref(''), phone = ref(''), recoveryPin = ref('')
const phoneTouched = ref(false)
const normalizedPhone = computed(() => {
  let value = phone.value.trim().replace(/[\s()+-]/g, '')
  if (/^0[67]\d{8}$/.test(value)) value = '255' + value.slice(1)
  else if (/^[67]\d{8}$/.test(value)) value = '255' + value
  return /^255[67]\d{8}$/.test(value) ? value : null
})
const phoneInvalid = computed(() => phoneTouched.value && !normalizedPhone.value)
function onPhoneInput(event: Event) {
  const input = event.target as HTMLInputElement
  phone.value = formatPhoneInput(input.value)
  input.value = phone.value
}
function validatePhone() {
  phoneTouched.value = true
  return normalizedPhone.value !== null
}
watch(phone, () => {
  selected.value = null; summaries.value = []; searched.value = false
  recoveryPin.value = ''; message.value = ''; requestStatus.value = 'idle'
})
const issuedPin = ref(''), token = ref(''), selected = ref<any>(null), voucher = ref<any>(null)
const summaries = ref<any[]>([]), searched = ref(false), busy = ref(false), message = ref('')
const state = ref(''), verified = ref(false), page = ref(1), lastPage = ref(1), filter = ref('all')
const expiresAt = ref(0)
const nowTick = ref(Date.now())
const params = new URLSearchParams(location.search)
const context = { device_mac: params.get('mac') || null, login_url: params.get('link-login-only') || null }
const headers = () => ({ 'X-Voucher-Recovery-Token': token.value })
const labels: Record<string,string> = { ready:'Unused', active:'Active', expired:'Expired', disabled:'Unavailable', revoked:'Unavailable', provision_pending:'Preparing' }
const messages: Record<string,string> = { ready:'Your voucher is ready.', active:'Your voucher is ready to reconnect.', online:'Connected to Wi-Fi.', unknown:'Your voucher has an active session. Open the RJAY Wi-Fi sign-in page to check this device.', offline:'Not connected. You can try again.', active_other_device:'This voucher is currently active on another device.', device_mismatch:'This voucher is already active on another device.', expired:'This voucher has expired.', unavailable:'This voucher is unavailable. Contact support.', preparing:'Your voucher is being prepared. Please try again shortly.', router_unavailable:'Your voucher is safe. Wi-Fi is temporarily unavailable.' }
const visible = computed(() => summaries.value.filter(v => filter.value === 'all' || (filter.value === 'unused' ? v.status === 'ready' : v.status === filter.value)))
const canConnect = computed(() => voucher.value && ['ready','active','provision_pending'].includes(voucher.value.status) && !['device_mismatch','active_other_device','expired','unavailable'].includes(state.value))
const requestStatus = ref<'idle' | 'success' | 'error'>('idle')
const eyeStatus = computed(() => {
  if (busy.value) return 'loading'
  if (requestStatus.value === 'error' || ['expired','unavailable','device_mismatch','router_unavailable'].includes(state.value)) return 'error'
  if (['preparing','active_other_device','unknown'].includes(state.value)) return 'waiting'
  if (state.value === 'online') return 'success'
  return requestStatus.value
})
let poll: number | undefined
function forget() {
  requestStatus.value='idle'; state.value=''
  token.value = ''; voucher.value = null; verified.value = false; expiresAt.value = 0
  sessionStorage.removeItem('rjay_voucher_access')
}
function accept(data: any, management: boolean) {
  token.value = data.recovery_token; voucher.value = data.voucher; verified.value = management
  expiresAt.value = Date.parse(data.expires_at)
  sessionStorage.setItem('rjay_voucher_access', JSON.stringify({ token:token.value, uuid:voucher.value.uuid, management, expires:expiresAt.value }))
}
async function run(action: () => Promise<void>) {
  if (busy.value) return
  busy.value = true; message.value = ''; requestStatus.value='idle'
  try { await action(); requestStatus.value='success' }
  catch (e: any) {
    requestStatus.value='error'
    if (e.response?.status === 403) { forget(); message.value = 'Your access has expired. Verify this voucher again.' }
    else if (e.response?.status === 429) message.value = 'Too many attempts. Please wait before trying again.'
    else message.value = e.response?.status === 422 ? (props.mode === 'redeem' ? "We couldn't verify that voucher. Check the code and PIN." : "We couldn't verify those recovery details.") : 'We could not complete that request. Your voucher is safe. Please try again.'
  } finally { busy.value = false }
}
function applyState(value: string) { state.value = value; message.value = messages[value] || 'Please try again.' }
async function redeem() {
  await run(async () => {
    const { data } = await api.post('/public/vouchers/redeem', { code:code.value.trim().toUpperCase(), pin:pin.value, ...context })
    accept(data,false); pin.value=''; finishLogin(data)
  })
}
async function lookup(next = 1) {
  if (!validatePhone()) return
  const lookupPhone = normalizedPhone.value
  await run(async () => {
    const { data } = await api.post('/public/vouchers/recovery/lookup', { phone:lookupPhone }, { params:{page:next} })
    if (normalizedPhone.value !== lookupPhone) return
    summaries.value=data.vouchers; page.value=data.page; lastPage.value=data.last_page; searched.value=true; selected.value=null
  })
}
async function verify() {
  if (!validatePhone() || !selected.value) return
  await run(async () => {
    const { data } = await api.post('/public/vouchers/recovery/verify', { phone:normalizedPhone.value, voucher_uuid:selected.value.uuid, recovery_pin:recoveryPin.value })
    recoveryPin.value=''; accept(data,true); await check()
  })
}
async function check() {
  if (!voucher.value) return
  if (Date.now() >= expiresAt.value) { forget(); message.value='Your access has expired. Verify again to continue.'; return }
  try {
    const { data } = await api.get(`/public/vouchers/${voucher.value.uuid}/connection`, { headers:headers(), params:{device_mac:context.device_mac || undefined} })
    applyState(data.state)
    voucher.value=(await api.get(`/public/vouchers/${voucher.value.uuid}`, {headers:headers()})).data
    nowTick.value=Date.now()
  } catch (e: any) { if (e.response?.status === 403) forget(); else applyState('router_unavailable') }
}
async function connect() {
  await run(async () => {
    const { data } = await api.post(`/public/vouchers/${voucher.value.uuid}/prepare-connection`, context, { headers:headers() })
    finishLogin(data)
  })
}
function finishLogin(data: any) {
    applyState(data.state)
    if (['ready','active'].includes(data.state)) {
      if (data.login_url) {
        const url = new URL(location.href); url.searchParams.delete('order'); url.searchParams.set('voucher-return','1')
        submitHotspotLogin(data.login_url,voucher.value,url)
      } else message.value = 'Connect to RJAY Wi-Fi, open its sign-in page, and enter the voucher code and PIN shown below.'
    }
}
async function claim() {
  if (!validatePhone()) return
  await run(async () => {
    const { data } = await api.post('/public/vouchers/claim', { code:voucher.value.code, pin:voucher.value.password, phone:normalizedPhone.value })
    accept(data,true); issuedPin.value=data.recovery_pin
  })
}
async function support(action: string) {
  await run(async () => {
    const { data } = await api.post(`/public/vouchers/${voucher.value.uuid}/${action}`, context, { headers:headers() })
    message.value=data.message
    if (action==='device-transfer-request') voucher.value.transfer_pending=true
  })
}
onMounted(async () => {
  try {
    const saved=JSON.parse(sessionStorage.getItem('rjay_voucher_access') || 'null')
    if (saved && saved.expires>Date.now()) {
      token.value=saved.token; expiresAt.value=saved.expires; verified.value=saved.management
      const { data }=await api.get(`/public/vouchers/${saved.uuid}`,{headers:headers()}); voucher.value=data
      await check()
    } else {
      sessionStorage.removeItem('rjay_voucher_access')
      const orderUuid=sessionStorage.getItem('rjay_current_order')
      if (props.mode === 'recovery' && orderUuid && sessionStorage.getItem('rjay_order_token')) {
        const order=(await api.get(`/public/orders/${encodeURIComponent(orderUuid)}`)).data
        if (order.voucher?.uuid) {
          voucher.value=(await api.get(`/public/vouchers/${order.voucher.uuid}`)).data
          verified.value=true; expiresAt.value=Date.now()+15*60*1000
          await check()
        }
      }
    }
  } catch { forget() }
  poll=window.setInterval(() => { if (voucher.value && !busy.value) void check() },15000)
})
onUnmounted(() => { if (poll) clearInterval(poll) })
</script>
<template>
  <section class="portal-state-card voucher-access text-start" :class="{compact}">
    <h1 v-if="!compact" class="h3">{{ mode === 'redeem' ? 'Use a Voucher' : 'My Vouchers' }}</h1>
    <p v-if="message" role="status" class="alert alert-info mt-3">{{ message }}</p>
    <div v-if="issuedPin" class="alert alert-warning">
      <strong>Recovery PIN: {{ issuedPin }}</strong><p>Keep this recovery PIN. You can use it to recover this voucher later.</p>
      <button class="btn btn-outline-dark" @click="issuedPin=''">I have saved it</button>
    </div>
    <template v-if="voucher">
      <VoucherCard :voucher="voucher" :plan="voucher.plan" :now-ms="nowTick" :show-connect="canConnect && !issuedPin" :connect-label="voucher.status === 'active' ? 'Reconnect' : 'Connect'" :connecting="busy" @connect="connect" />
      <p class="mt-3 mb-1">Linked phone: {{ voucher.customer_phone || 'Not registered' }}</p>
      <p>Purchased: {{ formatDate(voucher.created_at) }}</p>
      <p v-if="voucher.session">Last activity: {{ formatDate(voucher.session.last_seen_at) }}</p>
      <div v-if="verified" class="d-grid gap-2 mt-3">
        <button v-if="voucher.device_mac && ['ready','active'].includes(voucher.status)" class="btn btn-outline-primary" :disabled="busy || voucher.transfer_pending" @click="support('device-transfer-request')">{{ voucher.transfer_pending ? 'Device request awaiting review' : 'This is not my device' }}</button>
        <button class="btn btn-outline-secondary" :disabled="busy" @click="support('report-compromised')">Report voucher compromised</button>
      </div>
      <p v-else-if="voucher.device_mac" class="mt-3">Use My Vouchers with your recovery PIN, or contact support, to request a device transfer.</p>
      <form v-if="!voucher.registered" class="mt-3" @submit.prevent="claim">
        <label for="claim-phone" class="form-label">Register this voucher for recovery</label>
        <input id="claim-phone" :value="phone" @input="onPhoneInput" type="tel" inputmode="numeric" pattern="[0-9 ]*" autocomplete="tel" required class="form-control" :class="{ 'phone-invalid':phoneInvalid }" :aria-invalid="phoneInvalid" aria-describedby="claim-phone-feedback" :disabled="busy" @blur="phoneTouched=true" placeholder="255 7XX XXX XXX" />
        <small id="claim-phone-feedback" class="phone-feedback" :class="{ 'phone-feedback--error':phoneInvalid }" aria-live="polite">{{ phoneInvalid ? 'Weka namba sahihi ya Tanzania.' : normalizedPhone ? '✓ Namba imekamilika' : '' }}</small>
        <button class="btn btn-outline-primary mt-2" :disabled="busy || !normalizedPhone">Claim voucher</button>
      </form>
      <button v-if="voucher.status === 'expired' || state === 'expired'" class="btn btn-primary mt-3" @click="emit('buy')">Buy new package</button>
      <p v-if="['disabled','revoked'].includes(voucher.status)">Contact the Wi-Fi operator for support.</p>
      <button class="btn btn-link mt-3" @click="forget(); selected=null; message=''">Use another voucher</button>
    </template>
    <form v-else-if="mode === 'redeem'" @submit.prevent="redeem">
      <label for="redeem-code" class="visually-hidden">Voucher code</label>
      <div class="credential-field"><i class="bi bi-ticket-perforated" aria-hidden="true"></i><input id="redeem-code" v-model="code" class="form-control" placeholder="Voucher code" autocomplete="off" autocapitalize="characters" required maxlength="80" @input="code=code.toUpperCase()" @blur="code=code.trim()" /></div>
      <label for="redeem-pin" class="visually-hidden">Voucher PIN</label>
      <div class="credential-field"><i class="bi bi-lock" aria-hidden="true"></i><input id="redeem-pin" v-model="pin" class="form-control" placeholder="PIN" type="password" inputmode="numeric" autocomplete="off" required maxlength="80" /></div>
      <button class="btn btn-primary w-100 access-connect" :disabled="busy"><span>{{ busy ? 'Connecting…' : 'Connect' }}</span><span class="button-eye"><SignalEye :status="eyeStatus" /></span></button>
    </form>
    <template v-else>
      <form @submit.prevent="lookup()">
        <label for="recovery-phone" class="form-label">Phone number</label>
        <input id="recovery-phone" :value="phone" @input="onPhoneInput" class="form-control" :class="{ 'phone-invalid':phoneInvalid }" type="tel" inputmode="numeric" pattern="[0-9 ]*" autocomplete="tel" required :aria-invalid="phoneInvalid" aria-describedby="recovery-phone-feedback" :disabled="busy" @blur="phoneTouched=true" placeholder="255 7XX XXX XXX" />
        <small id="recovery-phone-feedback" class="phone-feedback" :class="{ 'phone-feedback--error':phoneInvalid }" aria-live="polite">{{ phoneInvalid ? 'Weka namba sahihi ya Tanzania.' : normalizedPhone ? '✓ Namba imekamilika' : '' }}</small>
        <button class="btn btn-primary w-100 access-connect" :disabled="busy || !normalizedPhone"><span>{{ busy ? 'Searching…' : 'Find vouchers' }}</span><span class="button-eye"><SignalEye :status="eyeStatus" /></span></button>
      </form>
      <form v-if="selected" class="mt-4" @submit.prevent="verify">
        <h2 class="h5">{{ selected.plan.name }} · {{ selected.code }}</h2>
        <label for="recovery-pin" class="form-label">This voucher’s recovery PIN</label>
        <input id="recovery-pin" v-model="recoveryPin" class="form-control mb-3" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required />
        <button class="btn btn-primary" :disabled="busy || !normalizedPhone">Verify</button>
        <p class="small mt-2">Lost your recovery PIN? Contact the Wi-Fi operator.</p>
      </form>
      <div v-else-if="searched" class="mt-4">
        <label for="voucher-filter" class="form-label">Show</label>
        <select id="voucher-filter" v-model="filter" class="form-select mb-3"><option value="all">All</option><option value="active">Active</option><option value="unused">Unused</option><option value="expired">Expired</option></select>
        <p v-if="!visible.length">No matching vouchers on this page. Check the number or contact support.</p>
        <article v-for="item in visible" :key="item.uuid" class="card p-3 mb-2">
          <h2 class="h5">{{ item.plan.name }}</h2><p>{{ labels[item.status] || 'Unavailable' }} · {{ item.code }}</p>
          <small>Purchased {{ formatDate(item.created_at) }}</small><small v-if="item.device_mac">Device {{ item.device_mac }}</small>
          <button class="btn btn-outline-primary mt-2" @click="selected=item; recoveryPin=''">Recover this voucher</button>
        </article>
        <div v-if="lastPage>1" class="d-flex gap-2"><button class="btn btn-outline-secondary" :disabled="busy || page===1" @click="lookup(page-1)">Previous</button><span>{{ page }} / {{ lastPage }}</span><button class="btn btn-outline-secondary" :disabled="busy || page===lastPage" @click="lookup(page+1)">Next</button></div>
      </div>
    </template>
  </section>
</template>
<style scoped>
.voucher-access { max-width: 620px; margin: 0 auto; }
button, input, select { min-height: 46px; }
.voucher-access.compact { border:0; border-radius:0; box-shadow:none; background:transparent; padding:18px 2px 3px; }
.credential-field { display:flex; align-items:center; gap:10px; padding:0 15px; margin-bottom:13px; min-height:48px; border-radius:13px; background:#e7edf2; box-shadow:inset 4px 4px 8px #c5ced7,inset -4px -4px 8px #fff; color:#617987; }
.credential-field .form-control { min-width:0; padding:0; border:0; border-radius:0; background:transparent; box-shadow:none; color:#233746; font-size:14px; }
.credential-field:focus-within { outline:2px solid #16879e; outline-offset:2px; }
.credential-field input::placeholder { color:#61717d; opacity:1; }
.compact .form-label { color:#526571; font-size:12px; }
.compact .form-control:not(.credential-field input),.compact .form-select { border:1px solid #c8d4dc; border-radius:12px; background:#edf2f6; }
.compact .btn { font-size:13px; }
.compact .alert { font-size:13px; line-height:1.45; }
button:focus-visible { outline:3px solid #16879e; outline-offset:3px; }

.access-connect { display:flex; align-items:center; justify-content:space-between; min-height:52px; padding:3px 16px; background:linear-gradient(145deg,#f3f7fb,#dfe8ef); color:#155b86; border:1px solid #f8fcff; border-radius:16px; box-shadow:5px 5px 10px #bccbd7,-5px -5px 10px #fff; text-align:left; }
.access-connect:hover,.access-connect:focus { background:#e5eff6; color:#104f78; }
.access-connect:active { box-shadow:inset 3px 3px 7px #bccbd7,inset -3px -3px 7px #fff; }
.button-eye { width:58px; height:38px; flex-shrink:0; }
.credential-field:focus-within { outline-color:#198ab5; }

.phone-feedback { display:block; min-height:24px; margin:5px 0 9px; font-size:12px; color:#196b97; }
.phone-feedback--error { color:#a33e3e; }
.compact .form-control.phone-invalid { border-color:#bd6262; }
.access-connect:disabled { opacity:.55; box-shadow:none; cursor:not-allowed; }
</style>
