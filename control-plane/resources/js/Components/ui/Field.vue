<script setup>
import { useId } from 'vue';

/**
 * A label above one control, with an optional hint and its error underneath:
 *   <Field label="Email" hint="Where alerts go" :error="form.errors.email" v-slot="{ id }"><input :id="id" v-model="form.email"></Field>
 * The control takes the id the slot hands it, so the label is tied to it. Extra attributes
 * (a class, a style) land on the wrapper. Text inputs, selects and textareas are styled by
 * the base rules in app.css, so the control itself needs no classes.
 */
defineProps({ label: String, hint: String, error: String });
const id = useId();
</script>

<template>
  <div class="field flex flex-col gap-[7px]">
    <label :for="id" class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">{{ label }}<slot name="label" /></label>
    <slot :id="id" />
    <span v-if="hint || $slots.hint" class="text-[13px] text-faint"><slot name="hint">{{ hint }}</slot></span>
    <span v-if="error" class="text-[13px] text-failed" role="alert">{{ error }}</span>
  </div>
</template>
