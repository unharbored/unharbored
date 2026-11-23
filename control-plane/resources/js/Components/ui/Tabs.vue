<script setup>
/**
 * A row of tabs, the active one underlined:
 *   <Tabs :tabs="[['overview', 'Overview'], ['metrics', 'Metrics']]" :model-value="tab" @update:model-value="pick" />
 */
defineProps({ tabs: Array, modelValue: String, label: { type: String, default: 'Sections' } });
const emit = defineEmits(['update:modelValue']);
const tab = [
    'relative inline-flex cursor-pointer items-center rounded-t-lg border-0 bg-transparent px-3 py-3 font-mono text-xs tracking-[0.02em] whitespace-nowrap text-muted no-underline transition-colors',
    'hover:bg-[var(--nav-hover)] hover:text-ink',
    'aria-[current=page]:text-ink aria-[current=page]:after:absolute aria-[current=page]:after:inset-x-3 aria-[current=page]:after:-bottom-px aria-[current=page]:after:h-0.5 aria-[current=page]:after:rounded-sm aria-[current=page]:after:bg-butter aria-[current=page]:after:content-[\'\']',
].join(' ');
</script>

<template>
  <nav class="flex gap-0.5 overflow-x-auto border-b border-line-soft [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" :aria-label="label">
    <button v-for="[key, name] in tabs" :key="key" type="button" :class="tab" :aria-current="modelValue === key ? 'page' : undefined" @click="emit('update:modelValue', key)">{{ name }}</button>
  </nav>
</template>
