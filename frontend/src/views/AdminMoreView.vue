<script setup lang="ts">
import { useRouter } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import { can, logout as endSession } from '../auth/access'
const router = useRouter()

async function logout() {
  try { await endSession() } finally { router.push('/admin/login') }
}
</script>

<template>
  <AdminShell>
    <h1 class="h2 mb-3">More</h1>
    <nav class="d-grid gap-2" aria-label="More admin pages">
        <router-link v-if="can('users.view')" to="/admin/users"><i class="bi bi-people"></i><span>Users</span></router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" v-if="can('plans.view')" to="/admin/plans">Packages</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" v-if="can('sessions.view')" to="/admin/sessions">Sessions</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" v-if="can('router.view')" to="/admin/router">Router and diagnostics</router-link>
      <router-link v-if="can('router.advanced')" class="btn btn-outline-secondary text-start py-3" to="/admin/router/advanced">Advanced Tools</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" v-if="can('support.view')" to="/admin/support">Customer support</router-link>
      <router-link class="btn btn-outline-secondary text-start py-3" v-if="can('logs.view')" to="/admin/logs">Errors</router-link>
    </nav>
    <button class="btn btn-outline-danger mt-4" @click="logout">Sign out</button>
  </AdminShell>
</template>
