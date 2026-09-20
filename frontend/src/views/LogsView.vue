<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'

interface LogEntry { time: string; level: string; message: string }
const entries = ref<LogEntry[]>([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { entries.value = (await api.get<{ entries: LogEntry[] }>('/admin/logs')).data.entries }
  catch { error.value = 'Application events could not be loaded.' }
  finally { loading.value = false }
}

onMounted(load)
</script>

<template>
  <AdminShell>
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-3">
      <div><h1 class="h2 mb-1">Errors</h1><p class="text-secondary mb-0">Recent application errors. Detailed logs remain on the server.</p></div>
      <button class="btn btn-outline-primary" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
    </div>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="btn btn-sm btn-outline-danger ms-2" @click="load">Retry</button></div>
    <p v-if="loading" role="status">Loading events…</p>
    <p v-else-if="!error && !entries.length" role="status">No recent errors found.</p>
    <div v-else-if="!error" class="mobile-ledger d-md-none" aria-label="Error events">
      <div class="mobile-ledger__head"><span>Event</span><span>Time</span><span>Level</span></div>
      <div v-for="(entry, index) in [...entries].reverse()" :key="`${entry.time}-${index}`" class="mobile-ledger__row">
        <span class="text-break">{{ entry.message }}</span><small>{{ entry.time }}</small><strong>{{ entry.level }}</strong>
      </div>
    </div>
    <div v-if="!loading && !error && entries.length" class="card p-3 table-responsive d-none d-md-block">
      <table class="table align-middle mb-0"><thead><tr><th scope="col">Time (UTC)</th><th scope="col">Level</th><th scope="col">Event</th></tr></thead>
        <tbody><tr v-for="(entry, index) in [...entries].reverse()" :key="`${entry.time}-${index}`"><td class="text-nowrap">{{ entry.time }}</td><td>{{ entry.level }}</td><td>{{ entry.message }}</td></tr></tbody>
      </table>
    </div>
  </AdminShell>
</template>
