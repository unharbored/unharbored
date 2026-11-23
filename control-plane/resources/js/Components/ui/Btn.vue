<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * A button, or a link that looks like one:
 *   <Btn>Save</Btn>   <Btn primary small>Deploy</Btn>   <Btn danger>Remove</Btn>
 *   <Btn href="/servers">Servers</Btn>   <Btn href="/alerts" inertia>Alerts</Btn>
 * A button is type="button" unless you say type="submit". `inertia` makes a link a client-side visit
 * (only to a page that is an Inertia page); without it a link is a full page load.
 */
const props = defineProps({
    href: String,
    inertia: Boolean,
    primary: Boolean,
    danger: Boolean,
    small: Boolean,
    type: { type: String, default: 'button' },
});

const tag = computed(() => (props.href ? (props.inertia ? Link : 'a') : 'button'));
const classes = computed(() => [
    'inline-flex cursor-pointer items-center justify-center gap-2 rounded-[11px] border leading-[1.2] whitespace-nowrap no-underline transition-colors hover:no-underline active:translate-y-px',
    'aria-disabled:translate-y-0 aria-disabled:cursor-not-allowed aria-disabled:border-line-soft aria-disabled:bg-raised aria-disabled:text-muted aria-disabled:opacity-55',
    'disabled:translate-y-0 disabled:cursor-not-allowed disabled:border-line-soft disabled:bg-raised disabled:text-muted disabled:opacity-55',
    props.small ? 'min-h-[38px] px-[13px] py-[7px] text-[13px] font-medium' : 'min-h-[42px] px-4 py-[9px] text-sm font-medium',
    props.primary
        ? 'border-accent bg-accent font-semibold text-on-accent hover:border-accent-hover hover:bg-accent-hover hover:text-on-accent'
        : props.danger
          ? 'border-failed-line bg-failed-bg text-failed hover:border-failed hover:bg-[color-mix(in_srgb,var(--failed-bg)_85%,var(--failed))] hover:text-failed'
          : 'border-line bg-raised text-ink hover:border-line-control hover:bg-[color-mix(in_srgb,var(--panel-raised)_80%,var(--accent))] hover:text-ink-strong',
]);
</script>

<template>
  <component :is="tag" :href="href" :type="href ? undefined : type" :class="classes"><slot /></component>
</template>
