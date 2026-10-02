<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '../../api'
import { paymentMoney } from '../../utils/paymentReporting'
const period = ref('today'), currency = ref('TZS')
const analytics = ref<any>(null), busy = ref(false), error = ref('')
const updatedAt = ref<string | null>(null), loadedPeriod = ref("")
let requestId = 0
async function load() {
  const id = ++requestId; busy.value=true; error.value=''
  try { const response=await api.get('/admin/dashboard/analytics',{params:{period:period.value}}); if (id === requestId) { analytics.value=response.data; updatedAt.value=new Date().toISOString(); loadedPeriod.value=period.value } }
  catch { if (id === requestId) error.value='Analytics could not be loaded.' }
  finally { if (id === requestId) busy.value=false }
}
const currencies = computed(() => [...new Set<string>((analytics.value?.totals || []).map((row: any) => row.currency))])
const total = computed(() => analytics.value?.totals?.find((row: any) => row.currency === currency.value))
const packages = computed(() => (analytics.value?.packages || []).filter((row: any) => row.currency === currency.value))
const statusRows = computed(() => [
  { key:'completed', label:'Paid', color:'#198754' },
  { key:'pending', label:'Pending', color:'#f59e0b' },
  { key:'failed', label:'Failed', color:'#dc3545' },
  { key:'expired', label:'Expired', color:'#6c757d' },
  { key:'voided', label:'Voided', color:'#196b97' },
].map(row => ({ ...row, count:Number(analytics.value?.statuses?.[row.key] || 0) })))
const statusMax = computed(() => Math.max(1,...statusRows.value.map(row => row.count)))
const points = computed(() => {
  if (!analytics.value) return []
  const rows = analytics.value.trend.filter((row: any) => row.currency === currency.value)
  const buckets: any[] = []
  for (let date = new Date(`${analytics.value.from}T00:00:00Z`); date.toISOString().slice(0,10) <= analytics.value.to; date = new Date(date.getTime()+86400000)) {
    for (let hour = 0; hour < (period.value === 'today' ? 24 : 1); hour++) {
      const bucket = date.toISOString().slice(0,10) + (period.value === 'today' ? ` ${String(hour).padStart(2,'0')}:00` : '')
      const row = rows.find((item: any) => item.bucket === bucket)
      buckets.push(row || { bucket, sales:0,gross:0,net:0,settled_sales:0 })
    }
  }
  return buckets
})
function scale(field: string) { return Math.max(1,...points.value.map(row=>Number(row[field] || 0))) }
const charts = [
  { field:'sales', label:'Completed sales', color:'#196b97' },
  { field:'gross', label:'Gross sales', color:'#198754' },
  { field:'net', label:'Confirmed net', color:'#64748b' },
]
function y(row: any, field: string) { return 145 - Number(row[field]) * 120 / scale(field) }
function barX(index: number) { return 45 + index * 490 / Math.max(1,points.value.length) }
function barWidth() { return Math.max(2,490 / Math.max(1,points.value.length)-4) }
function chartValue(row: any, field: string) {
  if (field === 'net' && row.sales > 0 && !row.settled_sales) return null
  return row[field]
}
function bucketLabel(bucket: string | undefined) {
  if (!bucket) return ''
  return period.value === 'today' ? bucket.slice(11) : bucket.slice(5)
}
watch(currencies, values => { if (values.length && !values.includes(currency.value)) currency.value=values[0]! })
watch(period, () => { analytics.value=null; updatedAt.value=null; void load() })
let refreshTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  refreshTimer=setInterval(() => { if (!busy.value && document.visibilityState === 'visible') void load() },60000)
})
onUnmounted(() => { if (refreshTimer) clearInterval(refreshTimer) })
</script>
<template>
  <section class="card dashboard-analytics" aria-label="Sales accounting">
    <header class="analytics-header">
      <h2>Sales accounting</h2>
      <div class="analytics-controls">
        <label><span class="visually-hidden">Period</span><select v-model="period" class="form-select form-select-sm"><option value="today">Today</option><option value="7d">7 days</option><option value="30d">30 days</option></select></label>
        <label v-if="currencies.length>1"><span class="visually-hidden">Currency</span><select v-model="currency" class="form-select form-select-sm"><option v-for="value in currencies" :key="value">{{ value }}</option></select></label>
        <router-link to="/admin/payments" class="btn btn-sm btn-outline-primary">Payments</router-link>
      </div>
    </header>
    <p v-if="busy" role="status" class="analytics-empty">Loading sales…</p>
    <div v-if="error" role="alert" class="alert alert-warning">{{ error }} <span v-if="updatedAt">Showing previous results.</span> <button class="btn btn-sm btn-outline-danger" @click="load">Retry</button></div>
    <template v-if="analytics && !busy">
      <div class="accounting-totals">
        <div><span>Completed sales</span><strong>{{ total?.sales ?? 0 }}</strong></div>
        <div><span>Gross sales</span><strong>{{ paymentMoney(total?.gross ?? 0,currency) }}</strong></div>
        <div><span>Confirmed fees <small v-if="total?.missing_settlement">(partial)</small></span><strong>{{ paymentMoney(total?.fees,currency) }}</strong></div>
        <div><span>Confirmed net <small v-if="total?.missing_settlement">(partial)</small></span><strong>{{ paymentMoney(total?.net,currency) }}</strong></div>
      </div>
      <p v-if="total?.missing_settlement" class="settlement-coverage"><i class="bi bi-info-circle" aria-hidden="true"></i> Settlement received for {{ total.settled_sales }} / {{ total.sales }} sales. Fees and net exclude unconfirmed settlements.</p>
      <p v-if="!statusRows.some(row => row.count)" class="analytics-empty">No payments in this period.</p>
      <div v-else class="analytics-grid">
        <section v-for="chart in charts" :key="chart.field" class="chart-panel">
          <h3>{{ chart.label }} <small v-if="chart.field !== 'sales'">{{ currency }}</small></h3>
          <p v-if="chart.field==='net' && !total?.settled_sales" class="chart-empty">Awaiting settlement details</p>
          <svg v-else class="trend" viewBox="0 0 570 185" role="img" :aria-label="chart.label + ' by time'">
            <line x1="45" y1="85" x2="535" y2="85" stroke="#e2e8f0" stroke-dasharray="4 4"/>
            <line x1="45" y1="25" x2="535" y2="25" stroke="#e2e8f0" stroke-dasharray="4 4"/>
            <line x1="45" y1="145" x2="535" y2="145" stroke="#94a3b8"/>
            <text x="2" y="30">{{ scale(chart.field).toLocaleString() }}</text><text x="25" y="148">0</text>
            <template v-for="(row,index) in points" :key="row.bucket"><rect v-if="chartValue(row,chart.field) !== null" :x="barX(index)" :y="y(row,chart.field)" :width="barWidth()" :height="145-y(row,chart.field)" rx="2" :fill="chart.color"><title>{{ row.bucket }}: {{ chartValue(row,chart.field) }}{{ chart.field !== 'sales' ? ' '+currency : '' }}{{ chart.field==='net' && row.settled_sales < row.sales ? ' (partial)' : '' }}</title></rect></template>
            <text x="45" y="175">{{ bucketLabel(points[0]?.bucket) }}</text><text x="535" y="175" text-anchor="end">{{ bucketLabel(points[points.length-1]?.bucket) }}</text>
          </svg>
        </section>
        <section class="chart-panel"><h3>Payment status</h3><div class="horizontal-bars"><div v-for="status in statusRows" :key="status.key" class="bar-row"><span>{{ status.label }}</span><div class="bar-track"><i :style="{width:(status.count/statusMax*100)+'%',background:status.color}"></i></div><strong>{{ status.count }}</strong></div></div></section>
        <section class="chart-panel package-chart"><h3>Sales by package</h3><p v-if="!packages.length" class="chart-empty">No completed package sales.</p><div v-else class="horizontal-bars"><div v-for="plan in packages" :key="plan.plan_id" class="package-row"><div class="package-label"><span>{{ plan.name }}</span><strong>{{ plan.sales }} <small>sales · {{ paymentMoney(plan.gross,currency) }}</small></strong></div><div class="bar-track"><i :style="{width:(Number(plan.sales)/Math.max(1,...packages.map((p:any)=>Number(p.sales)))*100)+'%',background:'#196b97'}"></i></div></div></div></section>
      </div>
      <footer v-if="updatedAt">{{ analytics.from }} – {{ analytics.to }} · Tanzania time · Updated {{ new Date(updatedAt).toLocaleTimeString('en-GB', { timeZone:'Africa/Dar_es_Salaam',hour:'2-digit',minute:'2-digit' }) }}</footer>
    </template>
  </section>
</template>
<style scoped>
.dashboard-analytics { padding:16px; margin:10px 0; background:#fff; border:1px solid #d5e2eb; border-radius:10px; box-shadow:none; }
.analytics-header,.analytics-controls { display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
h2 { margin:0; font-size:17px; color:#196b97; } h3 { margin:0 0 10px; font-size:13px; font-weight:600; }
h3 small { float:right; color:#64748b; font-size:11px; }
.accounting-totals { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin:16px 0 10px; }
.accounting-totals > div { background:#f1f5f9; border-radius:8px; padding:12px; }
.accounting-totals span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.accounting-totals strong { font-size:18px; overflow-wrap:anywhere; }
.settlement-coverage,footer { color:#64748b; font-size:11px; margin:8px 0; }
.analytics-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:14px; }
.chart-panel { min-width:0; border:1px solid #e2e8f0; border-radius:8px; padding:12px; }
.trend { display:block; width:100%; height:auto; } .trend text { font:11px sans-serif; fill:#64748b; }
.horizontal-bars { display:grid; gap:12px; padding:10px 0; }
.bar-row { display:grid; grid-template-columns:58px minmax(0,1fr) 28px; align-items:center; gap:10px; font-size:12px; }
.bar-row strong { text-align:right; } .bar-track { height:12px; background:#eaf0f5; border-radius:3px; overflow:hidden; }
.bar-track i { display:block; height:100%; border-radius:3px; }
.package-chart { grid-column:1 / -1; } .package-label { display:flex; justify-content:space-between; gap:8px; font-size:12px; margin-bottom:6px; }
.package-label small { font-weight:400; color:#64748b; }
.chart-empty,.analytics-empty { display:flex; align-items:center; justify-content:center; min-height:130px; margin:0; color:#64748b; font-size:13px; }
footer { margin:12px 0 0; }
@media(max-width:600px) { .dashboard-analytics { padding:12px; } .accounting-totals { grid-template-columns:repeat(2,minmax(0,1fr)); } .accounting-totals strong { font-size:16px; } .analytics-grid { grid-template-columns:1fr; } .package-label { flex-wrap:wrap; } }
</style>
