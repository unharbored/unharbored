<script setup>
import { useId } from 'vue';

/**
 * A titled box: <Panel title="Alerts" note="Checked every minute" id="alerts">…</Panel>.
 * The note can be a slot (#note) when it has markup; #actions sits at the right of the head.
 * `flush` leaves out the padded body, for a table or list that runs edge to edge.
 */
defineProps({ title: String, note: String, id: String, flush: Boolean });
const headingId = useId();
</script>

<template>
  <section :id="id" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel" :aria-labelledby="headingId">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-5 pt-5 font-mono text-[11px] tracking-[0.06em] text-muted sm:px-7 sm:pt-[22px]">
      <h2 :id="headingId" class="m-0 font-display text-[22px] leading-[1.22] font-semibold tracking-[0.005em] break-words text-ink-strong normal-case">{{ title }}</h2>
      <span v-if="note || $slots.note" class="font-display text-sm font-medium tracking-normal text-muted italic"><slot name="note">{{ note }}</slot></span>
      <slot name="actions" />
    </div>
    <slot v-if="flush" />
    <div v-else class="flex flex-col gap-4 px-5 pt-4 pb-[22px] sm:px-7 sm:pt-[18px] sm:pb-6"><slot /></div>
  </section>
</template>
