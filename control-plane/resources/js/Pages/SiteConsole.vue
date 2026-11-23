<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useTerminal } from '../composables/useTerminal';
import { Head, router } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { confirm } from '../composables/useConsole';
import FormError from '../Components/ui/FormError.vue';
import Btn from '../Components/ui/Btn.vue';

defineOptions({ layout: Console });

const props = defineProps({ site: Object, enabled: Boolean, servers: Array, gatewayUp: Boolean, sessions: Array, socketUrl: String });

const anyConnected = computed(() => props.servers.some((s) => s.connected));
const node = ref(props.servers[0]?.id ?? '');
const runAs = ref('deploy');
const open = ref(false);
const minimised = ref(false);
const title = ref('console');
const sub = ref('');
const termEl = ref(null);

const pick = 'min-h-[42px] rounded-[11px] border border-line-control bg-raised px-2.5 font-mono text-[13px] text-ink';
const empty = 'rounded-panel border border-line bg-panel px-[18px] py-7 text-center text-sm text-muted';
const bar = 'size-[34px] cursor-pointer rounded-lg border-0 bg-transparent text-lg leading-none text-[#a5a7b3] hover:bg-[#2a2b32] hover:text-white';

const t = useTerminal(props.socketUrl);
const { opening, note } = t;

function shut() {
    t.dispose();
    open.value = false;
    minimised.value = false;
    // The list shows the console that has just ended.
    setTimeout(() => router.reload({ only: ['sessions'], preserveScroll: true }), 300);
}

async function openConsole() {
    if (t.live()) { minimised.value = false; t.focus(); return; }
    const as = runAs.value;
    if (as === 'root' && !(await confirm({ title: 'Open a console as root?', message: 'It can change anything on the server.', ok: 'Open as root', tone: 'danger' }))) return;

    open.value = true; minimised.value = false;
    title.value = node.value.split('.')[0];
    sub.value = `as ${as}`;
    await new Promise((r) => setTimeout(r, 0)); // the panel is on the page before the terminal opens in it
    await t.start(termEl.value, { site: props.site.id, node: node.value, as });
    if (note.value) open.value = false;
}

function toggleMin() {
    minimised.value = !minimised.value;
    if (!minimised.value) { setTimeout(() => { t.refit(); t.focus(); }, 0); }
}
const refit = () => { if (open.value && !minimised.value) t.refit(); };
onMounted(() => window.addEventListener('resize', refit));
onBeforeUnmount(() => { window.removeEventListener('resize', refit); t.dispose(); });
</script>

<template>
  <Head title="Commands" />
  <div class="mx-auto w-full max-w-[920px] pt-7 pb-[60px]">
    <div class="mb-[18px] flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="m-0 mb-1 font-body text-2xl font-semibold text-ink-strong">Commands</h2>
        <p class="m-0 text-sm text-muted">Open a console on one of this application's servers. For long-running work, use a background process under Settings.</p>
      </div>
      <div v-if="enabled && gatewayUp && anyConnected" class="flex items-center gap-2">
        <select v-if="servers.length > 1" id="con-node" v-model="node" aria-label="Server" :class="pick">
          <option v-for="s in servers" :key="s.id" :value="s.id" :disabled="!s.connected">{{ s.name }}{{ s.connected ? '' : ' (offline)' }}</option>
        </select>
        <select id="con-user" v-model="runAs" aria-label="Run as" :class="pick"><option value="deploy">as deploy</option><option value="root">as root</option></select>
        <Btn id="con-open" primary :disabled="opening" @click="openConsole">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M7 10l3 2-3 2M13 14h4" /></svg>
          Open console
        </Btn>
      </div>
    </div>

    <template v-for="s in servers" :key="s.id">
      <div v-if="s.stale" :class="empty" class="mb-3 !text-left">{{ s.name }} runs agent {{ s.agent }}, not the current build, so a console may not answer. Upgrade it on <a href="/servers">Servers</a>.</div>
    </template>

    <div v-if="!enabled" :class="empty">Consoles are off for this team. <a href="/account">Turn them on under Account</a>.</div>
    <div v-else-if="!gatewayUp" :class="empty">The gateway can't be reached, so no server can be reached either.</div>
    <div v-else-if="!anyConnected" :class="empty">None of this application's servers is connected, so a console cannot be opened.</div>

    <template v-if="enabled">
      <div v-if="sessions.length === 0" :class="empty" class="mt-3">No consoles have been opened for this application.</div>
      <ul v-else class="m-0 mt-3 list-none overflow-hidden rounded-panel border border-line bg-panel p-0" aria-label="Recent consoles">
        <li v-for="c in sessions" :key="c.id" class="flex items-center gap-3 border-t border-line-soft px-[18px] py-4 font-mono text-sm first:border-t-0">
          <span class="size-[9px] flex-none rounded-full" :class="c.open ? 'bg-running' : (c.ended_because !== 'closed' && c.ended_because !== 'exited') ? 'bg-failed' : 'bg-faint'" :title="c.open ? 'open' : c.ended_because"></span>
          <span>Console session {{ c.id }}</span>
          <span class="text-faint">as {{ c.as_user }}</span>
          <span class="ml-auto font-body text-[13px] whitespace-nowrap text-muted">{{ c.opened_by_name }} · {{ c.opened }}</span>
        </li>
      </ul>
    </template>
  </div>

  <!-- The console itself: a dark panel across the foot of the page. A terminal is dark in either theme, so these colours are fixed. -->
  <section
    id="console"
    class="fixed inset-x-0 bottom-0 z-50 flex-col bg-[#1c1d22] text-[#e6e6e6] shadow-[0_-12px_40px_rgba(0,0,0,.35)]"
    :class="[open ? 'flex' : 'hidden', minimised ? 'h-[52px]' : 'h-[62vh]']"
    aria-label="Console"
  >
    <div class="flex h-[52px] flex-none items-center gap-2.5 px-[18px] font-mono text-sm font-semibold text-[#e6e6e6]">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M7 10l3 2-3 2M13 14h4" /></svg>
      <span id="con-title">{{ title }}</span><span id="con-sub" class="text-xs font-normal text-[#8b8d98]">{{ sub }}</span>
      <span class="ml-auto flex gap-1">
        <button id="con-min" type="button" aria-label="Minimise" :class="bar" @click="toggleMin">&minus;</button>
        <button id="con-close" type="button" aria-label="Close the console" :class="bar" @click="shut">&times;</button>
      </span>
    </div>
    <div id="con-note" class="px-[18px] pb-2 font-mono text-xs text-[#f0b429]" :style="{ display: note ? 'block' : 'none' }">{{ note }}</div>
    <div class="mx-2 mb-2 min-h-0 flex-1 rounded-[10px] bg-[#101114] px-4 pt-3.5 pb-1.5" :class="minimised ? 'hidden' : ''"><div id="term" ref="termEl" class="h-full [&_.xterm-viewport]:!bg-transparent"></div></div>
  </section>
  <FormError v-if="!open" :message="note" class="mx-auto max-w-[920px]" />
</template>
