<script setup lang="ts">
import { onMounted, ref } from "vue";
import AdminShell from "../components/AdminShell.vue";
import { api } from "../api";
import { formatDate } from "../utils/formatDate";

const rows = ref<any[]>([]);
const loading = ref(true);
const error = ref("");

async function load() {
  loading.value = true;
  error.value = "";
  try { rows.value = (await api.get("/admin/payments")).data.data; }
  catch { error.value = "Payments could not be loaded."; }
  finally { loading.value = false; }
}

onMounted(load);
</script>

<template>
  <AdminShell>
    <h2>Payments</h2>
    <p v-if="loading" role="status">Loading payments…</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="btn btn-sm btn-outline-danger" @click="load">Retry</button></div>
    <p v-if="!loading && !error && !rows.length" role="status">No payments found.</p>
    <div v-if="!loading && !error && rows.length" class="mobile-ledger d-md-none" aria-label="Payments">
      <div v-for="payment in rows" :key="payment.id" class="payment-row">
        <div class="payment-row__main">
          <strong>{{ payment.order?.plan?.name || 'Package unavailable' }}</strong>
          <span>{{ payment.order?.customer_phone || 'Phone unavailable' }}</span>
          <small>{{ formatDate(payment.created_at) }}</small>
        </div>
        <div class="payment-row__amount">
          <strong>TZS {{ Number(payment.amount).toLocaleString() }}</strong>
          <span class="badge" :class="payment.status === 'completed' ? 'text-bg-success' : payment.status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary'">{{ payment.status === 'completed' ? 'Paid' : payment.status === 'failed' ? 'Failed' : 'Pending' }}</span>
        </div>
        <details v-if="payment.reference" class="payment-row__reference">
          <summary>Reference</summary>
          <span>{{ payment.reference }}</span>
        </details>
      </div>
    </div>
    <div v-if="!loading && !error && rows.length" class="card p-3 table-responsive d-none d-md-block">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Phone</th>
            <th>Package</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="payment in rows" :key="payment.id">
            <td>{{ payment.reference || "-" }}</td>
            <td>{{ payment.order?.customer_phone }}</td>
            <td>{{ payment.order?.plan?.name }}</td>
            <td>TZS {{ Number(payment.amount).toLocaleString() }}</td>
            <td><span class="badge" :class="payment.status === 'completed' ? 'text-bg-success' : payment.status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary'">{{ payment.status === 'completed' ? 'Paid' : payment.status === 'failed' ? 'Failed' : 'Pending' }}</span></td>
            <td>{{ formatDate(payment.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminShell>
</template>

<style scoped>
.payment-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, .7fr); gap: 4px 12px; padding: 12px; }
.payment-row + .payment-row { border-top: 1px solid #dce4ec; }
.payment-row__main, .payment-row__amount { min-width: 0; display: flex; flex-direction: column; gap: 4px; font-size: 13px; overflow-wrap: anywhere; }
.payment-row__main span, .payment-row__main small { color: #64748b; font-size: 12px; }
.payment-row__amount { align-items: flex-end; text-align: right; }
.payment-row__amount .badge { font-size: 11px; }
.payment-row__reference { grid-column: 1 / -1; font-size: 12px; color: #526571; overflow-wrap: anywhere; }
.payment-row__reference summary { min-height: 44px; padding: 12px 0; cursor: pointer; }
.payment-row__reference summary:focus-visible { outline: 2px solid #198ab5; outline-offset: 2px; }
</style>
