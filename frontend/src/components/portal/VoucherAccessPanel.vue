<script setup lang="ts">
import { usePortalLanguage } from '../../i18n/portalLanguage'
const { t, formatDate } = usePortalLanguage()
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '../../api'
import { formatPhoneInput } from '../../utils/formatPhoneInput'
import VoucherCard from '../vouchers/VoucherCard.vue'
import SignalEye from './SignalEye.vue'
import { submitHotspotLogin } from '../../utils/hotspotLogin'
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
    <h1 v-if="!compact" class="h3">{{ t(mode === 'redeem' ? 'Use a Voucher' : 'My Vouchers') }}</h1>
    <p v-if="message" role="status" class="alert alert-info mt-3">{{ t(message) }}</p>
    <div v-if="issuedPin" class="alert alert-warning">
      <strong> {{ t("Recovery PIN:") }} {{ issuedPin }}</strong><p> {{ t("Keep this recovery PIN. You can use it to recover this voucher later.") }} </p>
      <button class="btn btn-outline-dark" @click="issuedPin=''"> {{ t("I have saved it") }} </button>
    </div>
    <template v-if="voucher">
      <VoucherCard :voucher="voucher" :plan="voucher.plan" :now-ms="nowTick" :show-connect="canConnect && !issuedPin" :connect-label="voucher.status === 'active' ? 'Reconnect' : 'Connect'" :connecting="busy" @connect="connect" />
      <p class="mt-3 mb-1"> {{ t("Linked phone:") }} {{ voucher.customer_phone || t('Not registered') }}</p>
      <p> {{ t("Purchased:") }} {{ formatDate(voucher.created_at) }}</p>
      <p v-if="voucher.session"> {{ t("Last activity:") }} {{ formatDate(voucher.session.last_seen_at) }}</p>
      <div v-if="verified" class="d-grid gap-2 mt-3">
        <button v-if="voucher.device_mac && ['ready','active'].includes(voucher.status)" class="btn btn-outline-primary" :disabled="busy || voucher.transfer_pending" @click="support('device-transfer-request')">{{ t(voucher.transfer_pending ? 'Device request awaiting review' : 'This is not my device') }}</button>
        <button class="btn btn-outline-secondary" :disabled="busy" @click="support('report-compromised')"> {{ t("Report voucher compromised") }} </button>
      </div>
      <p v-else-if="voucher.device_mac" class="mt-3"> {{ t("Use My Vouchers with your recovery PIN, or contact support, to request a device transfer.") }} </p>
      <form v-if="!voucher.registered" class="mt-3" @submit.prevent="claim">
        <label for="claim-phone" class="form-label"> {{ t("Register this voucher for recovery") }} </label>
        <input id="claim-phone" :value="phone" @input="onPhoneInput" type="tel" inputmode="numeric" pattern="[0-9 ]*" autocomplete="tel" required class="form-control" :class="{ 'phone-invalid':phoneInvalid }" :aria-invalid="phoneInvalid" aria-describedby="claim-phone-feedback" :disabled="busy" @blur="phoneTouched=true" placeholder="255 7XX XXX XXX" />
        <small id="claim-phone-feedback" class="phone-feedback" :class="{ 'phone-feedback--error':phoneInvalid }" aria-live="polite">{{ t(phoneInvalid ? 'Weka namba sahihi ya Tanzania.' : normalizedPhone ? '✓ Namba imekamilika' : '') }}</small>
        <button class="btn btn-outline-primary mt-2" :disabled="busy || !normalizedPhone"> {{ t("Claim voucher") }} </button>
      </form>
      <button v-if="voucher.status === 'expired' || state === 'expired'" class="btn btn-primary mt-3" @click="emit('buy')"> {{ t("Buy new package") }} </button>
      <p v-if="['disabled','revoked'].includes(voucher.status)"> {{ t("Contact the Wi-Fi operator for support.") }} </p>
      <button class="btn btn-link mt-3" @click="forget(); selected=null; message=''"> {{ t("Use another voucher") }} </button>
    </template>
    <form v-else-if="mode === 'redeem'" @submit.prevent="redeem">
      <label for="redeem-code" class="visually-hidden"> {{ t("Voucher code") }} </label>
      <div class="credential-field"><i class="bi bi-ticket-perforated" aria-hidden="true"></i><input id="redeem-code" v-model="code" class="form-control" :placeholder="t('Voucher code')" autocomplete="off" autocapitalize="characters" required maxlength="80" @input="code=code.toUpperCase()" @blur="code=code.trim()" /></div>
      <label for="redeem-pin" class="visually-hidden"> {{ t("Voucher PIN") }} </label>
      <div class="credential-field"><i class="bi bi-lock" aria-hidden="true"></i><input id="redeem-pin" v-model="pin" class="form-control" placeholder="PIN" type="password" inputmode="numeric" autocomplete="off" required maxlength="80" /></div>
      <button class="btn btn-primary w-100 access-connect" :disabled="busy"><span>{{ t(busy ? 'Connecting…' : 'Connect') }}</span><span class="button-eye"><SignalEye :status="eyeStatus" /></span></button>
    </form>
    <template v-else>
      <form @submit.prevent="lookup()">
        <label for="recovery-phone" class="form-label"> {{ t("Phone number") }} </label>
        <input id="recovery-phone" :value="phone" @input="onPhoneInput" class="form-control" :class="{ 'phone-invalid':phoneInvalid }" type="tel" inputmode="numeric" pattern="[0-9 ]*" autocomplete="tel" required :aria-invalid="phoneInvalid" aria-describedby="recovery-phone-feedback" :disabled="busy" @blur="phoneTouched=true" placeholder="255 7XX XXX XXX" />
        <small id="recovery-phone-feedback" class="phone-feedback" :class="{ 'phone-feedback--error':phoneInvalid }" aria-live="polite">{{ t(phoneInvalid ? 'Weka namba sahihi ya Tanzania.' : normalizedPhone ? '✓ Namba imekamilika' : '') }}</small>
        <button class="btn btn-primary w-100 access-connect" :disabled="busy || !normalizedPhone"><span>{{ t(busy ? 'Searching…' : 'Find vouchers') }}</span><span class="button-eye"><SignalEye :status="eyeStatus" /></span></button>
      </form>
      <form v-if="selected" class="mt-4" @submit.prevent="verify">
        <h2 class="h5">{{ selected.plan.name }} · {{ selected.code }}</h2>
        <label for="recovery-pin" class="form-label"> {{ t("This voucher’s recovery PIN") }} </label>
        <input id="recovery-pin" v-model="recoveryPin" class="form-control mb-3" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required />
        <button class="btn btn-primary" :disabled="busy || !normalizedPhone"> {{ t("Verify") }} </button>
        <p class="small mt-2"> {{ t("Lost your recovery PIN? Contact the Wi-Fi operator.") }} </p>
      </form>
      <div v-else-if="searched" class="recovery-results" :aria-busy="busy">
        <div class="recovery-results__toolbar">
          <h2> {{ t("Vouchers") }} </h2>
          <label for="voucher-filter" class="visually-hidden"> {{ t("Filter vouchers on this page") }} </label>
          <select id="voucher-filter" v-model="filter" class="form-select"><option value="all"> {{ t("All") }} </option><option value="active"> {{ t("Active") }} </option><option value="unused"> {{ t("Unused") }} </option><option value="expired"> {{ t("Expired") }} </option></select>
        </div>
        <p v-if="!visible.length" class="recovery-empty" role="status"> {{ t("No vouchers match on this page.") }} </p>
        <div class="recovery-list">
          <article v-for="item in visible" :key="item.uuid" class="recovery-ticket">
            <header class="recovery-ticket__header">
              <h3>{{ item.plan.name }}</h3>
              <span class="recovery-ticket__status" :class="`recovery-ticket__status--${item.status}`">{{ t(labels[item.status] || 'Unavailable') }}</span>
            </header>
            <div class="recovery-ticket__code"><i class="bi bi-ticket-perforated" aria-hidden="true"></i><span>{{ item.code }}</span></div>
            <div class="recovery-ticket__footer">
              <time :datetime="item.created_at" :aria-label="t('Purchased')">{{ formatDate(item.created_at) }}</time>
              <button type="button" class="recovery-ticket__action" :disabled="busy" :aria-label="`${t('Recover')} ${item.plan.name}, ${item.code}`" @click="selected=item; recoveryPin=''"> {{ t("Recover") }} <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </div>
            <details v-if="item.device_mac" class="recovery-ticket__details">
              <summary> {{ t("Device") }} </summary>
              <span>{{ item.device_mac }}</span>
            </details>
          </article>
        </div>
        <nav v-if="lastPage>1" class="recovery-pagination" :aria-label="t('Voucher pages')">
          <button class="btn btn-outline-secondary" :disabled="busy || page===1" @click="lookup(page-1)"> {{ t("Previous") }} </button>
          <span>{{ page }} / {{ lastPage }}</span>
          <button class="btn btn-outline-secondary" :disabled="busy || page===lastPage" @click="lookup(page+1)"> {{ t("Next") }} </button>
        </nav>
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

.recovery-results { margin-top: 22px; min-width: 0; }
.recovery-results__toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.recovery-results__toolbar h2 { margin: 0; font-size: 15px; font-weight: 750; color: #233746; }
.recovery-results__toolbar .form-select { width: 128px; min-width: 0; font-size: 16px; }
.recovery-list { display: grid; gap: 12px; }
.recovery-ticket { min-width: 0; padding: 13px; border: 1px solid #fff; border-radius: 16px; background: #eef3f9; box-shadow: 3px 3px 8px #cbd5df, -3px -3px 8px #fff; }
.recovery-ticket__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.recovery-ticket__header h3 { min-width: 0; margin: 0; font-size: 15px; line-height: 1.4; font-weight: 750; color: #233746; overflow-wrap: anywhere; }
.recovery-ticket__status { flex-shrink: 0; max-width: 45%; padding: 4px 7px; border-radius: 7px; background: #e1e7ed; color: #526571; font-size: 11px; line-height: 1.4; font-weight: 650; overflow-wrap: anywhere; }
.recovery-ticket__status--ready { background: #dfedf8; color: #185e87; }
.recovery-ticket__status--active { background: #dcefed; color: #13696a; }
.recovery-ticket__status--provision_pending { background: #e3eaf3; color: #485f7c; }
.recovery-ticket__code { display: flex; align-items: center; gap: 7px; margin-top: 7px; color: #526571; font-size: 13px; overflow-wrap: anywhere; }
.recovery-ticket__code span { min-width: 0; }
.recovery-ticket__footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px 10px; border-top: 1px solid #dce4ec; margin-top: 10px; padding-top: 6px; }
.recovery-ticket__footer time { color: #526571; font-size: 11px; line-height: 1.5; overflow-wrap: anywhere; }
.recovery-ticket__action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 10px; border: 1px solid #c4d9e5; border-radius: 11px; background: linear-gradient(145deg, #f6faff, #dfeaf2); color: #185e87; font-size: 12px; font-weight: 650; }
.recovery-ticket__action:disabled { opacity: .55; }
.recovery-ticket__details { margin-top: 4px; color: #526571; font-size: 12px; overflow-wrap: anywhere; }
.recovery-ticket__details summary { min-height: 44px; padding: 12px 0; cursor: pointer; }
.recovery-ticket__details summary:focus-visible { outline: 2px solid #16879e; outline-offset: 2px; }
.recovery-pagination { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 16px; font-size: 12px; }
.recovery-pagination .btn { padding: 8px 10px; min-width: 0; }
.recovery-empty { padding: 16px 0; font-size: 13px; color: #526571; }
@media (max-width: 359px) {
  .recovery-ticket { padding: 10px; }
  .recovery-ticket__header h3 { font-size: 14px; }
  .recovery-ticket__footer { gap: 4px; }
  .recovery-ticket__action { padding: 0 8px; }
}
</style>
