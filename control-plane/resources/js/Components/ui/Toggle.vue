<script setup>
import { computed, useSlots } from 'vue';

/**
 * An on/off switch. On is a solid accent track with the knob right and "ON"; off is an empty outlined track with the
 * knob left and "OFF". Three ways to use it:
 *   <Toggle v-model="form.enabled">Turn the firewall on</Toggle>      a setting with its words (the words toggle it too)
 *   <Toggle :model-value="data.on" @update:model-value="send">…</Toggle>   when the change is sent straight away
 *   <Toggle :checked="on" label="Install on this server" @click="flip" />  bare, for a row that has its own words
 */
const props = defineProps({ modelValue: Boolean, checked: Boolean, label: String });
const emit = defineEmits(['update:modelValue']);
const slots = useSlots();
const on = computed(() => props.modelValue || props.checked);
const track = computed(() => [
    'relative h-6 w-[54px] flex-none cursor-pointer rounded-full border p-0 font-mono text-[9px] font-semibold tracking-[0.08em] disabled:cursor-not-allowed disabled:opacity-50',
    on.value ? 'border-accent bg-accent text-white' : 'border-line-strong bg-transparent text-muted',
]);
const word = computed(() => ['absolute top-1/2 -translate-y-1/2', on.value ? 'left-2.5' : 'right-2.5']);
const knob = computed(() => ['absolute top-0.5 size-[18px] rounded-full shadow-sm transition-all duration-150', on.value ? 'left-[32px] bg-white' : 'left-0.5 bg-muted']);
</script>

<template>
  <label v-if="slots.default" class="inline-flex min-h-11 cursor-pointer items-center gap-2.5">
    <button type="button" role="switch" :aria-checked="on ? 'true' : 'false'" :class="track" @click="emit('update:modelValue', !on)">
      <span :class="word">{{ on ? 'ON' : 'OFF' }}</span><span :class="knob"></span>
    </button>
    <span><slot /></span>
  </label>
  <button v-else type="button" role="switch" :aria-checked="on ? 'true' : 'false'" :aria-label="label" :class="track" @click="emit('update:modelValue', !on)">
    <span :class="word">{{ on ? 'ON' : 'OFF' }}</span><span :class="knob"></span>
  </button>
</template>
