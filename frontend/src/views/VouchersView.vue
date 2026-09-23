<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import AdminShell from "../components/AdminShell.vue";
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
  if (!planId.value) return;
  busy.value = true;
  error.value = "";
  try {
    const response = await api.post("/admin/vouchers/generate", {
      plan_id: planId.value,
      quantity: qty.value,
    });
    generatedTickets.value = response.data.map((ticket: any) => ({ ...ticket, plan: plans.value.find(plan => plan.id === ticket.plan_id) }));
    await load();
  } catch (e: any) {
    error.value = e.response?.data?.message || e.message;
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
    <div class="voucher-admin-header mb-3">
      <div>
        <h2 class="mb-1">Vouchers</h2>
        <div class="text-secondary">Create and print Wi-Fi vouchers for customers paying outside the portal.</div>
      </div>

      <div class="voucher-generator">
        <select
          v-model="planId"
          class="form-select"
          aria-label="Voucher package"
        >
          <option v-for="plan in plans" :key="plan.id" :value="plan.id">
            {{ plan.name }}
          </option>
        </select>
        <input
          v-model.number="qty"
          type="number"
          min="1"
          max="100"
          class="form-control"
          aria-label="Voucher quantity"
        />
        <button class="btn btn-primary" :disabled="busy" @click="generate">
          <span
            v-if="busy"
            class="spinner-border spinner-border-sm me-1"
            aria-hidden="true"
          ></span>
          Create manual vouchers
        </button>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

    <form class="card p-3 mb-3" @submit.prevent="applyFilters">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label" for="voucher-search">Search</label><input id="voucher-search" v-model="search" class="form-control" placeholder="Code, phone or payment reference" /></div>
        <div class="col-6 col-md-2"><label class="form-label" for="voucher-status">Status</label><select id="voucher-status" v-model="status" class="form-select"><option value="">All</option><option value="ready">Ready</option><option value="active">Active</option><option value="expired">Expired</option><option value="pending">Needs provisioning</option><option value="failed">Failed provisioning</option><option value="disabled">Disabled</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label" for="voucher-plan">Package</label><select id="voucher-plan" v-model="filterPlan" class="form-select"><option value="">All</option><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label" for="voucher-date">Date</label><input id="voucher-date" v-model="date" type="date" class="form-control" /></div>
        <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" type="button" @click="clearFilters">Clear</button></div>
      </div>
    </form>
    <p v-if="loading" role="status">Loading vouchers…</p>
    <p v-else class="text-secondary">{{ total }} vouchers</p>
    <div v-if="rows.length || generatedTickets.length" class="card p-3 mb-3 d-flex flex-row flex-wrap align-items-center gap-2">
      <button type="button" class="btn btn-outline-secondary" :disabled="!printableRows.length" @click="selectPage">{{ selectedIds.length === printableRows.length && printableRows.length ? 'Clear selection' : 'Select ready tickets' }}</button>
      <label class="visually-hidden" for="voucher-print-template">Ticket template</label>
      <select id="voucher-print-template" v-model="printTemplate" class="form-select w-auto" aria-label="Ticket template"><option value="cards">A4 · 30 tickets</option><option value="thermal">58 mm receipt</option></select>
      <button type="button" class="btn btn-primary" :disabled="!ticketsToPrint.length" @click="printTickets">Print {{ ticketsToPrint.length }} {{ selectedIds.length ? 'selected' : 'new' }} tickets</button>
      <small class="text-secondary">Use your phone or computer's print menu.</small>
      <small v-if="generatedTickets.some(ticket => !['ready', 'active'].includes(ticket.status))" class="text-warning">Tickets awaiting router provisioning cannot be printed yet.</small>
    </div>
    <details v-if="previewTicket" class="card p-3 mb-3" aria-label="Customize Wi-Fi voucher printing">
      <summary class="fw-semibold">Customize ticket and preview</summary>
      <p class="small text-secondary mt-2">A4 preview shows the tickets selected for printing, up to 30 per page. Code, PIN and package stay as issued. Extra text may reduce how many fit on A4.</p>
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
    <div v-if="!loading && !rows.length" class="alert alert-info" role="status">No vouchers match these filters.</div>

    <div v-if="rows.length" class="mobile-ledger voucher-ledger d-lg-none" aria-label="Vouchers">
      <div class="voucher-ledger__head"><span>Voucher / package</span><span>Status</span></div>
      <div v-for="voucher in rows" :key="voucher.id" class="voucher-ledger__row">
        <div class="voucher-ledger__main"><strong>{{ voucher.code }}</strong><small>{{ voucher.plan?.name || 'Package unavailable' }}</small><small v-if="displayStatus(voucher) === 'active'">Left: {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Time unavailable' }}</small></div>
        <span class="badge" :class="displayStatus(voucher) === 'active' ? 'text-bg-success' : displayStatus(voucher) === 'ready' ? 'text-bg-primary' : 'text-bg-secondary'">{{ voucher.status === 'provision_pending' ? 'Pending setup' : displayStatus(voucher) }}</span>
        <div class="voucher-ledger__actions"><label v-if="canPrint(voucher)"><input type="checkbox" :checked="selectedIds.includes(voucher.id)" @change="toggleSelection(voucher.id)" /> Select to print</label><router-link :to="`/admin/vouchers/${voucher.id}`" class="btn btn-sm btn-outline-primary">Details</router-link><button v-if="voucher.status === 'provision_pending'" class="btn btn-sm btn-outline-secondary" @click="retry(voucher)">Retry setup</button></div>
      </div>
    </div>

    <div class="card p-3 table-responsive d-none d-lg-block">
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
            <td><span>{{ displayStatus(voucher) }}</span><small v-if="displayStatus(voucher) === 'active'" class="d-block text-secondary" style="font-variant-numeric: tabular-nums">Package left: {{ voucherTimeLeft(voucher.expires_at, nowTick) || 'Time unavailable' }}</small></td>
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
    <nav v-if="lastPage > 1" class="d-flex justify-content-between align-items-center mt-3" aria-label="Voucher pages"><button class="btn btn-outline-secondary" :disabled="loading || page === 1" @click="changePage(page - 1)">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="btn btn-outline-secondary" :disabled="loading || page === lastPage" @click="changePage(page + 1)">Next</button></nav>
    <Teleport to="body"><section v-if="ticketsToPrint.length" class="voucher-print-sheet" :class="`voucher-print-sheet--${printTemplate}`" aria-label="Tickets to print">
      <PrintableVoucherTicket v-for="ticket in ticketsToPrint" :key="ticket.id" :ticket="ticket" :title="printTitle" :wifi-name="printWifiName" :instructions="printInstructions" :show-price="printShowPrice" :show-details="printShowDetails" :show-instructions="printShowInstructions" :show-activation="printShowActivation" :compact="printTemplate === 'cards'" :thermal="printTemplate === 'thermal'" />
    </section></Teleport>
  </AdminShell>
</template>
