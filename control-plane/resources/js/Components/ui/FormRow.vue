<script setup>
/**
 * Fields and a button side by side, wrapping on a narrow screen. Each <Field> grows from 180px;
 * give one a fixed width with `class="!flex-[0_1_150px]"`. Renders a <div>; pass `tag="form"` for a form.
 */
defineProps({ tag: { type: String, default: 'div' } });
</script>

<template>
  <component :is="tag" class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]"><slot /></component>
</template>
