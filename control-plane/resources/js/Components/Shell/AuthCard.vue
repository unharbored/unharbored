<script setup>
/**
 * The card on the sign-in pages: a small caption row, the page title, then the content.
 *   <AuthCard as="form" label="auth" detail="password" title="Sign in" @submit.prevent="send">…</AuthCard>
 */
defineProps({ as: { type: String, default: 'div' }, label: String, detail: String, title: String });
</script>

<template>
  <component :is="as" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
    <div class="flex flex-wrap items-center justify-between gap-x-4 px-6 pt-5 font-mono text-[11px] tracking-[0.06em] text-muted">
      <span class="text-[10px] tracking-[0.18em] uppercase">{{ label }}</span><span>{{ detail }}</span>
    </div>
    <div class="flex flex-col gap-[18px] px-6 pt-3.5 pb-[26px]">
      <h1 class="m-0 font-display text-[34px] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ title }}<span class="text-butter" aria-hidden="true">.</span></h1>
      <slot />
    </div>
  </component>
</template>
