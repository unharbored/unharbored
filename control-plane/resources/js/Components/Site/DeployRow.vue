<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '../ui/Badge.vue';

/**
 * One deploy: a status icon, the commit, the release (a link to its operation), a badge, who and when (#meta),
 * and an action button (the default slot). state: "ok", "failed" or anything else for in progress.
 */
defineProps({ state: String, label: String, commit: String, href: String, text: String, badge: String, badgeStatus: String });

const icon = (state) => (state === 'failed'
    ? 'bg-failed text-failed-bg'
    : state === 'ok'
        ? 'bg-accent text-on-accent'
        : 'animate-[breathe_2.4s_ease-in-out_infinite] border border-running-line bg-running-bg text-[9px] text-running');
const mark = (state) => (state === 'ok' ? '✓' : state === 'failed' ? '✕' : '●');
</script>

<template>
  <li class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line-soft px-5 py-[13px] text-sm first:border-t-0">
    <span class="inline-flex size-5 flex-none items-center justify-center rounded-full text-[11px] font-bold" :class="icon(state)" :aria-label="label">{{ mark(state) }}</span>
    <span class="min-w-16 font-mono text-[12.5px] text-ink-strong">{{ commit ?? '—' }}</span>
    <Link class="min-w-0 break-words text-ink max-sm:inline-block max-sm:py-2" :href="href">{{ text }}</Link>
    <Badge v-if="badge" plain :status="badgeStatus || 'neutral'">{{ badge }}</Badge>
    <span class="ml-auto flex flex-wrap justify-end gap-x-4 gap-y-1 text-[13px] text-muted max-[680px]:ml-9 max-[680px]:justify-start [&_code]:rounded-md [&_code]:border [&_code]:border-line [&_code]:bg-raised [&_code]:px-1.5 [&_code]:py-px [&_code]:text-[11.5px] [&_code]:text-ink"><slot name="meta" /></span>
    <slot />
  </li>
</template>
