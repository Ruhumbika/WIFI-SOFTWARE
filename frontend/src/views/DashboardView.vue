<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import AdminShell from "../components/AdminShell.vue";
import { api } from "../api";
import { formatDate } from "../utils/formatDate";

const dashboard = ref<any>({});
const routerHealth = ref<any>({ connected: false });
const loading = ref(true);

async function load() {
  loading.value = true;
  try {
    const [dashboardResponse, routerResponse] = await Promise.allSettled([
      api.get("/admin/dashboard"),
      api.get("/admin/router/health"),
    ]);

    if (dashboardResponse.status === "fulfilled")
      dashboard.value = dashboardResponse.value.data;
    if (routerResponse.status === "fulfilled")
      routerHealth.value = routerResponse.value.data;
    else routerHealth.value = routerResponse.reason?.response?.data || { connected: false };
  } finally {
    loading.value = false;
  }
}

const attentionCount = computed(
  () =>
    Number(dashboard.value.pending_provision || 0) +
    Number(dashboard.value.failed_payments || 0) +
    (routerHealth.value.connected ? 0 : 1),
);
const routerResource = computed(() => routerHealth.value?.resource || {});

function paymentTone(status: string) {
  if (status === "completed") return "success";
  if (status === "failed") return "danger";
  return "pending";
}

function friendlyStatus(status: string) {
  const labels: Record<string, string> = {
    completed: "Paid",
    pending: "Pending",
    failed: "Failed",
    expired: "Expired",
  };
  return labels[status] || status;
}

function displayPhone(phone?: string | null) {
  if (!phone) return "No phone recorded";
  const digits = phone.replace(/\D/g, "");
  return /^255[67]\d{8}$/.test(digits)
    ? `${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6, 9)} ${digits.slice(9)}`
    : phone;
}

onMounted(load);
</script>

<template>
  <AdminShell>
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

    <div class="dashboard-stats">
      <router-link to="/admin/sessions" class="dashboard-stat">
        <span class="dashboard-stat__icon"><i class="bi bi-wifi"></i></span>
        <span class="dashboard-stat__label">Online now</span>
        <strong>{{ dashboard.online || 0 }}</strong>
      </router-link>
      <router-link to="/admin/payments" class="dashboard-stat">
        <span class="dashboard-stat__icon"
          ><i class="bi bi-cash-stack"></i
        ></span>
        <span class="dashboard-stat__label">Revenue today</span>
        <strong class="money"
          >TZS
          {{ Number(dashboard.revenue_today || 0).toLocaleString() }}</strong
        >
      </router-link>
      <router-link to="/admin/vouchers" class="dashboard-stat">
        <span class="dashboard-stat__icon"
          ><i class="bi bi-ticket-perforated"></i
        ></span>
        <span class="dashboard-stat__label">Active vouchers</span>
        <strong>{{ dashboard.active_vouchers || 0 }}</strong>
      </router-link>
      <router-link
        to="/admin/payments"
        class="dashboard-stat"
        :class="{ 'has-attention': dashboard.failed_payments > 0 }"
      >
        <span class="dashboard-stat__icon"><i class="bi bi-receipt"></i></span>
        <span class="dashboard-stat__label">Sales today</span>
        <strong>{{ dashboard.sales_today || 0 }}</strong>
        <small v-if="dashboard.failed_payments"
          >{{ dashboard.failed_payments }} failed</small
        >
      </router-link>
    </div>

    <div class="dashboard-grid mt-3">
      <section class="ops-card system-health">
        <div class="ops-card__head">
          <div>
            <h2>Network</h2>
          </div>
          <span
            class="health-badge"
            :class="routerHealth.connected ? 'online' : 'offline'"
            ><span></span
            >{{ routerHealth.connected ? "Online" : "Offline" }}</span
          >
        </div>
        <div class="health-list">
          <div>
            <span>MikroTik</span
            ><strong>{{
              routerHealth.connected ? "Connected" : "Unavailable"
            }}</strong>
          </div>
          <div><span>HotSpot</span><strong>{{ !routerHealth.connected ? "Not checked" : routerHealth.hotspot ? "Responding" : "Issue" }}</strong></div>
          <div><span>Active users</span><strong>{{ routerHealth.active_users ?? "Unavailable" }}</strong></div>
          <div><span>Last sync</span><strong>{{ formatDate(routerHealth.last_sync, "Not recorded") }}</strong></div>
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
                attentionCount
                  ? `${attentionCount} item${attentionCount === 1 ? "" : "s"}`
                  : "All clear"
              }}
            </h2>
          </div>
          <span class="attention-count" :class="{ clear: !attentionCount }">{{
            attentionCount
          }}</span>
        </div>
        <div v-if="attentionCount" class="attention-list">
          <router-link v-if="!routerHealth.connected" to="/admin/router">
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
        <div v-else class="all-clear">
          <i class="bi bi-check-circle-fill"></i
          ><span>No issues.</span>
        </div>
      </section>
    </div>

    <section class="ops-card mt-3">
      <div class="ops-card__head recent-head">
        <div>
          <h2>Recent payments</h2>
        </div>
        <router-link to="/admin/payments">View all</router-link>
      </div>
      <div v-if="dashboard.recent_payments?.length" class="recent-list">
        <div
          v-for="payment in dashboard.recent_payments"
          :key="payment.id"
          class="recent-row"
        >
          <span class="recent-row__icon" :class="paymentTone(payment.status)"
            ><i class="bi bi-phone"></i
          ></span>
          <div class="recent-row__main">
            <strong>{{
              payment.order?.plan?.name || "Internet package"
            }}</strong>
            <span>{{ displayPhone(payment.order?.customer_phone) }}</span>
          </div>
          <div class="recent-row__amount">
            <strong
              >TZS {{ Number(payment.amount || 0).toLocaleString() }}</strong
            ><span :class="paymentTone(payment.status)">{{
              friendlyStatus(payment.status)
            }}</span>
          </div>
        </div>
      </div>
      <div v-else class="empty-activity">No payments yet.</div>
    </section>
  </AdminShell>
</template>

<style scoped>
.dashboard-actions { display:flex; gap:10px; flex-wrap:wrap; }
.dashboard-actions a { display:flex; flex:1; justify-content:center; align-items:center; gap:8px; min-height:44px; padding:9px 14px; border:1px solid #f7fbff; border-radius:14px; background:#eef3f9; box-shadow:4px 4px 9px #cbd5df,-4px -4px 9px #fff; color:#196b97; text-decoration:none; font-size:13px; font-weight:700; }
.dashboard-actions a:focus-visible { outline:2px solid #196b97; outline-offset:3px; }
.dashboard-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
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
  min-height: 44px;
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
  gap: 10px;
}
.dashboard-stat {
  position: relative;
  display: flex;
  min-height: 132px;
  flex-direction: column;
  padding: 15px;
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 18px;
  background: #eef3f9;
  box-shadow: 7px 7px 16px #cbd5df, -7px -7px 16px #fff;
  color: #0f172a;
  text-decoration: none;
}
.dashboard-stat__icon {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 11px;
  background: #f1faf7;
  color: #0f9675;
}
.dashboard-stat__label {
  margin-top: 14px;
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
  padding: 17px;
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
  gap: 10px;
  margin-top: 16px;
}
.health-list div {
  padding: 11px;
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
  min-height: 70px;
  padding: 10px;
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
  min-height: 100px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #047857;
  font-size: 0.8rem;
  text-align: center;
}
.recent-head a {
  color: #0f9675;
  font-size: 0.75rem;
  font-weight: 800;
  text-decoration: none;
}
.recent-list {
  display: grid;
  margin-top: 10px;
}
.recent-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 11px 0;
  border-top: 1px solid #eef2f7;
}
.recent-row__icon {
  display: grid;
  width: 38px;
  height: 38px;
  place-items: center;
  border-radius: 11px;
  background: #f1f5f9;
  color: #475569;
}
.recent-row__icon.success {
  background: #ecfdf5;
  color: #047857;
}
.recent-row__icon.danger {
  background: #fff1f2;
  color: #be123c;
}
.recent-row__icon.pending {
  background: #fffbeb;
  color: #a16207;
}
.recent-row__main {
  min-width: 0;
}
.recent-row__main strong,
.recent-row__main span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recent-row__main strong {
  font-size: 0.8rem;
}
.recent-row__main span {
  margin-top: 3px;
  color: #64748b;
  font-size: 0.68rem;
}
.recent-row__amount {
  text-align: right;
}
.recent-row__amount strong,
.recent-row__amount span {
  display: block;
}
.recent-row__amount strong {
  font-size: 0.76rem;
}
.recent-row__amount span {
  margin-top: 3px;
  font-size: 0.67rem;
  font-weight: 800;
  text-transform: capitalize;
}
.recent-row__amount span.success {
  color: #047857;
}
.recent-row__amount span.danger {
  color: #be123c;
}
.recent-row__amount span.pending {
  color: #a16207;
}
.empty-activity {
  padding: 32px 0;
  text-align: center;
  color: #94a3b8;
  font-size: 0.8rem;
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
    min-height: 145px;
  }
  .dashboard-grid {
    grid-template-columns: 1fr 1fr;
  }
  .ops-card {
    padding: 20px;
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
  .recent-row { gap: 8px; padding: 9px 0; }
  .recent-row__icon { width: 28px; height: 28px; border-radius: 9px; }
  .recent-row__main span { font-size: 0.75rem; }
  .recent-row__amount { max-width: 100px; overflow-wrap: anywhere; }
}
</style>
