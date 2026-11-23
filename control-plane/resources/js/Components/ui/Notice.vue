<script setup>
/**
 * A boxed message. tone: "warn" (amber, the default), "bad" (red, for something broken).
 * Anything that needs the reader's attention now gets role="alert"; the rest role="status".
 */
defineProps({ tone: { type: String, default: 'warn' }, alert: Boolean });
</script>

<template>
  <div
    class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border px-[18px] py-[13px]"
    :class="tone === 'bad' ? 'border-failed-line bg-failed-bg text-failed' : 'border-running-line bg-running-panel text-running-soft'"
    :role="alert || tone === 'bad' ? 'alert' : 'status'"
  ><slot /></div>
</template>
