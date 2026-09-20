<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  ticket: any
  title?: string
  wifiName?: string
  instructions?: string
  showPrice?: boolean
  thermal?: boolean
}>(), {
  title: 'RJAY WiFi',
  wifiName: '',
  instructions: 'Connect to the Wi-Fi and enter this code on the login page.',
  showPrice: true,
  thermal: false,
})

const packageDetails = computed(() => {
  const plan = props.ticket.plan
  if (!plan) return ''
  const data = plan.data_limit_bytes ? `${Number((plan.data_limit_bytes / 1048576).toFixed(1)).toLocaleString()} MB` : ''
  const time = plan.duration_seconds ? `${Number((plan.duration_seconds / 3600).toFixed(1))} hours` : ''
  return [time, data].filter(Boolean).join(' · ')
})
</script>

<template>
  <article class="wifi-ticket" :class="{ 'wifi-ticket--thermal': thermal }">
    <div class="wifi-ticket__top">
      <div class="wifi-ticket__brand"><i class="bi bi-wifi" aria-hidden="true"></i><span>{{ title || 'RJAY WiFi' }}</span></div>
      <span class="wifi-ticket__kind">WI-FI VOUCHER</span>
    </div>
    <div class="wifi-ticket__body">
      <div class="wifi-ticket__eyebrow">GET CONNECTED</div>
      <h2>{{ ticket.plan?.name || 'Internet access' }}</h2>
      <p v-if="packageDetails" class="wifi-ticket__details">{{ packageDetails }}</p>
      <p v-if="wifiName" class="wifi-ticket__network">Network: <strong>{{ wifiName }}</strong></p>
      <div class="wifi-ticket__credentials">
        <div><span>VOUCHER CODE</span><strong>{{ ticket.code }}</strong></div>
        <div v-if="ticket.password"><span>PIN</span><strong>{{ ticket.password }}</strong></div>
      </div>
      <p class="wifi-ticket__instructions">{{ instructions }}</p>
      <div class="wifi-ticket__bottom">
        <span>{{ ticket.activated_at ? 'Activated voucher' : 'Time starts on first login' }}</span>
        <strong v-if="showPrice && ticket.plan?.price !== undefined">Package value · TZS {{ Number(ticket.plan.price).toLocaleString() }}</strong>
      </div>
    </div>
  </article>
</template>

<style scoped>
.wifi-ticket { width: 100%; max-width: 440px; overflow: hidden; border: 1px solid #d7e3df; border-radius: 18px; background: #fff; color: #15332d; box-shadow: 0 8px 24px rgba(15, 53, 43, .12); }
.wifi-ticket__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; background: #0c6653; color: #fff; }
.wifi-ticket__brand { display: flex; align-items: center; gap: 7px; min-width: 0; font-weight: 800; overflow-wrap: anywhere; }
.wifi-ticket__kind { font-size: .57rem; font-weight: 800; letter-spacing: .1em; white-space: nowrap; }
.wifi-ticket__body { position: relative; padding: 18px; background: radial-gradient(circle at 100% 0%, #e0f5ed 0 17%, transparent 17.5%), #fff; }
.wifi-ticket__eyebrow { color: #a06b1e; font-size: .64rem; font-weight: 800; letter-spacing: .12em; }
.wifi-ticket h2 { margin: 3px 0 0; color: #0c6653; font-size: 1.5rem; line-height: 1.1; }
.wifi-ticket p { margin: 6px 0 0; }
.wifi-ticket__details, .wifi-ticket__network { font-size: .76rem; }
.wifi-ticket__credentials { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 16px; }
.wifi-ticket__credentials div { min-width: 0; padding: 9px; border: 1px dashed #8bb8aa; border-radius: 10px; background: #f2faf7; }
.wifi-ticket__credentials span { display: block; color: #49685f; font-size: .57rem; font-weight: 800; letter-spacing: .06em; }
.wifi-ticket__credentials strong { display: block; margin-top: 4px; font-family: ui-monospace, monospace; font-size: .9rem; overflow-wrap: anywhere; }
.wifi-ticket__instructions { color: #39584f; font-size: .72rem; }
.wifi-ticket__bottom { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 10px; margin-top: 15px; padding-top: 9px; border-top: 1px solid #d7e3df; font-size: .63rem; }
.wifi-ticket--thermal { border: 0; border-radius: 0; box-shadow: none; }
.wifi-ticket--thermal .wifi-ticket__top { padding: 8px; background: #fff; color: #111; border-bottom: 1px dashed #777; }
.wifi-ticket--thermal .wifi-ticket__body { padding: 9px; background: #fff; }
.wifi-ticket--thermal .wifi-ticket__credentials { grid-template-columns: 1fr; }
@media print { .wifi-ticket { max-width: none; break-inside: avoid; box-shadow: none; print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
</style>
