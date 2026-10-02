<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminShell from '../components/AdminShell.vue'
import RouterSetupPanel from '../components/RouterSetupPanel.vue'
import RouterCustomizationPanel from '../components/RouterCustomizationPanel.vue'
import { api } from '../api'
import { formatDate } from '../utils/formatDate'

interface RouterHealth {
  connected: boolean
  hotspot?: boolean
  router_name?: string | null
  configured_hotspot?: string | null
  hotspot_server?: { name?: string; interface?: string } | null
  active_users?: number | null
  last_sync?: string | null
  resource?: Record<string, string | number>
  message?: string
}

const health = ref<RouterHealth | null>(null)
const loading = ref(true)
const syncing = ref(false)
const diagnosticsLoading = ref(false)
const error = ref('')
const notice = ref('')
const diagnostics = ref<Record<string, { message: string }> | null>(null)
function showCustomerSetup() {
  const section = document.getElementById('customer-phone-test')
  section?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  section?.focus({ preventScroll: true })
}

async function testConnection() {
  loading.value = true
  error.value = ''
  try { health.value = (await api.get<RouterHealth>('/admin/router/health')).data }
  catch (e: any) {
    health.value = e.response?.data || { connected: false }
    error.value = health.value?.message || 'The router could not be reached.'
  } finally { loading.value = false }
}

async function runDiagnostics() {
  diagnosticsLoading.value = true
  error.value = ''
  try { diagnostics.value = (await api.get('/admin/router/diagnostics')).data.checks }
  catch { error.value = 'Diagnostics could not be completed.' }
  finally { diagnosticsLoading.value = false }
}

async function sync() {
  if (syncing.value || !health.value?.connected) return
  syncing.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await api.post('/admin/router/sync')
    notice.value = response.data.message
    await testConnection()
  } catch (e: any) { error.value = e.response?.data?.message || 'Router sync could not complete.' }
  finally { syncing.value = false }
}

onMounted(testConnection)
</script>

<template>
  <AdminShell>
    <div class="router-page">
    <div class="router-header d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
      <div><h1 class="h2 mb-1">Router</h1><p class="text-secondary mb-0">{{ loading && !health ? 'Checking router connection…' : health?.connected ? 'HotSpot connection and sessions' : 'Connect your MikroTik to start managing HotSpot access.' }}</p></div>
      <span class="badge fs-6" :class="health?.connected ? 'text-bg-success' : 'text-bg-secondary'">{{ loading ? 'Checking…' : health?.connected ? 'Connected' : 'Not connected' }}</span>
    </div>
    <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !health" role="status">Loading router status…</p>
    <RouterSetupPanel v-else-if="!health?.connected" :connected="false" :router-name="health?.router_name || ''" @saved="testConnection" />
    <div v-if="health?.connected" class="row g-3 mt-1">
      <div class="col-12 col-xl-7">
        <section class="card p-3 h-100" aria-label="Router status">
          <h2 class="h5">Router status</h2>
          <dl class="row mb-0">
            <dt class="col-sm-5">Router name</dt><dd class="col-sm-7">{{ health?.router_name || 'Unavailable' }}</dd>
            <dt class="col-sm-5">RouterOS</dt><dd class="col-sm-7">{{ health?.resource?.version || 'Unavailable' }}</dd>
            <dt class="col-sm-5">Board</dt><dd class="col-sm-7">{{ health?.resource?.['board-name'] || 'Unavailable' }}</dd>
            <dt class="col-sm-5">HotSpot server</dt><dd class="col-sm-7">{{ health?.hotspot_server?.name || health?.configured_hotspot || 'Not configured' }} <span v-if="health?.connected" class="badge ms-1" :class="health?.hotspot ? 'text-bg-success' : 'text-bg-warning'">{{ health?.hotspot ? 'Running' : 'Issue' }}</span></dd>
            <dt class="col-sm-5">Interface</dt><dd class="col-sm-7">{{ health?.hotspot_server?.interface || 'Unavailable' }}</dd>
            <dt class="col-sm-5">Active users</dt><dd class="col-sm-7">{{ health?.active_users ?? 'Unavailable' }}</dd>
            <dt class="col-sm-5">Last successful sync</dt><dd class="col-sm-7">{{ formatDate(health?.last_sync, 'Not recorded') }}</dd>
          </dl>
        </section>
      </div>
      <div class="col-12 col-xl-5">
        <section class="card p-3 h-100" aria-label="Router actions">
          <h2 class="h5">Actions</h2>
          <div class="router-actions">
            <button class="btn btn-primary py-2" :disabled="loading || syncing || !health?.connected" @click="sync">{{ syncing ? 'Syncing…' : 'Sync HotSpot sessions' }}</button>
            <button class="btn btn-outline-primary py-2" :disabled="loading || syncing" @click="testConnection">Test connection</button>
            <router-link class="btn btn-outline-secondary py-2" to="/admin/sessions">View active sessions</router-link>
            <button class="btn btn-outline-secondary py-2" type="button" @click="showCustomerSetup">Set up customer Wi-Fi portal</button>
            <router-link class="btn btn-outline-secondary py-2" to="/admin/logs">View errors</router-link>
          </div>
        </section>
      </div>
    </div>
    <RouterCustomizationPanel v-if="!loading" :connected="Boolean(health?.connected)" />
    <RouterSetupPanel v-if="health?.connected" :connected="true" :router-name="health?.router_name || ''" @saved="testConnection" />
    <details class="card p-3 mt-3" aria-label="Router diagnostics">
      <summary class="fw-semibold">Troubleshooting and diagnostics</summary>
      <button class="btn btn-outline-secondary" :disabled="diagnosticsLoading" @click="runDiagnostics">{{ diagnosticsLoading ? 'Checking…' : 'Run diagnostics' }}</button>
      <div v-if="diagnostics" class="mt-3" role="status">
        <div v-for="(check, name) in diagnostics" :key="name" class="d-flex justify-content-between gap-3 border-top py-2"><span>{{ name }}</span><strong>{{ check.message }}</strong></div>
      </div>
    </details>
    </div>
  </AdminShell>
</template>

<style scoped>
.router-page { max-width:1200px; margin:0 auto; }
.router-header { padding:16px; border:1px solid #d5e2eb; border-radius:10px; background:#fff; }
.router-header h1 { font-size:22px; color:#196b97; }
.router-header p { font-size:13px; }
.router-page > .row .card,.router-page > details { border:1px solid #d5e2eb; border-radius:10px; box-shadow:none; }
.router-page h2 { font-size:16px; color:#196b97; margin-bottom:16px; }
.router-page dl { font-size:13px; row-gap:6px; }
.router-page dt { color:#64748b; font-weight:500; }
.router-page dd { overflow-wrap:anywhere; font-weight:600; }
.router-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
.router-actions .btn { font-size:12px; min-height:40px; display:flex; align-items:center; justify-content:center; }
.router-actions .btn:first-child { grid-column:1 / -1; }
.router-page > details summary { padding:4px 0; color:#196b97; font-size:13px; cursor:pointer; }
.router-page > details[open] summary { margin-bottom:12px; }
@media(max-width:575px) { .router-page dt { margin-bottom:0; } .router-page dd { padding-bottom:8px; border-bottom:1px solid #eaf0f5; } }
</style>
