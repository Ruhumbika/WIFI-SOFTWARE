<script setup lang="ts">
withDefaults(defineProps<{ busy?: boolean }>(), { busy: false })
</script>

<template>
  <svg class="animated-wifi" :class="{ 'animated-wifi--busy': busy }" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" aria-hidden="true">
    <path class="animated-wifi__arc animated-wifi__arc--outer" d="M5 15a23 23 0 0 1 30 0" />
    <path class="animated-wifi__arc animated-wifi__arc--middle" d="M10 21a15 15 0 0 1 20 0" />
    <path class="animated-wifi__arc animated-wifi__arc--inner" d="M15 27a7 7 0 0 1 10 0" />
    <circle cx="20" cy="33" r="2" fill="currentColor" stroke="none" />
  </svg>
</template>

<style scoped>
.animated-wifi { display: block; width: 1em; height: 1em; }
.animated-wifi--busy .animated-wifi__arc { animation: wifi-wave 1.8s ease-in-out infinite; }
.animated-wifi--busy .animated-wifi__arc--inner { animation-delay: 0s; }
.animated-wifi--busy .animated-wifi__arc--middle { animation-delay: .22s; }
.animated-wifi--busy .animated-wifi__arc--outer { animation-delay: .44s; }
@keyframes wifi-wave { 0%, 18%, 100% { opacity: .18; } 42%, 72% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .animated-wifi--busy .animated-wifi__arc { animation: none; opacity: 1; } }
</style>
