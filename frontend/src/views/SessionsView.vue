<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import AdminShell from "../components/AdminShell.vue";
import { api } from "../api";

const rows = ref<any[]>([]);
const loading = ref(true);
const busyId = ref<number | null>(null);
const error = ref("");
const actionError = ref("");
const notice = ref("");
let refreshTimer: number | undefined;

async function load(background = false) {
  if (!background) loading.value = true;
  error.value = "";
  try { rows.value = (await api.get("/admin/sessions")).data.data; }
  catch { error.value = "Sessions could not be loaded."; }
  finally { loading.value = false; }
}

async function disconnect(session: any) {
  if (busyId.value !== null || !confirm(`Block ${session.voucher?.code || 'this voucher'} and disconnect all its sessions? This voucher cannot be used again.`)) return;
  busyId.value = session.id;
  actionError.value = "";
  notice.value = "";
  try {
    const response = await api.post(`/admin/sessions/${session.id}/disconnect`);
    notice.value = response.data.message;
    await load();
  }
  catch (e: any) { actionError.value = e.response?.data?.message || "The router could not confirm the disconnect."; }
  finally { busyId.value = null; }
}

onMounted(() => {
  void load();
  refreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') void load(true);
  }, 30000);
});
onUnmounted(() => { if (refreshTimer) clearInterval(refreshTimer); });
</script>

<template>
  <AdminShell>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 class="h2 mb-0">HotSpot sessions</h1><small class="text-secondary">Session uptime restarts after each login and reflects the last router sync. Package time runs from first login.</small></div><button class="btn btn-outline-primary" :disabled="loading" @click="load()">Refresh</button></div>
    <div v-if="actionError" class="alert alert-warning" role="alert">{{ actionError }}</div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading" role="status">Loading sessions…</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="btn btn-sm btn-outline-danger" @click="load()">Retry</button></div>
    <p v-if="!loading && !error && !rows.length" role="status">No sessions found.</p>
    <div v-if="!loading && !error && rows.length" class="mobile-ledger d-md-none" aria-label="HotSpot sessions">
      <div class="mobile-ledger__head"><span>Voucher / device</span><span>Uptime</span><span>Status</span></div>
      <div v-for="session in rows" :key="session.id" class="mobile-ledger__row">
        <div><strong>{{ session.voucher?.code }}</strong><br><small>{{ session.ip_address }}</small><br><small class="text-break">{{ session.mac_address }}</small><br><small>Synced: {{ session.last_seen_at }}</small></div>
        <span>{{ session.uptime }}</span>
        <div><span class="badge" :class="session.ended_at ? 'text-bg-secondary' : 'text-bg-success'">{{ session.ended_at ? 'Ended' : 'Active' }}</span><button v-if="!session.ended_at" class="btn btn-sm btn-outline-danger mt-2" :disabled="busyId === session.id" @click="disconnect(session)">Block & disconnect</button></div>
      </div>
    </div>
    <div v-if="!loading && !error && rows.length" class="card p-3 table-responsive d-none d-md-block">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Voucher</th>
            <th>MAC</th>
            <th>IP</th>
            <th>Session uptime</th>
            <th>Last synced</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="session in rows" :key="session.id">
            <td>{{ session.voucher?.code }}</td>
            <td>{{ session.mac_address }}</td>
            <td>{{ session.ip_address }}</td>
            <td>{{ session.uptime }}</td>
            <td>{{ session.last_seen_at }}</td>
            <td><span class="badge" :class="session.ended_at ? 'text-bg-secondary' : 'text-bg-success'">{{ session.ended_at ? 'Ended' : 'Active' }}</span></td>
            <td>
              <button
                v-if="!session.ended_at"
                class="btn btn-sm btn-outline-danger"
                :disabled="busyId === session.id"
                @click="disconnect(session)"
              >
                Block voucher & disconnect
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminShell>
</template>
