<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
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
const printTemplate = ref<'cards' | 'thermal'>('cards')
const nowTick = ref(Date.now())
let clockTimer: number | undefined
function finishPrint() { document.body.classList.remove('voucher-printing') }
async function printVoucher() {
  if (!voucher.value || !['ready', 'active'].includes(voucher.value.status)) return
  await nextTick()
  document.body.classList.add('voucher-printing')
  window.print()
}

async function load() {
  loading.value = true
  try { voucher.value = (await api.get('/admin/vouchers/' + route.params.id)).data }
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
        <div class="col-lg-5"><VoucherCard :voucher="voucher" :plan="voucher.plan" :now-ms="nowTick" /></div>
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
            <p v-if="voucher.status === 'active'"><strong>Package time left:</strong> {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Unavailable' }}<span v-if="voucherTimeLeft(voucher.expires_at, nowTick) === 'Time ended'"> · awaiting sync</span></p>
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
          <div class="d-flex flex-wrap gap-2">
            <button v-if="voucher.status === 'provision_pending'" class="btn btn-primary" :disabled="busy" @click="retry">Retry provisioning</button>
            <button v-if="['ready','active'].includes(voucher.status)" class="btn btn-outline-danger" :disabled="busy" @click="disable">Disable voucher</button>
            <select v-if="['ready', 'active'].includes(voucher.status)" v-model="printTemplate" class="form-select w-auto" aria-label="Ticket template"><option value="cards">Premium card</option><option value="thermal">58 mm receipt</option></select>
            <button v-if="['ready', 'active'].includes(voucher.status)" class="btn btn-outline-secondary" @click="printVoucher">Print ticket</button>
            <router-link v-if="voucher.status === 'active'" class="btn btn-outline-secondary" to="/admin/sessions">View sessions</router-link>
            <button v-if="voucher.status === 'expired'" class="btn btn-outline-primary" @click="router.push('/admin/vouchers')">Generate new voucher</button>
          </div>
          <details v-if="voucher.provision_error" class="mt-3"><summary>Provisioning details</summary><pre class="text-wrap">{{ voucher.provision_error }}</pre></details>
        </div>
      </div>
      <Teleport to="body"><section v-if="['ready', 'active'].includes(voucher.status)" class="voucher-print-sheet" :class="`voucher-print-sheet--${printTemplate}`" aria-label="Ticket to print">
        <PrintableVoucherTicket :ticket="voucher" :thermal="printTemplate === 'thermal'" />
      </section></Teleport>
    </template>
  </AdminShell>
</template>
