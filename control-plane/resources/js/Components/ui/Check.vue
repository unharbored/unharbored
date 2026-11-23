<script setup>
import { computed, useAttrs } from 'vue';

/**
 * A checkbox with its words: <Check v-model="form.failed">Failed only</Check>. Without v-model it is a plain
 * checkbox for a native form: <Check name="x" value="1" checked>…</Check>. class and style go on the label;
 * every other attribute lands on the input.
 */
defineOptions({ inheritAttrs: false });
defineProps({ modelValue: Boolean });
defineEmits(['update:modelValue']);

const attrs = useAttrs();
const inputAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;
    return rest;
});
</script>

<template>
  <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5" :class="attrs.class" :style="attrs.style">
    <input type="checkbox" class="size-[17px] accent-butter" v-bind="inputAttrs" :checked="modelValue || undefined" @change="$emit('update:modelValue', $event.target.checked)">
    <slot />
  </label>
</template>
