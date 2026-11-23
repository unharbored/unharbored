<script setup>
/**
 * A summary card of label and value rows. With `action` the whole card is a button that opens something
 * (a drawer) and the action reads in the corner:
 *   <InfoCard title="Firewall" action="Edit" :rows="[['Status', 'On'], ['SSH', '2 addresses']]" @open="open = true" />
 * A row's third item tints its value: 'good', 'warn', 'bad' or 'faint'. The default slot adds anything else under the rows.
 */
defineProps({ title: String, count: [String, Number], action: String, rows: { type: Array, default: () => [] } });
const emit = defineEmits(['open']);
const tint = { good: 'text-accent', warn: 'text-running', bad: 'text-failed', faint: 'text-faint' };
</script>

<template>
  <section class="flex min-w-0 flex-col overflow-hidden rounded-panel border border-line bg-panel shadow-panel" :class="action ? 'transition-colors hover:border-line-control' : ''">
    <component :is="action ? 'button' : 'div'" :type="action ? 'button' : undefined" class="flex w-full items-center justify-between gap-3 border-0 border-b border-line-soft bg-transparent px-[18px] py-3.5 text-left font-body text-[15px] font-semibold text-ink-strong" :class="action ? 'cursor-pointer' : ''" @click="action && emit('open')">
      <span>{{ title }}<span v-if="count !== undefined" class="ml-2 font-mono text-[11px] font-normal text-faint">{{ count }}</span></span>
      <span v-if="action" class="font-mono text-[10.5px] font-normal tracking-[0.1em] text-faint uppercase">{{ action }} ›</span>
    </component>
    <div v-for="[label, value, tone] in rows" :key="label" class="flex justify-between gap-3 border-b border-line-soft px-[18px] py-2.5 text-[13px] last:border-b-0">
      <span class="text-muted">{{ label }}</span><span class="text-right font-mono" :class="tint[tone] ?? 'text-ink'">{{ value }}</span>
    </div>
    <slot />
  </section>
</template>
