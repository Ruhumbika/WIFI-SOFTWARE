<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import DashboardAnalytics from "../components/admin/DashboardAnalytics.vue";
import { paymentMoney, reportingDate } from "../utils/paymentReporting";
import AdminShell from "../components/AdminShell.vue";
import { api } from "../api";

const dashboard = ref<any>({});
const routerHealth = ref<any>({});
const dashboardUpdated = ref<string | null>(null);
const routerUpdated = ref<string | null>(null);
const routerError = ref("");
const loading = ref(true);
const error = ref("");

async function load() {
  if (loading.value && dashboardUpdated.value) return;
  loading.value = true;
  routerError.value = "";
  error.value = "";
  try {
    const [dashboardResponse, routerResponse] = await Promise.allSettled([
      api.get("/admin/dashboard"),
      api.get("/admin/router/health"),
    ]);

    if (dashboardResponse.status === "fulfilled") {
      dashboard.value = dashboardResponse.value.data;
      dashboardUpdated.value = new Date().toISOString();
    }
    else error.value = "Dashboard could not be loaded.";
    if (routerResponse.status === "fulfilled") {
      routerHealth.value = routerResponse.value.data;
      routerUpdated.value = new Date().toISOString();
    } else {
      const response = routerResponse.reason?.response;
      if (response?.status === 503 && response.data?.connected === false) {
        routerHealth.value = response.data;
        routerUpdated.value = new Date().toISOString();
      } else routerError.value = "Network status could not be checked.";
    }
  } finally {
    loading.value = false;
  }
}

const attentionCount = computed(
  () =>
    Number(dashboard.value.pending_provision || 0) +
    Number(dashboard.value.failed_payments || 0) +
    Number(dashboard.value.open_support_requests || 0) +
    (routerHealth.value.connected === false ? 1 : 0),
);
const attentionKnown = computed(() => !!dashboardUpdated.value && !!routerUpdated.value && !error.value && !routerError.value);
const networkState = computed(() => routerError.value ? "Unknown" : routerHealth.value.connected === true ? "Online" : routerHealth.value.connected === false ? "Unavailable" : loading.value ? "Checking…" : "Unknown");
function count(value: unknown) { return value === undefined || value === null ? (loading.value ? "Loading…" : "—") : value; }
const routerResource = computed(() => routerHealth.value?.resource || {});

let refreshTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  void load();
  refreshTimer = setInterval(() => { if (!loading.value && document.visibilityState === 'visible') void load(); }, 60000);
});
onUnmounted(() => { if (refreshTimer) clearInterval(refreshTimer); });
</script>

<template>
  <AdminShell>
    <div class="dashboard-page" :class="{ 'is-loading': loading && !dashboardUpdated }">
    <div class="dashboard-head">
      <div>
        <h1>Dashboard</h1>
      </div>
      <button class="refresh-button" :disabled="loading" @click="load">
        <i class="bi bi-arrow-clockwise" :class="{ spinning: loading }"></i
        ><span class="d-none d-sm-inline">Refresh</span>
      </button>
    </div>

    <nav class="dashboard-actions mb-3" aria-label="Quick actions">
      <router-link to="/admin/vouchers"><i class="bi bi-ticket-perforated" aria-hidden="true"></i> Vouchers</router-link>
      <router-link to="/admin/plans"><i class="bi bi-grid" aria-hidden="true"></i> Plans</router-link>
      <router-link to="/admin/router"><i class="bi bi-router" aria-hidden="true"></i> Router</router-link>
    </nav>

    <p v-if="error" role="alert" class="alert alert-warning py-2">{{ error }} {{ dashboardUpdated ? "Showing previously loaded data." : "Use Refresh to retry." }}</p>
    <p v-if="dashboardUpdated" class="small text-secondary mb-2">Last updated: {{ reportingDate(dashboardUpdated) }}<span v-if="error"> · Out of date</span></p>
    <p v-if="dashboard.missing_settlement" class="alert alert-warning small">{{ dashboard.missing_settlement }} completed payments today have no confirmed settlement. Net revenue is incomplete.</p>
    <div class="dashboard-stats">
      <router-link to="/admin/sessions" class="dashboard-stat">
        <span class="dashboard-stat__icon"><i class="bi bi-wifi"></i></span>
        <span class="dashboard-stat__label">Online now</span>
        <strong>{{ count(dashboard.online) }}</strong>
      </router-link>
      <router-link to="/admin/payments" class="dashboard-stat">
        <span class="dashboard-stat__icon"
          ><i class="bi bi-cash-stack"></i
        ></span>
        <span class="dashboard-stat__label">Net revenue today</span>
        <strong class="money"
          >{{ dashboardUpdated ? paymentMoney(dashboard.net_revenue_today) : loading ? "Loading…" : "—" }}</strong
        >
      </router-link>
      <router-link to="/admin/vouchers" class="dashboard-stat">
        <span class="dashboard-stat__icon"
          ><i class="bi bi-ticket-perforated"></i
        ></span>
        <span class="dashboard-stat__label">Active vouchers</span>
        <strong>{{ count(dashboard.active_vouchers) }}</strong>
      </router-link>
      <router-link
        to="/admin/payments"
        class="dashboard-stat"
        :class="{ 'has-attention': dashboard.failed_payments > 0 }"
      >
        <span class="dashboard-stat__icon"><i class="bi bi-receipt"></i></span>
        <span class="dashboard-stat__label">Sales today</span>
        <strong>{{ count(dashboard.sales_today) }}</strong>
        <small v-if="dashboard.failed_payments"
          >{{ dashboard.failed_payments }} failed</small
        >
      </router-link>
    </div>

    <DashboardAnalytics />
    <div class="dashboard-grid mt-3">
      <section class="ops-card system-health">
        <div class="ops-card__head">
          <div>
            <h2>Network</h2>
          </div>
          <span
            class="health-badge"
            :class="routerError || routerHealth.connected === undefined ? 'unknown' : routerHealth.connected ? 'online' : 'offline'"
            ><span></span
            >{{ networkState }}</span
          >
        </div>
        <p v-if="routerError" class="small text-secondary" role="status">{{ routerError }} {{ routerUpdated ? "Showing previous results." : "Use Refresh to retry." }}</p>
        <p v-if="routerUpdated" class="small text-secondary mb-0">Last checked: {{ reportingDate(routerUpdated) }}</p>
        <div class="health-list">
          <div>
            <span>MikroTik</span
            ><strong>{{
              routerHealth.connected === undefined ? "Not checked" : routerHealth.connected ? "Connected" : "Unavailable"
            }}</strong>
          </div>
          <div><span>HotSpot</span><strong>{{ !routerHealth.connected ? "Not checked" : routerHealth.hotspot ? "Responding" : "Issue" }}</strong></div>
          <div><span>Active users</span><strong>{{ routerHealth.active_users ?? "Unavailable" }}</strong></div>
          <div><span>Scheduler</span><strong>{{ !dashboardUpdated ? 'Not checked' : dashboard.sync_health?.scheduler_status === 'healthy' ? 'Healthy' : dashboard.sync_health?.scheduler_status === 'delayed' ? 'Delayed' : 'Not recorded' }}</strong></div>
          <div><span>Last scheduler attempt</span><strong>{{ reportingDate(dashboard.sync_health?.last_scheduler_attempt) }}</strong></div>
          <div><span>Last successful session sync</span><strong>{{ reportingDate(dashboard.sync_health?.last_successful_sync) }}</strong></div>
          <div v-if="['failed','blocked'].includes(dashboard.sync_health?.last_sync_result?.state)"><span>Session sync</span><strong>Failed / partial — retry required</strong></div>
        </div>
        <details class="mt-3">
          <summary class="text-secondary">Router details</summary>
          <div class="health-list mt-2">
          <div><span>Router name</span><strong>{{ routerHealth.router_name || "Unavailable" }}</strong></div>
          <div><span>HotSpot server</span><strong>{{ routerHealth.hotspot_server?.name || routerHealth.configured_hotspot || "Not configured" }}</strong></div>
          <div>
            <span>RouterOS</span
            ><strong>{{ routerResource.version || "—" }}</strong>
          </div>
          <div>
            <span>Board</span
            ><strong>{{ routerResource["board-name"] || "—" }}</strong>
          </div>
          <div>
            <span>CPU load</span
            ><strong>{{
              routerResource["cpu-load"] !== undefined
                ? `${routerResource["cpu-load"]}%`
                : "—"
            }}</strong>
          </div>
          </div>
        </details>
        <router-link to="/admin/router" class="card-action"
          >Details <i class="bi bi-arrow-right"></i
        ></router-link>
      </section>

      <section class="ops-card attention-card">
        <div class="ops-card__head">
          <div>
            <span class="dashboard-eyebrow">Attention</span>
            <h2>
              {{
                !attentionKnown ? "Not fully checked" : attentionCount
                  ? `${attentionCount} item${attentionCount === 1 ? "" : "s"}`
                  : "All clear"
              }}
            </h2>
          </div>
          <span class="attention-count" :class="{ clear: attentionKnown && !attentionCount }">{{
            attentionKnown ? attentionCount : "—"
          }}</span>
        </div>
        <div v-if="attentionCount" class="attention-list">
          <router-link v-if="dashboard.open_support_requests" to="/admin/support?status=all"><span class="attention-icon"><i class="bi bi-life-preserver"></i></span><strong>{{ dashboard.open_support_requests }} support requests need attention</strong><i class="bi bi-chevron-right"></i></router-link>
          <router-link v-if="routerHealth.connected === false" to="/admin/router">
            <span class="attention-icon"><i class="bi bi-router"></i></span>
            <span><strong>Router unavailable</strong></span>
            <i class="bi bi-chevron-right"></i>
          </router-link>
          <router-link v-if="dashboard.pending_provision" to="/admin/vouchers?status=pending">
            <span class="attention-icon"><i class="bi bi-router"></i></span>
            <span
              ><strong
                >{{ dashboard.pending_provision }} voucher{{
                  dashboard.pending_provision === 1 ? "" : "s"
                }}
                need setup</strong></span
            >
            <i class="bi bi-chevron-right"></i>
          </router-link>
          <router-link v-if="dashboard.failed_payments" to="/admin/payments">
            <span class="attention-icon"
              ><i class="bi bi-exclamation-circle"></i
            ></span>
            <span
              ><strong
                >{{ dashboard.failed_payments }} failed payment{{
                  dashboard.failed_payments === 1 ? "" : "s"
                }}
                today</strong></span
            >
            <i class="bi bi-chevron-right"></i>
          </router-link>
        </div>
        <p v-else-if="!attentionKnown" class="small text-secondary" role="status">{{ loading ? "Checking…" : "Some checks are unavailable. Refresh to retry." }}</p>
        <div v-else class="all-clear">
          <i class="bi bi-check-circle-fill"></i
          ><span>No issues.</span>
        </div>
      </section>
    </div>

    </div>
  </AdminShell>
</template>

<style scoped>
.dashboard-actions { display:flex; gap:8px; flex-wrap:wrap; }
.dashboard-actions a { display:flex; flex:1; justify-content:center; align-items:center; gap:6px; min-height:38px; padding:6px 12px; border:1px solid #f7fbff; border-radius:12px; background:#eef3f9; box-shadow:3px 3px 7px #cbd5df,-3px -3px 7px #fff; color:#196b97; text-decoration:none; font-size:13px; font-weight:700; }
.dashboard-actions a:focus-visible { outline:2px solid #196b97; outline-offset:3px; }
.dashboard-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 10px;
}
.dashboard-head h1 {
  margin: 2px 0 0;
  font-size: clamp(1.55rem, 6vw, 2rem);
  font-weight: 900;
  letter-spacing: -0.035em;
}
.dashboard-head p {
  margin: 5px 0 0;
  color: #64748b;
  font-size: 0.86rem;
}
.dashboard-eyebrow {
  color: #0f9675;
  font-size: 0.69rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.refresh-button {
  display: flex;
  min-width: 46px;
  min-height: 38px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0 12px;
  border: 1px solid #dbe4ec;
  border-radius: 12px;
  background: #fff;
  color: #334155;
  font-weight: 750;
}
.refresh-button:disabled {
  opacity: 0.65;
}
.spinning {
  animation: spin 0.9s linear infinite;
}
.dashboard-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.dashboard-stat {
  position: relative;
  display: flex;
  min-height: 104px;
  flex-direction: column;
  padding: 11px;
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 18px;
  background: #eef3f9;
  box-shadow: 7px 7px 16px #cbd5df, -7px -7px 16px #fff;
  color: #0f172a;
  text-decoration: none;
}
.dashboard-stat__icon {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 11px;
  background: #f1faf7;
  color: #0f9675;
}
.dashboard-stat__label {
  margin-top: 8px;
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 700;
}
.dashboard-stat strong {
  margin-top: 3px;
  font-size: 1.6rem;
  font-weight: 900;
  letter-spacing: -0.04em;
}
.dashboard-stat strong.money {
  font-size: 1.1rem;
}
.dashboard-stat small {
  margin-top: 2px;
  color: #b45309;
}
.dashboard-stat.has-attention {
  border-color: #fed7aa;
}
.dashboard-grid {
  display: grid;
  gap: 12px;
}
.ops-card {
  padding: 12px;
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 18px;
  background: #eef3f9;
  box-shadow: 7px 7px 16px #cbd5df, -7px -7px 16px #fff;
}
.ops-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.ops-card h2 {
  margin: 3px 0 0;
  font-size: 1.05rem;
  font-weight: 850;
}
.health-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 9px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 0.7rem;
  font-weight: 800;
}
.health-badge span {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}
.health-badge.online {
  background: #ecfdf5;
  color: #047857;
}
.health-badge.offline {
  background: #fff1f2;
  color: #be123c;
}
.health-list {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 7px;
  margin-top: 10px;
}
.health-list div {
  padding: 8px;
  border-radius: 12px;
  background: #f8fafc;
}
.health-list span,
.health-list strong {
  display: block;
}
.health-list span {
  color: #64748b;
  font-size: 0.68rem;
}
.health-list strong {
  margin-top: 3px;
  font-size: 0.79rem;
}
.card-action {
  display: flex;
  min-height: 44px;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
  padding: 0 2px;
  color: #0f9675;
  text-decoration: none;
  font-size: 0.78rem;
  font-weight: 800;
}
.attention-count {
  display: grid;
  min-width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 999px;
  background: #fff7ed;
  color: #c2410c;
  font-size: 0.76rem;
  font-weight: 900;
}
.attention-count.clear {
  background: #ecfdf5;
  color: #047857;
}
.attention-list {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}
.attention-list a {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 10px;
  min-height: 54px;
  padding: 8px;
  border-radius: 13px;
  background: #fffaf5;
  color: #1e293b;
  text-decoration: none;
}
.attention-icon {
  display: grid;
  width: 38px;
  height: 38px;
  place-items: center;
  border-radius: 11px;
  background: #ffedd5;
  color: #c2410c;
}
.attention-list strong,
.attention-list small {
  display: block;
}
.attention-list strong {
  font-size: 0.78rem;
}
.attention-list small {
  margin-top: 3px;
  color: #64748b;
  font-size: 0.69rem;
  line-height: 1.4;
}
.all-clear {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #047857;
  font-size: 0.8rem;
  text-align: center;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
@media (min-width: 768px) {
  .dashboard-stats {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .dashboard-stat {
    min-height: 96px;
  }
  .dashboard-grid {
    grid-template-columns: 1fr 1fr;
  }
  .ops-card {
    padding: 14px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .spinning {
    animation: none;
  }
}
@media (max-width: 767.98px) {
  .dashboard-stat { min-height: 108px; padding: 12px; border-radius: 16px; }
  .dashboard-stat__icon { width: 28px; height: 28px; border-radius: 9px; }
  .dashboard-stat__label { margin-top: 8px; }
  .dashboard-stat strong { font-size: 1.35rem; overflow-wrap: anywhere; }
  .ops-card { padding: 12px; border-radius: 16px; min-width: 0; }
  .health-list { gap: 8px; margin-top: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .health-list div { padding: 9px; overflow-wrap: anywhere; }
}
.health-badge.unknown { background: #edf2f7; color: #64748b; }
</style>

<style scoped>
.dashboard-page .dashboard-stat { display:grid; grid-template-columns:34px minmax(0,1fr); align-content:center; gap:3px 10px; min-height:86px; padding:12px; border:1px solid #d5e2eb; border-left:4px solid #196b97; border-radius:8px; background:#fff; box-shadow:none; }
.dashboard-page .dashboard-stat__icon { grid-row:1/3; background:#eaf0f5; color:#196b97; align-self:center; }
.dashboard-page .dashboard-stat__label { margin:0; font-size:11px; }
.dashboard-page .dashboard-stat strong { font-size:22px; margin:0; letter-spacing:0; }
.dashboard-page .dashboard-stat strong.money { font-size:17px; }
.dashboard-page .dashboard-actions a { box-shadow:none; border:1px solid #9dd4f2; border-radius:6px; background:#fff; }
.dashboard-page .ops-card { border:1px solid #d5e2eb; border-radius:8px; background:#fff; box-shadow:none; }
</style>

<style scoped>
.dashboard-page.is-loading .dashboard-stat strong { color:transparent; background:#eaf0f5; border-radius:4px; width:90px; height:22px; overflow:hidden; }
</style>
