<script setup lang="ts">
import { useRoute } from 'vue-router'
import AdminShell from '../components/AdminShell.vue'
import { firstAccessible } from '../auth/access'
const route = useRoute()
</script>
<template>
  <AdminShell>
    <section class="card p-4" role="alert">
      <h1 class="h3">{{ route.query.reason === 'unavailable' ? 'Access could not be verified' : 'Access denied' }}</h1>
      <p v-if="route.query.reason === 'unavailable'">The server could not confirm your access. Please retry.</p>
      <p v-else-if="firstAccessible() === '/admin/forbidden'">No access has been assigned. Contact the administrator.</p>
      <p v-else>You do not have permission to open this area.</p>
      <router-link v-if="firstAccessible() !== '/admin/forbidden'" class="btn btn-primary align-self-start" :to="firstAccessible()">Go to an accessible page</router-link>
      <button v-if="route.query.reason === 'unavailable'" class="btn btn-outline-primary" @click="$router.go(0)">Retry</button>
    </section>
  </AdminShell>
</template>
