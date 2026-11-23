<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { usage } from './serverActions.js';

const props = defineProps({ node: Object, chart: String });
const m = computed(() => props.node.metrics);
const ranges = { '1h': 'Last hour', '24h': 'Last day', '7d': 'Last 7 days' };

function setRange(event) {
    router.get(window.location.pathname, { tab: 'metrics', chart: event.target.value }, { only: ['chart', 'node', 'tab'], preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <h2 class="m-0 font-body text-[17px] font-semibold text-ink-strong">Metrics</h2>
    <select aria-label="Time range" class="max-w-[200px]" :value="chart" @change="setRange"><option v-for="(label, key) in ranges" :key="key" :value="key">{{ label }}</option></select>
  </div>

  <div v-if="!m" class="rounded-panel border border-line bg-panel px-6 py-10 text-center text-sm text-muted shadow-panel">The agent has not reported yet, so there is nothing to chart.</div>
  <div v-else class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
    <div class="px-[18px] py-4">
      <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-[13px]">
        <b class="font-semibold text-ink-strong">CPU and memory</b>
        <span class="flex gap-4 text-muted"><span><span class="text-accent">━</span> CPU {{ m.cpu }}%</span><span><span class="text-peri">━</span> memory {{ m.mem ?? '?' }}%</span><span v-if="m.peak !== null">peak {{ m.peak }}%</span></span>
      </div>
      <svg v-if="m.cpuLine" class="block h-[150px] w-full" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" :aria-label="`CPU and memory, ${ranges[chart]}`">
        <line x1="0" y1="0.5" x2="240" y2="0.5" stroke="var(--line)" stroke-width="1" vector-effect="non-scaling-stroke"/><line x1="0" y1="24" x2="240" y2="24" stroke="var(--line)" stroke-dasharray="3 5" vector-effect="non-scaling-stroke"/><line x1="0" y1="47.5" x2="240" y2="47.5" stroke="var(--line)" vector-effect="non-scaling-stroke"/>
        <polyline :points="m.memLine" fill="none" stroke="var(--peri)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
        <polyline :points="m.cpuLine" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
      </svg>
      <p v-else class="m-0 py-10 text-center text-[13px] text-faint">Not enough samples in this range to draw a line yet.</p>
    </div>
    <div class="grid border-t border-line-soft sm:grid-cols-2">
      <div class="border-b border-line-soft px-[18px] py-4 sm:border-r sm:border-b-0">
        <div class="mb-2 flex justify-between gap-2 text-[13px]"><b class="font-semibold text-ink-strong">Disk</b><span class="text-muted"><b :class="usage(m.disk ?? 0)">{{ m.disk ?? '?' }}%</b> used · {{ m.diskFree }}</span></div>
        <div class="h-2 overflow-hidden rounded-full bg-raised"><div class="h-full rounded-full" :class="(m.disk ?? 0) >= 90 ? 'bg-failed' : 'bg-butter'" :style="{ width: `${m.disk ?? 0}%` }"></div></div>
        <p class="m-0 mt-2 text-[12.5px] text-faint">The latest reading. Disk is not charted over time.</p>
      </div>
      <div class="px-[18px] py-4">
        <div class="mb-2 flex justify-between gap-2 text-[13px]"><b class="font-semibold text-ink-strong">Network</b><span class="text-faint">not collected yet</span></div>
        <p class="m-0 text-[12.5px] text-faint">The agent does not report network traffic today. It will show here once it does.</p>
      </div>
    </div>
    <div class="border-t border-line-soft px-[18px] py-3 text-[12.5px] text-faint">Last sample {{ m.ago }}.</div>
  </div>
</template>
