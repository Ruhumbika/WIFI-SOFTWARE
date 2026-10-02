<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { api } from '../api'

const emit = defineEmits<{ saved: [] }>()
const props = defineProps<{ connected: boolean; routerName: string }>()
const form = ref({
  base_url: '',
  username: '',
  password: '',
  verify_tls: false,
  hotspot_server: '',
  address_pool: '',
})
const passwordSet = ref(false)
const loading = ref(true)
const testing = ref(false)
const saving = ref(false)
const preparing = ref(false)
const restartRequired = ref(false)
const tested = ref(false)
const availableServers = ref<string[]>([])
const availablePools = ref<string[]>([])
const editingConnection = ref(false)
const error = ref('')
const notice = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const activePackages = ref(0)
const identityName = ref(props.routerName)
const renaming = ref(false)
const portalUrl = ref(window.location.origin)
const downloadingLogin = ref(false)
type UplinkStatus = {
  interfaces: { name: string; kind: 'cable' | 'wifi'; available: boolean; eligible: boolean; existing_dhcp: boolean; route_ready: boolean }[]
  recommendation: { interface: string; kind: 'cable' | 'wifi'; reason: string; action: 'ready' | 'complete' | 'review' | 'setup' } | null
  clients: { interface: string; status?: string; address?: string; gateway?: string }[]
  addresses: { interface: string; address?: string }[]
  default_route: boolean
  dns: string[]
  nat_interfaces: string[]
  internet_reachable: boolean | null
}
const uplink = ref<UplinkStatus | null>(null)
const uplinkLoading = ref(false)
const uplinkSaving = ref(false)
const uplinkError = ref('')
const uplinkNotice = ref('')
const uplinkInterface = ref('')
const uplinkSsid = ref('')
const uplinkPassword = ref('')
const selectedUplink = computed(() => uplink.value?.interfaces.find(item => item.name === uplinkInterface.value))
const selectedUplinkReady = computed(() => Boolean(selectedUplink.value?.existing_dhcp && selectedUplink.value.route_ready && uplink.value?.nat_interfaces.includes(selectedUplink.value.name)))
const selectedUplinkNeedsReview = computed(() => Boolean(selectedUplink.value?.existing_dhcp && !selectedUplink.value.route_ready))
const portalError = ref('')
const routerAddress = ref('')
const connectionProtocol = ref<'http' | 'https'>('http')
const connectionPort = ref('')
const portalReachable = computed(() => {
  try { return !['localhost', '127.0.0.1', '0.0.0.0'].includes(new URL(portalUrl.value).hostname) }
  catch { return false }
})
watch(() => props.routerName, name => { identityName.value = name })
const routerPort = computed(() => connectionPort.value || (connectionProtocol.value === 'https' ? '443' : '80'))
const poolIsVerified = computed(() => !form.value.address_pool || availablePools.value.includes(form.value.address_pool))
const hotspotIsVerified = computed(() => availableServers.value.includes(form.value.hotspot_server))

function readConnectionUrl(value: string) {
  try {
    const url = new URL(value)
    routerAddress.value = url.hostname
    connectionProtocol.value = url.protocol === 'https:' ? 'https' : 'http'
    connectionPort.value = url.port || (connectionProtocol.value === 'https' ? '443' : '80')
  } catch { routerAddress.value = '' }
}

function setConnectionUrl(): boolean {
  const address = routerAddress.value.trim()
  const port = Number(connectionPort.value)
  if (!/^(?:\d{1,3}\.){3}\d{1,3}$/.test(address) || !Number.isInteger(port) || port < 1 || port > 65535) {
    fieldErrors.value = { base_url: ['Enter the private router address and a valid service port.'] }
    return false
  }
  form.value.base_url = `${connectionProtocol.value}://${address}:${port}/rest`
  if (connectionProtocol.value === 'http') form.value.verify_tls = false
  return true
}

async function cancelConnectionEdit() {
  editingConnection.value = false
  tested.value = false
  availableServers.value = []
  availablePools.value = []
  fieldErrors.value = {}
  error.value = ''
  await load()
}

async function beginConnectionEdit() {
  editingConnection.value = true
  await testConnection()
}

watch(() => [routerAddress.value, connectionProtocol.value, connectionPort.value, form.value.username, form.value.password, form.value.verify_tls], () => {
  tested.value = false
  fieldErrors.value = {}
}, { deep: true })

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/router/setup')
    form.value = {
      base_url: data.base_url || '',
      username: data.username || '',
      password: '',
      verify_tls: Boolean(data.verify_tls),
      hotspot_server: data.hotspot_server || '',
      address_pool: data.address_pool || '',
    }
    readConnectionUrl(form.value.base_url)
    passwordSet.value = Boolean(data.password_set)
    activePackages.value = Number(data.active_packages || 0)
  } catch { error.value = 'Router setup details could not be loaded.' }
  finally { loading.value = false }
}

async function loadUplink() {
  if (!props.connected || uplinkLoading.value) return
  uplinkLoading.value = true
  uplinkError.value = ''
  try {
    uplink.value = (await api.get<UplinkStatus>('/admin/router/uplink')).data
    if ((!uplinkInterface.value || !uplink.value.interfaces.some(item => item.name === uplinkInterface.value && item.eligible)) && uplink.value.recommendation) {
      uplinkInterface.value = uplink.value.recommendation.interface
    }
  }
  catch (e: any) { uplinkError.value = e.response?.data?.message || 'Internet connection status is unavailable.' }
  finally { uplinkLoading.value = false }
}

async function configureUplink() {
  const selected = selectedUplink.value
  if (!selected?.eligible || selectedUplinkReady.value || selectedUplinkNeedsReview.value || uplinkSaving.value) return
  if (!confirm(`${selected.existing_dhcp ? 'Complete' : 'Set'} internet input on ${selected.name}? Keep your management connection available while this runs.`)) return
  uplinkSaving.value = true
  uplinkError.value = ''
  uplinkNotice.value = ''
  try {
    const { data } = await api.post('/admin/router/uplink', {
      interface: selected.name, kind: selected.kind,
      ...(selected.kind === 'wifi' ? { ssid: uplinkSsid.value.trim(), password: uplinkPassword.value } : {}),
    })
    uplink.value = data.uplink
    uplinkNotice.value = data.message
    uplinkPassword.value = ''
  } catch (e: any) {
    const message = Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Internet setup failed.'
    await loadUplink()
    uplinkError.value = message
  } finally { uplinkSaving.value = false }
}

async function testConnection(): Promise<boolean> {
  if (!setConnectionUrl()) return false
  testing.value = true
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
  tested.value = false
  availableServers.value = []
  availablePools.value = []
  try {
    const { data } = await api.post('/admin/router/setup/test', form.value)
    availableServers.value = data.servers || []
    availablePools.value = data.address_pools || []
    if (!availableServers.value.includes(form.value.hotspot_server) && availableServers.value.length === 1) {
      form.value.hotspot_server = availableServers.value[0]
      await nextTick()
    }
    if (form.value.address_pool && !availablePools.value.includes(form.value.address_pool)) {
      fieldErrors.value.address_pool = ['The saved pool was not found on this router. Choose a verified pool or keep the router default before saving.']
    }
    tested.value = true
    notice.value = `Found ${data.router_name || 'the router'}.`
    return true
  } catch (e: any) {
    fieldErrors.value = e.response?.data?.errors || {}
    error.value = e.response?.data?.message || 'The router test failed. Check the setup steps above.'
    return false
  } finally { testing.value = false }
}

async function connectRouter() {
  if (testing.value || saving.value || !await testConnection()) return
  if (availableServers.value.length === 1) {
    form.value.hotspot_server = availableServers.value[0]
    await save()
  } else if (availableServers.value.length > 1) {
    form.value.hotspot_server = ''
    notice.value = 'Choose the customer Wi-Fi network below, then finish connecting.'
  } else {
    error.value = 'No customer HotSpot was found. Ask the installer to prepare this router.'
  }
}

async function save() {
  if (!tested.value || saving.value) return
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const response = await api.post('/admin/router/setup', form.value)
    const { data } = response
    notice.value = data.message
    restartRequired.value = response.status === 202
    form.value.password = ''
    passwordSet.value = true
    tested.value = false
    if (!restartRequired.value) editingConnection.value = false
    if (!restartRequired.value) emit('saved')
  } catch (e: any) {
    fieldErrors.value = e.response?.data?.errors || {}
    error.value = e.response?.data?.message || 'Router settings could not be saved.'
  } finally { saving.value = false }
}

async function prepareProfiles() {
  if (preparing.value || !confirm(`Create or update profiles for ${activePackages.value} active package(s) on this router?`)) return
  preparing.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await api.post('/admin/router/profiles/prepare')
    notice.value = data.message
    emit('saved')
  } catch (e: any) { error.value = e.response?.data?.message || 'Package profiles could not be prepared.' }
  finally { preparing.value = false }
}

async function renameRouter() {
  const name = identityName.value.trim()
  if (!props.connected || !name || name === props.routerName || renaming.value) return
  if (!confirm(`Change the router name from "${props.routerName || 'Unnamed'}" to "${name}"?`)) return
  renaming.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await api.post('/admin/router/identity', { name })
    notice.value = data.message
    emit('saved')
  } catch (e: any) { error.value = e.response?.data?.message || 'Router name could not be changed.' }
  finally { renaming.value = false }
}

async function downloadHotspotLogin() {
  if (downloadingLogin.value) return
  portalError.value = ''
  downloadingLogin.value = true
  try {
    const response = await api.get('/admin/router/hotspot-login', {
      params: { portal_url: portalUrl.value.trim() }, responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'login.html'
    link.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch { portalError.value = 'Could not prepare the login page. Enter a portal URL that customer phones can open.' }
  finally { downloadingLogin.value = false }
}

onMounted(() => { void load(); if (props.connected) void loadUplink() })
watch(() => props.connected, connected => { if (connected) void loadUplink() })
</script>

<template>
  <section class="card p-3 mt-3 router-setup" aria-label="Router setup">
    <h2 class="h5">Set up customer Wi-Fi</h2>
    <p class="text-secondary small">Connect the router, prepare packages, and test customer access.</p>
    <div class="setup-progress" aria-label="Setup progress">
      <span class="badge" :class="connected ? 'text-bg-success' : 'text-bg-secondary'">1. Router {{ connected ? 'connected' : 'pending' }}</span>
      <span class="badge" :class="activePackages ? 'text-bg-primary' : 'text-bg-secondary'">2. {{ activePackages ? `${activePackages} package(s) created` : 'Add packages' }}</span>
      <span class="badge text-bg-secondary">3. Test on a phone</span>
    </div>
    <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <div v-if="loading" role="status">Loading setup…</div>
    <template v-else>
      <div v-if="connected" class="alert alert-success" role="status">{{ routerName || 'Router' }} is connected to this dashboard.</div>
      <section v-if="connected" class="border rounded p-3 mb-3" aria-label="Internet input setup">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h3 class="h6 mb-0">Internet input</h3><button type="button" class="btn btn-sm btn-outline-primary" :disabled="uplinkLoading" @click="loadUplink">{{ uplinkLoading ? 'Checking…' : 'Check status' }}</button></div>
        <p class="small text-secondary">Connect an internet cable from your provider, or use a separate Wi-Fi radio to join the provider's network.</p>
        <div v-if="uplinkError" class="alert alert-warning" role="alert">{{ uplinkError }}</div>
        <div v-if="uplinkNotice" class="alert alert-success" role="status">{{ uplinkNotice }}</div>
        <div v-if="uplink?.recommendation" class="alert alert-info" role="status">
          <strong>{{ uplink.recommendation.action === 'setup' ? 'Possible internet input' : 'Detected internet input' }}: {{ uplink.recommendation.interface }}</strong>
          <span class="d-block">{{ uplink.recommendation.reason }} {{ uplink.recommendation.action === 'ready' ? 'Default route and NAT are ready; no setup is needed.' : uplink.recommendation.action === 'complete' ? 'Confirm below to add missing NAT without replacing the DHCP connection.' : uplink.recommendation.action === 'setup' ? 'Confirm the cable before setting up DHCP.' : 'The default route is missing; review router settings before making changes.' }}</span>
        </div>
        <div v-if="uplink" class="row g-2 small mb-3">
          <div class="col-6 col-md-3">Router → 1.1.1.1: <strong>{{ uplink.internet_reachable === true ? 'Responding' : uplink.internet_reachable === false ? 'No response' : 'Not checked' }}</strong></div>
          <div class="col-6 col-md-3">Default route: <strong>{{ uplink.default_route ? 'Ready' : 'Missing' }}</strong></div>
          <div class="col-12 col-md-6">DNS: <strong>{{ uplink.dns?.join(', ') || 'Not assigned' }}</strong></div>
          <div v-for="client in uplink.clients" :key="client.interface" class="col-12 col-md-6">{{ client.interface }}: <strong>{{ client.status || 'Waiting' }}</strong><span v-if="client.address"> · {{ client.address }}</span><span v-if="client.gateway"> · gateway {{ client.gateway }}</span></div>
          <div v-for="item in uplink.addresses" :key="`address-${item.interface}`" class="col-12 col-md-6">{{ item.interface }} IP: <strong>{{ item.address }}</strong></div>
          <div v-for="name in uplink.nat_interfaces" :key="`nat-${name}`" class="col-12 col-md-6">{{ name }}: <strong>NAT ready</strong></div>
        </div>
        <form v-if="uplink" @submit.prevent="configureUplink">
          <label for="uplink-interface" class="form-label">Where internet comes in</label>
          <select id="uplink-interface" v-model="uplinkInterface" class="form-select" required>
            <option value="">Choose an unused port or Wi-Fi radio</option>
            <option v-for="item in uplink.interfaces" :key="item.name" :value="item.name" :disabled="!item.eligible">{{ item.name }} · {{ item.kind === 'wifi' ? 'Wi-Fi' : 'Cable' }}{{ item.existing_dhcp ? ' · DHCP detected' : item.available ? '' : ' · in use' }}</option>
          </select>
          <p v-if="!uplink.interfaces.some(item => item.kind === 'wifi' && item.available)" class="small text-secondary mt-2 mb-0">Wi-Fi input needs a separate radio that is not serving customer Wi-Fi. Use an available cable port if this router has no spare radio.</p>
          <div v-if="selectedUplink?.kind === 'wifi' && !selectedUplink.existing_dhcp" class="row g-2 mt-1">
            <div class="col-12 col-md-6"><label for="uplink-ssid" class="form-label">Provider Wi-Fi name</label><input id="uplink-ssid" v-model.trim="uplinkSsid" class="form-control" maxlength="32" required /></div>
            <div class="col-12 col-md-6"><label for="uplink-password" class="form-label">Provider Wi-Fi password</label><input id="uplink-password" v-model="uplinkPassword" class="form-control" type="password" minlength="8" maxlength="63" autocomplete="new-password" required /></div>
          </div>
          <p class="small text-secondary mt-2 mb-2">The router will request an IP, gateway and DNS automatically. Customer Wi-Fi and the dashboard connection must use a different interface.</p>
          <button type="submit" class="btn btn-outline-primary" :disabled="!selectedUplink?.eligible || selectedUplinkReady || selectedUplinkNeedsReview || uplinkSaving">{{ uplinkSaving ? 'Configuring…' : selectedUplinkReady ? 'Internet input ready' : selectedUplinkNeedsReview ? 'Review default route' : selectedUplink?.existing_dhcp ? 'Complete internet input' : 'Set internet input' }}</button>
        </form>
      </section>
      <form v-else class="border rounded p-3 mb-3" @submit.prevent="connectRouter">
        <h3 class="h6">1. Connect the router</h3>
        <p class="text-secondary small">Plug the router into power and connect its Internet cable. Connect this computer to the router's local network.</p>
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label" for="router-address">Router address</label><input id="router-address" v-model.trim="routerAddress" class="form-control" required inputmode="decimal" autocomplete="off" placeholder="192.168.88.1" /><small class="text-secondary">Use the address supplied with your router.</small><small v-if="fieldErrors.base_url" class="d-block text-danger">{{ fieldErrors.base_url[0] }}</small></div>
          <div class="col-12 col-md-6"><label class="form-label" for="router-password">Setup password</label><input id="router-password" v-model="form.password" class="form-control" type="password" :required="!passwordSet" autocomplete="new-password" :placeholder="passwordSet ? 'Leave blank to keep saved password' : 'Password supplied with your router'" /><small v-if="fieldErrors.password" class="d-block text-danger">{{ fieldErrors.password[0] }}</small></div>
        </div>
        <button class="btn btn-primary mt-3" type="submit" :disabled="testing || saving">{{ testing ? 'Checking router…' : saving ? 'Saving connection…' : 'Connect router' }}</button>
      </form>
      <div v-if="tested && availableServers.length > 1 && !connected" class="border rounded p-3 mb-3">
        <label class="form-label" for="router-hotspot">Customer Wi-Fi network</label>
        <select id="router-hotspot" v-model="form.hotspot_server" class="form-select"><option value="">Choose the network customers use</option><option v-for="server in availableServers" :key="server" :value="server">{{ server }}</option></select>
        <small v-if="fieldErrors.hotspot_server" class="d-block text-danger">{{ fieldErrors.hotspot_server[0] }}</small>
        <button class="btn btn-primary mt-3" type="button" :disabled="saving || !availableServers.includes(form.hotspot_server)" @click="save">{{ saving ? 'Saving…' : 'Finish connection' }}</button>
      </div>
    </template>

    <details class="border rounded p-3 mb-3">
      <summary class="fw-semibold">Installer settings</summary>
      <div v-if="connected && !editingConnection" class="mt-3">
        <p class="text-secondary small">Current router connection. Change these only when the router's management access has changed.</p>
        <dl class="row mb-3">
          <dt class="col-sm-4">Router connection</dt><dd class="col-sm-8 text-break">{{ form.base_url || 'Unavailable' }}</dd>
          <dt class="col-sm-4">API username</dt><dd class="col-sm-8">{{ form.username || 'Unavailable' }}</dd>
          <dt class="col-sm-4">Customer HotSpot</dt><dd class="col-sm-8">{{ form.hotspot_server || 'Not selected' }}</dd>
          <dt class="col-sm-4">Address pool</dt><dd class="col-sm-8">{{ form.address_pool || 'Router default' }}</dd>
          <dt class="col-sm-4">HTTPS certificate check</dt><dd class="col-sm-8">{{ connectionProtocol === 'https' ? form.verify_tls ? 'On' : 'Off' : 'Not applicable' }}</dd>
        </dl>
        <button type="button" class="btn btn-outline-primary" @click="beginConnectionEdit">Change connection settings</button>
      </div>
      <template v-else>
      <p class="text-secondary small mt-3">Prepare the router once before giving it to the customer. The internet provider may use DHCP, PPPoE or a static address; use the details supplied by that provider.</p>
      <ol class="small">
        <li>Connect the provider cable to the WAN port and confirm the router has internet.</li>
        <li>Set the customer Wi-Fi name and password, and enable HotSpot.</li>
        <li>In RouterOS IP → Services, enable REST access on the selected port for the management network. Create a dedicated API user with read, write and rest-api permissions.</li>
      </ol>
      <div class="row g-3">
        <div v-if="connected" class="col-12 col-md-6"><label class="form-label" for="router-address-advanced">Router address</label><input id="router-address-advanced" v-model.trim="routerAddress" class="form-control" inputmode="decimal" autocomplete="off" /><small v-if="fieldErrors.base_url" class="d-block text-danger">{{ fieldErrors.base_url[0] }}</small></div>
        <div v-if="connected" class="col-12 col-md-6"><label class="form-label" for="router-password-advanced">New setup password</label><input id="router-password-advanced" v-model="form.password" class="form-control" type="password" autocomplete="new-password" placeholder="Leave blank to keep saved password" /></div>
        <div class="col-6 col-md-3"><label class="form-label" for="router-protocol">Connection type</label><select id="router-protocol" v-model="connectionProtocol" class="form-select"><option value="http">HTTP on local network</option><option value="https">HTTPS</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label" for="router-port">Service port</label><input id="router-port" v-model="connectionPort" class="form-control" type="number" min="1" max="65535" required /><small class="text-secondary">RouterOS service port, currently {{ routerPort }}.</small><small v-if="fieldErrors.base_url" class="d-block text-danger">{{ fieldErrors.base_url[0] }}</small></div>
        <div class="col-12 col-md-6"><label class="form-label" for="router-user">API username</label><input id="router-user" v-model.trim="form.username" class="form-control" autocomplete="off" /><small v-if="fieldErrors.username" class="d-block text-danger">{{ fieldErrors.username[0] }}</small></div>
        <div v-if="tested && availableServers.length > 1" class="col-12 col-md-6"><label class="form-label" for="router-hotspot-advanced">Customer HotSpot</label><select id="router-hotspot-advanced" v-model="form.hotspot_server" class="form-select"><option value="">Choose a HotSpot</option><option v-for="server in availableServers" :key="server" :value="server">{{ server }}</option></select><small v-if="fieldErrors.hotspot_server" class="d-block text-danger">{{ fieldErrors.hotspot_server[0] }}</small></div>
        <div class="col-12 col-md-6"><label class="form-label" for="router-pool">Address pool</label><select id="router-pool" v-model="form.address_pool" class="form-select" :disabled="!tested"><option v-if="!tested && form.address_pool" :value="form.address_pool">{{ form.address_pool }} · saved, not yet verified</option><option v-if="tested && form.address_pool && !poolIsVerified" :value="form.address_pool" disabled>{{ form.address_pool }} · unavailable on this router</option><option value="">Keep router default</option><option v-for="pool in availablePools" :key="pool" :value="pool">{{ pool }}</option></select><small v-if="!tested" class="text-secondary">Test settings to load pools from the router.</small><small v-if="fieldErrors.address_pool && tested && !poolIsVerified" class="d-block text-danger">{{ fieldErrors.address_pool[0] }}</small></div>
        <div v-if="connectionProtocol === 'https'" class="col-12"><div class="form-check"><input id="router-tls" v-model="form.verify_tls" class="form-check-input" type="checkbox" /><label class="form-check-label" for="router-tls">Verify the router's HTTPS certificate</label></div></div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3"><button type="button" class="btn btn-outline-primary" :disabled="testing || saving" @click="testConnection">{{ testing ? 'Testing…' : 'Test settings' }}</button><button type="button" class="btn btn-primary" :disabled="!tested || testing || saving || !hotspotIsVerified || !poolIsVerified" @click="save">{{ saving ? 'Saving…' : 'Save verified settings' }}</button><button v-if="connected" type="button" class="btn btn-outline-secondary" :disabled="testing || saving" @click="cancelConnectionEdit">Cancel</button></div>
      <p v-if="tested && availableServers.length > 1 && !availableServers.includes(form.hotspot_server)" class="text-warning mt-2 mb-0">Choose the customer Wi-Fi network above before saving.</p>
      </template>
    </details>

    <div class="border-top mt-4 pt-3">
      <h3 class="h6">2. Prepare packages</h3>
      <p class="text-secondary">{{ activePackages }} active package(s). Send their access settings to the connected router before selling.</p>
      <button class="btn btn-outline-secondary" :disabled="preparing || loading || !connected || restartRequired || activePackages === 0" @click="prepareProfiles">{{ preparing ? 'Preparing…' : 'Prepare packages on router' }}</button>
      <p v-if="activePackages === 0" class="text-secondary small mt-2 mb-0">Create an active package first.</p>
      <div class="d-flex flex-wrap gap-2 mt-3"><router-link class="btn btn-outline-primary" to="/admin/plans">Create plan</router-link><router-link class="btn btn-outline-primary" to="/admin/vouchers">Generate tickets</router-link></div>
    </div>
    <div id="customer-phone-test" class="border-top mt-4 pt-3" tabindex="-1">
      <h3 class="h6">3. Test on a phone</h3>
      <p class="text-secondary mb-1">Connect a phone to the customer Wi-Fi and open the sales page.</p>
      <p v-if="portalReachable" class="mb-2"><strong>Customer page:</strong> <a :href="portalUrl" target="_blank" rel="noopener noreferrer">{{ portalUrl }}</a></p>
      <p v-else class="text-warning mb-2">This dashboard is open through localhost. Ask the installer for the customer page address reachable from the phone.</p>
      <p class="text-secondary small mb-0">If the phone cannot open the page before login, ask the installer to check the HotSpot login page and allowed portal address.</p>
    </div>
    <details class="border-top mt-4 pt-3">
      <summary class="fw-semibold">Installer: set up the customer login page</summary>
      <p class="text-secondary">Use the URL a phone on the customer Wi-Fi can open. A localhost or 127.0.0.1 URL works only on your computer.</p>
      <div class="d-flex flex-wrap gap-2">
        <label class="visually-hidden" for="portal-url">Customer portal URL</label>
        <input id="portal-url" v-model.trim="portalUrl" class="form-control flex-grow-1" type="url" placeholder="https://wifi.example.com" />
        <button type="button" class="btn btn-outline-primary" :disabled="downloadingLogin || !portalUrl" @click="downloadHotspotLogin">{{ downloadingLogin ? 'Preparing…' : 'Download login.html' }}</button>
      </div>
      <p v-if="portalError" class="text-danger mt-2" role="alert">{{ portalError }}</p>
      <ol class="text-secondary small mt-3 mb-0">
        <li>Allow this portal host and port in the HotSpot walled garden so phones can open it before login.</li>
        <li>In WinBox Files, place the downloaded login.html in the HTML directory of the active HotSpot server profile.</li>
        <li>Connect a phone to customer Wi-Fi and open a website. The HotSpot page should offer package purchase or voucher login.</li>
      </ol>
    </details>
    <details class="border-top mt-4 pt-3">
      <summary class="fw-semibold">Installer: name this router</summary>
      <div class="d-flex flex-wrap gap-2">
        <label class="visually-hidden" for="router-identity">Router name</label>
        <input id="router-identity" v-model="identityName" class="form-control flex-grow-1" maxlength="64" :disabled="!connected" placeholder="Router name" />
        <button class="btn btn-outline-secondary" :disabled="!connected || restartRequired || renaming || !identityName.trim() || identityName.trim() === routerName" @click="renameRouter">{{ renaming ? 'Saving…' : 'Save router name' }}</button>
      </div>
    </details>
  </section>
</template>

<style scoped>
.router-setup { border:1px solid #d5e2eb; border-radius:10px; box-shadow:none; }
.router-setup h2 { color:#196b97; font-size:16px; }
.router-setup h3 { font-size:14px; }
.setup-progress { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin:8px 0 16px; }
.setup-progress .badge { white-space:normal; padding:12px 8px; text-align:left; font-size:12px; border-radius:6px; }
.router-setup .border { border-color:#d5e2eb !important; background:#fafcfe; border-radius:8px !important; }
.router-setup .form-label { font-size:12px; font-weight:600; color:#475569; }
.router-setup .form-control,.router-setup .form-select,.router-setup .btn { font-size:13px; min-height:38px; }
.router-setup summary { cursor:pointer; font-size:13px; color:#196b97; }
.router-setup details[open] > summary { margin-bottom:14px; }
.router-setup .alert { font-size:13px; }
@media(max-width:575px) { .setup-progress { gap:5px; } .setup-progress .badge { font-size:11px; padding:10px 6px; } }
</style>
