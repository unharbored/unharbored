<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { confirm } from '../../composables/useConsole.js';
import { useTerminal } from '../../composables/useTerminal.js';
import Drawer from '../ui/Drawer.vue';
import Btn from '../ui/Btn.vue';

const props = defineProps({ open: Boolean, node: Object, socketUrl: String });
const emit = defineEmits(['close']);

const t = useTerminal(props.socketUrl);
const { opening, note } = t;
const runAs = ref('deploy');
const termEl = ref(null);
const started = ref(false);
let watcher = null;

async function begin() {
    if (t.live()) {
        t.focus();
        return;
    }
    if (runAs.value === 'root' && !(await confirm({ title: 'Open a console as root?', message: 'It can change anything on the server.', ok: 'Open as root', tone: 'danger' }))) {
        return;
    }
    started.value = true;
    await nextTick();
    // The terminal follows the drawer when it goes full screen and back.
    watcher = new ResizeObserver(() => t.refit());
    watcher.observe(termEl.value);
    await t.start(termEl.value, { node: props.node.id, as: runAs.value });
}

function stop() {
    watcher?.disconnect();
    t.dispose();
}

function shut() {
    stop();
    started.value = false;
    emit('close');
}

watch(() => props.open, (now) => {
    if (!now && started.value) {
        stop();
        started.value = false;
    }
});
onBeforeUnmount(stop);
</script>

<template>
  <Drawer :open="open" :title="`Console · ${node.name}`" note="A shell through the agent. Audited; nothing typed is stored." wide expandable @close="shut">
    <div v-if="!node.connected" class="text-sm text-muted">This server is not connected, so a console cannot be opened.</div>
    <template v-else>
      <div v-if="!started" class="flex flex-wrap items-center gap-2">
        <select v-model="runAs" aria-label="Run as" class="max-w-[160px]"><option value="deploy">as deploy</option><option value="root">as root</option></select>
        <Btn primary :disabled="opening" @click="begin">Open console</Btn>
      </div>
      <p v-if="note" class="m-0 font-mono text-xs text-running">{{ note }}</p>
      <div v-show="started" class="min-h-[320px] flex-1 rounded-[10px] bg-[#101114] px-4 pt-3.5 pb-1.5"><div ref="termEl" class="h-full [&_.xterm-viewport]:!bg-transparent"></div></div>
    </template>
    <template #footer><span class="mr-auto"></span><Btn @click="shut">Close</Btn></template>
  </Drawer>
</template>
