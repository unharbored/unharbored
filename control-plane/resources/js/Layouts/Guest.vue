<script setup>
import { usePage } from '@inertiajs/vue3';
import BrandMark from '../Components/Shell/BrandMark.vue';

/** The frame for pages a signed-out person sees: sign in, accept an invitation, not allowed. */
const page = usePage();
</script>

<template>
  <div class="flex min-h-screen items-center justify-center px-4 py-12">
    <main class="flex w-full max-w-[420px] flex-col gap-[22px]">
      <div class="flex flex-col items-center gap-2.5 text-center">
        <span class="flex items-center gap-3 font-display text-[26px] font-semibold tracking-[-0.01em] text-ink-strong"><BrandMark />Unharbored</span>
        <span class="font-mono text-xs text-faint">{{ page.props.host }}</span>
      </div>
      <slot />
      <p class="m-0 text-center font-mono text-[11px] text-faint">One account. Sessions end after 12 hours.</p>
    </main>
  </div>
</template>
