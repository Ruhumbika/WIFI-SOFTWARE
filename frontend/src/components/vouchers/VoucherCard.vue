<script setup lang="ts">
import { usePortalLanguage } from "../../i18n/portalLanguage";
const { t, formatDate } = usePortalLanguage();
import { computed, onUnmounted, ref } from "vue";
import { voucherTimeLeft } from "../../utils/voucherTime";

interface Voucher {
  code: string;
  password?: string | null;
  status?: string;
  device_mac?: string | null;
  activated_at?: string | null;
  expires_at?: string | null;
  provision_error?: string | null;
}

interface Plan {
  name: string;
  price?: number | string;
  currency?: string;
  rate_limit?: string | null;
  duration_seconds?: number;
  data_limit_bytes?: number | null;
}

const props = withDefaults(
  defineProps<{
    voucher: Voucher;
    plan?: Plan | null;
    showConnect?: boolean;
    connectLabel?: string;
    connecting?: boolean;
    compact?: boolean;
    nowMs?: number;
  }>(),
  {
    plan: null,
    showConnect: false,
    connectLabel: "Connect now",
    connecting: false,
    compact: false,
    nowMs: () => Date.now(),
  },
);

const emit = defineEmits<{ connect: [] }>();
const copied = ref(false);
const copyError = ref(false);
const detailsOpen = ref(false);
let copyResetTimer: number | undefined;

const statusLabel = computed(() => {
  const status = props.voucher.status || "ready";
  const labels: Record<string, string> = {
    generated: "Created",
    provision_pending: "Preparing",
    ready: "Ready",
    active: "Active",
    expired: "Expired",
    disabled: "Unavailable",
    revoked: "Unavailable",
  };
  return (
    labels[status] ||
    status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
  );
});

const activeTimeLeft = computed(() =>
  props.voucher.status === "active"
    ? voucherTimeLeft(props.voucher.expires_at, props.nowMs)
    : null,
);

const statusTone = computed(() => {
  const status = props.voucher.status || "ready";
  if (status === "active" && activeTimeLeft.value === "Time ended")
    return "pending";
  if (["ready", "active"].includes(status)) return "success";
  if (["expired", "disabled", "revoked"].includes(status)) return "muted";
  return "pending";
});

const priceLabel = computed(() => {
  if (props.plan?.price === undefined || props.plan?.price === null)
    return "Internet access";
  const amount = Number(props.plan.price);
  return `TZS ${Number.isFinite(amount) ? amount.toLocaleString() : props.plan.price}`;
});

const durationLabel = computed(() => {
  const seconds = Number(props.plan?.duration_seconds || 0);
  if (!seconds) return null;
  if (seconds % 604800 === 0)
    return `${seconds / 604800} week${seconds === 604800 ? "" : "s"}`;
  if (seconds % 86400 === 0)
    return `${seconds / 86400} day${seconds === 86400 ? "" : "s"}`;
  if (seconds % 3600 === 0)
    return `${seconds / 3600} hour${seconds === 3600 ? "" : "s"}`;
  return `${Math.round(seconds / 60)} minutes`;
});

const speedLabel = computed(() => {
  const value = props.plan?.rate_limit?.trim();
  if (!value) return null;
  const first = value.split("/")[0]?.trim() || value;
  const match = first.match(/^([\d.]+)M$/i);
  return match ? `${match[1]} Mbps` : value;
});

const dataLabel = computed(() => {
  const bytes = Number(props.plan?.data_limit_bytes || 0);
  return bytes
    ? `${Number((bytes / 1048576).toFixed(1)).toLocaleString()} MB`
    : null;
});

const terms = computed(
  () =>
    [
      "Works on one device only; the first successful device becomes linked to this voucher.",
      durationLabel.value
        ? `Validity starts on first successful login and lasts ${durationLabel.value}.`
        : "Validity starts on first successful login.",
      speedLabel.value ? `Package speed: ${speedLabel.value}.` : null,
      dataLabel.value ? `Data allowance: ${dataLabel.value}.` : null,
      "Keep your voucher code and PIN private.",
    ].filter(Boolean) as string[],
);

function fallbackCopy(text: string): boolean {
  const textarea = document.createElement("textarea");
  textarea.value = text;
  textarea.setAttribute("readonly", "");
  textarea.style.position = "fixed";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.select();
  const success = document.execCommand("copy");
  document.body.removeChild(textarea);
  return success;
}

async function copyCode() {
  copyError.value = false;
  try {
    if (navigator.clipboard?.writeText)
      await navigator.clipboard.writeText(props.voucher.code);
    else if (!fallbackCopy(props.voucher.code)) throw new Error("Copy failed");
  } catch {
    try {
      if (!fallbackCopy(props.voucher.code)) throw new Error("Copy failed");
    } catch {
      copyError.value = true;
      return;
    }
  }

  copied.value = true;
  window.clearTimeout(copyResetTimer);
  copyResetTimer = window.setTimeout(() => {
    copied.value = false;
  }, 1800);
}

onUnmounted(() => window.clearTimeout(copyResetTimer));
</script>

<template>
  <article
    class="voucher-ticket"
    :class="{ 'voucher-ticket--compact': compact }"
  >
    <div class="voucher-ticket__head">
      <div>
        <div class="voucher-ticket__eyebrow">{{ t("Your voucher") }}</div>
        <div class="voucher-ticket__package">
          {{ plan?.name || "RJAY WiFi access" }}
        </div>
      </div>
      <span class="voucher-ticket__status" :class="`is-${statusTone}`">
        <span aria-hidden="true"></span>{{ t(statusLabel)
        }}<time
          v-if="activeTimeLeft"
          aria-live="off"
          :title="t('Package time left')"
          >· {{ t(activeTimeLeft) }} {{ t("left") }}
        </time>
      </span>
    </div>

    <div class="voucher-ticket__summary">
      <strong>{{ t(priceLabel) }}</strong>
      <div class="voucher-ticket__facts">
        <span v-if="durationLabel"
          ><i class="bi bi-clock" aria-hidden="true"></i
          >{{ t(durationLabel) }}</span
        >
        <span v-if="speedLabel"
          ><i class="bi bi-speedometer2" aria-hidden="true"></i
          >{{ speedLabel }}</span
        >
        <span v-if="dataLabel"
          ><i class="bi bi-database" aria-hidden="true"></i
          >{{ dataLabel }}</span
        >
        <span
          ><i class="bi bi-phone" aria-hidden="true"></i> {{ t("1 device") }}
        </span>
      </div>
    </div>

    <div class="voucher-ticket__tear" aria-hidden="true"></div>

    <div class="voucher-ticket__body">
      <div class="voucher-ticket__code-label">{{ t("Voucher code") }}</div>
      <button
        type="button"
        class="voucher-ticket__copy"
        :class="{ 'is-copied': copied }"
        :aria-label="t(copied ? 'Voucher code copied' : 'Copy voucher code')"
        @click="copyCode"
      >
        <strong>{{ voucher.code }}</strong>
        <span aria-live="polite">
          <template v-if="copied"
            ><i class="bi bi-check-lg" aria-hidden="true"></i>
            {{ t("Copied!") }}
          </template>
          <template v-else
            ><i class="bi bi-copy" aria-hidden="true"></i> {{ t("Copy") }}
          </template>
        </span>
      </button>
      <p v-if="copyError" class="text-danger small mt-2" role="alert">
        {{ t("Could not copy the code. Select it and copy it manually.") }}
      </p>

      <div v-if="voucher.password" class="voucher-ticket__pin">
        <span>PIN</span><strong>{{ voucher.password }}</strong>
      </div>

      <button
        v-if="showConnect"
        type="button"
        class="btn btn-primary voucher-ticket__connect"
        :disabled="connecting"
        @click="emit('connect')"
      >
        <span
          v-if="connecting"
          class="spinner-border spinner-border-sm"
          aria-hidden="true"
        ></span>
        <i v-else class="bi bi-wifi" aria-hidden="true"></i>
        {{ t(connecting ? "Connecting…" : connectLabel) }}
      </button>

      <button
        type="button"
        class="voucher-ticket__details-toggle"
        :aria-expanded="detailsOpen"
        @click="detailsOpen = !detailsOpen"
      >
        <span> {{ t("Voucher details") }} </span>
        <i
          class="bi bi-chevron-down"
          :class="{ 'is-open': detailsOpen }"
          aria-hidden="true"
        ></i>
      </button>

      <Transition name="voucher-details">
        <div v-if="detailsOpen" class="voucher-ticket__details">
          <ul>
            <li v-for="term in terms" :key="term">{{ t(term) }}</li>
          </ul>
          <div v-if="voucher.device_mac" class="voucher-ticket__detail-row">
            <span> {{ t("Device") }} </span
            ><strong>{{ voucher.device_mac }}</strong>
          </div>
          <div v-if="voucher.expires_at" class="voucher-ticket__detail-row">
            <span> {{ t("Expires") }} </span
            ><strong>{{ formatDate(voucher.expires_at) }}</strong>
          </div>
          <div
            v-else-if="!voucher.activated_at"
            class="voucher-ticket__detail-row"
          >
            <span> {{ t("Validity") }} </span
            ><strong> {{ t("Starts on first login") }} </strong>
          </div>
          <div v-if="voucher.provision_error" class="voucher-ticket__notice">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
            {{
              t("Internet setup needs attention. Your voucher is still safe.")
            }}
          </div>
        </div>
      </Transition>
    </div>
  </article>
</template>

<style scoped>
.voucher-ticket {
  position: relative;
  width: 100%;
  overflow: hidden;
  border: 4px solid transparent;
  border-radius: 22px;
  background:
    linear-gradient(#fff, #fff) padding-box,
    linear-gradient(120deg, #93652b, #e4ca78 35%, #3d896e 65%, #c69a44)
      border-box;
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.1);
  color: #0f172a;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
  -webkit-tap-highlight-color: transparent;
}

.voucher-ticket:active {
  transform: scale(0.99);
}
.voucher-ticket__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 20px 12px;
}
.voucher-ticket__eyebrow {
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.voucher-ticket__package {
  margin-top: 3px;
  font-size: 1.08rem;
  font-weight: 850;
  letter-spacing: -0.02em;
}
.voucher-ticket__status {
  display: inline-flex;
  min-height: 30px;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 0.73rem;
  font-weight: 800;
  flex-wrap: wrap;
  justify-content: center;
  text-align: center;
}
.voucher-ticket__status time {
  font-variant-numeric: tabular-nums;
}
.voucher-ticket__status span {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}
.voucher-ticket__status.is-success {
  background: #ecfdf5;
  color: #047857;
}
.voucher-ticket__status.is-pending {
  background: #fffbeb;
  color: #a16207;
}
.voucher-ticket__status.is-muted {
  background: #f1f5f9;
  color: #64748b;
}
.voucher-ticket__summary {
  padding: 0 20px 18px;
}
.voucher-ticket__summary > strong {
  display: block;
  font-size: clamp(1.8rem, 9vw, 2.6rem);
  font-weight: 900;
  letter-spacing: -0.05em;
  line-height: 1;
}
.voucher-ticket__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
}
.voucher-ticket__facts span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 34px;
  padding: 6px 9px;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  color: #475569;
  font-size: 0.76rem;
  font-weight: 700;
}
.voucher-ticket__tear {
  position: relative;
  height: 1px;
  margin: 0 18px;
  border-top: 2px dashed #dbe4ec;
}
.voucher-ticket__tear::before,
.voucher-ticket__tear::after {
  position: absolute;
  top: 50%;
  width: 24px;
  height: 24px;
  border: 1px solid #dbe4ec;
  border-radius: 50%;
  background: #f8fafc;
  content: "";
  transform: translateY(-50%);
}
.voucher-ticket__tear::before {
  left: -31px;
}
.voucher-ticket__tear::after {
  right: -31px;
}
.voucher-ticket__body {
  padding: 18px 20px 20px;
}
.voucher-ticket__code-label {
  margin-bottom: 7px;
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}
.voucher-ticket__copy {
  display: flex;
  width: 100%;
  min-height: 58px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 12px 9px 15px;
  border: 1px solid #dbe4ec;
  border-radius: 14px;
  background: #f8fafc;
  color: #0f172a;
  text-align: left;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    transform 0.15s ease;
}
.voucher-ticket__copy:active {
  transform: scale(0.99);
}
.voucher-ticket__copy strong {
  overflow-wrap: anywhere;
  font-size: 1.05rem;
  letter-spacing: 0.07em;
}
.voucher-ticket__copy span {
  display: inline-flex;
  min-width: 84px;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 10px;
  background: #fff;
  color: #0f9675;
  font-size: 0.78rem;
  font-weight: 850;
}
.voucher-ticket__copy.is-copied {
  border-color: #86efac;
  background: #f0fdf4;
}
.voucher-ticket__pin,
.voucher-ticket__detail-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 2px;
  color: #64748b;
  font-size: 0.82rem;
}
.voucher-ticket__pin strong,
.voucher-ticket__detail-row strong {
  color: #0f172a;
}
.voucher-ticket__connect {
  display: flex;
  width: 100%;
  min-height: 54px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 8px;
  border-radius: 14px;
  font-weight: 850;
}
.voucher-ticket__details-toggle {
  display: flex;
  width: 100%;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 8px;
  padding: 8px 2px 0;
  border: 0;
  background: transparent;
  color: #334155;
  font-size: 0.84rem;
  font-weight: 800;
}
.voucher-ticket__details-toggle i {
  transition: transform 0.2s ease;
}
.voucher-ticket__details-toggle i.is-open {
  transform: rotate(180deg);
}
.voucher-ticket__details {
  padding-top: 8px;
  color: #64748b;
  font-size: 0.8rem;
  line-height: 1.5;
}
.voucher-ticket__details ul {
  margin: 0;
  padding-left: 1.1rem;
}
.voucher-ticket__details li + li {
  margin-top: 5px;
}
.voucher-ticket__detail-row {
  border-top: 1px solid #eef2f7;
}
.voucher-ticket__notice {
  display: flex;
  gap: 8px;
  margin-top: 8px;
  padding: 10px 12px;
  border-radius: 12px;
  background: #fffbeb;
  color: #92400e;
}
.voucher-details-enter-active,
.voucher-details-leave-active {
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}
.voucher-details-enter-from,
.voucher-details-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

@media (hover: hover) {
  .voucher-ticket:hover {
    transform: translateY(-2px);
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.13);
  }
}
@media (prefers-reduced-motion: reduce) {
  .voucher-ticket,
  .voucher-ticket__copy,
  .voucher-ticket__details-toggle i,
  .voucher-details-enter-active,
  .voucher-details-leave-active {
    transition: none !important;
  }
}
</style>
