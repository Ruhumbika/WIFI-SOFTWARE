<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'

const props = defineProps<{ connected: boolean }>()
type WifiInterface = { interface: string; driver: 'legacy' | 'wifi'; ssid: string }
type Naming = { profile_prefix: string; voucher_prefix: string }
const loading = ref(true)
const wifiLoading = ref(false)
const namingLoading = ref(false)
const wifiError = ref('')
const namingError = ref('')
const wifiNotice = ref('')
const namingNotice = ref('')
const interfaces = ref<WifiInterface[]>([])
const selected = ref('')
const newSsid = ref('')
const naming = ref<Naming>({ profile_prefix: '', voucher_prefix: '' })
const savedNaming = ref<Naming>({ profile_prefix: '', voucher_prefix: '' })
const namingLoaded = ref(false)
const editingNaming = ref(false)
const forbidden = ref(false)
const selectedInterface = computed(() => interfaces.value.find(item => `${item.driver}:${item.interface}` === selected.value))
const prefixPattern = /^[A-Z][A-Z0-9_-]{1,15}$/
const namingValid = computed(() => prefixPattern.test(naming.value.profile_prefix) && prefixPattern.test(naming.value.voucher_prefix))
const namingChanged = computed(() => naming.value.profile_prefix !== savedNaming.value.profile_prefix || naming.value.voucher_prefix !== savedNaming.value.voucher_prefix)

async function load() {
  loading.value = true
  wifiError.value = ''
  namingError.value = ''
  try {
    const [wifiResult, namingResult] = await Promise.allSettled([
      props.connected ? api.get<{ interfaces: WifiInterface[] }>('/admin/router/customer-wifi') : Promise.resolve({ data: { interfaces: [] as WifiInterface[] } }),
      api.get<Naming>('/admin/router/naming'),
    ])
    if (wifiResult.status === 'fulfilled') {
      interfaces.value = wifiResult.value.data.interfaces
      if (interfaces.value.length === 1) selectWifi(`${interfaces.value[0].driver}:${interfaces.value[0].interface}`)
    } else if (wifiResult.reason?.response?.status === 403) forbidden.value = true
    else wifiError.value = 'Customer Wi-Fi settings could not be loaded.'
    if (namingResult.status === 'fulfilled') {
      naming.value = { ...namingResult.value.data }
      savedNaming.value = { ...namingResult.value.data }
      namingLoaded.value = true
    } else if (namingResult.reason?.response?.status === 403) forbidden.value = true
    else namingError.value = 'Naming prefixes could not be loaded.'
  } finally { loading.value = false }
}

function selectWifi(value: string) {
  selected.value = value
  newSsid.value = interfaces.value.find(item => `${item.driver}:${item.interface}` === value)?.ssid || ''
  wifiNotice.value = ''
  wifiError.value = ''
}

async function saveWifi() {
  const current = selectedInterface.value
  if (!current || !newSsid.value.trim() || newSsid.value === current.ssid || wifiLoading.value) return
  if (new TextEncoder().encode(newSsid.value).length > 32) {
    wifiError.value = 'Wi-Fi name must be at most 32 bytes.'
    return
  }
  if (!confirm(`Change ${current.interface} Wi-Fi name from "${current.ssid}" to "${newSsid.value}"? Connected customers may disconnect.`)) return
  wifiLoading.value = true
  wifiError.value = ''
  wifiNotice.value = ''
  try {
    const { data } = await api.patch('/admin/router/customer-wifi', {
      interface: current.interface, driver: current.driver, ssid: newSsid.value,
    })
    const row = interfaces.value.find(item => item.interface === current.interface && item.driver === current.driver)
    if (row) row.ssid = data.interface.ssid
    wifiNotice.value = data.message
  } catch (e: any) {
    wifiError.value = e.response?.data?.errors?.ssid?.[0] || e.response?.data?.message || 'Wi-Fi name could not be confirmed. Check the router.'
  } finally { wifiLoading.value = false }
}

async function saveNaming() {
  if (!namingValid.value || !namingChanged.value || namingLoading.value) return
  if (!confirm('Use these prefixes for new profiles and vouchers? Existing names and codes will stay the same.')) return
  namingLoading.value = true
  namingError.value = ''
  namingNotice.value = ''
  try {
    const { data } = await api.put('/admin/router/naming', naming.value)
    savedNaming.value = { ...naming.value }
    editingNaming.value = false
    namingNotice.value = data.message
  } catch (e: any) {
    namingError.value = Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Prefixes could not be saved.'
  } finally { namingLoading.value = false }
}

function cancelNaming() { naming.value = { ...savedNaming.value }; editingNaming.value = false; namingError.value = '' }
onMounted(load)
watch(() => props.connected, load)
</script>

<template>
  <section class="card p-3 mt-3" aria-label="Customer Wi-Fi and naming">
    <h2 class="h5">Customer Wi-Fi and naming</h2>
    <p v-if="loading" role="status">Loading router settings…</p>
    <p v-else-if="forbidden" class="text-secondary">These router settings are unavailable for this account.</p>
    <template v-else>
      <div class="border-top pt-3">
        <h3 class="h6">Customer Wi-Fi name</h3>
        <p class="small text-secondary">Only access points confirmed on the customer HotSpot are shown. Changing a name may disconnect connected devices.</p>
        <p v-if="wifiError" class="alert alert-warning" role="alert">{{ wifiError }}</p>
        <p v-if="wifiNotice" class="alert alert-success" role="status">{{ wifiNotice }}</p>
        <p v-if="!connected" class="text-secondary">Connect the router to read and change its customer Wi-Fi name.</p>
        <p v-else-if="!interfaces.length && !wifiError" class="text-secondary">No directly managed customer Wi-Fi interface was confirmed on this router. Review its radio or CAPsMAN settings.</p>
        <div v-else class="row g-2 align-items-end">
          <div class="col-12 col-md-5"><label class="form-label" for="customer-wifi-interface">Customer access point</label><select id="customer-wifi-interface" class="form-select" :value="selected" @change="selectWifi(($event.target as HTMLSelectElement).value)"><option value="">Choose an access point</option><option v-for="item in interfaces" :key="`${item.driver}:${item.interface}`" :value="`${item.driver}:${item.interface}`">{{ item.interface }} · {{ item.ssid }}</option></select></div>
          <div class="col-12 col-md-5"><label class="form-label" for="customer-wifi-ssid">Wi-Fi name</label><input id="customer-wifi-ssid" v-model="newSsid" class="form-control" :disabled="!selectedInterface" maxlength="32" autocomplete="off" /></div>
          <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100" :disabled="!selectedInterface || !newSsid.trim() || newSsid === selectedInterface.ssid || wifiLoading" @click="saveWifi">{{ wifiLoading ? 'Saving…' : 'Save name' }}</button></div>
        </div>
      </div>
      <div class="border-top pt-3 mt-3">
        <h3 class="h6">Names for new profiles and vouchers</h3>
        <p class="small text-secondary">Existing package profiles and voucher codes keep their current names.</p>
        <p v-if="namingError" class="alert alert-warning" role="alert">{{ namingError }}</p>
        <p v-if="namingNotice" class="alert alert-success" role="status">{{ namingNotice }}</p>
        <div v-if="namingLoaded && !editingNaming"><p class="mb-2">Profile prefix: <code>{{ savedNaming.profile_prefix }}</code><br>Voucher prefix: <code>{{ savedNaming.voucher_prefix }}</code></p><button class="btn btn-outline-primary" @click="editingNaming = true">Change prefixes</button></div>
        <div v-else-if="namingLoaded" class="row g-2 align-items-end">
          <div class="col-12 col-md-4"><label class="form-label" for="profile-prefix">Profile prefix</label><input id="profile-prefix" v-model.trim="naming.profile_prefix" class="form-control" maxlength="16" autocomplete="off" /></div>
          <div class="col-12 col-md-4"><label class="form-label" for="voucher-prefix">Voucher prefix</label><input id="voucher-prefix" v-model.trim="naming.voucher_prefix" class="form-control" maxlength="16" autocomplete="off" /></div>
          <div class="col-12 col-md-4"><button class="btn btn-primary me-2" :disabled="!namingValid || !namingChanged || namingLoading" @click="saveNaming">{{ namingLoading ? 'Saving…' : 'Save prefixes' }}</button><button class="btn btn-outline-secondary" :disabled="namingLoading" @click="cancelNaming">Cancel</button></div>
          <p class="small text-secondary mb-0">Use 2–16 uppercase letters, numbers, hyphens or underscores; start with a letter.</p>
        </div>
      </div>
    </template>
  </section>
</template>
