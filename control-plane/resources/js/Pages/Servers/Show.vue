<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import { usePolling } from '../../composables/usePolling.js';
import { useKeptErrors } from '../../Components/Servers/api.js';
import { useServerActions } from '../../Components/Servers/serverActions.js';
import Overview from '../../Components/Servers/Overview.vue';
import Metrics from '../../Components/Servers/Metrics.vue';
import Commands from '../../Components/Servers/Commands.vue';
import Access from '../../Components/Servers/Access.vue';
import Settings from '../../Components/Servers/Settings.vue';
import FirewallDrawer from '../../Components/Servers/FirewallDrawer.vue';
import ConsoleDrawer from '../../Components/Servers/ConsoleDrawer.vue';
import Tabs from '../../Components/ui/Tabs.vue';
import Notice from '../../Components/ui/Notice.vue';
import Chip from '../../Components/ui/Chip.vue';
import Btn from '../../Components/ui/Btn.vue';

defineOptions({ layout: Console });

const props = defineProps({
    tab: String,
    chart: String,
    gatewayUp: Boolean,
    phpVersions: Array,
    consoles: Object,
    node: Object,
    underneath: Object,
    commands: Object,
});

const page = usePage();
const can = computed(() => page.props.can);
const n = computed(() => props.node);
const { errors, clearErrors } = useKeptErrors();
const { unavailable, act, busyReason } = useServerActions(n, clearErrors);
const readonly = computed(() => !can.value.infrastructure);
// An archived server can be read, not changed: only Settings stays open, for Restore and the ways to end its life.
const frozen = computed(() => readonly.value || n.value.archived);

usePolling(['node', 'commands', 'gatewayUp'], 5000);

const tabs = [['overview', 'Overview'], ['metrics', 'Metrics'], ['logs', 'Logs'], ['commands', 'Commands'], ['access', 'Access'], ['settings', 'Settings']];
function pick(key) {
    if (key === 'logs') {
        router.visit(`/logs?server=${encodeURIComponent(n.value.id)}`);

        return;
    }
    router.get(window.location.pathname, { tab: key, chart: props.chart }, { only: ['tab', 'underneath'], preserveState: true, preserveScroll: true, replace: true });
}

const firewalling = ref(false);
const consoling = ref(false);

const c = computed(() => n.value.compute);
const where = computed(() => [c.value.providerLabel ?? 'Your own server', c.value.regionLabel ?? c.value.region].filter(Boolean).join(' · '));
const size = computed(() => [c.value.sizeLabel ?? c.value.size, c.value.price !== null ? `~$${c.value.price}/mo` : null].filter(Boolean).join(' · '));

const consoleReady = computed(() => can.value.console && props.consoles.enabled && n.value.connected && n.value.sites.length > 0);
const consoleNote = computed(() => {
    if (!props.consoles.enabled) return 'Consoles are off for this team. Turn them on under Account.';
    if (!n.value.connected) return 'This server is not connected, so a console cannot be opened.';
    if (n.value.sites.length === 0) return 'A console is opened through an application on the server, and this one has none yet.';

    return '';
});
const busyTip = computed(() => busyReason(''));
const reserved = computed(() => n.value.role === 'incus-host' || n.value.role === 'ingress');
</script>

<template>
  <Head :title="n.name" />
  <div class="flex flex-col gap-2.5">
    <Link href="/servers" class="font-mono text-[10px] tracking-[0.2em] text-accent uppercase">← All servers</Link>
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
      <h1 class="m-0 flex items-center gap-3 font-display text-[clamp(32px,5vw,44px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">
        <span class="size-3 flex-none rounded-full" :class="n.stale || !n.connected ? 'bg-failed' : 'bg-accent'" aria-hidden="true"></span>{{ n.name }}
      </h1>
      <div class="flex flex-wrap gap-2"><Btn v-if="can.console && consoles.enabled" :disabled="!consoleReady" @click="consoling = true">Open console</Btn></div>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-muted">
      <span>{{ where }}</span><span v-if="size" class="font-mono">{{ size }}</span><span v-if="n.ip" class="font-mono">{{ n.ip }}</span>
      <Chip v-if="n.pool"><Link :href="`/servers/pool/${n.pool.site}`">in an autoscale pool</Link></Chip>
      <Chip v-if="n.role === 'incus-host'">Incus host</Chip>
      <Chip v-else-if="n.role === 'ingress'">IPv4 ingress</Chip>
      <span v-if="n.stale" class="text-failed">● Droplet deleted</span>
      <span v-else-if="n.connected" class="text-accent">● connected {{ n.since }}</span>
      <span v-else class="text-failed">● not connected · last seen {{ n.lastSeen }}</span>
    </div>
  </div>

  <Notice v-if="!gatewayUp" tone="bad">The gateway is unreachable. Every server keeps serving its sites, but none can be commanded until it returns.</Notice>
  <Notice v-if="n.archived" tone="plain">
    <span>Archived: Unharbored no longer manages this server. It keeps running as it was, and nothing here can change it until it is restored.</span>
    <Btn v-if="can.infrastructure" small @click="act('restore')">Restore</Btn>
  </Notice>
  <Notice v-if="!can.infrastructure" tone="plain">You can look at this server but not change it: setting up, repairing, locking down or removing a server needs a Manager or an Owner.</Notice>
  <div v-if="n.phpWarnings && Object.keys(n.phpWarnings).length > 0 && tab === 'overview'" class="flex flex-col gap-1">
    <Notice v-for="(list, v) in n.phpWarnings" :key="v" tone="plain" class="!justify-start">⚠ PHP {{ v }}: {{ list.join(' ') }}</Notice>
  </div>

  <Notice v-if="n.role === 'incus-host'" tone="plain" class="!justify-start">
    This machine is an Incus host: nothing runs on it directly, only on the servers inside it.
    <template v-if="n.hosted.length > 0"> Servers on it: {{ n.hosted.join(', ') }}.</template>
    <template v-else> No servers on it yet; create one with Incus as the provider.</template>
  </Notice>
  <Notice v-else-if="n.role === 'ingress'" tone="plain" class="!justify-start">
    This is the Incus host's IPv4 ingress. It routes visitors without IPv6 to the servers on the host, and goes when the host is removed.
  </Notice>

  <Tabs :tabs="tabs" :model-value="tab" label="Server" @update:model-value="pick" />

  <Overview v-if="tab === 'overview' && !reserved" :node="n" :php-versions="phpVersions" :errors="errors" :readonly="frozen" :act="act" :clear="clearErrors" @firewall="firewalling = true" />
  <Metrics v-else-if="tab === 'metrics'" :node="n" :chart="chart" />
  <Commands v-else-if="tab === 'commands'" :commands="commands" :console-ready="consoleReady" :console-note="consoleNote" @console="consoling = true" />
  <Access v-else-if="tab === 'access'" :node="n" :errors="errors" :unavailable="unavailable" :readonly="frozen" :act="act" :busy-reason="busyReason" @firewall="firewalling = true" />
  <Settings v-else-if="tab === 'settings'" :node="n" :underneath="underneath" :errors="errors" :unavailable="unavailable" :readonly="readonly" :act="act" />

  <FirewallDrawer :open="firewalling" :node="n" :errors="errors" :readonly="frozen" :unavailable="unavailable" :tip="busyTip" @close="firewalling = false" @act="clearErrors" />
  <ConsoleDrawer :open="consoling" :node="n" :socket-url="consoles.socketUrl" @close="consoling = false" />
</template>
