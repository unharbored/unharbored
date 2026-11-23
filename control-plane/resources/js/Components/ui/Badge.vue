<script setup>
/**
 * A small status pill with a dot: <Badge status="live">serving</Badge>.
 * status: "neutral" (default), "live" (green), "running" (amber, the dot breathes), "failed" (red).
 * `plain` leaves the dot out.
 */
defineProps({ status: { type: String, default: 'neutral' }, plain: Boolean });
const tones = {
    neutral: 'border-line bg-[var(--neutral-bg)] text-muted',
    live: 'border-accent-line bg-accent-bg text-accent',
    running: 'border-running-line bg-running-bg text-running before:animate-[breathe_2.4s_ease-in-out_infinite]',
    failed: 'border-failed-line bg-failed-bg text-failed',
};
</script>

<template>
  <span
    class="inline-flex items-center gap-[7px] self-start rounded-full border px-[11px] py-1 font-mono text-[10.5px] tracking-[0.06em] whitespace-nowrap uppercase"
    :class="[tones[status] ?? tones.neutral, plain ? '' : 'before:size-[7px] before:rounded-full before:bg-current before:content-[\'\']']"
  ><slot /></span>
</template>
