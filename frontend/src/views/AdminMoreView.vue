<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'
const router = useRouter()
const advancedAllowed = ref(false)
onMounted(async () => { try { advancedAllowed.value = (await api.get('/admin/router/advanced/permission')).data.allowed === true } catch {} })
async function logout() {
  try { await api.post('/admin/logout') } catch {}
  localStorage.removeItem('rjay_admin_token')
  router.push('/admin/login')
}
</script>

<template>
  <AdminShell>
    <h1 class="h2 mb-3">More</h1>
    <nav class="d-grid gap-2" aria-label="More admin pages">
      <router-link class="btn btn-outline-secondary text-start py-3" to="/admin/plans">Packages</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" to="/admin/sessions">Sessions</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" to="/admin/router">Router and diagnostics</router-link>
      <router-link v-if="advancedAllowed" class="btn btn-outline-secondary text-start py-3" to="/admin/router/advanced">Advanced Tools</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" to="/admin/support">Customer support</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" to="/admin/logs">Errors</router-link>
    </nav>
    <button class="btn btn-outline-danger mt-4" @click="logout">Sign out</button>
  </AdminShell>
</template>
