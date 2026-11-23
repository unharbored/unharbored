<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import { usePolling } from '../../composables/usePolling.js';
import { useSiteSettings } from '../../composables/useSiteSettings.js';
import { note } from '../../Components/Servers/serverActions.js';
import Tabs from '../../Components/ui/Tabs.vue';
import InfoCard from '../../Components/ui/InfoCard.vue';
import Drawer from '../../Components/ui/Drawer.vue';
import Field from '../../Components/ui/Field.vue';
import Notice from '../../Components/ui/Notice.vue';
import FormError from '../../Components/ui/FormError.vue';
import Badge from '../../Components/ui/Badge.vue';
import Btn from '../../Components/ui/Btn.vue';
import Empty from '../../Components/ui/Empty.vue';

defineOptions({ layout: Console });

const props = defineProps({ tab: String, chart: String, gatewayUp: Boolean, pool: Object });
const p = computed(() => props.pool);
const page = usePage();
const can = computed(() => page.props.can);
const { send, ask, error } = useSiteSettings(() => p.value.site.id);

usePolling(['pool', 'gatewayUp'], 5000);

const tabs = [['overview', 'Overview'], ['history', 'Scaling history'], ['metrics', 'Metrics'], ['logs', 'Logs']];
function pick(key) {
    if (key === 'logs') {
        router.visit(`/site/logs?site=${p.value.site.id}`);

        return;
    }
    router.get(window.location.pathname, { tab: key, chart: props.chart }, { only: ['tab'], preserveState: true, preserveScroll: true, replace: true });
}
function setRange(event) {
    router.get(window.location.pathname, { tab: 'metrics', chart: event.target.value }, { only: ['chart', 'pool', 'tab'], preserveState: true, preserveScroll: true, replace: true });
}
const ranges = { '1h': 'Last hour', '24h': 'Last day', '7d': 'Last 7 days' };

// The scaling and busy-hours drawers.
const drawer = ref(null);
const min = ref(p.value.min);
const max = ref(p.value.max);
watch(drawer, (now) => { if (now === 'scaling') { min.value = p.value.min; max.value = p.value.max; } });
const windowDays = ref('weekdays');
const windowFrom = ref(13);
const windowTo = ref(22);
const windowMin = ref(2);
const dayChoices = { every: 'Every day', weekdays: 'Weekdays', weekends: 'Weekends', mon: 'Mondays', tue: 'Tuesdays', wed: 'Wednesdays', thu: 'Thursdays', fri: 'Fridays', sat: 'Saturdays', sun: 'Sundays' };
const hours = (h) => `${String(h).padStart(2, '0')}:00`;

async function saveLimits() {
    if (await ask({ title: 'Change the limits?', message: 'Change the limits of the autoscale pool?', ok: 'Change limits' })) {
        send('pool', { min: min.value, max: max.value });
    }
}
async function stop() {
    if (await ask({ title: 'Stop autoscaling?', message: `Stop autoscaling ${p.value.site.name}? The pool and every server it added are deleted.`, ok: 'Stop', tone: 'danger' })) {
        send('pool/remove');
    }
}
async function resume() {
    if (await ask({ title: 'Resume?', message: 'Carry on adding servers? Do this once what stopped it is put right.', ok: 'Resume' })) {
        send('pool/resume');
    }
}
async function dropWindow(i) {
    if (await ask({ title: 'Remove the window?', message: 'Remove this window?', ok: 'Remove', tone: 'danger' })) {
        send('windows/remove', { index: i });
    }
}
const newWindow = () => send('windows', { days: windowDays.value, from: windowFrom.value, to: windowTo.value, min: windowMin.value });

const money = (v) => (v === null ? '—' : `~$${v}/mo`);
const c = computed(() => p.value.compute);
const where = computed(() => [c.value.providerLabel, c.value.regionLabel ?? c.value.region].filter(Boolean).join(' · '));
const scaling = computed(() => [
    ['Extra servers', `${p.value.members.length - 1} now · ${p.value.min} to ${p.value.max}`],
    ['CPU target', `${p.value.target}%`],
    ['Average CPU', p.value.cpu === null ? '—' : `${p.value.cpu}%`],
    ['Next action', p.value.paused ? 'stopped' : p.value.cooling ? `cooling down · ${p.value.cooling} min` : 'none', p.value.paused ? 'bad' : 'faint'],
]);
const dot = (kind) => (kind === 'up' ? 'bg-accent' : kind === 'down' ? 'bg-peri' : kind === 'bad' ? 'bg-failed' : 'bg-faint');
const head = 'px-4 py-3 text-left font-mono text-[10px] font-normal tracking-[0.14em] text-faint uppercase';
const cell = 'border-t border-line-soft px-4 py-3.5 align-middle';
</script>

<template>
  <Head :title="p.name" />
  <div class="flex flex-col gap-2.5">
    <Link href="/servers" class="font-mono text-[10px] tracking-[0.2em] text-accent uppercase">← All servers</Link>
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
      <h1 class="m-0 flex items-center gap-3 font-display text-[clamp(32px,5vw,44px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">
        <span class="size-3 flex-none rounded-full" :class="p.paused ? 'bg-failed' : 'bg-accent'" aria-hidden="true"></span>{{ p.name }}
      </h1>
      <Btn v-if="can.infrastructure" @click="drawer = 'scaling'">Scaling settings</Btn>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-muted">
      <span v-if="where">{{ where }}</span><span v-if="c.sizeLabel" class="font-mono">{{ c.sizeLabel }} each</span>
      <span v-if="p.balancer">load balancer</span>
      <span class="font-mono">{{ money(p.costNow) }} now<template v-if="p.costMax !== null"> · up to {{ money(p.costMax).replace('~', '') }}</template></span>
      <span>application <a :href="`/site?site=${p.site.id}`">{{ p.site.name }}</a></span>
      <Badge plain>autoscaling</Badge>
    </div>
  </div>

  <Notice v-if="!gatewayUp" tone="bad">The gateway is unreachable. Every server keeps serving its sites, but none can be commanded until it returns.</Notice>
  <Notice v-if="p.paused" tone="bad" class="flex-col items-start!">
    <span><b>Autoscaling has stopped adding servers.</b> {{ p.pausedBecause }}</span>
    <Btn v-if="can.edit" small @click="resume">Resume</Btn>
  </Notice>
  <FormError :message="error('pool')" />

  <Tabs :tabs="tabs" :model-value="tab" label="Pool" @update:model-value="pick" />

  <template v-if="tab === 'overview'">
    <div class="grid gap-4 sm:grid-cols-2">
      <InfoCard title="Scaling" :action="can.infrastructure ? 'Edit' : undefined" :rows="scaling" @open="drawer = 'scaling'" />
      <InfoCard title="Busy hours" :action="can.edit ? 'Edit' : undefined" @open="drawer = 'schedule'">
        <div v-for="(w, i) in p.schedule" :key="i" class="flex justify-between gap-3 border-b border-line-soft px-[18px] py-2.5 text-[13px] last:border-b-0">
          <span class="text-muted">{{ w.days === 'every' ? 'Every day' : w.days }} {{ hours(w.from) }}–{{ hours(w.to) }} UTC</span><span class="font-mono">at least {{ w.min }}</span>
        </div>
        <div v-if="p.schedule.length === 0" class="px-[18px] py-3 text-[13px] text-faint">No windows. The fewest ({{ p.min }}) applies all day.</div>
      </InfoCard>
    </div>

    <section class="flex flex-col gap-2.5">
      <h3 class="m-0 font-body text-[15px] font-semibold text-ink-strong">Servers in this pool</h3>
      <div class="overflow-x-auto rounded-panel border border-line bg-panel shadow-panel">
        <table class="w-full min-w-[620px] border-collapse text-sm" aria-label="Servers in this pool">
          <thead><tr><th :class="head">Server</th><th :class="head">State</th><th :class="head">CPU / memory</th><th :class="head">Serving</th><th :class="head">Added</th></tr></thead>
          <tbody>
            <tr v-for="m in p.members" :key="m.id" class="hover:bg-accent-bg">
              <td :class="cell"><Link :href="`/servers/${encodeURIComponent(m.id)}`" class="font-semibold text-ink-strong">{{ m.name }}</Link> <Badge v-if="m.primary" plain>primary</Badge></td>
              <td :class="cell"><span class="inline-flex items-center gap-2"><span class="size-2 rounded-full" :class="m.connected ? 'bg-accent' : m.joined ? 'bg-failed' : 'bg-running'"></span>{{ m.connected ? 'connected' : m.joined ? 'not connected' : 'joining' }}</span></td>
              <td :class="cell" class="font-mono text-[13px] text-muted">{{ m.cpu === null ? 'starting' : `${m.cpu}% · ${m.mem ?? '?'}%` }}</td>
              <td :class="cell">{{ m.joined ? 'yes' : 'not yet' }}</td>
              <td :class="cell" class="text-[13px] text-faint">{{ m.added ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p :class="note" class="m-0">A pool's servers cannot be destroyed or disconnected one by one: the scaler adds and removes them, and the primary is never removed.</p>
    </section>

    <section v-if="can.infrastructure" class="flex flex-col gap-2.5">
      <h3 class="m-0 font-body text-[15px] font-semibold text-failed">Danger</h3>
      <div class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-failed-line bg-failed-bg px-[18px] py-4">
        <div><p class="m-0 font-semibold text-ink-strong">Stop autoscaling</p><p :class="note" class="m-0">Deletes the pool and every server it added. {{ p.site.name }} goes back to its own server.</p></div>
        <Btn danger @click="stop">Stop autoscaling</Btn>
      </div>
    </section>
  </template>

  <template v-else-if="tab === 'history'">
    <h2 class="m-0 font-body text-[17px] font-semibold text-ink-strong">Scaling history</h2>
    <p v-if="p.failures > 0 && !p.paused" :class="note" class="m-0">{{ p.failures }} server{{ p.failures === 1 ? '' : 's' }} in a row could not join. It stops at {{ p.failureLimit }}. Last: {{ p.lastFailure }}</p>
    <div class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
      <Empty v-if="p.history.length === 0">The scaler has not done anything yet.</Empty>
      <ul v-else class="m-0 list-none p-0">
        <li v-for="(h, i) in p.history" :key="i" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-line-soft px-[18px] py-3 text-sm last:border-b-0">
          <span class="size-2 flex-none self-center rounded-full" :class="dot(h.kind)" aria-hidden="true"></span>
          <b class="font-mono font-semibold text-ink-strong">{{ h.title }}</b>
          <span v-if="h.why" class="text-[13px] text-muted">{{ h.why }}</span>
          <span class="ml-auto text-[13px] whitespace-nowrap text-faint">{{ h.by }} · {{ h.ago }}</span>
        </li>
      </ul>
    </div>
  </template>

  <template v-else-if="tab === 'metrics'">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="m-0 font-body text-[17px] font-semibold text-ink-strong">Metrics</h2>
      <select aria-label="Time range" class="max-w-[200px]" :value="chart" @change="setRange"><option v-for="(label, key) in ranges" :key="key" :value="key">{{ label }}</option></select>
    </div>
    <div class="overflow-hidden rounded-panel border border-line bg-panel px-[18px] py-4 shadow-panel">
      <div class="mb-2 flex flex-wrap justify-between gap-2 text-[13px]">
        <b class="font-semibold text-ink-strong">Average CPU against the target</b>
        <span class="flex gap-4 text-muted"><span v-if="p.series"><span class="text-accent">━</span> average {{ p.series.avg }}%</span><span><span class="text-butter">╌</span> target {{ p.target }}%</span><span v-if="p.series">peak {{ p.series.peak }}%</span></span>
      </div>
      <svg v-if="p.series" class="block h-[150px] w-full" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" :aria-label="`Average CPU across the pool, ${ranges[chart]}`">
        <line x1="0" y1="0.5" x2="240" y2="0.5" stroke="var(--line)" vector-effect="non-scaling-stroke"/><line x1="0" y1="47.5" x2="240" y2="47.5" stroke="var(--line)" vector-effect="non-scaling-stroke"/>
        <line x1="0" :y1="48 - 48 * p.target / 100" x2="240" :y2="48 - 48 * p.target / 100" stroke="var(--butter)" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
        <polyline :points="p.series.cpuLine" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
      </svg>
      <p v-else class="m-0 py-10 text-center text-[13px] text-faint">Not enough samples in this range to draw a line yet.</p>
      <p :class="note" class="m-0 mt-2">Server count over time is not recorded, so it is not charted; the history tab shows each change.</p>
    </div>
  </template>

  <Drawer :open="drawer === 'scaling'" title="Scaling settings" note="One scaler for every provider. It adds or removes one server at a time." @close="drawer = null">
    <Field label="Fewest extra servers" v-slot="{ id }"><input :id="id" v-model.number="min" type="number" min="1" max="10"><span :class="note">Never fewer than this. Busy hours can raise it.</span></Field>
    <Field label="Most extra servers" v-slot="{ id }"><input :id="id" v-model.number="max" type="number" min="1" max="10"></Field>
    <div class="flex flex-col gap-1"><span class="font-semibold text-ink-strong">CPU target {{ p.target }}%</span><span :class="note">Adds a server when CPU stays over this for 5 minutes, and removes one when it stays under half of it for 10. It is not editable here yet.</span></div>
    <FormError :message="error('pool')" />
    <template #footer><span class="mr-auto text-[13px] text-faint">{{ p.costMax !== null ? `Costs up to ${money(c.price === null ? null : c.price * (max + 1)).replace('~', '')}.` : '' }}</span><Btn @click="drawer = null">Cancel</Btn><Btn primary @click="saveLimits">Update</Btn></template>
  </Drawer>

  <Drawer :open="drawer === 'schedule'" title="Busy hours" note="Raise the fewest servers during set windows." @close="drawer = null">
    <div v-for="(w, i) in p.schedule" :key="i" class="flex items-center justify-between gap-3 border-b border-line-soft py-2.5">
      <span>At least <b class="font-semibold text-ink-strong">{{ w.min }}</b> <span :class="note">· {{ w.days === 'every' ? 'every day' : w.days }}, {{ hours(w.from) }}–{{ hours(w.to) }} UTC{{ w.to < w.from ? ', past midnight' : '' }}{{ w.to === w.from ? ', all day' : '' }}</span></span>
      <Btn small danger :aria-label="`Remove window ${i + 1}`" @click="dropWindow(i)">Remove</Btn>
    </div>
    <div class="flex flex-col gap-3 pt-2">
      <Field label="Busy hours" v-slot="{ id }"><select :id="id" v-model="windowDays"><option v-for="(label, k) in dayChoices" :key="k" :value="k">{{ label }}</option></select></Field>
      <Field label="From, UTC" v-slot="{ id }"><input :id="id" v-model.number="windowFrom" type="number" min="0" max="23"></Field>
      <Field label="To" v-slot="{ id }"><input :id="id" v-model.number="windowTo" type="number" min="0" max="23"></Field>
      <Field label="At least" v-slot="{ id }"><input :id="id" v-model.number="windowMin" type="number" min="1" max="10"></Field>
      <span :class="note">Servers ready before the load arrives. Outside every window the pool's own fewest applies.</span>
      <FormError :message="error('window')" />
    </div>
    <template #footer><span class="mr-auto"></span><Btn @click="drawer = null">Close</Btn><Btn primary @click="newWindow">+ Add window</Btn></template>
  </Drawer>
</template>
