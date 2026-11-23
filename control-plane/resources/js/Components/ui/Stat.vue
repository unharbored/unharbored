<script setup>
/**
 * One fact: a small-caps label over a value, a dashed rule above.
 *   <Stat label="Port" big>8080<template #hint>on 127.0.0.1:8080</template></Stat>
 * `big` sets the value in larger, stronger type. The label can be a slot (#label) when it has markup.
 */
defineProps({ label: String, big: Boolean });
</script>

<template>
  <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]">
    <dt class="m-0 font-mono text-[9.5px] tracking-[0.18em] text-muted uppercase"><slot name="label">{{ label }}</slot></dt>
    <dd class="m-0 break-words" :class="big ? 'text-[15px] font-semibold text-ink-strong' : ''"><slot /></dd>
    <dd v-if="$slots.hint" class="m-0 text-[13px] break-words text-faint"><slot name="hint" /></dd>
  </div>
</template>
