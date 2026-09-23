<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  ticket: any
  title?: string
  wifiName?: string
  instructions?: string
  showPrice?: boolean
  showDetails?: boolean
  showInstructions?: boolean
  showActivation?: boolean
  compact?: boolean
  thermal?: boolean
}>(), {
  title: 'RJAY WiFi',
  wifiName: '',
  instructions: 'Connect to the Wi-Fi and enter this code on the login page.',
  showPrice: true,
  showDetails: false,
  showInstructions: false,
  showActivation: false,
  compact: false,
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
  <article class="wifi-ticket" :class="{ 'wifi-ticket--thermal': thermal, 'wifi-ticket--compact': compact }">
    <div class="wifi-ticket__top">
      <div class="wifi-ticket__brand"><i class="bi bi-wifi" aria-hidden="true"></i><span>{{ title || 'RJAY WiFi' }}</span></div>
    </div>
    <div class="wifi-ticket__body">
      <h2>{{ ticket.plan?.name || 'Internet access' }}</h2>
      <p v-if="(thermal || showDetails) && packageDetails" class="wifi-ticket__details">{{ packageDetails }}</p>
      <p v-if="wifiName" class="wifi-ticket__network">Network: <strong>{{ wifiName }}</strong></p>
      <div class="wifi-ticket__credentials">
        <div><span>CODE</span><strong>{{ ticket.code }}</strong></div>
        <div v-if="ticket.password"><span>PIN</span><strong>{{ ticket.password }}</strong></div>
      </div>
      <p v-if="(thermal || showInstructions) && instructions" class="wifi-ticket__instructions">{{ instructions }}</p>
      <div v-if="thermal || showActivation || (showPrice && ticket.plan?.price !== undefined)" class="wifi-ticket__bottom">
        <span v-if="thermal || showActivation">{{ ticket.activated_at ? 'Active' : 'Starts on first login' }}</span>
        <strong v-if="showPrice && ticket.plan?.price !== undefined">Package value · TZS {{ Number(ticket.plan.price).toLocaleString() }}</strong>
      </div>
    </div>
  </article>
</template>

<style scoped>
.wifi-ticket { width: 100%; max-width: 440px; overflow: hidden; border: 1px solid #808060; border-radius: 18px; background: #fff; color: #333329; box-shadow: 0 8px 24px rgba(51, 51, 41, .12); }
.wifi-ticket__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; background: #808060; color: #fff; }
.wifi-ticket__brand { display: flex; align-items: center; gap: 7px; min-width: 0; font-weight: 800; overflow-wrap: anywhere; }
.wifi-ticket__body { position: relative; padding: 18px; background: #fff; }
.wifi-ticket h2 { margin: 3px 0 0; color: #4d4d39; font-size: 1.5rem; line-height: 1.1; }
.wifi-ticket p { margin: 6px 0 0; }
.wifi-ticket__details, .wifi-ticket__network { font-size: .76rem; }
.wifi-ticket__credentials { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 16px; }
.wifi-ticket__credentials div { min-width: 0; padding: 9px; border: 1px solid #c8c8b8; border-radius: 10px; background: #f7f7f2; }
.wifi-ticket__credentials span { display: block; color: #5c5c48; font-size: .57rem; font-weight: 800; letter-spacing: .06em; }
.wifi-ticket__credentials strong { display: block; margin-top: 4px; font-family: ui-monospace, monospace; font-size: .9rem; overflow-wrap: anywhere; }
.wifi-ticket__instructions { color: #4d4d39; font-size: .72rem; }
.wifi-ticket__bottom { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 10px; margin-top: 15px; padding-top: 9px; border-top: 1px solid #c8c8b8; font-size: .63rem; }
.wifi-ticket--thermal { border: 0; border-radius: 0; box-shadow: none; }
.wifi-ticket--thermal .wifi-ticket__top { padding: 8px; background: #fff; color: #111; border-bottom: 1px dashed #777; }
.wifi-ticket--thermal .wifi-ticket__body { padding: 9px; background: #fff; }
.wifi-ticket--thermal .wifi-ticket__credentials { grid-template-columns: 1fr; }
.wifi-ticket--compact { width: 37mm; min-height: 44mm; border: 1px dashed #808060; border-radius: 0; box-shadow: none; }
.wifi-ticket--compact .wifi-ticket__top { gap: 1mm; padding: 1.5mm 2mm; border-bottom: 2px solid #808060; background: #fff; color: #4d4d39; font-size: 7pt; }
.wifi-ticket--compact .wifi-ticket__brand { gap: 1mm; }
.wifi-ticket--compact .wifi-ticket__body { padding: 1.5mm 2mm; background: #fff; }
.wifi-ticket--compact h2 { margin: 0; font-size: 8pt; line-height: 1.1; }
.wifi-ticket--compact p { margin-top: 1mm; font-size: 5.5pt; line-height: 1.2; }
.wifi-ticket--compact .wifi-ticket__credentials { grid-template-columns: 1fr; gap: 1mm; margin-top: 2mm; }
.wifi-ticket--compact .wifi-ticket__credentials div { padding: 1mm; border-radius: 1mm; }
.wifi-ticket--compact .wifi-ticket__credentials span { font-size: 5pt; }
.wifi-ticket--compact .wifi-ticket__credentials strong { margin-top: .5mm; font-size: 7pt; }
.wifi-ticket--compact .wifi-ticket__bottom { gap: 1mm; margin-top: 1mm; padding-top: 1mm; font-size: 5pt; line-height: 1.2; }
@media print {
  .wifi-ticket { max-width: none; break-inside: avoid; box-shadow: none; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .wifi-ticket--compact { width: 100%; }
}
</style>
