<script setup lang="ts">
import { useId } from 'vue'
withDefaults(defineProps<{ active?: boolean; status?: 'idle' | 'loading' | 'waiting' | 'success' | 'error' }>(), { active: true, status: 'idle' })
const id = useId().replace(/:/g, '')
const routes = [
  'M42 38 L27 27 L16 27 L8 18', 'M53 29 L45 13 L34 8',
  'M68 24 L65 10 L72 3', 'M81 24 L87 11 L101 7',
  'M96 30 L110 18 L122 18 L133 8', 'M108 40 L126 34 L140 23',
  'M42 58 L27 69 L16 69 L8 78', 'M53 67 L45 83 L34 88',
  'M68 72 L65 86 L72 93', 'M81 72 L87 85 L101 89',
  'M96 66 L110 78 L122 78 L133 88', 'M108 56 L126 62 L140 73',
]
</script>
<template>
  <svg class="signal-eye" :class="[{ 'signal-eye--active': active }, `signal-eye--${status}`]" :data-status="status" viewBox="0 0 148 96" aria-hidden="true" focusable="false">
    <defs>
      <radialGradient :id="id + '-iris'"><stop offset="0" stop-color="#052649"/><stop offset=".42" class="iris-base"/><stop offset=".72" class="iris-color"/><stop offset="1" stop-color="#124579"/></radialGradient>
      <linearGradient :id="id + '-shell'" x2="0" y2="1"><stop stop-color="#fbfdff"/><stop offset="1" stop-color="#bfd1df"/></linearGradient>
    </defs>
    <g fill="none" stroke="#94b7cb" stroke-width="1.2" stroke-linecap="round">
      <path v-for="route in routes" :key="route" :d="route" />
    </g>
    <g class="signal-traces" fill="none" stroke="#00bfea" stroke-width="1.6" stroke-linecap="round">
      <path v-for="(route, i) in routes" :key="route" :d="route" :style="{ animationDelay: `${i * -.19}s` }" />
    </g>
    <path d="M13 48 Q42 14 74 20 Q106 14 135 48 Q106 82 74 76 Q42 82 13 48Z" :fill="`url(#${id}-shell)`" stroke="#8aa9c0" />
    <circle cx="74" cy="48" r="28" fill="#143b60" />
    <circle cx="74" cy="48" r="25" :fill="`url(#${id}-iris)`" />
    <g class="iris-rays" stroke-width="1.4">
      <path v-for="i in 24" :key="i" d="M74 26 V34" :transform="`rotate(${i * 15} 74 48)`" />
    </g>
    <circle class="iris-pupil" cx="74" cy="48" r="12" fill="#06294e" stroke-width="1.4" />
    <path d="M76 40 Q81 40 82 45" fill="none" stroke="#e9fbff" stroke-width="2" stroke-linecap="round" />
    <g fill="#3c8cab"><circle cx="8" cy="18" r="2"/><circle cx="140" cy="23" r="2"/><circle cx="8" cy="78" r="2"/><circle cx="140" cy="73" r="2"/></g>
  </svg>
</template>
<style scoped>
.signal-eye { width:100%; height:100%; display:block; filter:drop-shadow(0 3px 3px #9bb4c466); }
.signal-traces path { stroke-dasharray:5 60; stroke-dashoffset:65; }
.signal-eye--active .signal-traces path { animation:signal-travel 2.8s linear infinite; }
.signal-eye--active .iris-rays { transform-origin:74px 48px; animation:iris-turn 24s linear infinite; }
.signal-eye { --iris-color:#09c8ed; --iris-base:#063c79; }
.iris-base { stop-color:var(--iris-base); transition:stop-color .3s ease; }
.iris-color { stop-color:var(--iris-color); transition:stop-color .3s ease; }
.iris-rays,.iris-pupil { stroke:var(--iris-color); transition:stroke .3s ease; }
.signal-eye--loading { --iris-color:#42deff; --iris-base:#1666c2; }
.signal-eye--waiting { --iris-color:#f4ba55; --iris-base:#966225; }
.signal-eye--success { --iris-color:#45e2b4; --iris-base:#167e75; }
.signal-eye--error { --iris-color:#fb857b; --iris-base:#943f60; }
.signal-eye--active.signal-eye--loading .iris-rays { animation:iris-turn 1.4s linear infinite; }
.signal-eye--active.signal-eye--waiting .iris-rays { animation:iris-turn 5s linear infinite; }
.signal-eye--loading .iris-color { animation:iris-loading 1.4s ease-in-out infinite alternate; }
.signal-eye--success .iris-rays,.signal-eye--error .iris-rays { animation:none; }
@keyframes iris-loading { from { stop-color:#168ae5; } to { stop-color:#79f0ff; } }
@keyframes signal-travel { to { stroke-dashoffset:0; } }
@keyframes iris-turn { to { transform:rotate(360deg); } }
@media(prefers-reduced-motion:reduce) { .signal-eye--active .signal-traces path,.signal-eye.signal-eye--active .iris-rays,.signal-eye--loading .iris-color { animation:none; } .signal-traces path { stroke-dasharray:none; opacity:.5; } }
</style>
