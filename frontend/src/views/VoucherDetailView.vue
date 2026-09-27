<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import VoucherCard from '../components/vouchers/VoucherCard.vue'
import PrintableVoucherTicket from '../components/vouchers/PrintableVoucherTicket.vue'
import { api } from '../api'
import { formatDate } from '../utils/formatDate'
import { voucherTimeLeft } from '../utils/voucherTime'

const route = useRoute()
const router = useRouter()
const voucher = ref<any>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const security = ref<any>({events:[],transfer_requests:[],operations:[]})
const recoveryPin = ref('')
const pendingTransfer = computed(() => security.value.transfer_requests.find((item: any) => item.status === 'pending'))
async function action(name: string, existingKey?: string) {
  if (!confirm(name === 'recovery-pin' ? 'Issue/reset recovery access? The PIN is shown once and existing voucher access tokens will expire.' : 'Confirm this voucher action? Device release and rotation disconnect current sessions. Validity will not restart.')) return
  busy.value=true; error.value=''; notice.value=''
  try {
    const saved = security.value.operations.find((op: any) => op.state !== 'completed')
    const requestKey = existingKey || saved?.request_key || crypto.randomUUID()
    const { data } = await api.post(`/admin/vouchers/${route.params.id}/${name}`, { request_key:requestKey, transfer_request_id:pendingTransfer.value?.id, reset:!!voucher.value.recovery_pin_issued_at })
    if (data.recovery_pin) recoveryPin.value=data.recovery_pin
    notice.value=data.state === 'pending_reconciliation' ? data.message : 'Action completed.'
    await load()
  } catch { error.value='Action could not be completed. Refresh the voucher and retry; do not assume router changes succeeded.' }
  finally { busy.value=false }
}
const printTemplate = ref<'cards' | 'thermal'>('cards')
const nowTick = ref(Date.now())
let clockTimer: number | undefined
const displayStatus = computed(() => {
  const expiry = Date.parse(voucher.value?.expires_at || '')
  return voucher.value?.status === 'active' && Number.isFinite(expiry) && expiry <= nowTick.value ? 'expired' : voucher.value?.status
})
const canPrint = computed(() => ['ready', 'active'].includes(displayStatus.value))
function finishPrint() { document.body.classList.remove('voucher-printing') }
async function printVoucher() {
  if (!voucher.value || !canPrint.value) return
  await nextTick()
  document.body.classList.add('voucher-printing')
  window.print()
}

async function load() {
  loading.value = true
  try {
    voucher.value = (await api.get('/admin/vouchers/' + route.params.id)).data
    security.value = (await api.get('/admin/vouchers/' + route.params.id + '/device-events')).data
  }
  catch { error.value = 'Voucher details are unavailable.' }
  finally { loading.value = false }
}
async function retry() {
  busy.value = true
  try { await api.post('/admin/vouchers/' + route.params.id + '/retry'); await load() }
  catch { error.value = 'Provisioning could not be completed. Try again later.' }
  finally { busy.value = false }
}
async function disable() {
  if (!confirm('Disable this voucher and prevent future logins?')) return
  busy.value = true
  try {
    const response = await api.post('/admin/vouchers/' + route.params.id + '/disable')
    notice.value = response.data.message
    await load()
  }
  catch { error.value = 'The voucher could not be disabled.' }
  finally { busy.value = false }
}
onMounted(() => { window.addEventListener('afterprint', finishPrint); clockTimer = window.setInterval(() => { nowTick.value = Date.now() }, 1000); load() })
onUnmounted(() => { window.removeEventListener('afterprint', finishPrint); if (clockTimer) clearInterval(clockTimer); finishPrint() })
</script>

<template>
  <AdminShell>
    <button class="btn btn-link ps-0" @click="router.push('/admin/vouchers')">← All vouchers</button>
    <h1 class="h2">Voucher details</h1>
    <p v-if="loading" role="status">Loading voucher…</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert alert-info" role="status">{{ notice }}</div>
    <template v-if="voucher">
      <div class="row g-3">
        <div class="col-lg-5"><VoucherCard :voucher="{ ...voucher, status: displayStatus }" :plan="voucher.plan" :now-ms="nowTick" /></div>
        <div class="col-lg-7">
          <section class="card p-3 mb-3">
            <h2 class="h5">Customer and payment</h2>
            <p><strong>Phone:</strong> {{ voucher.customer_phone || 'No customer linked' }}</p>
            <p><strong>Order:</strong> {{ voucher.order?.order_number || 'Manual voucher' }}</p>
            <p><strong>Payment:</strong> {{ voucher.order?.payments?.[0]?.status || 'No payment linked' }}</p>
            <p class="mb-0"><strong>Reference:</strong> {{ voucher.order?.payments?.[0]?.reference || 'Unavailable' }}</p>
          </section>
          <section class="card p-3 mb-3">
            <h2 class="h5">Network and activity</h2>
            <p><strong>Device:</strong> {{ voucher.device_mac || 'Not yet bound' }}</p>
            <p><strong>Provisioned:</strong> {{ formatDate(voucher.provisioned_at, 'Pending') }}</p>
            <p><strong>First login:</strong> {{ formatDate(voucher.activated_at, 'Not yet') }}</p>
            <p><strong>Expiry:</strong> {{ formatDate(voucher.expires_at, 'Not yet started') }}</p>
            <p v-if="voucher.status === 'active'"><strong>Package time left:</strong> {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Unavailable' }}<span v-if="displayStatus === 'expired'"> · awaiting sync</span></p>
            <p><strong>Total online time:</strong> {{ voucher.router_total_uptime || 'Unavailable' }}<small v-if="voucher.router_checked_at" class="d-block text-secondary">Router checked {{ formatDate(voucher.router_checked_at) }}</small></p>
            <p><strong>Current session uptime:</strong> {{ voucher.sessions?.find((session: any) => !session.ended_at)?.uptime || 'No active session recorded' }}</p>
            <p><strong>Session last synced:</strong> {{ formatDate(voucher.sessions?.find((session: any) => !session.ended_at)?.last_seen_at, 'Not active') }}</p>
            <p><strong>Voucher last synced:</strong> {{ formatDate(voucher.last_synced_at, 'Not yet') }}</p>
            <p class="mb-0"><strong>Sessions:</strong> {{ voucher.sessions?.length || 0 }} recent</p>
          </section>
          <section class="card p-3 mb-3">
            <h2 class="h5">Activity</h2>
            <ul class="mb-0">
              <li v-if="voucher.order?.payments?.[0]?.created_at">Payment initiated · {{ formatDate(voucher.order.payments[0].created_at) }}</li>
              <li v-if="voucher.order?.payments?.[0]?.completed_at">Payment confirmed · {{ formatDate(voucher.order.payments[0].completed_at) }}</li>
              <li>Voucher generated · {{ formatDate(voucher.created_at) }}</li>
              <li v-if="voucher.provisioned_at">Router provisioned · {{ formatDate(voucher.provisioned_at) }}</li>
              <li v-if="voucher.activated_at">First login · {{ formatDate(voucher.activated_at) }}</li>
              <li v-if="voucher.device_mac && voucher.activated_at">Device bound · {{ formatDate(voucher.activated_at) }}</li>
              <li v-for="session in voucher.sessions || []" :key="session.id">Session started · {{ formatDate(session.started_at) }}</li>
            </ul>
          </section>
          <section class="card p-3 mb-3">
            <h2 class="h5">Device recovery and security</h2>
            <p>Transfers: {{ voucher.transfer_count || 0 }}</p>
            <p v-if="voucher.compromised_at" class="alert alert-warning">Owner reported compromised credentials {{ formatDate(voucher.compromised_at) }}.</p>
            <div v-if="recoveryPin" class="alert alert-warning"><strong>Recovery PIN: {{ recoveryPin }}</strong><p>Hand this to the owner now. It will not be shown again.</p><button class="btn btn-outline-dark" @click="recoveryPin=''">Handed over</button></div>
            <div v-if="pendingTransfer" class="alert alert-info">
              <p>Transfer requested {{ formatDate(pendingTransfer.requested_at) }}. {{ pendingTransfer.reason }}</p>
              <button class="btn btn-primary me-2" :disabled="busy" @click="action('transfer/approve')">Approve transfer</button>
              <button class="btn btn-outline-danger" :disabled="busy" @click="action('transfer/reject')">Reject transfer</button>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button v-if="voucher.device_mac && canPrint" class="btn btn-outline-warning" :disabled="busy" @click="action('release-device')">Release device</button>
              <button v-if="canPrint" class="btn btn-outline-danger" :disabled="busy" @click="action('rotate-credentials')">Rotate voucher PIN</button>
              <button v-if="voucher.customer_phone" class="btn btn-outline-primary" :disabled="busy || !!recoveryPin" @click="action('recovery-pin')">{{ voucher.recovery_pin_issued_at ? 'Reset recovery PIN' : 'Issue recovery PIN' }}</button>
            </div>
            <div v-for="op in security.operations.filter((item: any) => item.state !== 'completed')" :key="op.id" class="alert alert-warning mt-3">
              Router operation needs reconciliation. Retry to confirm completion.
              <button class="btn btn-outline-dark" :disabled="busy" @click="action(op.action === 'rotate' ? 'rotate-credentials' : (op.transfer_request_id ? 'transfer/approve' : 'release-device'), op.request_key)">Retry operation</button>
            </div>
            <details class="mt-3"><summary>Recent security events</summary><p v-if="!security.events.length">No events recorded.</p><ul><li v-for="event in security.events" :key="event.id">{{ event.event_type.replaceAll('_',' ') }} · {{ formatDate(event.occurred_at) }}<span v-if="event.attempted_mac"> · {{ event.attempted_mac }}</span></li></ul></details>
          </section>
          <div class="d-flex flex-wrap gap-2">
            <button v-if="voucher.status === 'provision_pending'" class="btn btn-primary" :disabled="busy" @click="retry">Retry provisioning</button>
            <button v-if="canPrint" class="btn btn-outline-danger" :disabled="busy" @click="disable">Disable voucher</button>
            <select v-if="canPrint" v-model="printTemplate" class="form-select w-auto" aria-label="Ticket template"><option value="cards">A4 · 30 tickets</option><option value="thermal">58 mm receipt</option></select>
            <button v-if="canPrint" class="btn btn-outline-secondary" @click="printVoucher">Print ticket</button>
            <router-link v-if="voucher.status === 'active'" class="btn btn-outline-secondary" to="/admin/sessions">View sessions</router-link>
            <button v-if="displayStatus === 'expired'" class="btn btn-outline-primary" @click="router.push('/admin/vouchers')">Generate new voucher</button>
          </div>
          <details v-if="voucher.provision_error" class="mt-3"><summary>Provisioning details</summary><pre class="text-wrap">{{ voucher.provision_error }}</pre></details>
        </div>
      </div>
      <Teleport to="body"><section v-if="canPrint" class="voucher-print-sheet" :class="`voucher-print-sheet--${printTemplate}`" aria-label="Ticket to print">
        <PrintableVoucherTicket :ticket="voucher" :compact="printTemplate === 'cards'" :thermal="printTemplate === 'thermal'" />
      </section></Teleport>
    </template>
  </AdminShell>
</template>
