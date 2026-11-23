<script setup>
/** A line of intro text under a page title, for when it has markup (PageHead's `lede` prop is for plain text). */
</script>

<template>
  <p class="m-0 max-w-[64ch] leading-[1.65] text-muted [&_code]:rounded-md [&_code]:bg-raised [&_code]:px-1.5 [&_code]:py-px [&_code]:font-mono [&_code]:text-[0.86em] [&_code]:text-ink"><slot /></p>
</template>
