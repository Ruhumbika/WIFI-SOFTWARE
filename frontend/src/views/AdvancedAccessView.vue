<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'
import { detectPlatform, type ClientPlatform } from '../utils/platform'

type Service = { enabled: boolean; port: number | null; allowed_addresses: string | null }
type Access = { router: { name: string | null; management_address: string | null }; username: string;
  connected: boolean; services: Record<string, Service>; webfig_url: string | null;
  technical: { routeros_version: string | null; board: string | null; architecture: string | null }; message?: string }
const platform = ref<ClientPlatform>({ family: 'Unknown', mobile: false, architecture: null, browser: 'Unknown' })
const access = ref<Access | null>(null)
const loading = ref(true)
const error = ref('')
const assistant = ref(false)
const warning = ref(false)
const allowed = ref(false)
const notice = ref('')
const downloadUrl = 'https://mikrotik.com/download/winbox'
const recommended = computed(() => platform.value.mobile || platform.value.family === 'Unknown' ? 'WebFig' : 'WinBox')
const winbox = computed(() => access.value?.services?.winbox)
const webfig = computed(() => access.value?.webfig_url)

async function load() {
  loading.value = true; error.value = ''
  try {
    allowed.value = (await api.get<{ allowed: boolean }>('/admin/router/advanced/permission')).data.allowed
    if (!allowed.value) { error.value = 'Advanced Tools access is not available for this account.'; return }
    const response = await api.get<Access>('/admin/router/advanced/access', { headers: { 'X-Client-Platform': platform.value.family } })
    access.value = response.data
    if (!sessionStorage.getItem('rjay_advanced_warning_seen')) warning.value = true
  } catch (e: any) {
    if (e.response?.status === 403) error.value = 'Advanced Tools access is not available for this account.'
    else { access.value = e.response?.data || null; error.value = 'Router management services are unavailable. Run diagnostics.' }
  } finally { loading.value = false }
}
function continueAccess() { sessionStorage.setItem('rjay_advanced_warning_seen', '1'); warning.value = false }
async function audit(action: string, result: string) {
  try { await api.post('/admin/router/advanced/events', { action, result, client_platform: platform.value.family }) }
  catch { notice.value = 'The action could not be recorded. Please try again.'; return false }
  return true
}
async function openWinbox() { if (await audit('WINBOX_LAUNCH_REQUESTED', 'manual')) assistant.value = true }
async function copy(value: string | null | undefined, action = 'WINBOX_DETAILS_COPIED') {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    notice.value = 'Copied to clipboard.'
    await audit(action, 'copied')
  } catch { notice.value = 'Clipboard is unavailable. Select the displayed value to copy it.' }
}
function openWebfig() { void audit('WEBFIG_OPENED', 'opened') }
function download() { void audit('WINBOX_DOWNLOAD_OPENED', 'opened') }
onMounted(async () => { platform.value = await detectPlatform(); await load() })
</script>

<template>
  <AdminShell>
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div><h1 class="h2">Advanced Tools</h1><p class="text-secondary">Direct MikroTik management for experienced administrators.</p></div><router-link class="btn btn-outline-secondary align-self-start" to="/admin/router">Diagnostics</router-link></div>
    <p v-if="loading" role="status">Loading advanced access…</p>
    <div v-if="error" class="alert alert-warning" role="alert">{{ error }} <button v-if="allowed" class="btn btn-sm btn-outline-dark ms-2" @click="load">Retry</button></div>
    <div v-if="notice" class="alert alert-info" role="status">{{ notice }}</div>
    <template v-if="allowed && access && !warning">
      <div class="row g-3">
        <div class="col-12 col-lg-6"><section class="card p-3 h-100"><h2 class="h5">Advanced router access</h2><dl class="row mb-0">
          <dt class="col-5">Router</dt><dd class="col-7">{{ access.router.name || 'Unavailable' }}</dd>
          <dt class="col-5">REST API</dt><dd class="col-7">{{ access.connected ? 'Connected' : 'Unavailable' }}</dd>
          <dt class="col-5">Management IP</dt><dd class="col-7 text-break">{{ access.router.management_address || 'Unavailable' }}</dd>
          <dt class="col-5">Your device</dt><dd class="col-7">{{ platform.family }} · {{ platform.browser }} · {{ platform.mobile ? 'Mobile' : 'Desktop' }}</dd>
          <dt class="col-5">Recommended</dt><dd class="col-7">{{ recommended }}</dd>
        </dl></section></div>
        <div class="col-12 col-lg-6"><section class="card p-3 h-100"><h2 class="h5">Management services</h2><div v-for="name in ['winbox','www-ssl','www','ssh','api','api-ssl']" :key="name" class="d-flex justify-content-between border-top py-2"><span>{{ name === 'www-ssl' ? 'WebFig HTTPS' : name === 'www' ? 'WebFig HTTP' : name.toUpperCase() }}</span><span>{{ access.services?.[name]?.enabled ? `Enabled · Port ${access.services[name].port ?? 'unknown'}` : 'Disabled / unavailable' }}</span></div></section></div>
        <div class="col-12 col-lg-6"><section class="card p-3 h-100"><h2 class="h5">WinBox for {{ platform.family }}</h2><p v-if="platform.mobile" class="text-secondary">For full WinBox administration, use a Windows, Linux, or macOS computer.</p><template v-else><p>Service: {{ winbox?.enabled ? 'Enabled' : 'Disabled / unavailable' }}<br>Address: {{ access.router.management_address || 'Unavailable' }}<br>Port: {{ winbox?.port ?? 'Unavailable' }}<br>Username: {{ access.username || 'Unavailable' }}</p><p v-if="winbox?.allowed_addresses">Allowed networks: {{ winbox.allowed_addresses }}</p><button class="btn btn-primary me-2 mb-2" :disabled="!winbox?.enabled || !winbox.port || !access.router.management_address" @click="openWinbox">Open WinBox</button></template><a class="btn btn-outline-primary me-2 mb-2" :href="downloadUrl" target="_blank" rel="noopener noreferrer" @click="download">Download from MikroTik</a><button class="btn btn-outline-secondary mb-2" :disabled="!access.router.management_address" @click="copy(access.router.management_address)">Copy router address</button><p class="small text-secondary mb-0">WinBox opens manually on this device. Enter your MikroTik password inside WinBox.</p></section></div>
        <div class="col-12 col-lg-6"><section class="card p-3 h-100"><h2 class="h5">WebFig</h2><p>Browser-based MikroTik administration.</p><p v-if="!webfig" class="text-secondary">WebFig HTTPS is unavailable on this router.</p><a v-if="webfig" class="btn btn-primary me-2" :href="webfig" target="_blank" rel="noopener noreferrer" @click="openWebfig">Open WebFig</a><router-link class="btn btn-outline-secondary" to="/admin/router">Run diagnostics</router-link></section></div>
        <div class="col-12"><section class="card p-3"><h2 class="h5">Technical router information</h2><p class="mb-0">RouterOS: {{ access.technical?.routeros_version || 'Unavailable' }} · Board: {{ access.technical?.board || 'Unavailable' }} · Architecture: {{ access.technical?.architecture || 'Unavailable' }}</p></section></div>
      </div>
      <section v-if="assistant" class="card p-3 mt-3" role="dialog" aria-label="Open MikroTik with WinBox"><h2 class="h5">Open MikroTik with WinBox</h2><p>Router: {{ access.router.management_address }}<br>Port: {{ winbox?.port }}<br>Username: {{ access.username }}</p><ol><li>Open WinBox.</li><li>Enter the router address and port shown above.</li><li>Enter your MikroTik credentials.</li><li>Select Connect.</li></ol><div><button class="btn btn-outline-primary me-2 mb-2" @click="copy(access.router.management_address)">Copy router address</button><button class="btn btn-outline-primary me-2 mb-2" @click="copy(access.username)">Copy username</button><button class="btn btn-outline-secondary mb-2" @click="assistant = false">Close</button></div></section>
    </template>
    <div v-if="warning" class="modal-backdrop-custom" role="dialog" aria-modal="true" aria-label="Advanced Router Management"><div class="card p-4 shadow" style="max-width:480px"><h2 class="h5">Advanced Router Management</h2><p>Changes made through WinBox or WebFig affect the network directly and may disconnect customers or make the router unreachable.</p><div class="d-flex gap-2"><router-link class="btn btn-outline-secondary" to="/admin/router">Cancel</router-link><button class="btn btn-primary" @click="continueAccess">Continue to Advanced Tools</button></div></div></div>
  </AdminShell>
</template>

<style scoped>.modal-backdrop-custom{position:fixed;inset:0;z-index:1050;background:#0009;display:flex;align-items:center;justify-content:center;padding:1rem}</style>
