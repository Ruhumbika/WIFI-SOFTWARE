<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'
import { paymentQuery, paymentMoney, paymentTime, reportingDate } from '../utils/paymentReporting'
const route = useRoute(), router = useRouter()
const page = ref<any>({ data: [], current_page: 1, last_page: 1, total: 0 })
const plans = ref<any[]>([]), loading = ref(true), error = ref(''), planError = ref('')
const filters = ref<Record<string,string>>(paymentQuery(route.query))
const showFilters = ref(false)
const filterCount = computed(() => ['date','status','plan_id','from','to'].filter(key => filters.value[key] && filters.value[key] !== 'all').length)
const paymentGroups = computed(() => {
  const groups: { phone: string; payments: any[] }[] = []
  for (const payment of page.value.data || []) {
    const phone = payment.order?.customer_phone || 'Phone unavailable'
    const current = groups[groups.length - 1]
    if (current?.phone === phone) current.payments.push(payment)
    else groups.push({ phone, payments: [payment] })
  }
  return groups
})
let loadId = 0
async function load() {
  const id = ++loadId; loading.value = true; error.value = ''
  try { const response = await api.get('/admin/payments', { params: paymentQuery(route.query) }); if (id === loadId) page.value = response.data }
  catch { if (id === loadId) error.value = 'Payments could not be loaded.' }
  finally { if (id === loadId) loading.value = false }
}
function apply(nextPage = 1) {
  const query = paymentQuery({ ...filters.value, page: String(nextPage) })
  if (query.date !== 'custom') { delete query.from; delete query.to }
  router.push({ path: '/admin/payments', query })
}
function label(status: string) { return status === 'completed' ? 'Paid' : status.charAt(0).toUpperCase() + status.slice(1) }
function statusClass(status: string) { return `payment-status payment-status--${status}` }
watch(() => route.query, () => { filters.value = paymentQuery(route.query); void load() })
async function loadPlans() {
  planError.value=''
  try { plans.value = (await api.get('/admin/plans')).data }
  catch { planError.value='Package filters could not be loaded.' }
}
onMounted(() => { void load(); void loadPlans() })
</script>
<template>
  <AdminShell>
    <div class="payments-head"><div><h1 class="h2 mb-0">Payments</h1><small class="text-secondary">{{ page.total || 0 }} entries</small></div></div>
    <p v-if="planError" role="alert" class="alert alert-warning">{{ planError }} <button class="btn btn-sm btn-outline-secondary" @click="loadPlans">Retry packages</button></p>
    <section class="admin-list-panel">
    <form class="payment-toolbar" @submit.prevent="apply()">
      <div class="admin-list-toolbar"><div class="admin-list-search"><label class="visually-hidden" for="payment-search">Search payments</label><input id="payment-search" v-model="filters.search" class="form-control" maxlength="100" placeholder="Reference, phone or order"><button class="btn btn-outline-primary" :disabled="loading" aria-label="Search"><i class="bi bi-search"></i></button></div><div class="admin-list-actions"><button type="button" class="btn btn-outline-primary" @click="load"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button><button type="button" class="btn btn-outline-primary" :aria-expanded="showFilters" aria-controls="payment-filters" @click="showFilters=!showFilters"><i class="bi bi-funnel-fill me-1"></i>Filters <span v-if="filterCount" class="badge text-bg-primary">{{ filterCount }}</span></button></div></div>
      <div v-show="showFilters" id="payment-filters" class="ledger-filters">
      <label>Date<select v-model="filters.date" class="form-select"><option value="">All dates</option><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="7d">Last 7 days</option><option value="30d">Last 30 days</option><option value="custom">Custom</option></select></label>
      <label v-if="filters.date === 'custom'">From<input v-model="filters.from" class="form-control" type="date" required></label>
      <label v-if="filters.date === 'custom'">To<input v-model="filters.to" class="form-control" type="date" :min="filters.from" required></label>
      <label>Status<select v-model="filters.status" class="form-select"><option value="">All statuses</option><option v-for="status in ['completed','pending','failed','expired','voided']" :key="status" :value="status">{{ label(status) }}</option></select></label>
      <label>Package<select v-model="filters.plan_id" class="form-select"><option value="">All packages</option><option v-for="plan in plans" :key="plan.id" :value="String(plan.id)">{{ plan.name }}</option></select></label>
      <button class="btn btn-primary" :disabled="loading">Apply filters</button>
      </div>
    </form>
    <p class="text-secondary small">Dates use Tanzania time. Paid payments use completion time; other statuses use their last update.</p>
    <p v-if="loading" role="status">Loading payments…</p>
    <div v-else-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="btn btn-sm btn-outline-danger" @click="load">Retry</button></div>
    <p v-else-if="!page.data.length" role="status">No payments match these filters.</p>
    <template v-else>
      <div class="payment-mobile d-md-none">
        <section v-for="group in paymentGroups" :key="group.phone" class="payment-group"><h2><i class="bi bi-phone"></i>{{ group.phone }} <span>{{ group.payments.length }}</span></h2>
        <article v-for="payment in group.payments" :key="payment.id" class="payment-row">
          <div class="d-flex justify-content-between gap-2"><strong>{{ payment.order?.plan?.name || 'Package unavailable' }}</strong><span :class="statusClass(payment.status)">{{ label(payment.status) }}</span></div>
          <small>{{ reportingDate(paymentTime(payment)) }}</small>
          <dl class="row mt-2 mb-0"><dt class="col-5">Gross</dt><dd class="col-7">{{ paymentMoney(payment.gross_amount ?? payment.amount, payment.currency) }}</dd><dt class="col-5">Snippe fee</dt><dd class="col-7">{{ paymentMoney(payment.fee_amount, payment.settlement_currency || payment.currency) }}</dd><dt class="col-5">Net received</dt><dd class="col-7">{{ paymentMoney(payment.net_amount, payment.settlement_currency || payment.currency) }}</dd></dl>
          <details><summary>Reference</summary>{{ payment.reference || '—' }}</details>
        </article></section>
      </div>
      <div class="admin-table-shell d-none d-md-block"><table class="admin-data-table"><thead><tr><th>Reference</th><th>Package</th><th>Gross</th><th>Snippe fee</th><th>Net received</th><th>Status</th><th>Payment time</th></tr></thead><tbody><template v-for="group in paymentGroups" :key="group.phone"><tr class="phone-group-row"><th colspan="7"><i class="bi bi-phone me-1"></i>{{ group.phone }} <span>{{ group.payments.length }} payment{{ group.payments.length === 1 ? '' : 's' }}</span></th></tr><tr v-for="payment in group.payments" :key="payment.id"><td>{{ payment.reference || '—' }}</td><td>{{ payment.order?.plan?.name }}</td><td>{{ paymentMoney(payment.gross_amount ?? payment.amount,payment.currency) }}</td><td>{{ paymentMoney(payment.fee_amount,payment.currency) }}</td><td>{{ paymentMoney(payment.net_amount,payment.currency) }}</td><td><span :class="statusClass(payment.status)">{{ label(payment.status) }}</span></td><td>{{ reportingDate(paymentTime(payment)) }}</td></tr></template></tbody></table></div>
      <nav class="admin-table-pagination" aria-label="Payment pages"><button class="btn btn-outline-secondary" :disabled="loading || page.current_page <= 1" @click="apply(page.current_page-1)">Previous</button><span>Showing page {{ page.current_page }} of {{ page.last_page }} · {{ page.total }} entries</span><button class="btn btn-outline-secondary" :disabled="loading || page.current_page >= page.last_page" @click="apply(page.current_page+1)">Next</button></nav>
    </template>
    </section>
  </AdminShell>
</template>
<style scoped>
.payments-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.payment-toolbar { margin-bottom:10px; }
.payment-search { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:8px; }
.payment-search .form-control,.payment-search .btn,.ledger-filters .form-control,.ledger-filters .form-select,.ledger-filters .btn { min-height:38px; font-size:13px; }
.ledger-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr)); gap:8px; align-items:end; padding:10px; margin-top:8px; border:1px solid #d7e2eb; border-radius:12px; background:#eaf0f5; }
.ledger-filters label { min-width:0; font-size:13px; font-weight:600; }
.payment-mobile { overflow:hidden; border:1px solid #d7e2eb; border-radius:14px; background:#fff; }
.payment-group h2,.phone-group-row th { margin:0; padding:7px 10px; background:#eaf0f5; color:#196b97; font-size:12px; font-weight:800; }
.payment-group h2 span,.phone-group-row span { color:#64748b; font-weight:600; }
.payment-row { overflow-wrap:anywhere; padding:10px; border-bottom:1px solid #d7e2eb; font-size:13px; }
.payment-row summary { min-height:44px; padding-top:12px; cursor:pointer; }
.payment-status { display:inline-flex; min-width:88px; align-items:center; justify-content:center; padding:4px 9px; border-left:4px solid; border-radius:6px; background:#eaf0f5; font-size:12px; font-weight:750; }
.payment-status--completed { border-color:#198754; color:#146c43; }
.payment-status--pending { border-color:#f59e0b; color:#9a5b00; }
.payment-status--failed { border-color:#dc3545; color:#b02a37; }
.payment-status--expired,.payment-status--voided { border-color:#6c757d; color:#495057; }
@media(max-width:575px) { .payment-search { grid-template-columns:minmax(0,1fr) auto; } .payment-search .btn:last-child { grid-column:1/-1; } }
</style>
