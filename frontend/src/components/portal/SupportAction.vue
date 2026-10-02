<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from "vue";
import { api } from "../../api";
import { usePortalLanguage } from "../../i18n/portalLanguage";
import { formatPhoneInput } from "../../utils/formatPhoneInput";
const props = defineProps<{
  orderUuid?: string;
  voucherUuid?: string;
  token?: string;
  phone?: string | null;
  deviceMac?: string | null;
  connectionState?: string;
}>();
const { t } = usePortalLanguage();
const inputId = `support-phone-${useId().replace(/:/g, "")}`;
const dialog = ref<HTMLDialogElement | null>(null);
const phoneField = ref<HTMLInputElement | null>(null);
const busy = ref(false),
  sent = ref(false),
  error = ref(""),
  phoneInput = ref("");
const contextPhone = computed(() => normalizePhone(props.phone || ""));
const enteredPhone = computed(() => normalizePhone(phoneInput.value));
const supportPhone = computed(() => contextPhone.value || enteredPhone.value);

function normalizePhone(value: string): string | null {
  const digits = value.replace(/\D/g, "");
  if (/^255[67]\d{8}$/.test(digits)) return digits;
  if (/^0[67]\d{8}$/.test(digits)) return `255${digits.slice(1)}`;
  if (/^[67]\d{8}$/.test(digits)) return `255${digits}`;
  return null;
}

function onPhoneInput(event: Event) {
  phoneInput.value = formatPhoneInput((event.target as HTMLInputElement).value);
  error.value = "";
}

function requestSupport() {
  if (!supportPhone.value) {
    error.value = phoneInput.value ? t("Enter a valid Tanzanian number.") : "";
    if (!dialog.value?.open) dialog.value?.showModal();
    void nextTick(() => phoneField.value?.focus());
    return;
  }
  void send();
}

function closePhoneDialog() {
  if (busy.value) return;
  dialog.value?.close();
  error.value = "";
}

watch(enteredPhone, (phone) => {
  if (phone && dialog.value?.open && !busy.value && !sent.value) void send();
});

async function send() {
  if (busy.value || sent.value) return;
  if (!supportPhone.value) {
    error.value = t("Enter a valid Tanzanian number.");
    return;
  }
  busy.value = true;
  error.value = "";
  try {
    const path = props.voucherUuid
      ? `/public/vouchers/${encodeURIComponent(props.voucherUuid)}/support`
      : props.orderUuid
        ? `/public/orders/${encodeURIComponent(props.orderUuid)}/support`
        : "/public/support";
    await api.post(
      path,
      {
        phone: supportPhone.value,
        device_mac: props.deviceMac || null,
        connection_state: props.connectionState || null,
      },
      {
        headers: props.token ? { "X-Voucher-Recovery-Token": props.token } : {},
      },
    );
    sent.value = true;
    dialog.value?.close();
  } catch (e: any) {
    error.value = e.response?.status === 422
      ? t("Enter a valid Tanzanian number.")
      : t("Support request could not be sent. Please try again.");
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <div class="support-action text-start">
    <p v-if="sent" class="mb-0" role="status">
      {{ t("Ombi la msaada limetumwa. Tutawasiliana nawe.") }}
    </p>
    <template v-else>
      <button class="btn btn-outline-secondary support-action__button" :disabled="busy" @click="requestSupport">
        {{ t(busy ? "Sending…" : "Need help") }}
      </button>
      <dialog ref="dialog" class="support-dialog" @cancel="busy ? $event.preventDefault() : closePhoneDialog()">
        <form class="support-dialog__card" @submit.prevent="requestSupport">
          <div class="support-dialog__head">
            <div>
              <h2>{{ t("Need help") }}</h2>
              <p>{{ t("Enter a valid Tanzanian number.") }}</p>
            </div>
            <button type="button" class="btn-close" :aria-label="t('Funga')" :disabled="busy" @click="closePhoneDialog"></button>
          </div>
          <label :for="inputId">{{ t("Phone number") }}</label>
          <input
            :id="inputId"
            ref="phoneField"
            :value="phoneInput"
            type="tel"
            inputmode="numeric"
            pattern="[0-9 ]*"
            autocomplete="tel"
            class="form-control form-control-lg"
            :aria-invalid="Boolean(phoneInput && !enteredPhone)"
            aria-describedby="support-phone-help"
            placeholder="255 7XX XXX XXX"
            :disabled="busy"
            @input="onPhoneInput"
          />
          <small id="support-phone-help" :class="phoneInput && !enteredPhone ? 'text-danger' : 'text-secondary'">
            {{ t(phoneInput && !enteredPhone ? "Check your phone number." : enteredPhone ? "✓ Number complete" : "") }}
          </small>
          <button class="btn btn-primary support-dialog__submit" :disabled="busy || !enteredPhone">
            {{ t(busy ? "Sending…" : "Send request") }}
          </button>
          <p v-if="busy" class="support-dialog__status" role="status">{{ t("Sending…") }}</p>
          <p v-if="error" class="text-danger mb-0" role="alert">{{ error }}</p>
        </form>
      </dialog>
      <p v-if="error" class="text-danger mt-2" role="alert">
        {{ error }}
      </p>
    </template>
  </div>
</template>
<style scoped>
.support-action {
  margin: 8px 0 0;
  font-size: 12px;
}
.support-action__button {
  min-height: 32px;
  padding: 2px 8px;
  background: transparent;
  font-size: inherit;
  line-height: 1.2;
}
.support-action p {
  margin-bottom: 0;
}
.support-dialog {
  width: min(92vw, 420px);
  padding: 0;
  border: 0;
  border-radius: 20px;
  background: transparent;
}
.support-dialog::backdrop {
  background: rgba(22, 34, 53, 0.59);
}
.support-dialog__card {
  display: grid;
  gap: 10px;
  padding: 18px;
  border: 1px solid #fff;
  border-radius: 20px;
  background: #eef3f9;
  box-shadow: 8px 8px 18px #101a2766;
}
.support-dialog__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.support-dialog__head h2 {
  margin: 0;
  color: #17364a;
  font-size: 1.15rem;
}
.support-dialog__head p {
  margin-top: 4px;
  color: #64748b;
}
.support-dialog label {
  color: #526571;
  font-weight: 700;
}
.support-dialog .form-control {
  min-height: 48px;
  font-size: 16px;
}
.support-dialog__submit {
  min-height: 44px;
}
.support-dialog__status {
  color: #196b97;
  text-align: center;
}
</style>
