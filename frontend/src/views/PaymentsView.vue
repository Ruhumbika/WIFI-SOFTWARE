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
      <div class="mobile-ledger__head"><span>Package / phone</span><span>Amount</span><span>Status</span></div>
      <div v-for="payment in rows" :key="payment.id" class="mobile-ledger__row">
        <div><strong>{{ payment.order?.plan?.name || 'Package unavailable' }}</strong><br><small>{{ payment.order?.customer_phone || 'Phone unavailable' }}</small><br><small>{{ formatDate(payment.created_at) }}</small><br><small v-if="payment.reference" class="text-break">{{ payment.reference }}</small></div>
        <strong>TZS {{ Number(payment.amount).toLocaleString() }}</strong>
        <span class="badge" :class="payment.status === 'completed' ? 'text-bg-success' : payment.status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary'">{{ payment.status === 'completed' ? 'Paid' : payment.status === 'failed' ? 'Failed' : 'Pending' }}</span>
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
