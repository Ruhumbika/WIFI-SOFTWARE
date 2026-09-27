<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'

interface Plan {
  id: number
  name: string
  code: string
  price: number
  original_price: number | null
  duration_seconds: number
  rate_limit: string
  mikrotik_profile_name: string
  active: boolean
  recommended: boolean
  description?: string | null
  data_limit_bytes?: number | null
  vouchers_exists: boolean
  orders_exists: boolean
}

interface PlanForm {
  name: string
  code: string
  price: number | null
  original_price: number | null
  duration_hours: number | null
  rate_limit: string
  active: boolean
  recommended: boolean
  kind: 'time' | 'data'
  data_limit_mb: number | null
}

function emptyForm(): PlanForm {
  return { name: '', code: '', price: null, original_price: null, duration_hours: null, rate_limit: '', active: true, recommended: false, kind: 'time', data_limit_mb: null }
}

const plans = ref<Plan[]>([])
const form = ref<PlanForm>(emptyForm())
const loading = ref(true)
const saving = ref(false)
const editingId = ref<number | null>(null)
const error = ref('')
const notice = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const activeCount = computed(() => plans.value.filter(plan => plan.active).length)
const editingPlan = computed(() => plans.value.find(plan => plan.id === editingId.value))
const hasHistory = computed(() => Boolean(editingPlan.value?.vouchers_exists || editingPlan.value?.orders_exists))

function startEdit(plan: Plan) {
  editingId.value = plan.id
  form.value = { name: plan.name, code: plan.code, price: plan.price, original_price: plan.original_price ?? null, duration_hours: plan.duration_seconds / 3600, rate_limit: plan.rate_limit, active: plan.active, recommended: plan.recommended, kind: plan.data_limit_bytes ? 'data' : 'time', data_limit_mb: plan.data_limit_bytes ? plan.data_limit_bytes / 1048576 : null }
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
}

function cancelEdit() {
  editingId.value = null
  form.value = emptyForm()
  fieldErrors.value = {}
}

function planPayload(plan: Plan, active: boolean) {
  return { name: plan.name, code: plan.code, description: plan.description, price: plan.price, original_price: plan.original_price ?? null, currency: 'TZS', duration_seconds: plan.duration_seconds, rate_limit: plan.rate_limit, data_limit_bytes: plan.data_limit_bytes, active, recommended: plan.recommended }
}

async function toggleActive(plan: Plan) {
  if (saving.value || !confirm(`${plan.active ? 'Deactivate' : 'Reactivate'} ${plan.name}?`)) return
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.put(`/admin/plans/${plan.id}`, planPayload(plan, !plan.active))
    notice.value = `Package ${plan.active ? 'deactivated' : 'reactivated'}.`
    await load()
    if (editingId.value === plan.id) cancelEdit()
  } catch (e: any) { error.value = e.response?.data?.message || 'Package status could not be changed.' }
  finally { saving.value = false }
}

function durationLabel(seconds: number): string {
  if (!seconds || seconds < 0) return 'Not set'
  if (seconds % 86400 === 0) return `${seconds / 86400} day${seconds === 86400 ? '' : 's'}`
  if (seconds % 3600 === 0) return `${seconds / 3600} hour${seconds === 3600 ? '' : 's'}`
  return `${Math.round(seconds / 60)} minutes`
}

function speedLabel(rate: string): string {
  const match = rate.match(/^([\d.]+)M(?:\/[\d.]+M)?$/i)
  return match ? `${match[1]} Mbps` : rate
}

async function load() {
  loading.value = true
  error.value = ''
  try { plans.value = (await api.get<Plan[]>('/admin/plans')).data }
  catch { error.value = 'Packages could not be loaded.' }
  finally { loading.value = false }
}

async function save() {
  if (saving.value) return
  saving.value = true
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
  try {
    const payload = {
      name: form.value.name.trim(),
      code: hasHistory.value && editingPlan.value ? editingPlan.value.code : form.value.code.trim().toUpperCase(),
      price: form.value.price,
      original_price: form.value.original_price === null || String(form.value.original_price) === "" ? null : Number(form.value.original_price),
      duration_seconds: hasHistory.value && editingPlan.value ? editingPlan.value.duration_seconds : form.value.duration_hours === null ? null : Math.round(form.value.duration_hours * 3600),
      rate_limit: hasHistory.value && editingPlan.value ? editingPlan.value.rate_limit : form.value.rate_limit.trim(),
      active: form.value.active,
      recommended: form.value.recommended,
      currency: 'TZS',
      data_limit_bytes: hasHistory.value && editingPlan.value ? editingPlan.value.data_limit_bytes : form.value.kind === 'data' && form.value.data_limit_mb !== null ? Math.round(form.value.data_limit_mb * 1048576) : null,
      ...(editingPlan.value ? { description: editingPlan.value.description } : {}),
    }
    if (editingId.value !== null) await api.put(`/admin/plans/${editingId.value}`, payload)
    else await api.post('/admin/plans', payload)
    notice.value = editingId.value !== null ? 'Package updated.' : 'Package saved. Its RouterOS profile will be created when a voucher is provisioned or profiles are bootstrapped.'
    cancelEdit()
    await load()
  } catch (e: any) {
    fieldErrors.value = e.response?.data?.errors || {}
    error.value = e.response?.data?.message || 'Package could not be saved.'
  } finally { saving.value = false }
}

onMounted(load)
</script>

<template>
  <AdminShell>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h1 class="h2 mb-1">Packages</h1>
        <p class="text-secondary mb-0">Manage customer internet packages. RouterOS profiles are created during voucher provisioning or router bootstrap.</p>
      </div>
      <router-link class="btn btn-outline-primary" to="/admin/router"><i class="bi bi-router me-1"></i>Router status</router-link>
    </div>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <div class="row g-3">
      <div class="col-12 col-xl-7">
        <section class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Current packages</h2><span class="badge text-bg-secondary">{{ activeCount }} active</span></div>
          <p v-if="loading" role="status">Loading packages…</p>
          <p v-else-if="!plans.length" class="text-secondary mb-0">No packages yet.</p>
          <div v-else class="mobile-ledger d-md-none" aria-label="Current packages">
            <div class="mobile-ledger__head"><span>Package</span><span>Price</span><span>Actions</span></div>
            <div v-for="plan in plans" :key="plan.id" class="mobile-ledger__row">
              <div><strong>{{ plan.name }}</strong><br><small>{{ plan.data_limit_bytes ? `${(plan.data_limit_bytes / 1048576).toLocaleString()} MB · ` : '' }}{{ durationLabel(plan.duration_seconds) }} · {{ speedLabel(plan.rate_limit) }}</small><br><small>Profile: {{ plan.mikrotik_profile_name }}</small><br><span v-if="plan.recommended" class="badge text-bg-warning">Recommended</span> <span class="badge" :class="plan.active ? 'text-bg-success' : 'text-bg-secondary'">{{ plan.active ? 'Active' : 'Inactive' }}</span></div>
              <strong class="price">TZS {{ Number(plan.price).toLocaleString() }}</strong>
              <div><button class="btn btn-sm btn-outline-primary mb-1" :disabled="saving" @click="startEdit(plan)">Edit</button><button class="btn btn-sm" :class="plan.active ? 'btn-outline-danger' : 'btn-outline-success'" :disabled="saving" @click="toggleActive(plan)">{{ plan.active ? 'Deactivate' : 'Reactivate' }}</button></div>
            </div>
          </div>
          <div v-if="plans.length" class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
              <thead><tr><th scope="col">Package</th><th scope="col">Price</th><th scope="col">Duration</th><th scope="col">Speed</th><th scope="col">Profile</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
              <tbody><tr v-for="plan in plans" :key="plan.id">
                <td class="fw-semibold">{{ plan.name }} <span v-if="plan.recommended" class="badge text-bg-warning">Recommended</span></td>
                <td class="text-nowrap price">TZS {{ Number(plan.price).toLocaleString() }}</td>
                <td class="text-nowrap">{{ durationLabel(plan.duration_seconds) }}<small v-if="plan.data_limit_bytes" class="d-block text-secondary">{{ (plan.data_limit_bytes / 1048576).toLocaleString() }} MB</small></td>
                <td class="text-nowrap">{{ speedLabel(plan.rate_limit) }}</td>
                <td><code>{{ plan.mikrotik_profile_name }}</code></td>
                <td><span class="badge" :class="plan.active ? 'text-bg-success' : 'text-bg-secondary'">{{ plan.active ? 'Active' : 'Inactive' }}</span></td>
                <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary me-1" :disabled="saving" @click="startEdit(plan)">Edit</button><button class="btn btn-sm" :class="plan.active ? 'btn-outline-danger' : 'btn-outline-success'" :disabled="saving" @click="toggleActive(plan)">{{ plan.active ? 'Deactivate' : 'Reactivate' }}</button></td>
              </tr></tbody>
            </table>
          </div>
        </section>
      </div>
      <div class="col-12 col-xl-5">
        <section class="card p-3">
          <div class="d-flex justify-content-between align-items-center"><h2 class="h5">{{ editingId === null ? 'New package' : 'Edit package' }}</h2><button v-if="editingId !== null" class="btn btn-sm btn-outline-secondary" type="button" @click="cancelEdit">Cancel</button></div>
          <p class="text-secondary small">{{ hasHistory ? 'This package has orders or vouchers. Its code, duration and speed are locked.' : 'Set the package customers will see when buying internet access.' }}</p>
          <form @submit.prevent="save">
            <fieldset class="mb-3" :disabled="hasHistory"><legend class="form-label mb-2">Plan type</legend><div class="d-flex gap-3"><label class="form-check-label"><input v-model="form.kind" class="form-check-input me-1" type="radio" value="time" /> Time</label><label class="form-check-label"><input v-model="form.kind" class="form-check-input me-1" type="radio" value="data" /> Mobile data</label></div></fieldset>
            <div class="mb-3"><label class="form-label" for="plan-name">Package name</label><input id="plan-name" v-model="form.name" class="form-control" maxlength="100" required placeholder="e.g. 24 Hours" /><small v-if="fieldErrors.name" class="text-danger">{{ fieldErrors.name[0] }}</small></div>
            <div class="row g-3 mb-3">
              <div class="col-sm-6"><label class="form-label" for="plan-code">Package code</label><input id="plan-code" v-model="form.code" class="form-control" maxlength="30" pattern="[A-Za-z0-9_-]+" :readonly="hasHistory" required placeholder="e.g. DAY" /><small v-if="fieldErrors.code" class="text-danger">{{ fieldErrors.code[0] }}</small></div>
              <div class="col-sm-6"><label class="form-label" for="plan-price">Price (TZS)</label><input id="plan-price" v-model.number="form.price" type="number" min="500" step="1" class="form-control" required placeholder="e.g. 2000" /><small v-if="fieldErrors.price" class="text-danger">{{ fieldErrors.price[0] }}</small></div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="plan-original-price">Bei ya awali (TZS) · hiari</label>
              <input id="plan-original-price" v-model.number="form.original_price" type="number" :min="Number(form.price || 0) + 1" step="1" class="form-control" aria-describedby="original-price-help" />
              <small id="original-price-help" class="text-secondary">Iwe juu ya bei ya sasa. Acha wazi kuondoa punguzo.</small>
              <small v-if="fieldErrors.original_price" class="text-danger d-block">{{ fieldErrors.original_price[0] }}</small>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-sm-6"><label class="form-label" for="plan-hours">{{ form.kind === 'data' ? 'Validity (hours)' : 'Duration (hours)' }}</label><input id="plan-hours" v-model.number="form.duration_hours" type="number" min="0.0167" step="any" class="form-control" :readonly="hasHistory" required placeholder="e.g. 24" /><small v-if="fieldErrors.duration_seconds" class="text-danger">{{ fieldErrors.duration_seconds[0] }}</small></div>
              <div class="col-sm-6"><label class="form-label" for="plan-speed">Router rate limit</label><input id="plan-speed" v-model="form.rate_limit" class="form-control" maxlength="30" :readonly="hasHistory" required placeholder="e.g. 4M/4M" /><small v-if="fieldErrors.rate_limit" class="text-danger">{{ fieldErrors.rate_limit[0] }}</small></div>
            </div>
            <div v-if="form.kind === 'data'" class="mb-3"><label class="form-label" for="plan-data">Data allowance (MB)</label><input id="plan-data" v-model.number="form.data_limit_mb" type="number" min="1" max="1048576" step="1" class="form-control" :readonly="hasHistory" required placeholder="e.g. 1024" /><small class="text-secondary d-block">Access ends at the data limit or validity time, whichever comes first.</small><small v-if="fieldErrors.data_limit_bytes" class="text-danger">{{ fieldErrors.data_limit_bytes[0] }}</small></div>
            <div class="form-check mb-3"><input id="plan-active" v-model="form.active" class="form-check-input" type="checkbox" /><label class="form-check-label" for="plan-active">Available to customers</label></div>
            <div class="form-check mb-3"><input id="plan-recommended" v-model="form.recommended" class="form-check-input" type="checkbox" /><label class="form-check-label" for="plan-recommended">Show as recommended</label></div>
            <button type="submit" class="btn btn-primary w-100 py-2" :disabled="saving">{{ saving ? 'Saving…' : editingId === null ? 'Create package' : 'Save changes' }}</button>
          </form>
        </section>
      </div>
    </div>
  </AdminShell>
</template>
