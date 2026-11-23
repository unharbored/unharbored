<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Notice from '../ui/Notice.vue';
import Btn from '../ui/Btn.vue';

/**
 * Shown while an application's creation waits for its deploy key to be added to the repository host.
 * `awaiting` is { key, host, path, operationId }; `link` adds a way to the operation from other pages.
 */
defineProps({ awaiting: Object, link: Boolean });

const copied = ref('');
async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = 'Copied.';
    } catch {
        copied.value = 'Select the key and copy it.';
    }
}
</script>

<template>
  <Notice v-if="awaiting.problem">
    <span class="min-w-0 flex-1"><b>The deploy key works, but the branch needs changing.</b> {{ awaiting.problem }}</span>
    <Btn small :href="`/site/settings?site=${awaiting.siteId}&tab=source`" inertia>Change the branch</Btn>
  </Notice>
  <Notice v-else>
    <div class="flex min-w-0 flex-1 flex-col gap-2.5">
      <span><b>Add this deploy key to {{ awaiting.host }} to continue.</b> Open <b>{{ awaiting.path }}</b> there, go to Settings → Repository → Deploy keys (GitHub: Settings → Deploy keys), paste the key, and leave write access off. Creating the application carries on by itself once it is added.</span>
      <code class="block rounded-lg border border-running-line bg-panel px-3 py-2 font-mono text-xs break-all text-ink select-all">{{ awaiting.key }}</code>
      <span class="flex flex-wrap items-center gap-3">
        <Btn small @click="copy(awaiting.key)">Copy key</Btn>
        <Link v-if="link" :href="`/operation?operation=${awaiting.operationId}`">See the progress</Link>
        <span v-if="copied" role="status" class="text-[13px]">{{ copied }}</span>
      </span>
    </div>
  </Notice>
</template>
