<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'
import { paymentMoney, reportingDate } from '../utils/paymentReporting'
const route = useRoute()
const search = ref(''), showFilters = ref(false)
const status = ref(['all','open','contacted','resolved','cancelled'].includes(String(route.query.status)) ? String(route.query.status) : 'open'), page = ref<any>({ data:[],current_page:1,last_page:1 }), busy = ref(false), error = ref('')
const historyDialog = ref<HTMLDialogElement | null>(null)
const history = ref<any>(null), historyBusy = ref(false), historyError = ref('')
const selectedRequest = ref<any>(null)
let historyLoadId = 0
async function viewHistory(request: any, pageNumber = 1) {
  selectedRequest.value=request
  historyDialog.value?.showModal()
  const id=++historyLoadId
  historyBusy.value=true; historyError.value=''; history.value=null
  try { const response=await api.get(`/admin/support/${request.uuid}/history`,{params:{page:pageNumber}}); if(id===historyLoadId) history.value=response.data }
  catch { if(id===historyLoadId) historyError.value='Customer history could not be loaded.' }
  finally { if(id===historyLoadId) historyBusy.value=false }
}
function historyDay(time: string) { return new Date(time).toLocaleDateString('en-GB',{timeZone:'Africa/Dar_es_Salaam'}) }
async function load(next = 1) {
  busy.value=true; error.value=''
  try { page.value=(await api.get('/admin/support',{params:{status:status.value,page:next,search:search.value || undefined}})).data }
  catch { error.value='Support requests could not be loaded.' }
  finally { busy.value=false }
}
async function update(uuid: string, value: string) {
  if (busy.value) return
  busy.value=true; error.value=''
  try { await api.patch(`/admin/support/${uuid}`,{status:value}); await load(page.value.current_page) }
  catch { error.value='Request could not be updated. Refresh and try again.' }
  finally { busy.value=false }
}
onMounted(() => load())
</script>
<template>
  <AdminShell><h1 class="h2">Customer support</h1>
<section class="admin-list-panel"><form class="admin-list-toolbar" @submit.prevent="load()"><div class="admin-list-search"><input v-model="search" class="form-control" maxlength="100" placeholder="Search support requests…" aria-label="Search support requests"><button aria-label="Search"><i class="bi bi-search"></i></button></div><div class="admin-list-actions"><button type="button" class="btn" @click="load()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button><button type="button" class="btn" :aria-expanded="showFilters" @click="showFilters=!showFilters"><i class="bi bi-funnel-fill me-1"></i>Filters</button></div></form>    <label v-show="showFilters" class="admin-list-filters">Status<select v-model="status" class="form-select" :disabled="busy" @change="load()"><option v-for="value in ['open','contacted','resolved','cancelled','all']" :key="value">{{ value }}</option></select></label>
    <p v-if="busy" role="status">Loading…</p><div v-if="error" role="alert" class="alert alert-danger">{{ error }} <button class="btn btn-sm btn-outline-danger" @click="load()">Retry</button></div>
    <p v-if="!busy && !error && !page.data.length">No support requests.</p>
    <div class="mobile-ledger d-lg-none"><div v-for="request in page.data" :key="request.uuid" class="mobile-ledger__row support-mobile-row"><article>
      <div class="d-flex justify-content-between gap-2"><strong>{{ request.customer_phone || 'Phone unavailable' }}</strong><span class="badge text-bg-secondary">{{ request.status }}</span></div>
      <small>{{ reportingDate(request.created_at) }}</small><p class="mt-2 mb-1">{{ request.plan?.name || 'Package unavailable' }} · {{ request.voucher?.code || 'No voucher' }}</p>
      <p class="small mb-1">Reported connection: {{ request.connection_state || 'Unknown' }} · Device: {{ request.device_mac || 'Unknown' }}</p>
      <p class="small">Order: {{ request.order?.order_number || '—' }} · Payment: {{ request.payment?.reference || '—' }}</p>
      <div class="support-actions mt-auto"><button class="btn btn-sm btn-outline-primary" title="View customer history" aria-label="View customer history" @click="viewHistory(request)"><i class="bi bi-eye"></i></button><template v-if="['open','contacted'].includes(request.status)"><a v-if="request.customer_phone" :href="`tel:+${request.customer_phone}`" class="btn btn-primary" title="Call" aria-label="Call customer"><i class="bi bi-telephone"></i></a><button v-if="request.status==='open'" class="btn btn-outline-secondary" :disabled="busy" title="Mark contacted" aria-label="Mark contacted" @click="update(request.uuid,'contacted')"><i class="bi bi-person-check"></i></button><button class="btn btn-outline-success" :disabled="busy" title="Resolve" aria-label="Resolve" @click="update(request.uuid,'resolved')"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-secondary" :disabled="busy" title="Cancel" aria-label="Cancel" @click="update(request.uuid,'cancelled')"><i class="bi bi-x-lg"></i></button></template></div>
    </article></div></div>
    <div v-if="page.data.length" class="admin-table-shell d-none d-lg-block"><table class="admin-data-table"><thead><tr><th>Phone</th><th>Created</th><th>Package / voucher</th><th>Connection / device</th><th>Order / payment</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="request in page.data" :key="request.uuid"><td>{{ request.customer_phone || 'Unavailable' }}</td><td>{{ reportingDate(request.created_at) }}</td><td>{{ request.plan?.name || 'Unavailable' }}<small class="d-block text-secondary">{{ request.voucher?.code || 'No voucher' }}</small></td><td>{{ request.connection_state || 'Unknown' }}<small class="d-block text-secondary">{{ request.device_mac || 'Unknown device' }}</small></td><td>{{ request.order?.order_number || '—' }}<small class="d-block text-secondary">{{ request.payment?.reference || '—' }}</small></td><td><span class="badge" :class="request.status==='resolved' ? 'text-bg-success' : request.status==='open' ? 'text-bg-warning' : 'text-bg-secondary'">{{ request.status }}</span></td><td><div class="support-actions"><button class="btn btn-sm btn-outline-primary" title="View customer history" aria-label="View customer history" @click="viewHistory(request)"><i class="bi bi-eye"></i></button><template v-if="['open','contacted'].includes(request.status)"><a v-if="request.customer_phone" :href="`tel:+${request.customer_phone}`" class="btn btn-primary" title="Call" aria-label="Call customer"><i class="bi bi-telephone"></i></a><button v-if="request.status==='open'" class="btn btn-outline-primary" :disabled="busy" title="Mark contacted" aria-label="Mark contacted" @click="update(request.uuid,'contacted')"><i class="bi bi-person-check"></i></button><button class="btn btn-outline-success" :disabled="busy" title="Resolve" aria-label="Resolve" @click="update(request.uuid,'resolved')"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-secondary" :disabled="busy" title="Cancel" aria-label="Cancel" @click="update(request.uuid,'cancelled')"><i class="bi bi-x-lg"></i></button></template></div></td></tr></tbody></table></div>
    <nav class="admin-table-pagination" aria-label="Support pages"><button class="btn btn-outline-secondary" :disabled="busy || page.current_page<=1" @click="load(page.current_page-1)">Previous</button><span>Page {{ page.current_page }} of {{ page.last_page }}</span><button class="btn btn-outline-secondary" :disabled="busy || page.current_page>=page.last_page" @click="load(page.current_page+1)">Next</button></nav>
    </section>

    <Teleport to="body"><dialog ref="historyDialog" class="support-history-dialog" aria-labelledby="support-history-title">
      <header><div><h2 id="support-history-title">Customer history</h2><small>{{ selectedRequest?.customer_phone }}</small></div><button class="btn btn-sm btn-outline-secondary" aria-label="Close history" @click="historyDialog?.close()"><i class="bi bi-x-lg"></i></button></header>
      <p v-if="historyBusy" role="status">Loading history…</p>
      <p v-if="historyError" role="alert">{{ historyError }} <button class="btn btn-sm btn-outline-primary" @click="viewHistory(selectedRequest)">Retry</button></p>
      <template v-if="history">
        <p class="small text-secondary">Same phone · newest records first · Tanzania time</p>
        <h3>Payments <small>{{ history.payments.total }}</small></h3>
        <p v-if="!history.payments.data.length" class="small text-secondary">No matching payments.</p>
        <div v-else class="admin-table-shell"><table class="admin-data-table"><thead><tr><th>Date</th><th>Package / reference</th><th>Amount</th><th>Status</th></tr></thead><tbody><tr v-for="payment in history.payments.data" :key="payment.id"><td>{{ reportingDate(payment.completed_at || payment.updated_at) }}</td><td>{{ payment.order?.plan?.name }}<small class="d-block text-secondary">{{ payment.reference || 'No reference' }}</small></td><td>{{ paymentMoney(payment.amount,payment.currency) }}</td><td><span class="badge" :class="payment.status==='completed' ? 'text-bg-success' : payment.status==='pending' ? 'text-bg-warning' : 'text-bg-secondary'">{{ payment.status==='completed' ? 'Paid' : payment.status }}</span></td></tr></tbody></table></div>
        <h3>Vouchers <small>{{ history.vouchers.total }}</small></h3>
        <p v-if="!history.vouchers.data.length" class="small text-secondary">No matching vouchers.</p>
        <div v-else class="admin-table-shell"><table class="admin-data-table"><thead><tr><th>Date</th><th>Voucher / package</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="voucher in history.vouchers.data" :key="voucher.id"><td>{{ reportingDate(voucher.created_at) }}</td><td>{{ voucher.code }}<small class="d-block text-secondary">{{ voucher.plan?.name }}</small></td><td>{{ voucher.status }}</td><td><router-link :to="`/admin/vouchers/${voucher.id}`" class="btn btn-sm btn-outline-primary" @click="historyDialog?.close()">View</router-link></td></tr></tbody></table></div>
        <nav class="admin-table-pagination"><button class="btn btn-sm btn-outline-primary" :disabled="history.payments.current_page<=1" @click="viewHistory(selectedRequest,history.payments.current_page-1)">Previous</button><span>History page {{ history.payments.current_page }} · 20 records per list</span><button class="btn btn-sm btn-outline-primary" :disabled="history.payments.current_page>=Math.max(history.payments.last_page,history.vouchers.last_page)" @click="viewHistory(selectedRequest,history.payments.current_page+1)">Next</button></nav>
        <h3>Recorded activity <small>Latest 100 events</small></h3>
        <p v-if="!history.timeline.length" class="small text-secondary">No recorded events.</p>
        <ol class="support-timeline"><li v-for="(event,index) in history.timeline" :key="index"><h4 v-if="index===0 || historyDay(event.time)!==historyDay(history.timeline[index-1].time)">{{ historyDay(event.time) }}</h4><div><span class="badge text-bg-light">{{ event.source }}</span><strong>{{ event.event.replaceAll('_',' ') }}</strong><small>{{ reportingDate(event.time) }} · {{ event.reference }}</small></div></li></ol>
        <p class="small text-secondary">{{ history.history_note }}</p>
      </template>
    </dialog></Teleport>
  </AdminShell>
</template>
<style scoped>
.support-mobile-row { display:block; padding:12px; }
.support-mobile-row article { width:100%; }
</style>

<style scoped>
.support-history-dialog { position:fixed; inset:0; margin:auto; width:min(850px,calc(100% - 24px)); max-height:calc(100dvh - 32px); overflow:auto; padding:16px; border:1px solid #9dd4f2; border-radius:10px; background:#fff; color:#243447; }
.support-history-dialog::backdrop { background:rgba(15,23,42,.4); }
.support-history-dialog header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.support-history-dialog h2 { font-size:18px; color:#196b97; margin:0; }
.support-history-dialog h3 { font-size:14px; color:#196b97; margin:16px 0 8px; }
.support-timeline { list-style:none; padding:0; font-size:12px; }
.support-timeline h4 { font-size:12px; padding:7px; margin:8px 0; background:#eaf0f5; }
.support-timeline li > div { display:flex; flex-wrap:wrap; gap:8px; padding:7px 0; border-bottom:1px solid #eaf0f5; }
.support-timeline small { margin-left:auto; color:#64748b; }
</style>

<style scoped>
.support-actions { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; width:max-content; }
.support-actions .btn { display:inline-flex; align-items:center; justify-content:center; min-width:36px; min-height:36px; padding:6px 9px; margin:0; flex-shrink:0; }
.support-mobile-row article { min-width:0; overflow-x:auto; }
</style>
