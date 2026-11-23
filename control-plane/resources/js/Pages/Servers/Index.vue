<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import { confirm } from '../../composables/useConsole.js';
import { usePolling } from '../../composables/usePolling.js';
import { useKeptErrors } from '../../Components/Servers/api.js';
import AddServer from '../../Components/Servers/AddServer.vue';
import Panel from '../../Components/ui/Panel.vue';
import PageHead from '../../Components/ui/PageHead.vue';
import Field from '../../Components/ui/Field.vue';
import Notice from '../../Components/ui/Notice.vue';
import FormError from '../../Components/ui/FormError.vue';
import Btn from '../../Components/ui/Btn.vue';
import Badge from '../../Components/ui/Badge.vue';
import Drawer from '../../Components/ui/Drawer.vue';
import Empty from '../../Components/ui/Empty.vue';
import RuleRow from '../../Components/ui/RuleRow.vue';

defineOptions({ layout: Console });

const props = defineProps({
    gatewayUp: Boolean,
    serving: String,
    accountNote: String,
    servers: Array,
    pools: Array,
    provisioning: Array,
    disconnected: Array,
    archives: Array,
    catalogs: Object,
    newKeys: Array,
    byo: Object,
});

const page = usePage();
const can = computed(() => page.props.can);
const { errors, clearErrors } = useKeptErrors();

usePolling(['servers', 'pools', 'provisioning', 'disconnected', 'archives', 'gatewayUp'], 5000);

// Pools first, each with its servers (the primary at the top), then the servers on their own. With no pools
// there are no group rows at all.
const groups = computed(() => {
    const out = props.pools.map((pool) => ({
        pool,
        rows: props.servers.filter((s) => s.pool?.site === pool.site.id).sort((a, b) => Number(b.pool.primary) - Number(a.pool.primary)),
    }));
    const single = props.servers.filter((s) => !s.pool || !props.pools.some((p) => p.site.id === s.pool.site));
    if (single.length > 0) {
        out.push({ pool: null, rows: single, titled: props.pools.length > 0 });
    }

    return out;
});

const money = (c) => (c.price === null || c.price === undefined ? '' : `~$${c.price}/mo`);
const where = (c) => [c.providerLabel ?? 'Your own server', c.regionLabel ?? c.region].filter(Boolean);

async function post(url, message) {
    if (!(await confirm({ message, tone: 'danger' }))) {
        return;
    }
    clearErrors();
    router.post(url, {}, { preserveScroll: true });
}

const adding = ref(false);
const owning = ref(props.byo !== null && props.byo !== undefined);
const own = useForm({ byoName: '' });
function addOwn() {
    clearErrors();
    own.post('/servers/own', { preserveScroll: true, onSuccess: () => { own.reset(); owning.value = true; } });
}

const head = 'px-4 py-3 text-left font-mono text-[10px] font-normal tracking-[0.14em] text-faint uppercase';
const cell = 'border-t border-line-soft px-4 py-3.5 align-middle';
</script>

<template>
  <Head title="Servers" />
  <PageHead title="Servers">
    <p class="m-0 max-w-[64ch] leading-[1.65] text-muted">Shared by the whole team, not filtered by project. Servers made in your connected cloud account, plus ones you added yourself{{ accountNote }}. The server agent build in use is <span class="font-semibold text-ink-strong">{{ serving }}</span>.</p>
    <template #actions>
      <div v-if="can.infrastructure" class="flex flex-wrap gap-2">
        <Btn @click="owning = true">Connect your own</Btn>
        <Btn primary @click="adding = true">+ New server</Btn>
      </div>
    </template>
  </PageHead>

  <Notice v-if="!gatewayUp" tone="bad">The gateway is unreachable. Every server keeps serving its sites, but none can be commanded until it returns.</Notice>

  <FormError :message="errors.remove" />
  <FormError :message="errors.destroy" />

  <Notice v-for="p in provisioning" :key="p.id" tone="plain"><span><b>▸</b> {{ p.title }} is in progress.</span><Btn small :href="`/operation?operation=${p.id}`">Watch it</Btn></Notice>

  <Notice v-if="!can.infrastructure" tone="plain">You can look at the servers but not change them: setting up, repairing, locking down or removing a server needs a Manager or an Owner.</Notice>

  <div v-if="servers.length === 0" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel"><Empty>No servers yet.</Empty></div>
  <div v-else class="overflow-x-auto rounded-panel border border-line bg-panel shadow-panel">
    <table class="w-full min-w-[820px] border-collapse text-sm" aria-label="Servers">
      <thead><tr><th :class="head">Server</th><th :class="head">Provider · region</th><th :class="head">Size</th><th :class="head">CPU / memory · 1 h</th><th :class="head">Apps</th><th :class="head">Agent</th></tr></thead>
      <template v-for="(g, i) in groups" :key="g.pool?.site.id ?? 'single'">
        <tbody>
          <tr v-if="g.pool">
            <td colspan="6" class="border-t border-line-soft bg-raised px-4 py-2.5 text-[13px]">
              <Link :href="`/servers/pool/${g.pool.site.id}`" class="font-semibold text-ink-strong">{{ g.pool.name }}</Link>
              <Badge class="ml-2" plain>autoscaling</Badge>
              <Badge v-if="g.pool.paused" class="ml-1" status="failed">paused</Badge>
              <span class="ml-2 text-faint">application <a :href="`/site?site=${g.pool.site.id}`">{{ g.pool.site.name }}</a> · {{ g.pool.extra }} extra of {{ g.pool.min }}–{{ g.pool.max }}<template v-if="g.pool.cpu !== null"> · CPU {{ g.pool.cpu }}% (target {{ g.pool.target }}%)</template> · <Link :href="`/servers/pool/${g.pool.site.id}`">open pool</Link></span>
            </td>
          </tr>
          <tr v-else-if="g.titled"><td colspan="6" class="border-t border-line-soft bg-raised px-4 py-2.5 text-[13px]"><span class="font-semibold text-ink-strong">Single servers</span> <span class="text-faint">· not part of a pool</span></td></tr>
          <tr v-for="s in g.rows" :key="s.id" class="hover:bg-accent-bg">
            <td :class="[cell, g.pool ? 'pl-8' : '']">
              <Link :href="`/servers/${encodeURIComponent(s.id)}`" class="flex items-start gap-2.5 text-ink-strong no-underline hover:no-underline">
                <span class="mt-1.5 size-2 flex-none rounded-full" :class="s.stale ? 'bg-failed' : s.connected ? 'bg-accent' : 'bg-failed'" aria-hidden="true"></span>
                <span><b class="font-semibold">{{ s.name }}</b>
                  <span v-if="s.pool?.primary" class="ml-1.5 font-mono text-[10px] tracking-[0.1em] text-faint uppercase">primary</span>
                  <span v-if="s.role === 'incus-host'" class="ml-1.5 font-mono text-[10px] tracking-[0.1em] text-faint uppercase">Incus host</span>
                  <span v-else-if="s.role === 'ingress'" class="ml-1.5 font-mono text-[10px] tracking-[0.1em] text-faint uppercase">IPv4 ingress</span>
                  <span class="block text-[13px] font-normal" :class="s.connected ? 'text-faint' : 'text-failed'">
                    <template v-if="s.stale">Droplet deleted</template>
                    <template v-else-if="s.connected">connected {{ s.since }}</template>
                    <template v-else>not connected · last seen {{ s.lastSeen }}</template>
                  </span>
                </span>
              </Link>
            </td>
            <td :class="cell"><span class="text-muted">{{ where(s.compute)[0] }}</span><span v-if="where(s.compute)[1]" class="block text-[13px] text-faint">{{ where(s.compute)[1] }}</span></td>
            <td :class="cell"><span class="font-mono text-[13px] text-muted">{{ s.compute.sizeLabel ?? s.compute.size ?? '—' }}</span><span v-if="money(s.compute)" class="block text-[13px] text-faint">{{ money(s.compute) }}</span></td>
            <td :class="cell">
              <span v-if="s.metrics" class="flex items-center gap-2.5">
                <svg v-if="s.metrics.cpuLine" width="90" height="24" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" :aria-label="`CPU and memory, last hour`"><polyline :points="s.metrics.memLine" fill="none" stroke="var(--peri)" stroke-width="3" vector-effect="non-scaling-stroke"/><polyline :points="s.metrics.cpuLine" fill="none" stroke="var(--accent)" stroke-width="3" vector-effect="non-scaling-stroke"/></svg>
                <span class="font-mono text-[13px] text-muted">{{ s.metrics.cpu }}% · {{ s.metrics.mem ?? '?' }}%</span>
              </span>
              <span v-else class="text-[13px] text-faint">no data</span>
            </td>
            <td :class="cell">{{ s.role === 'incus-host' || s.role === 'ingress' ? '—' : s.apps }}</td>
            <td :class="cell"><span class="font-mono text-[13px] text-muted">{{ s.agent ?? '—' }}</span><span v-if="s.upgrade" class="block text-[13px] text-running">update available</span></td>
          </tr>
        </tbody>
      </template>
    </table>
  </div>

  <Panel v-if="archives.length > 0" title="Retained application data" note="Deleted applications whose data was kept · blocks their names and their servers">
      <RuleRow v-for="a in archives" :key="a.id">
        <div>
          <b class="font-semibold text-ink-strong">{{ a.name }}</b>
          <div class="text-[13px] text-faint">{{ a.directory }}<template v-if="a.database"> · database {{ a.database }}</template> · on {{ a.servers }}</div>
        </div>
        <Btn small danger @click="post(`/servers/archives/${a.id}/delete`, `Permanently delete the retained files and database of ${a.name}? This cannot be undone, and frees its name unless other old cloud resources remain.`)">Delete retained data</Btn>
      </RuleRow>
  </Panel>

  <Panel v-if="disconnected.length > 0" title="Disconnected Droplets" note="Still billed by DigitalOcean · not connected to Unharbored">
      <RuleRow v-for="d in disconnected" :key="d.id">
        <div>
          <b class="font-semibold text-ink-strong">{{ d.name }}</b> <span class="text-[13px] text-faint">· {{ d.facts }}</span>
          <div v-for="why in d.blockers" :key="why" class="text-[13px] text-faint">• {{ why }}</div>
        </div>
        <Btn small danger :disabled="d.blockers.length > 0" @click="post(`/servers/droplets/${d.id}/destroy`, `Destroy ${d.name} in DigitalOcean? Billing stops. This cannot be undone.`)">Destroy Droplet</Btn>
      </RuleRow>
  </Panel>

  <template v-if="can.infrastructure">
    <Drawer :open="owning" title="Add your own server" note="Any Ubuntu 24.04 machine you can reach as root" @close="owning = false">
      <template v-if="byo">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Run this on the server, as root or with sudo</span>
        <pre class="m-0 overflow-x-auto rounded-[10px] border border-accent-line bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] break-all whitespace-pre-wrap text-ink">{{ byo.command }}</pre>
        <span class="text-[13px] text-faint">It works once, for 15 minutes. The server needs outbound access on ports 443 and 9443; the stack installs as soon as the agent connects.</span>
        <div class="flex flex-wrap gap-2"><Btn primary :href="`/operation?operation=${byo.operation}`">Follow it →</Btn></div>
      </template>
      <template v-else>
        <form class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]" @submit.prevent="addOwn">
          <Field label="Name" v-slot="{ id }"><input :id="id" v-model="own.byoName" placeholder="hetzner-1" autocomplete="off"></Field>
          <Btn type="submit" :disabled="own.processing">Get install command</Btn>
        </form>
        <FormError :message="own.errors.byoName" />
        <span class="text-[13px] text-faint">Installs the agent, which connects out to the control plane; nothing listens for it. The server stack is then installed as on a Droplet.</span>
        <span class="text-[13px] text-faint">Prefer not to run a command yourself? <Link href="/servers/connect">Connect over SSH</Link>: we log in once to install it and keep nothing.</span>
      </template>
    </Drawer>

    <AddServer :open="adding" :catalogs="catalogs" :new-keys="newKeys" @close="adding = false" />
  </template>
</template>
