<script setup>
/**
 * The title block at the top of a page: <PageHead title="Alerts" lede="Checked every minute.">.
 * The default slot holds lede paragraphs with markup (use <Lede>), #crumbs goes above the title,
 * #actions at the right (buttons, a filter). `dot` is the colour of the full stop after the title.
 */
defineProps({ title: String, lede: String });
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-6">
    <div class="flex min-w-0 flex-col gap-2.5">
      <slot name="crumbs" />
      <h1 class="m-0 font-display text-[clamp(36px,6vw,52px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ title }}<span class="text-butter" aria-hidden="true">.</span></h1>
      <p v-if="lede" class="m-0 max-w-[64ch] leading-[1.65] text-muted">{{ lede }}</p>
      <slot />
    </div>
    <slot name="actions" />
  </div>
</template>
