<script setup lang="ts">
import { providePortalLanguage } from "../i18n/portalLanguage";
const { t, locale, toggleLanguage, languageLabel } = providePortalLanguage();
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { api } from "../api";
import { formatPhoneInput } from "../utils/formatPhoneInput";
import VoucherCard from "../components/vouchers/VoucherCard.vue";
import SignalEye from "../components/portal/SignalEye.vue";
import { voucherTimeLeft } from "../utils/voucherTime";

import VoucherAccessPanel from "../components/portal/VoucherAccessPanel.vue";
import { submitHotspotLogin as sendHotspotLogin } from "../utils/hotspotLogin";
const portalMode = ref<"home" | "buy" | "redeem" | "recovery">(
  new URLSearchParams(location.search).has("voucher-return")
    ? "recovery"
    : "home",
);
const recoveryDisplay = ref("");
const recoveryBusy = ref(false);
const recoveryError = ref("");
async function issueRecovery() {
  if (!order.value?.voucher?.uuid || recoveryBusy.value) return;
  recoveryBusy.value = true;
  recoveryError.value = "";
  try {
    const { data } = await api.post(
      `/public/vouchers/${order.value.voucher.uuid}/recovery-pin`,
    );
    recoveryDisplay.value = data.recovery_pin;
    order.value.voucher.recovery_issued = true;
    sessionStorage.removeItem("rjay_recovery_new_order");
  } catch (e: any) {
    if (e.response?.status === 409) {
      order.value.voucher.recovery_issued = true;
      sessionStorage.removeItem("rjay_recovery_new_order");
    }
    recoveryError.value =
      "Recovery PIN could not be displayed. If it was already issued, contact the operator to reset it.";
  } finally {
    recoveryBusy.value = false;
  }
}

type ConnectionState =
  | "idle"
  | "checking"
  | "connecting"
  | "preparing"
  | "provision_failed"
  | "router_unavailable"
  | "expired"
  | "unavailable"
  | "device_mismatch"
  | "connected"
  | "manual";

const plans = ref<any[]>([]);
const selected = ref<any>(null);
const checkoutDialog = ref<HTMLDialogElement | null>(null);
const phoneInput = ref<HTMLInputElement | null>(null);
const phone = ref("");
const checkoutStep = ref("");
const checkoutSubmitted = ref(false);
const loading = ref(false);
const booting = ref(true);
const restoreFailed = ref(false);
const restoreMessage = ref("");
const error = ref("");
const order = ref<any>(null);
const timer = ref<number | null>(null);
const connectionTimer = ref<number | null>(null);
const clockTimer = ref<number | null>(null);
const nowTick = ref(Date.now());
const connectionState = ref<ConnectionState>("idle");
const connectionBusy = ref(false);
const refreshBusy = ref(false);
const resendBusy = ref(false);
const connectionCheckBusy = ref(false);

let lastPrepareAt = 0;
let prepareAttempts = 0;

watch(
  () => order.value?.voucher?.uuid,
  (uuid) => {
    if (
      uuid &&
      !order.value.voucher.recovery_issued &&
      sessionStorage.getItem("rjay_recovery_new_order") === order.value.uuid
    )
      void issueRecovery();
  },
);

const orderStorageKey = "rjay_current_order";
const params = new URLSearchParams(location.search);
if (params.has('payment-return')) {
  try {
    const saved = JSON.parse(sessionStorage.getItem('rjay_hotspot_context') || '{}');
    for (const key of ['mac', 'link-login-only', 'link-orig']) {
      if (!params.has(key) && typeof saved[key] === 'string') params.set(key, saved[key]);
    }
  } catch { /* Missing context falls back to the existing manual connection flow. */ }
}
const deviceMac = params.get("mac") || "";
const linkLogin = params.get("link-login-only") || "";
const linkOrig = params.get("link-orig") || "";
const returnedFromLoginAt = params.get("connected") === "1" ? Date.now() : 0;

onMounted(async () => {
  document.documentElement.classList.add("portal-page");
  clockTimer.value = window.setInterval(() => {
    nowTick.value = Date.now();
    if (preparationTimedOut.value) stopPoll();
  }, 1000);

  try {
    plans.value = (await api.get("/public/plans")).data;
  } catch {
    error.value = "Packages are unavailable right now. Please try again.";
  }

  const savedOrder =
    params.get("order") || sessionStorage.getItem(orderStorageKey);
  if (savedOrder) {
    if (!params.has("voucher-return")) portalMode.value = "buy";
    try {
      order.value = (
        await api.get(`/public/orders/${encodeURIComponent(savedOrder)}`)
      ).data;

      if (params.get("connected") === "1") {
        connectionState.value = "checking";
        await verifyConnection();
      } else if (
        order.value.status === "completed" &&
        order.value.voucher &&
        linkLogin
      ) {
        await prepareConnection(true);
      }

      if (
        params.get("connected") !== "1" &&
        !preparationTimedOut.value &&
        !voucherUnavailable.value
      )
        startPoll();
    } catch (e: any) {
      restoreFailed.value = true;
      restoreMessage.value = [403, 404].includes(e.response?.status)
        ? "Hatujaweza kuthibitisha malipo kwenye kifaa hiki. Wasiliana na msimamizi kabla ya kulipa tena."
        : "Hatujaweza kukagua malipo sasa. Taarifa za malipo zimehifadhiwa kwenye kifaa hiki.";
    }
  }

  booting.value = false;
});

onUnmounted(() => {
  document.documentElement.classList.remove("portal-page");
  if (timer.value) clearInterval(timer.value);
  if (connectionTimer.value) clearInterval(connectionTimer.value);
  if (clockTimer.value) clearInterval(clockTimer.value);
});

function retryRestore() {
  window.location.reload();
}

async function choosePlan(plan: any) {
  selected.value = plan;
  error.value = "";
  phone.value = "";
  checkoutSubmitted.value = false;
  checkoutStep.value = "Weka namba ya malipo";
  await nextTick();
  if (!checkoutDialog.value?.open) checkoutDialog.value?.showModal();
  phoneInput.value?.focus();
}

function closeCheckout() {
  checkoutDialog.value?.close();
  selected.value = null;
}

function onPhoneInput(event: Event) {
  const input = event.target as HTMLInputElement;
  phone.value = formatPhoneInput(input.value);
  input.value = phone.value;
}

async function buy() {
  portalMode.value = "buy";
  if (
    !selected.value ||
    loading.value ||
    checkoutSubmitted.value ||
    !phoneValid.value
  )
    return;

  checkoutSubmitted.value = true;
  loading.value = true;
  error.value = "";
  checkoutStep.value = "Tunaandaa malipo…";

  try {
    const created = await api.post("/public/orders", {
      plan_id: selected.value.id,
      phone: normalizedPhone.value,
      device_mac: deviceMac || null,
    });

    order.value = created.data;
    sessionStorage.setItem("rjay_recovery_new_order", order.value.uuid);
    sessionStorage.setItem(orderStorageKey, order.value.uuid);
    if (order.value.access_token)
      sessionStorage.setItem("rjay_order_token", order.value.access_token);
    checkoutStep.value = "Opening secure checkout…";
    await requestPayment();
  } catch (e: any) {
    if (!order.value) checkoutSubmitted.value = false;
    if (order.value) startPoll();
    error.value =
      e.response?.status === 422
        ? "Hakiki namba yako ya simu."
        : e.response?.status === 502
          ? "Huduma ya malipo haipatikani sasa. Jaribu baadaye."
          : order.value
            ? "Imeshindikana kutuma ombi. Jaribu tena baada ya muda."
            : "Imeshindikana kuanza malipo. Jaribu tena.";
    checkoutStep.value = "Ombi halijatumwa";
  } finally {
    loading.value = false;
  }
}

async function requestPayment() {
  if (!order.value) return;
  const response = await api.post(
    `/public/orders/${encodeURIComponent(order.value.uuid)}/pay`,
  );
  order.value = response.data.order;
  if (response.data.checkout_url) {
    const checkout = new URL(response.data.checkout_url);
    if (checkout.protocol !== 'https:' || checkout.username || checkout.password) throw new Error('Invalid checkout URL');
    sessionStorage.setItem('rjay_hotspot_context', JSON.stringify({ mac: deviceMac, 'link-login-only': linkLogin, 'link-orig': linkOrig }));
    window.location.assign(checkout.href);
    return;
  }
  startPoll();
}

async function continuePayment() {
  if (loading.value) return;
  loading.value = true;
  error.value = "";
  try {
    await requestPayment();
  } catch (e: any) {
    if (e.response?.status === 409) await refresh();
    else
      error.value =
        e.response?.status === 502
          ? "Huduma ya malipo haipatikani sasa. Jaribu baadaye."
          : e.response?.status === 429
            ? "Ombi jingine linaendelea. Subiri kidogo."
            : "Imeshindikana kutuma ombi. Jaribu tena baada ya muda.";
  } finally {
    loading.value = false;
  }
}

const pushSecondsLeft = computed(() => {
  const expires = Date.parse(order.value?.payment_push_expires_at || "");
  return Number.isFinite(expires)
    ? Math.max(0, Math.ceil((expires - nowTick.value) / 1000))
    : null;
});
const pushCountdown = computed(() => {
  const seconds = pushSecondsLeft.value ?? 0;
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
});

async function resendPush() {
  if (!order.value || resendBusy.value || pushSecondsLeft.value !== 0) return;
  resendBusy.value = true;
  error.value = "";
  try {
    order.value = (
      await api.post(
        `/public/orders/${encodeURIComponent(order.value.uuid)}/resend-push`,
      )
    ).data;
  } catch (e: any) {
    error.value =
      e.response?.status === 429
        ? "Subiri muda wa kusubiri umalizike."
        : "Ombi halijatumwa tena. Hakiki hali ya malipo kisha ujaribu tena.";
    await refresh();
  } finally {
    resendBusy.value = false;
  }
}

function startPoll() {
  if (timer.value) clearInterval(timer.value);
  timer.value = window.setInterval(() => {
    void refresh();
  }, 3000);
}

function startConnectionPoll() {
  if (connectionTimer.value) clearInterval(connectionTimer.value);
  connectionTimer.value = window.setInterval(() => {
    void verifyConnection();
  }, 10000);
}

function stopConnectionPoll() {
  if (connectionTimer.value) clearInterval(connectionTimer.value);
  connectionTimer.value = null;
}

async function verifyConnection() {
  if (!order.value?.uuid || connectionCheckBusy.value) return;
  connectionCheckBusy.value = true;
  const wasConnected = connectionState.value === "connected";
  try {
    const response = await api.get(
      `/public/orders/${encodeURIComponent(order.value.uuid)}/connection`,
      {
        params: { device_mac: deviceMac || undefined },
      },
    );
    const state =
      response.data.state === "active_other_device"
        ? "device_mismatch"
        : response.data.state === "unknown"
          ? "manual"
          : response.data.state;
    if (state === "online") {
      connectionState.value = "connected";
      stopPoll();
      startConnectionPoll();
      return;
    }
    connectionState.value =
      state === "offline"
        ? wasConnected ||
          (returnedFromLoginAt && Date.now() - returnedFromLoginAt > 45000)
          ? "manual"
          : "checking"
        : state;
  } catch (e: any) {
    connectionState.value =
      e.response?.data?.state === "router_unavailable"
        ? "router_unavailable"
        : "checking";
  } finally {
    connectionCheckBusy.value = false;
  }
  stopConnectionPoll();
  stopPoll();
  if (connectionState.value === "checking") startPoll();
  else if (connectionState.value === "router_unavailable")
    startConnectionPoll();
}

async function refresh() {
  if (!order.value || refreshBusy.value) return;
  refreshBusy.value = true;

  try {
    const wasCompleted = completed.value;
    order.value = (
      await api.get(`/public/orders/${encodeURIComponent(order.value.uuid)}`)
    ).data;

    if (
      new URLSearchParams(location.search).get("connected") === "1" &&
      ["checking", "router_unavailable"].includes(connectionState.value)
    ) {
      await verifyConnection();
    } else if (
      returnedFromLoginAt &&
      Date.now() - returnedFromLoginAt > 45000 &&
      connectionState.value === "checking"
    ) {
      connectionState.value = "manual";
    }

    if (
      paid.value &&
      !preparationTimedOut.value &&
      (!order.value.voucher ||
        order.value.voucher.status === "provision_pending") &&
      prepareAttempts < 3 &&
      Date.now() - lastPrepareAt > 20000
    ) {
      await prepareConnection(true);
    } else if (
      !wasCompleted &&
      completed.value &&
      linkLogin &&
      connectionState.value !== "connected"
    ) {
      await prepareConnection(true);
    }

    if (
      order.value.payment?.status === "failed" ||
      order.value.payment?.status === "expired"
    ) {
      stopPoll();
    }
    if (preparationTimedOut.value || voucherUnavailable.value) stopPoll();
  } catch {
    error.value =
      "We could not refresh your purchase. We will keep your order safe so you can try again.";
  } finally {
    refreshBusy.value = false;
  }
}

function stopPoll() {
  if (timer.value) clearInterval(timer.value);
  timer.value = null;
}

async function prepareConnection(automatic = false) {
  if (automatic && portalMode.value !== "buy") return;
  if (
    !order.value ||
    connectionBusy.value ||
    recoveryBusy.value ||
    recoveryDisplay.value
  )
    return;
  if (
    order.value.voucher &&
    !order.value.voucher.recovery_issued &&
    sessionStorage.getItem("rjay_recovery_new_order") === order.value.uuid
  ) {
    await issueRecovery();
    return;
  }

  lastPrepareAt = Date.now();
  connectionBusy.value = true;
  connectionState.value = "checking";

  try {
    const response = await api.post(
      `/public/orders/${encodeURIComponent(order.value.uuid)}/prepare-connection`,
      {
        device_mac: deviceMac || null,
        login_url: linkLogin || null,
      },
    );
    const state = response.data.state as ConnectionState | "ready" | "active";

    if (state === "preparing") {
      prepareAttempts += 1;
      connectionState.value =
        prepareAttempts >= 3 ? "provision_failed" : "preparing";
    } else if (state === "expired") connectionState.value = "expired";
    else if (state === "unavailable") connectionState.value = "unavailable";
    else if (state === "device_mismatch")
      connectionState.value = "device_mismatch";
    else if (state === "ready" || state === "active") {
      prepareAttempts = 0;
      if (!order.value.voucher) {
        order.value = (
          await api.get(
            `/public/orders/${encodeURIComponent(order.value.uuid)}`,
          )
        ).data;
      }
      if (response.data.login_url && order.value.voucher?.password) {
        connectionState.value = "connecting";
        submitHotspotLogin(response.data.login_url);
      } else {
        connectionState.value = "manual";
      }
    }
  } catch (e: any) {
    const state = e.response?.data?.state;
    connectionState.value = [
      "router_unavailable",
      "expired",
      "unavailable",
      "device_mismatch",
      "preparing",
    ].includes(state)
      ? (state as ConnectionState)
      : "manual";
    if (!automatic)
      error.value =
        "We could not connect automatically. Your voucher and payment are safe.";
  } finally {
    connectionBusy.value = false;
  }
}

function submitHotspotLogin(loginUrl: string) {
  if (!order.value?.voucher) return;

  const returnUrl = new URL(location.href);
  returnUrl.searchParams.set("order", order.value.uuid);
  returnUrl.searchParams.set("connected", "1");
  sendHotspotLogin(loginUrl, order.value.voucher, returnUrl);
}

function startNewPurchase() {
  recoveryDisplay.value = "";
  recoveryError.value = "";
  sessionStorage.removeItem("rjay_recovery_new_order");
  sessionStorage.removeItem(orderStorageKey);
  sessionStorage.removeItem("rjay_order_token");
  order.value = null;
  error.value = "";
  connectionState.value = "idle";
  prepareAttempts = 0;
  stopPoll();
  stopConnectionPoll();

  const url = new URL(location.href);
  url.searchParams.delete("order");
  url.searchParams.delete("connected");
  history.replaceState(null, "", url.toString());
}

function durationLabel(plan: any) {
  const seconds = Number(plan?.duration_seconds || 0);
  if (!seconds) return "Flexible access";
  if (seconds % 604800 === 0)
    return `${seconds / 604800} week${seconds === 604800 ? "" : "s"}`;
  if (seconds % 86400 === 0)
    return `${seconds / 86400} day${seconds === 86400 ? "" : "s"}`;
  if (seconds % 3600 === 0)
    return `${seconds / 3600} hour${seconds === 3600 ? "" : "s"}`;
  return `${Math.round(seconds / 60)} min`;
}

function speedLabel(rate: string | null | undefined) {
  if (!rate) return "Standard speed";
  const first = rate.split("/")[0]?.trim() || rate;
  const match = first.match(/^([\d.]+)M$/i);
  return match ? `${match[1]} Mbps` : rate;
}

function dataLabel(bytes: number | null | undefined) {
  if (!bytes) return null;
  return `${Number((bytes / 1048576).toFixed(1)).toLocaleString()} MB`;
}

const phoneDigits = computed(() => phone.value.replace(/\D/g, ""));
const normalizedPhone = computed(() => {
  const digits = phoneDigits.value;
  if (/^0[67]\d{8}$/.test(digits)) return `255${digits.slice(1)}`;
  if (/^[67]\d{8}$/.test(digits)) return `255${digits}`;
  if (/^255[67]\d{8}$/.test(digits)) return digits;
  return null;
});
const phoneValid = computed(() => normalizedPhone.value !== null);
const completed = computed(
  () =>
    paid.value && ["ready", "active"].includes(order.value?.voucher?.status),
);
const paid = computed(() =>
  Boolean(
    order.value?.paid_at ||
    ["paid", "provisioning", "completed"].includes(order.value?.status),
  ),
);
const voucherUnavailable = computed(() =>
  ["disabled", "revoked", "expired"].includes(order.value?.voucher?.status),
);
const preparationTimedOut = computed(() => {
  if (!paid.value || completed.value) return false;
  const paidAt = Date.parse(order.value?.paid_at || "");
  return (
    Boolean(order.value?.preparation_timed_out) ||
    (Number.isFinite(paidAt) && nowTick.value >= paidAt + 6 * 60 * 1000)
  );
});
const paymentFailed = computed(() =>
  ["failed", "expired", "voided"].includes(order.value?.payment?.status),
);
const paymentRequestMissing = computed(() =>
  Boolean(
    order.value && !paid.value && (!order.value.payment || paymentFailed.value),
  ),
);
const paymentAwaitingPin = computed(
  () =>
    !paymentRequestMissing.value &&
    !paymentFailed.value &&
    pushSecondsLeft.value !== null &&
    pushSecondsLeft.value > 0,
);

const selectedPrice = computed(() =>
  Number(selected.value?.price || 0).toLocaleString(),
);
const currentPlan = computed(() => order.value?.plan || selected.value);

const portalStage = computed(() => {
  if (booting.value) return "restoring";
  if (restoreFailed.value) return "restore_error";
  if (!order.value) return "choose";
  if (!paid.value) return paymentFailed.value ? "payment_failed" : "paying";
  if (voucherUnavailable.value || preparationTimedOut.value)
    return "preparation_failed";
  if (!completed.value) return "preparing";
  if (connectionState.value === "connected") return "online";
  return "connecting";
});
const connectionWaiting = computed(() =>
  ["checking", "connecting", "preparing", "idle"].includes(
    connectionState.value,
  ),
);
const connectionProblem = computed(() =>
  [
    "expired",
    "unavailable",
    "device_mismatch",
    "provision_failed",
    "router_unavailable",
  ].includes(connectionState.value),
);

const paymentServiceUnavailable = computed(() =>
  error.value.includes("Huduma ya malipo haipatikani"),
);
const hostedCheckout = computed(() => !order.value?.payment || order.value.payment.provider === 'snippe' && !!order.value.payment.session_reference);
const paymentTitle = computed(() => {
  if (hostedCheckout.value) return loading.value ? 'Opening secure checkout…' : 'Waiting for payment confirmation';
  if (loading.value || resendBusy.value) return "Tunatuma ombi…";
  if (paymentServiceUnavailable.value) return "Malipo hayapatikani";
  if (paymentFailed.value) return "Malipo hayajakamilika";
  if (paymentRequestMissing.value) return "Tuma ombi la malipo";
  if (pushSecondsLeft.value === 0) return "Bado hatujapokea malipo";
  if (paymentAwaitingPin.value) return "Weka PIN kwenye simu";
  return "Tunakagua malipo…";
});

const paymentMessage = computed(() => {
  if (hostedCheckout.value) return error.value || 'Complete payment on Snippe. This page updates after confirmation.';
  if (paymentServiceUnavailable.value) return "Jaribu tena baadaye.";
  if (error.value) return error.value;
  if (paymentAwaitingPin.value) return "";
  if (pushSecondsLeft.value === 0) return "Umeshalipa? Subiri uthibitisho.";
  return "";
});

const portalEyeStatus = computed<
  "idle" | "loading" | "waiting" | "success" | "error"
>(() => {
  if (
    booting.value ||
    loading.value ||
    resendBusy.value ||
    recoveryBusy.value ||
    connectionBusy.value ||
    refreshBusy.value
  )
    return "loading";
  if (portalStage.value === "online") return "success";
  if (
    restoreFailed.value ||
    paymentFailed.value ||
    paymentServiceUnavailable.value ||
    connectionProblem.value ||
    preparationTimedOut.value ||
    error.value
  )
    return "error";
  if (paymentAwaitingPin.value || (order.value && !paid.value))
    return "waiting";
  if (
    (paid.value && !completed.value) ||
    (portalStage.value === "connecting" && connectionWaiting.value)
  )
    return "loading";
  return "idle";
});

const connectionTitle = computed(() => {
  const titles: Record<ConnectionState, string> = {
    idle: "Preparing your internet",
    checking: "Checking your access",
    connecting: "Connecting you to Wi-Fi",
    preparing: "Preparing your internet",
    provision_failed: "Connection needs attention",
    router_unavailable: "Wi-Fi temporarily unavailable",
    expired: "This voucher has expired",
    unavailable: "Voucher unavailable",
    device_mismatch: "Voucher already in use",
    connected: "You're online",
    manual: "One tap left",
  };
  return titles[connectionState.value];
});

const connectionMessage = computed(() => {
  const messages: Record<ConnectionState, string> = {
    idle: "Payment received. We are preparing your access now.",
    checking: "Validating your voucher and network access…",
    connecting: "Your voucher is ready. Signing this device into the HotSpot…",
    preparing: "Payment received. Your access is being prepared automatically.",
    provision_failed:
      "Your payment is safe. Internet setup is taking longer than expected; you do not need to pay again.",
    router_unavailable:
      "Your payment is safe. The router is temporarily unavailable; you do not need to pay again.",
    expired: "Choose a new package to get back online.",
    unavailable:
      "This voucher cannot be used right now. Please contact support.",
    device_mismatch:
      "This voucher is already linked to another device. You need a separate package for this device.",
    connected: "Internet access is active on this device.",
    manual:
      "Open this page from the Wi-Fi login screen on this device to connect automatically. Your voucher is available below.",
  };
  return messages[connectionState.value];
});

const isRecoverableConnection = computed(() =>
  [
    "manual",
    "router_unavailable",
    "preparing",
    "provision_failed",
    "idle",
  ].includes(connectionState.value),
);

const remainingLabel = computed(() => {
  const expiresAt = order.value?.voucher?.expires_at;
  return voucherTimeLeft(expiresAt, nowTick.value) || "Unavailable";
});

const browsingDestination = computed(() => {
  try {
    const url = new URL(linkOrig);
    return ["http:", "https:"].includes(url.protocol) &&
      !url.username &&
      !url.password
      ? url.href
      : "/";
  } catch {
    return "/";
  }
});
</script>

<template>
  <div class="portal-shell" :lang="locale">
    <div class="portal-wrap">
      <header class="mobile-brand">
        <button
          class="mobile-brand__eye"
          :aria-label="t('Use a voucher')"
          @click="portalMode = 'redeem'"
        >
          <SignalEye :status="portalEyeStatus" />
        </button>
        <div>
          <h1>MWANAKITAA <span>KITONGA</span></h1>
        </div>
        <button
          type="button"
          class="portal-language"
          :aria-label="languageLabel"
          :title="languageLabel"
          @click="toggleLanguage"
        >
          {{ locale === "sw" ? "EN" : "SW" }}
        </button>
      </header>
      <main>
        <section class="access-shell">
          <div
            class="access-tabs"
            role="group"
            :aria-label="t('Voucher access')"
          >
            <button
              :class="{ active: portalMode !== 'recovery' }"
              :aria-pressed="portalMode !== 'recovery'"
              @click="portalMode = 'redeem'"
            >
              <i class="bi bi-wifi" aria-hidden="true"></i>
              {{ t("Use voucher") }}
            </button>
            <button
              :class="{ active: portalMode === 'recovery' }"
              :aria-pressed="portalMode === 'recovery'"
              @click="portalMode = 'recovery'"
            >
              <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
              {{ t("My vouchers") }}
            </button>
          </div>
          <VoucherAccessPanel
            v-if="!order || portalMode !== 'buy'"
            :key="portalMode === 'recovery' ? 'recovery' : 'redeem'"
            :mode="portalMode === 'recovery' ? 'recovery' : 'redeem'"
            compact
            @buy="
              portalMode = 'home';
              startNewPurchase();
            "
          />
          <button
            v-else-if="order.voucher"
            class="btn btn-link w-100"
            @click="portalMode = 'recovery'"
          >
            {{ t("My voucher") }}
          </button>
        </section>
        <div class="purchase-flow">
          <section
            v-if="
              recoveryDisplay ||
              recoveryError ||
              (order?.voucher && !order.voucher.recovery_issued)
            "
            class="portal-state-card mb-3"
            aria-live="polite"
          >
            <template v-if="recoveryDisplay"
              ><h2 class="h4">
                {{ t("Recovery PIN:") }} {{ recoveryDisplay }}
              </h2>
              <p>
                {{
                  t(
                    "Keep this recovery PIN. You can use it to recover this voucher later.",
                  )
                }}
              </p>
              <button
                class="btn btn-primary"
                @click="
                  recoveryDisplay = '';
                  prepareConnection();
                "
              >
                {{ t("I have saved it — Continue") }}
              </button></template
            >
            <template v-else
              ><p v-if="recoveryError">{{ t(recoveryError) }}</p>
              <p>{{ t("Save a recovery PIN to find this voucher later.") }}</p>
              <button
                class="btn btn-outline-primary"
                :disabled="recoveryBusy"
                @click="issueRecovery"
              >
                {{ t(recoveryBusy ? "Preparing…" : "Get recovery PIN once") }}
              </button></template
            >
          </section>
          <section
            v-if="portalStage === 'restoring'"
            class="portal-state-card"
            aria-live="polite"
          >
            <div class="status-eye"><SignalEye status="loading" /></div>
            <h1>{{ t("Tunakagua malipo yako") }}</h1>
            <p>{{ t("Tafadhali subiri…") }}</p>
          </section>

          <section
            v-else-if="portalStage === 'restore_error'"
            class="portal-state-card"
            aria-live="polite"
          >
            <div class="status-eye"><SignalEye status="error" /></div>
            <h1>{{ t("Hatujaweza kukagua malipo") }}</h1>
            <p>{{ t(restoreMessage) }}</p>
            <button class="primary-action mt-4" @click="retryRestore">
              {{ t("Jaribu tena") }}
            </button>
          </section>

          <section
            v-else-if="portalStage === 'choose'"
            class="portal-purchase-card"
          >
            <div class="plans-heading">
              <h2>{{ t("Buy internet") }}</h2>
              <span> {{ t("Chagua kifurushi") }} </span>
            </div>

            <div
              v-if="!plans.length && !error"
              class="plan-list"
              :aria-label="t('Loading packages')"
            >
              <div v-for="n in 3" :key="n" class="plan-skeleton">
                <span></span><span></span><span></span>
              </div>
            </div>

            <div v-else class="plan-list" :aria-label="t('Internet packages')">
              <button
                v-for="plan in plans"
                :key="plan.id"
                type="button"
                class="plan-option"
                :class="{
                  selected: selected?.id === plan.id,
                  recommended: plan.recommended,
                }"
                :aria-label="`${plan.name}, TZS ${Number(plan.price).toLocaleString()}. ${t('Enter your payment number')}`"
                @click="choosePlan(plan)"
              >
                <del
                  v-if="Number(plan.original_price) > Number(plan.price)"
                  class="plan-original-price"
                  :aria-label="t('Bei ya awali')"
                  >TZS {{ Number(plan.original_price).toLocaleString() }}</del
                >
                <span
                  v-if="plan.recommended"
                  class="plan-option__recommended"
                  >{{ t("Popular") }}</span
                >
                <div class="plan-option__main">
                  <div class="plan-option__title-row">
                    <strong>{{ plan.name }}</strong>
                  </div>
                  <div class="plan-option__meta">
                    <span
                      ><i class="bi bi-clock" aria-hidden="true"></i
                      >{{ t(durationLabel(plan)) }}</span
                    >
                    <span
                      ><i class="bi bi-speedometer2" aria-hidden="true"></i
                      >{{ t(speedLabel(plan.rate_limit)) }}</span
                    >
                    <span v-if="dataLabel(plan.data_limit_bytes)"
                      ><i class="bi bi-database" aria-hidden="true"></i
                      >{{ dataLabel(plan.data_limit_bytes) }}</span
                    >
                  </div>
                </div>
                <div class="plan-option__price">
                  <strong>{{ Number(plan.price).toLocaleString() }}</strong
                  ><span>TZS</span>
                  <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                </div>
              </button>
            </div>

            <div
              v-if="!plans.length && error"
              class="alert alert-danger mt-3"
              role="alert"
            >
              {{ t(error) }}
            </div>

            <dialog
              v-if="selected"
              ref="checkoutDialog"
              class="checkout-dialog border-0 p-0"
              @cancel="loading ? $event.preventDefault() : closeCheckout()"
              aria-labelledby="checkout-title"
            >
              <div class="checkout-card card border-0 p-4">
                <div
                  class="d-flex justify-content-between align-items-start gap-3 mb-3"
                >
                  <div>
                    <h2 id="checkout-title" class="h4 mb-1">
                      {{ selected?.name }}
                    </h2>
                    <p class="text-secondary mb-0">
                      <span class="price">TZS {{ selectedPrice }}</span> ·
                      {{ t(durationLabel(selected)) }}
                    </p>
                  </div>
                  <button
                    type="button"
                    class="btn-close"
                    :aria-label="t('Funga')"
                    :disabled="loading"
                    @click="closeCheckout"
                  ></button>
                </div>
                <form @submit.prevent="buy">
                  <div class="mb-3">
                    <label class="form-label" for="customer-phone">
                      {{ t("Namba ya kulipia") }} </label
                    ><input
                      id="customer-phone"
                      ref="phoneInput"
                      :value="phone"
                      @input="onPhoneInput"
                      class="form-control form-control-lg"
                      inputmode="numeric"
                      pattern="[0-9 ]*"
                      autocomplete="tel"
                      required
                      placeholder="255 7XX XXX XXX"
                      aria-describedby="phone-help"
                      :disabled="loading || checkoutSubmitted"
                    /><small id="phone-help" class="text-secondary">{{
                      t(
                        phone.length && !phoneValid
                          ? "Hakiki namba ya simu."
                          : "Continue to secure checkout.",
                      )
                    }}</small>
                  </div>
                  <div v-if="error" class="alert alert-danger" role="alert">
                    {{ t(error) }}
                  </div>
                  <p
                    v-if="(loading || checkoutSubmitted) && !error"
                    class="checkout-status mb-0"
                    role="status"
                    aria-live="polite"
                  >
                    <span v-if="loading" class="inline-eye"
                      ><SignalEye status="loading"
                    /></span>
                    {{ t(checkoutStep) }}
                  </p>
                  <button v-if="!order" type="submit" class="primary-action w-100 mt-3" :disabled="loading || !phoneValid">{{ t(loading ? 'Preparing…' : 'Pay') }}</button>
                  <button
                    v-if="error && order"
                    type="button"
                    class="btn btn-outline-primary mt-2"
                    @click="order ? continuePayment() : buy()"
                  >
                    {{ t("Jaribu tena") }}
                  </button>
                </form>
              </div>
            </dialog>
          </section>

          <section
            v-else-if="
              portalStage === 'paying' || portalStage === 'payment_failed'
            "
            class="portal-state-card payment-card"
            aria-live="polite"
            :aria-busy="loading || resendBusy"
          >
            <div class="payment-summary">
              <span>{{ order?.plan?.name || selected?.name }}</span>
              <strong
                >TZS
                {{
                  Number(order?.amount || selected?.price || 0).toLocaleString()
                }}</strong
              >
            </div>
            <div class="status-eye">
              <SignalEye :status="portalEyeStatus" />
            </div>
            <h1>{{ t(paymentTitle) }}</h1>
            <p
              v-if="paymentMessage"
              class="payment-message"
              :role="error ? 'alert' : undefined"
            >
              {{ t(paymentMessage) }}
            </p>
            <div
              v-if="paymentAwaitingPin && !hostedCheckout"
              class="payment-countdown"
              :aria-label="t('Muda uliobaki')"
            >
              {{ pushCountdown }}
            </div>
            <button
              v-if="paymentRequestMissing || hostedCheckout"
              class="primary-action"
              :disabled="loading"
              @click="continuePayment"
            >
              <span v-if="loading" class="inline-eye"
                ><SignalEye status="loading"
              /></span>
              {{
                t(
                  loading
                    ? "Subiri…"
                    : hostedCheckout
                      ? "Continue to payment"
                    : error || paymentFailed
                      ? "Jaribu tena"
                      : "Tuma ombi",
                )
              }}
              <i
                v-if="!loading"
                class="bi bi-arrow-right"
                aria-hidden="true"
              ></i>
            </button>
            <button
              v-else-if="pushSecondsLeft === 0 && !hostedCheckout"
              class="primary-action"
              :disabled="resendBusy"
              @click="resendPush"
            >
              <span v-if="resendBusy" class="inline-eye"
                ><SignalEye status="loading"
              /></span>
              {{ t(resendBusy ? "Subiri…" : "Tuma tena") }}
            </button>
          </section>

          <section
            v-else-if="portalStage === 'preparing'"
            class="portal-state-card"
            aria-live="polite"
          >
            <div class="status-eye"><SignalEye status="loading" /></div>
            <div class="state-eyebrow">{{ t("Malipo yamepokelewa") }}</div>
            <h1>{{ t("Tunaandaa intaneti yako") }}</h1>
            <p>{{ t("Tafadhali subiri, inaweza kuchukua hadi dakika 6.") }}</p>
            <div class="connection-progress"><span></span></div>
          </section>

          <section
            v-else-if="portalStage === 'preparation_failed'"
            class="portal-state-card"
            aria-live="polite"
          >
            <div class="status-eye"><SignalEye status="error" /></div>
            <div class="state-eyebrow">{{ t("Malipo yamepokelewa") }}</div>
            <h1>
              {{
                t(
                  voucherUnavailable
                    ? "Huduma ya Wi-Fi haipatikani sasa"
                    : "Maandalizi yanachukua muda",
                )
              }}
            </h1>
            <p>
              {{
                t(
                  voucherUnavailable
                    ? "Voucher hii haipatikani tena. Tafadhali wasiliana na msimamizi."
                    : "Malipo yamethibitishwa. Intaneti haijawa tayari; usilipe tena.",
                )
              }}
            </p>
            <button
              class="primary-action mt-4"
              :disabled="refreshBusy"
              @click="refresh"
            >
              <span v-if="refreshBusy" class="inline-eye"
                ><SignalEye status="loading"
              /></span>
              {{ t(refreshBusy ? "Tunakagua…" : "Kagua tena") }}
            </button>
            <p v-if="error" class="portal-alert danger" role="alert">
              {{ t(error) }}
            </p>
          </section>

          <section
            v-else-if="portalStage === 'connecting'"
            class="portal-state-card connection-card"
            aria-live="polite"
          >
            <div class="status-eye">
              <SignalEye :status="portalEyeStatus" />
            </div>
            <div class="state-eyebrow">{{ order?.plan?.name }}</div>
            <h1>{{ t(connectionTitle) }}</h1>
            <p>{{ t(connectionMessage) }}</p>

            <div v-if="connectionWaiting" class="connection-progress">
              <span></span>
            </div>

            <button
              v-if="isRecoverableConnection"
              class="primary-action"
              :disabled="connectionBusy"
              @click="prepareConnection()"
            >
              <span v-if="connectionBusy" class="inline-eye"
                ><SignalEye status="loading"
              /></span>
              <span v-else class="inline-eye"
                ><SignalEye :status="portalEyeStatus"
              /></span>
              {{
                t(
                  connectionState === "manual"
                    ? "Connect now"
                    : "Try connection again",
                )
              }}
            </button>

            <button
              v-if="
                connectionState === 'expired' ||
                connectionState === 'device_mismatch'
              "
              class="primary-action"
              @click="startNewPurchase"
            >
              {{ t("Buy another package") }}
            </button>

            <div v-if="order?.voucher" class="voucher-secondary">
              <p v-if="order.masked_phone">
                {{ t("Linked phone:") }} {{ order.masked_phone }}
              </p>
              <VoucherCard
                :voucher="{
                  ...order.voucher,
                  device_mac: order.voucher.masked_device_mac,
                }"
                :plan="order.plan"
                :now-ms="nowTick"
                :show-connect="false"
                compact
              />
            </div>
          </section>

          <section
            v-else-if="portalStage === 'online'"
            class="portal-online"
            aria-live="polite"
          >
            <div class="online-hero">
              <div class="status-eye"><SignalEye status="success" /></div>
              <div class="state-eyebrow">{{ t("Connected successfully") }}</div>
              <h1>{{ t("You’re online") }}</h1>
              <p>{{ t("Internet access is active on this device.") }}</p>
            </div>

            <div class="online-summary">
              <div>
                <span> {{ t("Plan") }} </span>
                <strong>{{ order?.plan?.name }}</strong>
              </div>
              <div>
                <span> {{ t("Package time left") }} </span>
                <strong>{{ t(remainingLabel) }}</strong>
              </div>
              <div>
                <span> {{ t("Speed") }} </span>
                <strong>{{ t(speedLabel(order?.plan?.rate_limit)) }}</strong>
              </div>
            </div>

            <a
              class="primary-action primary-action--link"
              :href="browsingDestination"
            >
              {{ t("Continue browsing") }}
              <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>

            <div class="voucher-secondary">
              <p v-if="order?.masked_phone">
                {{ t("Linked phone:") }} {{ order.masked_phone }}
              </p>
              <VoucherCard
                :voucher="{
                  ...order.voucher,
                  device_mac: order.voucher.masked_device_mac,
                }"
                :plan="order.plan"
                :now-ms="nowTick"
                :show-connect="false"
                compact
              />
            </div>
          </section>
        </div>
      </main>

      <footer class="portal-footer">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        {{ t("Malipo salama · Voucher moja kwa kifaa kimoja") }}
      </footer>
    </div>
  </div>
</template>

<style scoped>
.portal-shell {
  --ink: #0f172a;
  --muted: #64748b;
  --line: #e2e8f0;
  --accent: #0f9675;
  --accent-dark: #08745b;
  min-height: 100vh;
  background: #eef3f9;
  color: var(--ink);
  padding: 0 0 24px;
}
.portal-wrap {
  width: min(100%, 960px);
  margin: 0 auto;
  padding: 0 10px;
}
.portal-header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 12px;
  min-height: 92px;
  color: var(--ink);
}
.portal-logo {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 14px;
  background: #eef3f9;
  box-shadow:
    4px 4px 9px #cbd5df,
    -4px -4px 9px #fff;
  color: var(--accent-dark);
  font-size: 1.1rem;
}
.portal-brand {
  font-size: 1.05rem;
  font-weight: 900;
  letter-spacing: -0.025em;
}
.portal-kicker {
  margin-top: 2px;
  color: var(--muted);
  font-size: 0.76rem;
}
.portal-secure {
  display: inline-flex;
  min-height: 36px;
  align-items: center;
  gap: 6px;
  padding: 7px 10px;
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 999px;
  color: var(--accent-dark);
  background: #eef3f9;
  box-shadow:
    4px 4px 9px #cbd5df,
    -4px -4px 9px #fff;
  font-size: 0.72rem;
  font-weight: 750;
}
.checkout-dialog {
  max-width: 430px;
  max-height: min(90dvh, 720px);
  overflow-y: auto;
  border-radius: 24px;
  background: #eef3f9;
  box-shadow:
    12px 12px 30px #8796a8,
    -12px -12px 30px #fff;
}
.checkout-dialog::backdrop {
  background: rgba(15, 23, 42, 0.58);
}
.checkout-card {
  background: #eef3f9;
  box-shadow: none;
}
.checkout-eyebrow {
  color: var(--accent-dark);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.checkout-card .form-control {
  border: 1px solid #d5dfe9;
  border-radius: 14px;
  background: #eef3f9;
  box-shadow:
    inset 3px 3px 7px #cbd5df,
    inset -3px -3px 7px #fff;
}
.checkout-card .form-control:focus {
  border-color: var(--accent);
  box-shadow:
    inset 3px 3px 7px #cbd5df,
    0 0 0 3px rgba(15, 150, 117, 0.14);
}
.checkout-status {
  display: flex;
  align-items: center;
  gap: 9px;
  color: var(--accent-dark);
  font-size: 0.83rem;
  font-weight: 700;
}

.portal-purchase-card,
.portal-state-card,
.portal-online {
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 24px;
  background: #eef3f9;
  box-shadow:
    10px 10px 24px #cbd5df,
    -10px -10px 24px #fff;
}
.portal-purchase-card {
  padding: 24px 16px 20px;
}
.portal-section-heading {
  text-align: center;
}
.portal-hero-icon {
  display: grid;
  width: 70px;
  height: 70px;
  place-items: center;
  margin: 0 auto 18px;
  border-radius: 22px;
  background: #eef3f9;
  box-shadow:
    6px 6px 13px #cbd5df,
    -6px -6px 13px #fff;
  color: var(--accent-dark);
  font-size: 1.8rem;
}
.portal-section-heading .portal-eyebrow,
.state-eyebrow {
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.portal-section-heading h1,
.portal-state-card h1,
.online-hero h1 {
  margin: 5px 0 0;
  font-size: clamp(1.7rem, 8vw, 2.45rem);
  font-weight: 900;
  letter-spacing: -0.045em;
  line-height: 1.04;
}
.portal-section-heading p,
.portal-state-card p,
.online-hero p {
  margin: 8px 0 0;
  color: var(--muted);
  line-height: 1.55;
}

.plan-list {
  display: grid;
  gap: 14px;
  margin-top: 24px;
}
.plan-option {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 16px;
  width: 100%;
  min-height: 96px;
  padding: 15px 16px;
  border: 2px solid rgba(255, 255, 255, 0.9);
  border-radius: 18px;
  background: #eef3f9;
  box-shadow:
    5px 5px 11px #cbd5df,
    -5px -5px 11px #fff;
  color: var(--ink);
  text-align: left;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    transform 0.15s ease,
    box-shadow 0.15s ease;
  -webkit-tap-highlight-color: transparent;
}
.plan-option:active {
  transform: scale(0.97);
}
.plan-option:focus-visible,
.primary-action:focus-visible,
.secondary-action:focus-visible {
  outline: 3px solid var(--accent);
  outline-offset: 3px;
}
.plan-option:active .plan-option__price i,
.plan-option:focus-visible .plan-option__price i {
  transform: translateX(4px);
}
.plan-option .bi,
.primary-action .bi,
.secondary-action .bi {
  transition:
    transform 0.2s ease,
    color 0.2s ease;
}
.plan-option:active .plan-option__meta .bi,
.plan-option:focus-visible .plan-option__meta .bi {
  transform: scale(1.18);
  color: var(--accent-dark);
}
.plan-option.selected,
.plan-option.selected.recommended {
  border-color: var(--accent);
  background: #e7f6f1;
  box-shadow:
    inset 3px 3px 7px #cbd5df,
    inset -3px -3px 7px #fff;
}
.plan-option.recommended {
  border-color: #b7d9ce;
}
.plan-option__recommended {
  border-radius: 999px;
  padding: 3px 7px;
  background: #e9d895;
  color: #443410;
  font-size: 0.65rem;
  font-weight: 800;
}
.plan-option__title-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.plan-option__title-row strong {
  font-size: 1rem;
  font-weight: 850;
}
.plan-option__check {
  display: grid;
  width: 23px;
  height: 23px;
  place-items: center;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-size: 0.72rem;
}
.plan-option__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 7px 12px;
  margin-top: 9px;
  color: var(--muted);
  font-size: 0.74rem;
  font-weight: 650;
}
.plan-option__meta span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.plan-option__price {
  text-align: right;
}
.plan-option__price strong {
  display: block;
  font-size: 1.35rem;
  font-weight: 900;
  letter-spacing: -0.045em;
  line-height: 1;
}
.plan-option__price span {
  color: var(--muted);
  font-size: 0.65rem;
  font-weight: 850;
  letter-spacing: 0.08em;
}
.plan-option__price i {
  display: inline-block;
  margin-top: 5px;
  color: var(--accent-dark);
  font-size: 1.15rem;
}
v .plan-option:not(:active):not(:focus-visible) .plan-option__price i {
  animation: action-cue 2.8s ease-in-out infinite;
}
@keyframes action-cue {
  0%,
  70%,
  100% {
    transform: translateX(0);
  }
  82% {
    transform: translateX(4px);
  }
}
.plan-skeleton {
  min-height: 96px;
  padding: 18px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: #fff;
}
.plan-skeleton span {
  display: block;
  height: 11px;
  margin-bottom: 10px;
  border-radius: 999px;
  background: #eef2f7;
  animation: pulse 1.4s ease-in-out infinite;
}
.plan-skeleton span:nth-child(1) {
  width: 42%;
}
.plan-skeleton span:nth-child(2) {
  width: 68%;
}
.plan-skeleton span:nth-child(3) {
  width: 30%;
  margin: 0;
}

.purchase-divider {
  height: 1px;
  margin: 22px 0;
  background: #edf1f5;
}
.phone-block label,
.receipt-fields label {
  display: block;
  margin-bottom: 7px;
  font-size: 0.8rem;
  font-weight: 800;
}
.phone-block small {
  display: block;
  margin-top: 7px;
  color: var(--muted);
  font-size: 0.72rem;
  line-height: 1.45;
}
.phone-field {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  min-height: 56px;
  border: 1px solid #cbd5e1;
  border-radius: 15px;
  background: #fff;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}
.phone-field:focus-within {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15, 150, 117, 0.1);
}
.phone-field.valid {
  border-color: #86cdbb;
}
.phone-prefix {
  padding: 0 12px 0 14px;
  border-right: 1px solid #e2e8f0;
  color: #475569;
  font-weight: 800;
}
.phone-field input {
  width: 100%;
  min-width: 0;
  min-height: 54px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--ink);
  font-size: 1rem;
}
.phone-field > i {
  margin-right: 13px;
  color: var(--accent);
}
.receipt-toggle {
  display: flex;
  width: 100%;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 12px;
  padding: 0 2px;
  border: 0;
  background: transparent;
  color: #475569;
  font-size: 0.79rem;
  font-weight: 750;
}
.receipt-toggle span {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.receipt-toggle > i {
  transition: transform 0.2s ease;
}
.receipt-toggle > i.open {
  transform: rotate(180deg);
}
.receipt-fields {
  display: grid;
  gap: 12px;
  padding: 2px 0 6px;
}
.receipt-fields label span {
  color: #94a3b8;
  font-size: 0.68rem;
  font-weight: 600;
}
.receipt-fields input {
  width: 100%;
  min-height: 50px;
  padding: 0 13px;
  border: 1px solid #cbd5e1;
  border-radius: 13px;
  outline: 0;
}
.receipt-fields input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15, 150, 117, 0.1);
}
.device-note,
.trust-note,
.state-footnote {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  color: var(--muted);
  font-size: 0.72rem;
}
.device-note {
  justify-content: flex-start;
  margin-top: 10px;
}
.portal-alert {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-top: 14px;
  padding: 11px 12px;
  border-radius: 13px;
  font-size: 0.8rem;
}
.portal-alert.danger {
  background: #fff1f2;
  color: #9f1239;
}

.primary-action,
.secondary-action {
  display: flex;
  width: 100%;
  min-height: 56px;
  align-items: center;
  justify-content: center;
  gap: 9px;
  border-radius: 15px;
  font-weight: 850;
  transition:
    transform 0.15s ease,
    opacity 0.15s ease,
    background 0.15s ease;
}
.primary-action {
  border: 1px solid var(--accent);
  background: var(--accent);
  color: #fff;
}
.primary-action:hover {
  background: var(--accent-dark);
  border-color: var(--accent-dark);
}
.primary-action:active,
.secondary-action:active {
  transform: scale(0.97);
}
.primary-action:active .bi,
.secondary-action:active .bi,
.primary-action:focus-visible .bi,
.secondary-action:focus-visible .bi {
  transform: translateX(4px) scale(1.12);
}
.primary-action:disabled {
  opacity: 0.48;
}
.primary-action--link {
  text-decoration: none;
}
.secondary-action {
  margin-top: 18px;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #0f172a;
}
.text-action {
  min-height: 48px;
  margin-top: 6px;
  border: 0;
  background: transparent;
  color: #475569;
  font-weight: 750;
}
.desktop-pay-action {
  margin-top: 16px;
}
.trust-note {
  margin-top: 10px;
}

.portal-state-card {
  padding: 32px 22px;
  text-align: center;
}
.state-icon {
  position: relative;
  display: grid;
  width: 66px;
  height: 66px;
  place-items: center;
  margin: 0 auto 18px;
  border-radius: 22px;
  background: #eef2ff;
  color: #3730a3;
  font-size: 1.65rem;
}
.state-icon.is-waiting::after {
  content: "";
  position: absolute;
  inset: -6px;
  border: 2px solid transparent;
  border-top-color: currentColor;
  border-right-color: currentColor;
  border-radius: 27px;
  animation: status-ring 1.4s linear infinite;
}
.state-icon.phone.is-waiting i {
  animation: phone-alert 1.5s ease-in-out infinite;
}
.state-icon.preparing i {
  animation: spin 3s linear infinite;
}
.state-icon.success:not(.is-waiting) i {
  animation: success-pop 0.55s ease-out both;
}
@keyframes status-ring {
  to {
    transform: rotate(360deg);
  }
}
@keyframes phone-alert {
  0%,
  72%,
  100% {
    transform: rotate(0);
  }
  78% {
    transform: rotate(-12deg);
  }
  84% {
    transform: rotate(12deg);
  }
  90% {
    transform: rotate(-7deg);
  }
}
@keyframes success-pop {
  0% {
    opacity: 0.3;
    transform: scale(0.5);
  }
  75% {
    transform: scale(1.2);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
.state-icon.phone {
  background: #eff6ff;
  color: #2563eb;
}
.state-icon.wifi {
  background: #ecfdf5;
  color: #047857;
}
.state-icon.success {
  background: #ecfdf5;
  color: #047857;
}
.state-icon.warning {
  background: #fff7ed;
  color: #c2410c;
}
.payment-summary {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin-bottom: 12px;
  color: var(--muted);
  font-size: 0.85rem;
}
.payment-summary strong {
  color: var(--ink);
}
.payment-next-step {
  margin-top: 22px;
}
.payment-next-step p {
  font-size: 0.85rem;
}
.state-footnote {
  margin-top: 18px;
}
.connection-progress {
  height: 5px;
  margin: 26px 0 18px;
  overflow: hidden;
  border-radius: 999px;
  background: #e8edf2;
}
.connection-progress span {
  display: block;
  width: 40%;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  animation: connection-progress 1.35s ease-in-out infinite;
}
.connection-card .primary-action {
  margin-top: 20px;
}
.voucher-secondary {
  margin-top: 28px;
  padding-top: 22px;
  border-top: 1px solid #edf1f5;
  text-align: left;
}
.voucher-secondary__label {
  margin-bottom: 10px;
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.portal-online {
  overflow: hidden;
}
.online-hero {
  padding: 30px 22px 20px;
  text-align: center;
}
.online-summary {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  margin: 0 18px 20px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: #f8fafc;
}
.online-summary div {
  min-width: 0;
  padding: 13px 8px;
  text-align: center;
}
.online-summary div + div {
  border-left: 1px solid var(--line);
}
.online-summary span {
  display: block;
  color: var(--muted);
  font-size: 0.65rem;
  font-weight: 700;
}
.online-summary strong {
  display: block;
  margin-top: 3px;
  overflow: hidden;
  color: var(--ink);
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.portal-online > .primary-action {
  width: auto;
  margin: 0 18px;
}
.portal-online .voucher-secondary {
  margin: 24px 18px 18px;
}

.portal-footer {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  color: #94a3b8;
  font-size: 0.72rem;
}
.portal-loader {
  width: 38px;
  height: 38px;
  margin: 0 auto 18px;
  border: 3px solid #e2e8f0;
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
.receipt-details-enter-active,
.receipt-details-leave-active {
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}
.receipt-details-enter-from,
.receipt-details-leave-to {
  opacity: 0;
  transform: translateY(-5px);
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
@keyframes pulse {
  0%,
  100% {
    opacity: 0.55;
  }
  50% {
    opacity: 1;
  }
}
@keyframes waiting {
  0%,
  100% {
    opacity: 0.3;
    transform: translateY(0);
  }
  50% {
    opacity: 1;
    transform: translateY(-4px);
  }
}
@keyframes connection-progress {
  0% {
    transform: translateX(-110%);
  }
  100% {
    transform: translateX(260%);
  }
}

@media (min-width: 700px) {
  .portal-shell {
    padding-bottom: 32px;
    background: #eef3f9;
  }
  .portal-wrap {
    padding: 0 22px;
  }
  .portal-header {
    min-height: 110px;
  }
  .portal-purchase-card {
    padding: 30px;
  }
  .plan-list {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .receipt-fields {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 699.98px) {
  .portal-shell {
    padding-bottom: 8px;
  }
  .portal-wrap {
    padding: 0 10px;
  }
  .portal-purchase-card {
    padding: 17px 14px;
    margin: 8px 0;
    box-shadow:
      6px 6px 15px #cbd5df,
      -6px -6px 15px #fff;
  }
  .portal-section-heading {
    display: grid;
    grid-template-columns: 48px minmax(0, 1fr);
    column-gap: 13px;
    align-items: center;
    text-align: left;
  }
  .portal-hero-icon {
    grid-row: 1 / 4;
    width: 48px;
    height: 48px;
    margin: 0;
    border-radius: 15px;
    font-size: 1.3rem;
    box-shadow:
      4px 4px 9px #cbd5df,
      -4px -4px 9px #fff;
  }
  .portal-section-heading .portal-eyebrow,
  .portal-section-heading h1,
  .portal-section-heading p {
    grid-column: 2;
  }
  .portal-section-heading h1 {
    margin: 0;
    font-size: clamp(1.3rem, 6vw, 1.7rem);
  }
  .portal-section-heading p {
    margin: 2px 0 0;
    font-size: 0.78rem;
    line-height: 1.25;
  }
  .plan-list {
    gap: 9px;
    margin-top: 17px;
  }
  .plan-option {
    min-height: 76px;
    padding: 10px 12px;
    gap: 8px;
    border-radius: 14px;
  }
  .plan-option__meta {
    margin-top: 5px;
    gap: 4px 9px;
    font-size: 0.68rem;
  }
  .plan-option__price strong {
    font-size: 1.14rem;
  }
  .plan-option__price i {
    margin-top: 1px;
    font-size: 1rem;
  }
  .portal-footer {
    min-height: 36px;
    font-size: 0.65rem;
  }
}

@media (max-width: 360px) {
  .portal-wrap {
    padding: 0 8px;
  }
  .portal-secure span {
    display: none;
  }
  .portal-secure {
    width: 36px;
    justify-content: center;
    padding: 0;
  }
  .plan-option__recommended {
    font-size: 0.58rem;
  }
  .plan-option__title-row {
    flex-wrap: wrap;
    gap: 3px 6px;
  }
  .plan-option__price strong {
    font-size: 1rem;
  }
  .online-summary {
    grid-template-columns: 1fr;
  }
  .online-summary div + div {
    border-top: 1px solid var(--line);
    border-left: 0;
  }
}

@media (hover: hover) {
  .plan-option:not(.selected):hover {
    border-color: #a7cfc4;
  }
  .plan-option:hover .plan-option__meta .bi {
    transform: scale(1.18);
    color: var(--accent-dark);
  }
  .primary-action:hover .bi,
  .secondary-action:hover .bi {
    transform: translateX(4px) scale(1.12);
  }
}

@media (prefers-reduced-motion: reduce) {
  .plan-option .plan-option__price i {
    animation: none;
  }
  .state-icon::after {
    animation: none !important;
  }
  .state-icon i {
    animation: none !important;
  }
  *,
  *::before,
  *::after {
    scroll-behavior: auto !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}

/* Keep voucher entry and real packages together in a compact mobile layout. */
.portal-shell {
  --accent: #16879e;
  --accent-dark: #12677b;
  --ink: #233746;
  --muted: #526571;
  background: #e7edf2;
  padding: 12px 0 28px;
}
.portal-wrap {
  width: min(100%, 520px);
  padding: 0 18px;
}
.mobile-brand {
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 10px 5px 22px;
}
.mobile-brand h1 {
  margin: 0;
  font-size: 23px;
  font-weight: 850;
  letter-spacing: -0.8px;
}
.mobile-brand h1 span {
  color: #16879e;
}
.mobile-brand__secure {
  margin-left: auto;
  color: #16879e;
  font-size: 20px;
}
.access-shell {
  border-radius: 25px;
  padding: 14px;
  background: #e7edf2;
  border: 1px solid #f5f9fb;
  box-shadow:
    8px 8px 18px #c8d1d9,
    -8px -8px 18px #fff;
}
.access-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 5px;
  border-radius: 15px;
  box-shadow:
    inset 3px 3px 6px #cbd4dc,
    inset -3px -3px 6px #fff;
}
.access-tabs button {
  min-height: 44px;
  border: 0;
  border-radius: 11px;
  background: transparent;
  color: #526571;
  font-size: 13px;
  font-weight: 750;
}
.access-tabs button i {
  margin-right: 4px;
}
.access-tabs button.active {
  background: #eaf0f5;
  color: #12677b;
  box-shadow:
    3px 3px 6px #c4ced7,
    -3px -3px 6px #fff;
}
.access-tabs button:focus-visible,
.plan-option:focus-visible {
  outline: 3px solid #16879e;
  outline-offset: 3px;
}
.purchase-flow {
  margin-top: 22px;
}
.portal-purchase-card {
  padding: 0;
  margin: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  box-shadow: none;
}
.plans-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin: 0 2px 12px;
}
.plans-heading h2 {
  font-size: 17px;
  margin: 0;
  font-weight: 800;
}
.plans-heading span {
  font-size: 11px;
  color: #526571;
}
.plan-list {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
}
.plan-option {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: space-between;
  min-height: 110px;
  gap: 10px;
  padding: 13px;
  border: 1px solid #f7fafc;
  border-radius: 18px;
  background: #e7edf2;
  box-shadow:
    5px 5px 10px #c7d0d9,
    -5px -5px 10px #fff;
  text-align: left;
}
.plan-option.recommended {
  border-color: #66b7c7;
  background: linear-gradient(140deg, #eaf6f8, #dcecf1);
}
.plan-option.selected {
  border-color: #16879e;
  box-shadow:
    inset 3px 3px 6px #c2d1da,
    inset -3px -3px 6px #fff;
}
.plan-option__title-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 3px;
}
.plan-option__title-row strong {
  font-size: 13px;
  line-height: 1.3;
  overflow-wrap: anywhere;
}
.plan-option__recommended {
  font-size: 9px;
  padding: 3px 6px;
  color: #12677b;
  background: #c7e8ee;
  border-radius: 6px;
}
.plan-option__meta {
  gap: 3px 7px;
  font-size: 10px;
  margin-top: 5px;
}
.plan-option__meta i {
  display: none;
}
.plan-option__price {
  display: flex;
  align-items: baseline;
  justify-content: flex-start;
  gap: 4px;
  text-align: left;
}
.plan-option__price strong {
  color: #12677b;
  font-size: 21px;
  line-height: 1;
}
.plan-option__price span {
  font-size: 9px;
}
.plan-option__price i {
  margin-left: auto;
  color: #16879e;
  font-size: 19px;
}
.portal-footer {
  min-height: 36px;
  font-size: 10px;
}
.plan-option {
  min-height: 100px;
}
.plan-option:last-child:nth-child(odd) {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  min-height: 76px;
}
.plan-option:last-child:nth-child(odd) .plan-option__price {
  gap: 5px;
}
.plan-option:last-child:nth-child(odd) .plan-option__price i {
  margin-left: 12px;
}
@media (max-width: 359px) {
  .portal-wrap {
    padding: 0 12px;
  }
  .access-shell {
    padding: 11px;
  }
  .plan-list {
    gap: 10px;
  }
  .plan-option {
    padding: 11px;
  }
}

.portal-shell {
  --accent: #198ab5;
  --accent-dark: #185e87;
}
.mobile-brand__eye {
  width: 92px;
  height: 66px;
  padding: 3px;
  border: 0;
  border-radius: 22px;
  background: #eaf0f5;
  box-shadow:
    5px 5px 11px #c4cdd6,
    -5px -5px 11px #fff;
}
.mobile-brand__eye:focus-visible {
  outline: 3px solid #168fca;
  outline-offset: 4px;
}
.mobile-brand h1 span,
.mobile-brand__secure,
.plan-option__price strong,
.plan-option__price i {
  color: #196b97;
}
.access-tabs button.active {
  color: #196b97;
}
.plan-option.selected {
  border-color: #3699c5;
}
.checkout-dialog {
  width: calc(100% - 32px);
  max-width: 430px;
  max-height: calc(100dvh - 32px);
  margin: auto;
  border-radius: 24px;
  background: #eef3f9;
  box-shadow:
    0 18px 48px #132d4855,
    0 0 30px 10px #ffffffb3;
}
.checkout-dialog::backdrop {
  background: rgba(22, 34, 53, 0.59);
}
.checkout-dialog .checkout-card {
  background: #eef3f9;
}
.checkout-dialog .btn-close {
  min-width: 44px;
  min-height: 44px;
  padding: 0;
  background-size: 16px;
  flex-shrink: 0;
}
.checkout-dialog h2 {
  font-size: 24px;
  font-weight: 500;
}
.checkout-dialog .form-label {
  font-size: 14px;
  margin-bottom: 8px;
}
.checkout-dialog #phone-help {
  font-size: 12px;
}
.portal-shell :deep(.btn-primary),
.portal-shell .primary-action {
  background: linear-gradient(145deg, #f3f7fb, #dfe8ef);
  border: 1px solid #f8fcff;
  color: #155b86;
  border-radius: 16px;
  box-shadow:
    5px 5px 10px #bccbd7,
    -5px -5px 10px #fff;
}
.portal-shell :deep(.btn-primary:hover),
.portal-shell .primary-action:hover {
  background: #e5eff6;
  color: #104f78;
}
.portal-shell :deep(.btn-primary:active),
.portal-shell .primary-action:active {
  box-shadow:
    inset 3px 3px 7px #bccbd7,
    inset -3px -3px 7px #fff;
}
.portal-shell :deep(.btn-outline-primary) {
  --bs-btn-color: #196b97;
  --bs-btn-border-color: #8eb8cd;
  --bs-btn-hover-bg: #deebf4;
  --bs-btn-hover-color: #164c6e;
  --bs-btn-hover-border-color: #6999b4;
}

.payment-card {
  padding: 22px;
}
.payment-card .payment-summary {
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding-bottom: 18px;
  margin: 0 0 22px;
  border-bottom: 1px solid #d5e0e8;
  text-align: left;
  font-size: 13px;
}
.payment-card .payment-summary strong {
  flex-shrink: 0;
  color: #185e87;
  font-size: 16px;
}
.payment-card .state-icon {
  width: 48px;
  height: 48px;
  border-radius: 16px;
  margin: 0 auto 16px;
  font-size: 23px;
  color: #196b97;
  background: #e4eef5;
}
.payment-card h1 {
  font-size: clamp(21px, 5.8vw, 27px);
  line-height: 1.2;
  letter-spacing: -0.5px;
}
.payment-card .payment-message {
  margin: 10px auto 0;
  max-width: 32ch;
  font-size: 14px;
  line-height: 1.5;
}
.payment-card .primary-action {
  margin-top: 24px;
  min-height: 50px;
  font-size: 14px;
}
.payment-countdown {
  display: inline-flex;
  margin-top: 16px;
  padding: 7px 15px;
  border-radius: 10px;
  color: #185e87;
  font-size: 15px;
  font-variant-numeric: tabular-nums;
  box-shadow:
    inset 3px 3px 6px #cbd6df,
    inset -3px -3px 6px #fff;
}

.status-eye {
  width: 104px;
  height: 68px;
  margin: 0 auto 16px;
}
.inline-eye {
  display: inline-flex;
  width: 42px;
  height: 28px;
  flex-shrink: 0;
  vertical-align: middle;
}
.plan-option {
  position: relative;
  isolation: isolate;
  overflow: hidden;
}
.plan-option__main,
.plan-option__price {
  position: relative;
  z-index: 1;
}
.plan-option__price {
  flex-wrap: wrap;
}
.plan-original-price {
  position: absolute;
  z-index: 0;
  top: 46%;
  right: 7px;
  max-width: calc(100% - 20px);
  transform: rotate(-28deg);
  transform-origin: center;
  color: rgba(24, 94, 135, 0.32);
  font-size: clamp(11px, 3vw, 14px);
  line-height: 1;
  font-weight: 750;
  white-space: nowrap;
  text-decoration-thickness: 1px;
  pointer-events: none;
}
.plan-option:last-child:nth-child(odd) .plan-original-price {
  right: 35%;
}

.portal-language {
  min-width: 44px;
  min-height: 44px;
  padding: 0 9px;
  flex-shrink: 0;
  border: 1px solid #fff;
  border-radius: 12px;
  background: #edf3f8;
  color: #185e87;
  font-size: 13px;
  font-weight: 750;
  box-shadow:
    3px 3px 7px #cbd5df,
    -3px -3px 7px #fff;
}
.portal-language:focus-visible {
  outline: 3px solid #16879e;
  outline-offset: 2px;
}
.plan-option > .plan-option__recommended {
  position: absolute;
  z-index: 0;
  top: 13px;
  right: 3px;
  transform: rotate(28deg);
  padding: 0;
  max-width: 65%;
  background: transparent;
  color: rgba(18, 103, 123, 0.32);
  border: 0;
  box-shadow: none;
  font-size: 11px;
  line-height: 1.2;
  font-weight: 750;
  white-space: nowrap;
  pointer-events: none;
}
</style>
