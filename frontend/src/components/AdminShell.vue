<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { api } from "../api";

const router = useRouter();
const moreOpen = ref(false);
const advancedAllowed = ref(false);
onMounted(async () => {
  try { advancedAllowed.value = (await api.get('/admin/router/advanced/permission')).data.allowed === true }
  catch { advancedAllowed.value = false }
});

async function logout() {
  try {
    await api.post("/admin/logout");
  } catch {}
  localStorage.removeItem("rjay_admin_token");
  router.push("/admin/login");
}
</script>

<template>
  <div class="admin-layout">
    <aside class="admin-sidebar d-none d-lg-flex">
      <div class="brand admin-brand">RJAY Hotspot</div>
      <nav class="admin-nav" aria-label="Admin navigation">
        <router-link to="/admin" exact
          ><i class="bi bi-grid"></i><span>Dashboard</span></router-link
        >
        <router-link to="/admin/plans"
          ><i class="bi bi-box"></i><span>Packages</span></router-link
        >
        <router-link to="/admin/vouchers"
          ><i class="bi bi-ticket-perforated"></i
          ><span>Vouchers</span></router-link
        >
        <router-link to="/admin/payments"
          ><i class="bi bi-wallet2"></i><span>Payments</span></router-link
        >
        <router-link to="/admin/sessions"
          ><i class="bi bi-wifi"></i><span>Sessions</span></router-link
        >
        <router-link to="/admin/router"
          ><i class="bi bi-router"></i><span>Router</span></router-link
        >
        <router-link v-if="advancedAllowed" to="/admin/router/advanced"
          ><i class="bi bi-tools"></i><span>Advanced Tools</span></router-link
        >
        <router-link to="/admin/logs"
          ><i class="bi bi-journal-text"></i><span>Errors</span></router-link
        >
      </nav>
      <button class="admin-logout" @click="logout">
        <i class="bi bi-box-arrow-left"></i>Logout
      </button>
    </aside>

    <div class="admin-main">
      <header class="admin-mobile-header d-lg-none">
        <div>
          <div class="brand">RJAY Hotspot</div>
          <small>Admin Console</small>
        </div>
        <button
          type="button"
          aria-label="Open more menu"
          @click="moreOpen = true"
        >
          <i class="bi bi-three-dots"></i>
        </button>
      </header>

      <main class="admin-content"><slot /></main>
    </div>

    <nav
      class="admin-bottom-nav d-lg-none"
      aria-label="Mobile admin navigation"
    >
      <router-link to="/admin" exact
        ><i class="bi bi-grid"></i><span>Home</span></router-link
      >
      <router-link to="/admin/payments"
        ><i class="bi bi-wallet2"></i><span>Sales</span></router-link
      >
      <router-link to="/admin/vouchers"
        ><i class="bi bi-ticket-perforated"></i
        ><span>Vouchers</span></router-link
      >
      <button type="button" @click="moreOpen = true">
        <i class="bi bi-grid-3x3-gap"></i><span>More</span>
      </button>
    </nav>

    <Transition name="admin-sheet">
      <div
        v-if="moreOpen"
        class="admin-sheet d-lg-none"
        @click.self="moreOpen = false"
      >
        <div class="admin-sheet__panel">
          <div class="admin-sheet__handle"></div>
          <div class="admin-sheet__head">
            <strong>More</strong>
            <button
              type="button"
              aria-label="Close menu"
              @click="moreOpen = false"
            >
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          <router-link to="/admin/plans" @click="moreOpen = false"
            ><i class="bi bi-box"></i>Packages</router-link
          >
          <router-link to="/admin/sessions" @click="moreOpen = false"
            ><i class="bi bi-wifi"></i>Sessions</router-link
          >
          <router-link to="/admin/router" @click="moreOpen = false"
            ><i class="bi bi-router"></i>Router</router-link
          >
          <router-link v-if="advancedAllowed" to="/admin/router/advanced" @click="moreOpen = false"
            ><i class="bi bi-tools"></i>Advanced Tools</router-link
          >
          <router-link to="/admin/logs" @click="moreOpen = false"
            ><i class="bi bi-journal-text"></i>Errors</router-link
          >
          <button class="admin-sheet__logout" @click="logout">
            <i class="bi bi-box-arrow-left"></i>Logout
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.admin-layout {
  min-height: 100vh;
  background: #eef3f9;
}
.admin-sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 242px;
  flex-direction: column;
  padding: 24px 18px;
  background: #0f172a;
  color: #fff;
}
.admin-brand {
  padding: 0 8px;
  font-size: 1.2rem;
}
.admin-nav {
  display: grid;
  gap: 6px;
  margin-top: 28px;
}
.admin-nav a {
  display: flex;
  min-height: 46px;
  align-items: center;
  gap: 11px;
  padding: 0 12px;
  border-radius: 12px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 700;
}
.admin-nav a i {
  width: 20px;
  text-align: center;
}
.admin-nav a.router-link-exact-active,
.admin-nav a:hover {
  background: rgba(255, 255, 255, 0.09);
  color: #fff;
}
.admin-logout {
  display: flex;
  min-height: 44px;
  align-items: center;
  gap: 9px;
  margin-top: auto;
  padding: 0 12px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 12px;
  background: transparent;
  color: #e2e8f0;
  font-weight: 700;
}
.admin-main {
  min-height: 100vh;
}
.admin-content {
  padding: 20px 16px 92px;
}
.admin-mobile-header {
  display: flex;
  min-height: 66px;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  border-bottom: 1px solid #e2e8f0;
  background: #eef3f9;
}
.admin-mobile-header .brand {
  font-size: 1rem;
}
.admin-mobile-header small {
  color: #64748b;
}
.admin-mobile-header button,
.admin-sheet__head button {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  border: 0;
  border-radius: 12px;
  background: #f1f5f9;
  color: #334155;
  font-size: 1.15rem;
}
.admin-bottom-nav {
  position: fixed;
  z-index: 40;
  right: 0;
  bottom: 0;
  left: 0;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  padding: 6px max(8px, env(safe-area-inset-right))
    calc(6px + env(safe-area-inset-bottom)) max(8px, env(safe-area-inset-left));
  border-top: 1px solid #e2e8f0;
  background: rgba(238, 243, 249, 0.97);
  box-shadow: 0 -5px 16px rgba(71, 85, 105, 0.12);
  backdrop-filter: blur(12px);
}
.admin-bottom-nav a,
.admin-bottom-nav button {
  display: flex;
  min-height: 54px;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  border: 0;
  border-radius: 12px;
  background: transparent;
  color: #64748b;
  text-decoration: none;
  font-size: 0.66rem;
  font-weight: 750;
}
.admin-bottom-nav i {
  font-size: 1.08rem;
}
.admin-bottom-nav a.router-link-exact-active {
  color: #0f9675;
  background: #f1faf7;
}
.admin-sheet {
  position: fixed;
  z-index: 60;
  inset: 0;
  display: flex;
  align-items: flex-end;
  background: rgba(15, 23, 42, 0.38);
}
.admin-sheet__panel {
  width: 100%;
  padding: 10px 14px calc(18px + env(safe-area-inset-bottom));
  border-radius: 24px 24px 0 0;
  background: #fff;
  box-shadow: 0 -20px 48px rgba(15, 23, 42, 0.18);
}
.admin-sheet__handle {
  width: 42px;
  height: 4px;
  margin: 2px auto 10px;
  border-radius: 999px;
  background: #cbd5e1;
}
.admin-sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.admin-sheet a,
.admin-sheet__logout {
  display: flex;
  width: 100%;
  min-height: 52px;
  align-items: center;
  gap: 11px;
  padding: 0 12px;
  border: 0;
  border-radius: 13px;
  background: #fff;
  color: #1e293b;
  text-decoration: none;
  font-weight: 750;
}
.admin-sheet a:hover {
  background: #f8fafc;
}
.admin-sheet__logout {
  margin-top: 6px;
  color: #b91c1c;
}
.admin-sheet-enter-active,
.admin-sheet-leave-active {
  transition: opacity 0.18s ease;
}
.admin-sheet-enter-active .admin-sheet__panel,
.admin-sheet-leave-active .admin-sheet__panel {
  transition: transform 0.18s ease;
}
.admin-sheet-enter-from,
.admin-sheet-leave-to {
  opacity: 0;
}
.admin-sheet-enter-from .admin-sheet__panel,
.admin-sheet-leave-to .admin-sheet__panel {
  transform: translateY(30px);
}
@media (min-width: 992px) {
  .admin-main {
    margin-left: 242px;
  }
  .admin-content {
    padding: 28px 30px 40px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .admin-sheet-enter-active,
  .admin-sheet-leave-active,
  .admin-sheet-enter-active .admin-sheet__panel,
  .admin-sheet-leave-active .admin-sheet__panel {
    transition: none;
  }
}
</style>
