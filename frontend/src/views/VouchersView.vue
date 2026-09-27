<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import AdminShell from "../components/AdminShell.vue";
import SignalEye from "../components/portal/SignalEye.vue";
import { formatPhoneInput } from "../utils/formatPhoneInput";
import { api } from "../api";
import { useRoute } from "vue-router";
import { formatDate } from "../utils/formatDate";
import { voucherTimeLeft } from "../utils/voucherTime";
import PrintableVoucherTicket from "../components/vouchers/PrintableVoucherTicket.vue";

const route = useRoute();

const rows = ref<any[]>([]);
const plans = ref<any[]>([]);
const planId = ref<number | null>(null);
const qty = ref(1);
const showGenerator = ref(false);
const showFilters = ref(false);
const filterCount = computed(() => [status.value, filterPlan.value, date.value].filter(Boolean).length);
function onRegisteredPhoneInput(event: Event) {
  const input = event.target as HTMLInputElement;
  registeredPhone.value = formatPhoneInput(input.value);
  input.value = registeredPhone.value;
}
function statusLabel(voucher: any) {
  const value = displayStatus(voucher);
  return ({ ready: 'Ready', active: 'Active', expired: 'Expired', disabled: 'Disabled', revoked: 'Revoked', provision_pending: 'Preparing' } as Record<string,string>)[value] || 'Unavailable';
}
const registeredPhone = ref('');
const recoveryPins = ref<{ code: string; pin: string }[]>([]);
const busy = ref(false);
const error = ref("");
const loading = ref(false);
const search = ref("");
const status = ref(typeof route.query.status === "string" ? route.query.status : "");
const filterPlan = ref("");
const date = ref("");
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const nowTick = ref(Date.now());
let clockTimer: number | undefined;
const selectedIds = ref<number[]>([]);
const generatedTickets = ref<any[]>([]);
const printTemplate = ref<'cards' | 'thermal'>('cards');
const printTitle = ref('RJAY WiFi');
const printWifiName = ref('');
const printInstructions = ref('Connect to the Wi-Fi and enter this code on the login page.');
const printShowPrice = ref(true);
const printShowDetails = ref(false);
const printShowInstructions = ref(false);
const printShowActivation = ref(false);
function displayStatus(voucher: any): string {
  const expiry = Date.parse(voucher.expires_at || '');
  return voucher.status === 'active' && Number.isFinite(expiry) && expiry <= nowTick.value ? 'expired' : voucher.status;
}
function canPrint(voucher: any): boolean { return ['ready', 'active'].includes(displayStatus(voucher)); }
const printableRows = computed(() => rows.value.filter(canPrint));
const ticketsToPrint = computed(() => selectedIds.value.length ? printableRows.value.filter(row => selectedIds.value.includes(row.id)) : generatedTickets.value.filter(canPrint));
const previewTicket = computed(() => ticketsToPrint.value[0] ?? printableRows.value[0] ?? null);
const previewPages = computed(() => {
  const tickets = ticketsToPrint.value.length ? ticketsToPrint.value : previewTicket.value ? [previewTicket.value] : [];
  return Array.from({ length: Math.ceil(tickets.length / 30) }, (_, page) => tickets.slice(page * 30, (page + 1) * 30));
});

function toggleSelection(id: number) {
  selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter(value => value !== id) : [...selectedIds.value, id];
}

function selectPage() {
  selectedIds.value = selectedIds.value.length === printableRows.value.length ? [] : printableRows.value.map(row => row.id);
}

function finishPrint() { document.body.classList.remove('voucher-printing'); }

async function printTickets() {
  if (!ticketsToPrint.value.length) return;
  await nextTick();
  document.body.classList.add('voucher-printing');
  window.print();
}

onMounted(() => { window.addEventListener('afterprint', finishPrint); clockTimer = window.setInterval(() => {
  nowTick.value = Date.now();
  if (status.value === 'active' && !loading.value && rows.value.some(row => displayStatus(row) === 'expired')) load();
}, 1000); });
onUnmounted(() => { window.removeEventListener('afterprint', finishPrint); if (clockTimer) clearInterval(clockTimer); finishPrint(); });

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [voucherResponse, planResponse] = await Promise.all([
      api.get("/admin/vouchers", { params: { search: search.value || undefined, status: status.value || undefined, plan_id: filterPlan.value || undefined, date: date.value || undefined, page: page.value } }),
      plans.value.length ? Promise.resolve({ data: plans.value }) : api.get("/admin/plans"),
    ]);
    rows.value = voucherResponse.data.data;
    selectedIds.value = [];
    lastPage.value = voucherResponse.data.last_page;
    total.value = voucherResponse.data.total;
    plans.value = planResponse.data;
    if (!planId.value && plans.value.length) planId.value = plans.value[0].id;
  } catch (e: any) {
    error.value = e.response?.data?.message || "Vouchers could not be loaded.";
  } finally { loading.value = false; }
}

function applyFilters() { page.value = 1; load(); }
function clearFilters() { search.value = ""; status.value = ""; filterPlan.value = ""; date.value = ""; page.value = 1; load(); }
function changePage(next: number) { page.value = next; load(); }

async function generate() {
  if (!planId.value || busy.value || recoveryPins.value.length) return;
  busy.value = true;
  error.value = "";
  try {
    const response = await api.post("/admin/vouchers/generate", {
      plan_id: planId.value,
      quantity: qty.value,
      phone: registeredPhone.value || null,
    });
    recoveryPins.value = response.data.filter((ticket: any) => ticket.recovery_pin).map((ticket: any) => ({code:ticket.code,pin:ticket.recovery_pin}));
    generatedTickets.value = response.data.map((ticket: any) => ({ ...ticket, recovery_pin: undefined, plan: plans.value.find(plan => plan.id === ticket.plan_id) }));
    showGenerator.value = false;
    await load();
  } catch (e: any) {
    error.value = e.response?.status === 422 ? "Check the package, quantity and phone." : "Could not complete this request. Please try again.";
  } finally {
    busy.value = false;
  }
}

async function retry(voucher: any) {
  error.value = "";
  try {
    await api.post(`/admin/vouchers/${voucher.id}/retry`);
    await load();
  } catch (e: any) {
    error.value = e.response?.data?.message || e.message;
  }
}

onMounted(load);
</script>

<template>
  <AdminShell>
    <div class="voucher-page">
    <header class="voucher-page__header">
      <div><h1>Vouchers</h1><span v-if="!loading" class="voucher-total">{{ total }} total</span></div>
      <button class="btn btn-primary" :aria-expanded="showGenerator" aria-controls="voucher-generator" @click="showGenerator=!showGenerator"><i class="bi" :class="showGenerator ? 'bi-x-lg' : 'bi-plus-lg'" aria-hidden="true"></i> {{ showGenerator ? 'Close' : 'Create' }}</button>
    </header>
    <form v-if="showGenerator" id="voucher-generator" class="card voucher-create" @submit.prevent="generate">
      <div class="voucher-create__plan"><label for="generate-plan">Package</label><select id="generate-plan" v-model="planId" class="form-select" required><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option></select></div>
      <div><label for="generate-quantity">Quantity</label><input id="generate-quantity" v-model.number="qty" type="number" min="1" max="100" required class="form-control" /></div>
      <div class="voucher-create__phone"><label for="generate-phone">Phone <span class="text-secondary">(optional)</span></label><input id="generate-phone" :value="registeredPhone" @input="onRegisteredPhoneInput" type="tel" inputmode="numeric" pattern="[0-9 ]*" class="form-control" placeholder="255 7XX XXX XXX" /></div>
      <button class="btn btn-primary voucher-create__submit" :disabled="busy || recoveryPins.length > 0 || !plans.length"><span v-if="busy" class="voucher-eye"><SignalEye status="loading" /></span>{{ busy ? 'Creating…' : 'Create vouchers' }}</button>
    </form>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

    <section v-if="recoveryPins.length" class="alert alert-warning">
      <h2 class="h6">Recovery PINs</h2><p class="small">Give these to the owner. Shown once.</p>
      <p v-for="item in recoveryPins" :key="item.code">{{ item.code }}: <strong>{{ item.pin }}</strong></p>
      <button class="btn btn-outline-dark" @click="recoveryPins=[]">Done</button>
    </section>
    <form class="voucher-filters" @submit.prevent="applyFilters">
      <div class="voucher-search-row">
        <label class="visually-hidden" for="voucher-search">Search by code, phone or payment reference</label>
        <input id="voucher-search" v-model="search" class="form-control" placeholder="Code or phone" />
        <button class="btn btn-outline-primary" :disabled="loading" aria-label="Search"><i class="bi bi-search" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-outline-secondary" aria-label="Filters" :aria-expanded="showFilters" aria-controls="voucher-filters-extra" @click="showFilters=!showFilters"><i class="bi bi-sliders" aria-hidden="true"></i><span class="filter-label"> Filters</span><span v-if="filterCount" class="filter-count">{{ filterCount }}</span></button>
      </div>
      <div v-show="showFilters" id="voucher-filters-extra" class="voucher-filter-grid">
        <div><label for="voucher-status">Status</label><select id="voucher-status" v-model="status" class="form-select"><option value="">All</option><option value="ready">Ready</option><option value="active">Active</option><option value="expired">Expired</option><option value="pending">Preparing</option><option value="failed">Setup failed</option><option value="disabled">Disabled</option></select></div>
        <div><label for="voucher-plan">Package</label><select id="voucher-plan" v-model="filterPlan" class="form-select"><option value="">All</option><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option></select></div>
        <div><label for="voucher-date">Date</label><input id="voucher-date" v-model="date" type="date" class="form-control" /></div>
        <div class="voucher-filter-actions"><button class="btn btn-primary" :disabled="loading">Apply</button><button class="btn btn-outline-secondary" type="button" :disabled="loading" @click="clearFilters">Clear</button></div>
      </div>
    </form>
    <div v-if="loading" class="voucher-loading" role="status"><span class="voucher-eye"><SignalEye status="loading" /></span>Loading…</div>
    <div v-if="rows.length || generatedTickets.length" class="voucher-print-tools">
      <button type="button" class="btn btn-outline-secondary" :disabled="!printableRows.length || loading" @click="selectPage">{{ selectedIds.length === printableRows.length && printableRows.length ? 'Clear' : 'Select all' }}</button>
      <label class="visually-hidden" for="voucher-print-template">Print format</label>
      <select id="voucher-print-template" v-model="printTemplate" class="form-select"><option value="cards">A4 · 30</option><option value="thermal">58 mm</option></select>
      <button type="button" class="btn btn-primary" :disabled="!ticketsToPrint.length || loading" @click="printTickets"><i class="bi bi-printer" aria-hidden="true"></i> Print<span v-if="ticketsToPrint.length"> ({{ ticketsToPrint.length }})</span></button>
      <small v-if="generatedTickets.some(ticket => !['ready', 'active'].includes(ticket.status))" class="text-secondary voucher-print-note">Preparing vouchers cannot be printed yet.</small>
    </div>
    <details v-if="previewTicket" class="card p-3 mb-3" aria-label="Customize Wi-Fi voucher printing">
      <summary class="fw-semibold">Print options</summary>
      <p class="small text-secondary mt-2">A4: up to 30 tickets per page.</p>
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6"><label class="form-label" for="ticket-title">Ticket heading</label><input id="ticket-title" v-model.trim="printTitle" class="form-control" maxlength="40" /></div>
        <div class="col-12 col-md-6"><label class="form-label" for="ticket-wifi">Wi-Fi network name</label><input id="ticket-wifi" v-model.trim="printWifiName" class="form-control" maxlength="50" placeholder="Optional" /></div>
        <div class="col-12"><label class="form-label" for="ticket-instructions">Login instructions</label><input id="ticket-instructions" v-model.trim="printInstructions" class="form-control" maxlength="140" /></div>
        <div class="col-12 d-flex flex-wrap gap-3">
          <label><input v-model="printShowPrice" type="checkbox" class="form-check-input me-2" />Price</label>
          <label><input v-model="printShowDetails" type="checkbox" class="form-check-input me-2" />Duration and data</label>
          <label><input v-model="printShowInstructions" type="checkbox" class="form-check-input me-2" />Instructions</label>
          <label><input v-model="printShowActivation" type="checkbox" class="form-check-input me-2" />Activation note</label>
        </div>
      </div>
      <div v-if="printTemplate === 'cards'" class="voucher-preview-scroll" aria-label="A4 print preview">
        <section v-for="(previewPage, pageIndex) in previewPages" :key="pageIndex" class="voucher-preview-page" :aria-label="`A4 page ${pageIndex + 1}`">
          <PrintableVoucherTicket v-for="ticket in previewPage" :key="ticket.id" :ticket="ticket" :title="printTitle" :wifi-name="printWifiName" :instructions="printInstructions" :show-price="printShowPrice" :show-details="printShowDetails" :show-instructions="printShowInstructions" :show-activation="printShowActivation" compact />
        </section>
      </div>
      <PrintableVoucherTicket v-else :ticket="previewTicket" :title="printTitle" :wifi-name="printWifiName" :instructions="printInstructions" :show-price="printShowPrice" :show-details="printShowDetails" :show-instructions="printShowInstructions" :show-activation="printShowActivation" thermal />
    </details>
    <div v-if="!loading && !rows.length" class="alert alert-info" role="status">No vouchers found.</div>

    <div v-if="rows.length" class="mobile-ledger voucher-ledger d-lg-none" aria-label="Vouchers">

      <div v-for="voucher in rows" :key="voucher.id" class="voucher-ledger__row">
        <div class="voucher-ledger__main"><router-link :to="`/admin/vouchers/${voucher.id}`" class="voucher-code">{{ voucher.code }}</router-link><small>{{ voucher.plan?.name || 'Package unavailable' }}</small><small v-if="displayStatus(voucher) === 'active'">Left: {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Time unavailable' }}</small></div>
        <span class="badge" :class="displayStatus(voucher) === 'active' ? 'text-bg-success' : displayStatus(voucher) === 'ready' ? 'text-bg-primary' : 'text-bg-secondary'">{{ statusLabel(voucher) }}</span>
        <div class="voucher-ledger__actions"><label v-if="canPrint(voucher)"><input type="checkbox" :checked="selectedIds.includes(voucher.id)" @change="toggleSelection(voucher.id)" /> Select</label><router-link :to="`/admin/vouchers/${voucher.id}`" class="btn btn-sm btn-outline-primary">Details</router-link><button v-if="voucher.status === 'provision_pending'" class="btn btn-sm btn-outline-secondary" @click="retry(voucher)">Retry</button></div>
      </div>
    </div>

    <div v-if="rows.length" class="card p-3 table-responsive d-none d-lg-block">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Print</th><th>Code</th>
            <th>PIN</th>
            <th>Package</th>
            <th>Device</th>
            <th>Status</th>
            <th>Activated</th>
            <th>Expires</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="voucher in rows" :key="voucher.id">
            <td><input v-if="canPrint(voucher)" type="checkbox" :checked="selectedIds.includes(voucher.id)" :aria-label="`Select ${voucher.code}`" @change="toggleSelection(voucher.id)" /></td>
            <td>
              <strong>{{ voucher.code }}</strong>
            </td>
            <td>
              <code>{{ voucher.password }}</code>
            </td>
            <td>{{ voucher.plan?.name }}</td>
            <td>{{ voucher.device_mac || "Not bound" }}</td>
            <td><span>{{ statusLabel(voucher) }}</span><small v-if="displayStatus(voucher) === 'active'" class="d-block text-secondary" style="font-variant-numeric: tabular-nums">Left: {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Time unavailable' }}</small></td>
            <td>{{ formatDate(voucher.activated_at, '-') }}</td>
            <td>{{ formatDate(voucher.expires_at, '-') }}</td>
            <td>
              <router-link :to="`/admin/vouchers/${voucher.id}`" class="btn btn-sm btn-outline-primary me-1">Details</router-link>
              <button
                v-if="voucher.status === 'provision_pending'"
                class="btn btn-sm btn-outline-secondary"
                @click="retry(voucher)"
              >
                Retry
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav v-if="lastPage > 1" class="d-flex justify-content-between align-items-center mt-3" aria-label="Voucher pages"><button class="btn btn-outline-secondary" :disabled="loading || page === 1" @click="changePage(page - 1)">Previous</button><span>{{ page }} / {{ lastPage }}</span><button class="btn btn-outline-secondary" :disabled="loading || page === lastPage" @click="changePage(page + 1)">Next</button></nav>
    <Teleport to="body"><section v-if="ticketsToPrint.length" class="voucher-print-sheet" :class="`voucher-print-sheet--${printTemplate}`" aria-label="Tickets to print">
      <PrintableVoucherTicket v-for="ticket in ticketsToPrint" :key="ticket.id" :ticket="ticket" :title="printTitle" :wifi-name="printWifiName" :instructions="printInstructions" :show-price="printShowPrice" :show-details="printShowDetails" :show-instructions="printShowInstructions" :show-activation="printShowActivation" :compact="printTemplate === 'cards'" :thermal="printTemplate === 'thermal'" />
    </section></Teleport>
    </div>
  </AdminShell>
</template>

<style scoped>
.voucher-page { min-width:0; }
.voucher-page__header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; }
.voucher-page__header h1 { margin:0; font-size:clamp(23px,5vw,30px); font-weight:800; }
.voucher-total { font-size:12px; color:#64748b; }
.voucher-page .btn,.voucher-page .form-control,.voucher-page .form-select { min-height:44px; font-size:13px; border-radius:12px; }
.voucher-page .btn-primary { background:linear-gradient(145deg,#f6faff,#dce8f1); border:1px solid #fff; color:#185e87; box-shadow:3px 3px 8px #cbd5df,-3px -3px 8px #fff; font-weight:700; }
.voucher-page .btn-outline-primary { color:#185e87; border-color:#a2bfce; }
.voucher-page .btn:focus-visible,.voucher-code:focus-visible { outline:3px solid #198ab5; outline-offset:2px; }
.voucher-create { display:grid; grid-template-columns:2fr .75fr 2fr auto; align-items:end; gap:12px; padding:16px; margin-bottom:18px; }
.voucher-page label { display:block; margin-bottom:5px; font-size:12px; font-weight:600; }
.voucher-create__submit { display:flex; align-items:center; justify-content:center; gap:5px; }
.voucher-search-row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:8px; }
.voucher-filter-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)) auto; align-items:end; gap:12px; padding:14px 0; }
.voucher-filter-actions { display:flex; gap:8px; }
.filter-count { display:inline-grid; place-items:center; min-width:20px; padding:1px 5px; margin-left:4px; border-radius:8px; background:#d9e9f4; color:#185e87; }
.voucher-filters { margin-bottom:16px; }
.voucher-print-tools { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:0 0 16px; }
.voucher-print-tools .form-select { width:110px; }
.voucher-print-note { flex-basis:100%; font-size:12px; }
.voucher-eye { display:inline-flex; width:44px; height:30px; }
.voucher-loading { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px; color:#185e87; font-size:13px; }
.voucher-page .voucher-ledger { border:0; background:transparent; box-shadow:none; }
.voucher-page .voucher-ledger__row { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:start; gap:12px; padding:16px; margin-bottom:12px; border:1px solid #fff; border-radius:18px; background:#eef3f9; box-shadow:4px 4px 10px #cbd5df,-4px -4px 10px #fff; }
.voucher-code { color:#183d56; font-size:15px; font-weight:800; overflow-wrap:anywhere; text-decoration:none; }
.voucher-page .voucher-ledger__main { min-width:0; display:grid; gap:5px; }
.voucher-page .voucher-ledger__main small { font-size:12px; color:#64748b; }
.voucher-page .badge { padding:6px 8px; border-radius:8px; font-size:10px; line-height:1.2; }
.voucher-page .text-bg-primary { background:#dceaf5 !important; color:#185e87 !important; }
.voucher-page .voucher-ledger__actions { grid-column:1/-1; display:flex; align-items:center; gap:8px; padding-top:10px; border-top:1px solid #dce4ec; flex-wrap:wrap; }
.voucher-page .voucher-ledger__actions label { display:flex; align-items:center; gap:8px; min-height:44px; margin:0 auto 0 0; color:#526571; }
.voucher-page input[type=checkbox] { width:18px; height:18px; accent-color:#196b97; }
.voucher-page details summary { min-height:28px; font-size:13px; }
.voucher-page .table { font-size:13px; }
@media(max-width:991.98px) {
  .voucher-page__header { margin-bottom: 12px; }
  .voucher-filters, .voucher-print-tools { margin-bottom: 12px; }
  .voucher-page .voucher-ledger__row { padding: 12px; gap: 8px; margin-bottom: 10px; border-radius: 16px; }
  .voucher-page .voucher-ledger__main { gap: 3px; }
  .voucher-page .voucher-ledger__actions { padding-top: 6px; }
  .voucher-page .badge { font-size: 11px; }
  .voucher-page .form-control, .voucher-page .form-select { font-size: 16px; }
  .voucher-print-tools .form-select { width: 120px; }
}
@media(max-width:767px) {
  .voucher-create { grid-template-columns:minmax(0,1fr) 86px; }
  .voucher-create__phone,.voucher-create__submit { grid-column:1/-1; }
  .voucher-filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .voucher-filter-grid input,.voucher-filter-grid select { width:100%; min-width:0; }
  .voucher-filter-actions { align-self:end; }
  .voucher-filter-actions .btn { flex:1; padding:8px; }
}
@media(max-width:359px) { .filter-label { display:none; } .voucher-print-tools { gap:6px; } .voucher-print-tools .btn { padding:8px 10px; } .voucher-print-tools .form-select { width:96px; } }
</style>
