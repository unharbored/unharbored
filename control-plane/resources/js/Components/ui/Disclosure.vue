<script setup>
/**
 * A button that opens a small form or detail underneath it:
 *   <Disclosure label="Replace key" small danger><form>…</form></Disclosure>
 * It is a <details>, so it works without script and keyboard users get it for free.
 */
defineProps({ label: String, small: Boolean, danger: Boolean, primary: Boolean });
</script>

<template>
  <details class="group">
    <summary
      class="inline-flex cursor-pointer list-none items-center justify-center gap-2 rounded-[11px] border font-medium leading-[1.2] whitespace-nowrap select-none [&::-webkit-details-marker]:hidden"
      :class="[
        small ? 'min-h-[38px] px-[13px] py-[7px] text-[13px]' : 'min-h-[42px] px-4 py-[9px] text-sm',
        primary ? 'border-accent bg-accent text-on-accent' : danger ? 'border-failed-line bg-failed-bg text-failed' : 'border-line bg-raised text-ink',
      ]"
    >{{ label }}</summary>
    <div class="mt-3 flex flex-col gap-3"><slot /></div>
  </details>
</template>
