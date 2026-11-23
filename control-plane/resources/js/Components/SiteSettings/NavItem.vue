<script setup>
import { Link } from '@inertiajs/vue3';

/** One link in the Settings side menu: current tab highlighted, `plain` for a page that is a full load. */
defineProps({ href: String, current: Boolean, plain: Boolean });
</script>

<template>
  <component
    :is="plain ? 'a' : Link"
    :href="href"
    :preserve-scroll="plain ? undefined : true"
    :aria-current="current ? 'page' : undefined"
    class="rounded-[10px] px-3 py-[7px] text-sm no-underline hover:text-ink-strong hover:no-underline"
    :class="current ? 'bg-raised font-medium text-ink-strong' : 'text-muted hover:bg-[var(--nav-hover)]'"
  ><slot /></component>
</template>
