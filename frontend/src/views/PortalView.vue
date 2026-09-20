<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { api } from "../api";
import VoucherCard from "../components/vouchers/VoucherCard.vue";
import AnimatedWifi from "../components/AnimatedWifi.vue";
import { voucherTimeLeft } from "../utils/voucherTime";

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
const name = ref("");
const email = ref("");
const loading = ref(false);
const booting = ref(true);
const restoreFailed = ref(false);
const restoreMessage = ref("");
const error = ref("");
const order = ref<any>(null);
const mock = ref(false);
const timer = ref<number | null>(null);
const connectionTimer = ref<number | null>(null);
const clockTimer = ref<number | null>(null);
const nowTick = ref(Date.now());
const receiptOpen = ref(false);
const connectionState = ref<ConnectionState>("idle");
const connectionBusy = ref(false);
const refreshBusy = ref(false);
const resendBusy = ref(false);
const connectionCheckBusy = ref(false);

let lastPrepareAt = 0;
let prepareAttempts = 0;

const orderStorageKey = "rjay_current_order";
const params = new URLSearchParams(location.search);
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
    try {
      order.value = (
        await api.get(`/public/orders/${encodeURIComponent(savedOrder)}`)
      ).data;
      mock.value = Boolean(order.value.mock);

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
        ? "We could not verify this purchase on this device. Contact the administrator before paying again."
        : "We could not check your purchase right now. Your payment details are saved on this device.";
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
  checkoutSubmitted.value = false;
  checkoutStep.value = "Enter your Mobile Money number";
  await nextTick();
  if (!checkoutDialog.value?.open) checkoutDialog.value?.showModal();
  phoneInput.value?.focus();
}

function closeCheckout() {
  checkoutDialog.value?.close();
}

function onPhoneInput(event: Event) {
  const input = event.target as HTMLInputElement;
  let digits = input.value.replace(/\D/g, "");
  if (digits.startsWith("0")) digits = `255${digits.slice(1)}`;
  else if (/^[67]/.test(digits)) digits = `255${digits}`;
  digits = digits.slice(0, 12);
  phone.value = digits.replace(
    /^(\d{0,3})(\d{0,3})(\d{0,3})(\d{0,3}).*$/,
    (_, a, b, c, d) => [a, b, c, d].filter(Boolean).join(" "),
  );
  input.value = phone.value;
  if (normalizedPhone.value && !checkoutSubmitted.value) {
    checkoutSubmitted.value = true;
    void buy();
  }
}

async function buy() {
  if (!selected.value || loading.value || !phoneValid.value) return;

  loading.value = true;
  error.value = "";
  checkoutStep.value = "Creating your order…";

  try {
    const created = await api.post("/public/orders", {
      plan_id: selected.value.id,
      phone: normalizedPhone.value,
      name: name.value || null,
      email: email.value || null,
      device_mac: deviceMac || null,
    });

    order.value = created.data;
    sessionStorage.setItem(orderStorageKey, order.value.uuid);
    if (order.value.access_token)
      sessionStorage.setItem("rjay_order_token", order.value.access_token);
    checkoutStep.value = "Sending payment request…";
    await requestPayment();
  } catch (e: any) {
    if (order.value) startPoll();
    error.value =
      e.response?.data?.message ||
      "We could not start your purchase. Please try again.";
    checkoutStep.value = "Could not send the request";
  } finally {
    loading.value = false;
  }
}

async function requestPayment() {
  if (!order.value) return;
  const response = await api.post(
    `/public/orders/${encodeURIComponent(order.value.uuid)}/pay`,
  );
  mock.value = Boolean(response.data.mock);
  order.value = response.data.order;
  startPoll();
}

async function continuePayment() {
  if (loading.value) return;
  loading.value = true;
  error.value = "";
  try {
    await requestPayment();
  } catch {
    error.value = "Payment request could not be sent. Please try again.";
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
      e.response?.data?.message ||
      "Could not resend the request. Please try again.";
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
    const state = response.data.state;
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
    mock.value = Boolean(order.value.mock);

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

async function simulate() {
  if (!order.value || loading.value) return;
  loading.value = true;
  try {
    await api.post(
      `/public/orders/${encodeURIComponent(order.value.uuid)}/mock-complete`,
    );
    await refresh();
  } catch {
    error.value =
      "Payment confirmation is unavailable. Your order is still saved.";
  } finally {
    loading.value = false;
  }
}

async function prepareConnection(automatic = false) {
  if (!order.value || connectionBusy.value) return;

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

  const form = document.createElement("form");
  form.method = "post";
  form.action = loginUrl;

  const returnUrl = new URL(location.href);
  returnUrl.searchParams.set("order", order.value.uuid);
  returnUrl.searchParams.set("connected", "1");

  const fields = [
    ["username", order.value.voucher.code],
    ["password", order.value.voucher.password],
    ["dst", returnUrl.toString()],
    ["popup", "false"],
  ];

  fields.forEach(([fieldName, value]) => {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = fieldName;
    input.value = value;
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
}

function startNewPurchase() {
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

const paymentTitle = computed(() => {
  if (paymentFailed.value) return "Payment not completed";
  if (paymentRequestMissing.value) return "Ready for payment";
  if (pushSecondsLeft.value === 0) return "Request timed out";
  return "Check your phone";
});

const paymentMessage = computed(() => {
  if (paymentFailed.value) return "The payment did not complete.";
  if (paymentRequestMissing.value)
    return "Send the Mobile Money request again.";
  if (pushSecondsLeft.value === 0)
    return "We have not received payment confirmation yet.";
  return "Approve the Mobile Money request on your phone.";
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
  <div class="portal-shell">
    <div class="portal-wrap">
      <header v-if="portalStage !== 'choose'" class="portal-header">
        <div class="portal-logo">
          <AnimatedWifi
            :busy="portalStage === 'connecting' && connectionWaiting"
          />
        </div>
        <div>
          <div class="brand portal-brand">RJAY WiFi</div>
          <div class="portal-kicker">Get online in under a minute</div>
        </div>
        <div class="portal-secure">
          <i class="bi bi-shield-check" aria-hidden="true"></i
          ><span>Secure</span>
        </div>
      </header>

      <main>
        <section
          v-if="portalStage === 'restoring'"
          class="portal-state-card"
          aria-live="polite"
        >
          <div class="portal-loader" aria-hidden="true"></div>
          <h1>Restoring your purchase</h1>
          <p>Checking your latest payment and internet access…</p>
        </section>

        <section
          v-else-if="portalStage === 'restore_error'"
          class="portal-state-card"
          aria-live="polite"
        >
          <div class="state-icon warning">
            <i class="bi bi-exclamation-lg" aria-hidden="true"></i>
          </div>
          <h1>Could not check your purchase</h1>
          <p>{{ restoreMessage }}</p>
          <button class="primary-action mt-4" @click="retryRestore">
            Try again
          </button>
        </section>

        <section
          v-else-if="portalStage === 'choose'"
          class="portal-purchase-card"
        >
          <div class="portal-section-heading">
            <div class="portal-hero-icon" aria-hidden="true">
              <AnimatedWifi busy />
            </div>
            <span class="portal-eyebrow">RJAY WIFI</span>
            <h1>Get connected</h1>
            <p>Choose a package and pay on your phone.</p>
          </div>

          <div
            v-if="!plans.length && !error"
            class="plan-list"
            aria-label="Loading internet packages"
          >
            <div v-for="n in 3" :key="n" class="plan-skeleton">
              <span></span><span></span><span></span>
            </div>
          </div>

          <div v-else class="plan-list" aria-label="Internet packages">
            <button
              v-for="plan in plans"
              :key="plan.id"
              type="button"
              class="plan-option"
              :class="{
                selected: selected?.id === plan.id,
                recommended: plan.recommended,
              }"
              :aria-label="`${plan.name}, TZS ${Number(plan.price).toLocaleString()}. Enter phone number`"
              @click="choosePlan(plan)"
            >
              <div class="plan-option__main">
                <div class="plan-option__title-row">
                  <strong>{{ plan.name }}</strong>
                  <span v-if="plan.recommended" class="plan-option__recommended"
                    >Recommended</span
                  >
                </div>
                <div class="plan-option__meta">
                  <span
                    ><i class="bi bi-clock" aria-hidden="true"></i
                    >{{ durationLabel(plan) }}</span
                  >
                  <span
                    ><i class="bi bi-speedometer2" aria-hidden="true"></i
                    >{{ speedLabel(plan.rate_limit) }}</span
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
            {{ error }}
          </div>

          <dialog
            ref="checkoutDialog"
            class="checkout-dialog border-0 p-0 col-11 col-sm-8 col-md-6 col-lg-4"
            aria-labelledby="checkout-title"
          >
            <div class="checkout-card card border-0 p-4">
              <div
                class="d-flex justify-content-between align-items-start gap-3 mb-3"
              >
                <div>
                  <div class="checkout-eyebrow">Mobile Money</div>
                  <h2 id="checkout-title" class="h4 mb-1">
                    {{ selected?.name }}
                  </h2>
                  <p class="text-secondary mb-0">
                    <span class="price">TZS {{ selectedPrice }}</span> ·
                    {{ durationLabel(selected) }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn-close"
                  aria-label="Close"
                  :disabled="loading"
                  @click="closeCheckout"
                ></button>
              </div>
              <form @submit.prevent>
                <div class="mb-3">
                  <label class="form-label" for="customer-phone"
                    >Mobile Money number</label
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
                    placeholder="255 787 550 399"
                    aria-describedby="phone-help"
                    :readonly="loading || checkoutSubmitted"
                  /><small id="phone-help" class="text-secondary">{{
                    phone.length && !phoneValid
                      ? "Enter a valid Tanzania mobile number."
                      : "Enter your number to get the payment prompt."
                  }}</small>
                </div>
                <button
                  type="button"
                  class="btn btn-link px-0 mb-2"
                  :aria-expanded="receiptOpen"
                  @click="receiptOpen = !receiptOpen"
                >
                  {{
                    receiptOpen ? "Hide receipt details" : "Add receipt details"
                  }}
                </button>
                <div v-if="receiptOpen" class="row g-2 mb-3">
                  <div class="col-sm-6">
                    <label class="form-label" for="customer-name">Name</label
                    ><input
                      id="customer-name"
                      v-model="name"
                      class="form-control"
                      autocomplete="name"
                    />
                  </div>
                  <div class="col-sm-6">
                    <label class="form-label" for="customer-email">Email</label
                    ><input
                      id="customer-email"
                      v-model="email"
                      class="form-control"
                      type="email"
                      autocomplete="email"
                    />
                  </div>
                </div>
                <div v-if="error" class="alert alert-danger" role="alert">
                  {{ error }}
                </div>
                <p
                  v-if="(loading || checkoutSubmitted) && !error"
                  class="checkout-status mb-0"
                  role="status"
                  aria-live="polite"
                >
                  <span
                    v-if="loading"
                    class="spinner-border spinner-border-sm"
                    aria-hidden="true"
                  ></span>
                  {{ checkoutStep }}
                </p>
                <button
                  v-if="error"
                  type="button"
                  class="btn btn-outline-primary mt-2"
                  @click="
                    order
                      ? continuePayment()
                      : ((checkoutSubmitted = true), buy())
                  "
                >
                  Try again
                </button>
              </form>
            </div>
          </dialog>
        </section>

        <section
          v-else-if="
            portalStage === 'paying' || portalStage === 'payment_failed'
          "
          class="portal-state-card"
          aria-live="polite"
        >
          <div
            class="state-icon"
            :class="[
              paymentFailed ? 'warning' : 'phone',
              { 'is-waiting': !paymentFailed && pushSecondsLeft !== 0 },
            ]"
          >
            <i
              :class="
                paymentFailed ? 'bi bi-exclamation-lg' : 'bi bi-phone-vibrate'
              "
              aria-hidden="true"
            ></i>
          </div>
          <div class="state-eyebrow">
            {{ order?.plan?.name || selected?.name }}
          </div>
          <h1>{{ paymentTitle }}</h1>
          <p>{{ paymentMessage }}</p>
          <div class="state-amount">
            TZS
            {{ Number(order?.amount || selected?.price || 0).toLocaleString() }}
          </div>

          <div
            v-if="!paymentFailed && pushSecondsLeft !== 0"
            class="waiting-indicator"
            aria-label="Waiting for payment confirmation"
          >
            <span></span><span></span><span></span>
          </div>
          <div
            v-if="
              !paymentFailed &&
              !paymentRequestMissing &&
              pushSecondsLeft !== null
            "
            class="mt-3"
            role="status"
          >
            <p v-if="pushSecondsLeft > 0" class="mb-1">
              Waiting for PIN confirmation · {{ pushCountdown }}
            </p>
            <p v-else class="mb-2">
              The 5-minute wait has ended. Check your phone or send the request
              again.
            </p>
            <button
              v-if="pushSecondsLeft === 0"
              type="button"
              class="secondary-action"
              :disabled="resendBusy"
              @click="resendPush"
            >
              <span
                v-if="resendBusy"
                class="spinner-border spinner-border-sm"
                aria-hidden="true"
              ></span>
              {{
                resendBusy ? "Checking payment…" : "Resend push notification"
              }}
            </button>
          </div>
          <div v-if="error" class="alert alert-warning mt-3" role="alert">
            {{ error }}
          </div>

          <button
            v-if="paymentRequestMissing"
            class="primary-action"
            :disabled="loading"
            @click="continuePayment"
          >
            <span
              v-if="loading"
              class="spinner-border spinner-border-sm"
              aria-hidden="true"
            ></span>
            {{
              loading
                ? "Sending request…"
                : paymentFailed
                  ? "Retry payment"
                  : "Continue payment"
            }}
          </button>
          <button
            v-if="mock && !paid"
            class="secondary-action"
            @click="simulate"
          >
            <i class="bi bi-lightning-charge" aria-hidden="true"></i>Simulate
            payment success
          </button>
          <button
            v-if="paymentFailed"
            class="text-action"
            @click="startNewPurchase"
          >
            Choose another package
          </button>
        </section>

        <section
          v-else-if="portalStage === 'preparing'"
          class="portal-state-card"
          aria-live="polite"
        >
          <div class="state-icon preparing is-waiting">
            <i class="bi bi-gear" aria-hidden="true"></i>
          </div>
          <div class="state-eyebrow">Payment received</div>
          <h1>Preparing your internet</h1>
          <p>
            Payment received. Setting up your Wi-Fi access. This should finish
            within 6 minutes.
          </p>
          <div class="connection-progress"><span></span></div>
        </section>

        <section
          v-else-if="portalStage === 'preparation_failed'"
          class="portal-state-card"
          aria-live="polite"
        >
          <div class="state-icon warning">
            <i class="bi bi-exclamation-lg" aria-hidden="true"></i>
          </div>
          <div class="state-eyebrow">Payment received</div>
          <h1>
            {{
              voucherUnavailable
                ? "Wi-Fi access unavailable"
                : "Setup is taking too long"
            }}
          </h1>
          <p>
            {{
              voucherUnavailable
                ? "This voucher is no longer available. Please contact the administrator."
                : "Your payment is confirmed, but internet access was not ready within 6 minutes. You do not need to pay again."
            }}
          </p>
          <button
            class="primary-action mt-4"
            :disabled="refreshBusy"
            @click="refresh"
          >
            <span
              v-if="refreshBusy"
              class="spinner-border spinner-border-sm"
              aria-hidden="true"
            ></span>
            {{ refreshBusy ? "Checking…" : "Check status again" }}
          </button>
          <p v-if="error" class="portal-alert danger" role="alert">
            {{ error }}
          </p>
        </section>

        <section
          v-else-if="portalStage === 'connecting'"
          class="portal-state-card connection-card"
          aria-live="polite"
        >
          <div
            class="state-icon"
            :class="[
              connectionProblem ? 'warning' : 'wifi',
              { 'is-waiting': connectionWaiting },
            ]"
          >
            <i
              v-if="connectionProblem"
              class="bi bi-exclamation-lg"
              aria-hidden="true"
            ></i>
            <AnimatedWifi v-else :busy="connectionWaiting" />
          </div>
          <div class="state-eyebrow">{{ order?.plan?.name }}</div>
          <h1>{{ connectionTitle }}</h1>
          <p>{{ connectionMessage }}</p>

          <div v-if="connectionWaiting" class="connection-progress">
            <span></span>
          </div>

          <button
            v-if="isRecoverableConnection"
            class="primary-action"
            :disabled="connectionBusy"
            @click="prepareConnection()"
          >
            <span
              v-if="connectionBusy"
              class="spinner-border spinner-border-sm"
              aria-hidden="true"
            ></span>
            <AnimatedWifi v-else />
            {{
              connectionState === "manual"
                ? "Connect now"
                : "Try connection again"
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
            Buy another package
          </button>

          <div v-if="order?.voucher" class="voucher-secondary">
            <VoucherCard
              :voucher="order.voucher"
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
            <div class="state-icon success">
              <i class="bi bi-check-lg" aria-hidden="true"></i>
            </div>
            <div class="state-eyebrow">Connected successfully</div>
            <h1>You’re online</h1>
            <p>Internet access is active on this device.</p>
          </div>

          <div class="online-summary">
            <div>
              <span>Plan</span>
              <strong>{{ order?.plan?.name }}</strong>
            </div>
            <div>
              <span>Package time left</span>
              <strong>{{ remainingLabel }}</strong>
            </div>
            <div>
              <span>Speed</span>
              <strong>{{ speedLabel(order?.plan?.rate_limit) }}</strong>
            </div>
          </div>

          <a
            class="primary-action primary-action--link"
            :href="browsingDestination"
          >
            Continue browsing
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>

          <div class="voucher-secondary">
            <VoucherCard
              :voucher="order.voucher"
              :plan="order.plan"
              :now-ms="nowTick"
              :show-connect="false"
              compact
            />
          </div>
        </section>
      </main>

      <footer class="portal-footer">
        <i class="bi bi-shield-check" aria-hidden="true"></i>Secure payment ·
        One device per voucher
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
.state-amount {
  margin-top: 20px;
  font-size: 1.5rem;
  font-weight: 900;
  letter-spacing: -0.04em;
}
.waiting-indicator {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 24px;
}
.waiting-indicator span {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  animation: waiting 1.15s ease-in-out infinite;
}
.waiting-indicator span:nth-child(2) {
  animation-delay: 0.15s;
}
.waiting-indicator span:nth-child(3) {
  animation-delay: 0.3s;
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
</style>
