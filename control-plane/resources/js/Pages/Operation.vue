<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { usePost } from '../composables/usePost';
import { getJson } from '../composables/useJson.js';
import Field from '../Components/ui/Field.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Notice from '../Components/ui/Notice.vue';
import Btn from '../Components/ui/Btn.vue';
import Badge from '../Components/ui/Badge.vue';
import Crumbs from '../Components/ui/Crumbs.vue';
import DeployKeyNotice from '../Components/Site/DeployKeyNotice.vue';

defineOptions({ layout: Console });

const props = defineProps({ view: Object });
const v = computed(() => props.view);

// Looked at again every second while it runs, and not once it has finished.
let timer;
onMounted(() => {
    timer = setInterval(() => {
        if (document.visibilityState === 'visible' && v.value.running) {
            router.reload({ only: ['view'], preserveScroll: true, preserveState: true });
        }
    }, 1000);
});
onUnmounted(() => clearInterval(timer));

const stageStyle = {
    done: { box: 'border-solid border-accent-line bg-panel', n: 'text-faint', name: 'font-semibold text-accent', time: 'text-muted' },
    running: { box: 'border-solid border-running-line bg-running-panel', n: 'text-running/70', name: 'font-semibold text-running', time: 'text-running-soft' },
    failed: { box: 'border-solid border-failed-line bg-failed-bg', n: 'text-faint', name: 'font-semibold text-failed', time: 'text-faint' },
};
const waiting = { box: 'bg-idle', n: 'text-faint', name: 'font-medium text-muted', time: 'text-faint' };
const style = (status) => stageStyle[status] ?? waiting;
const crumbs = computed(() => {
    const x = v.value;
    const first = x.site
        ? [{ label: 'sites', href: '/sites', inertia: true }, { label: x.site.name, href: `/site?site=${x.site.id}`, inertia: true }]
        : x.service
            ? [{ label: 'services', href: '/services' }, { label: x.service.name, href: `/service?service=${x.service.id}` }]
            : [{ label: 'servers', href: '/servers', inertia: true }, { label: x.node }];

    return [...first, { label: x.kind }];
});
const mark = (s) => (s === 'done' ? '✓ ' : s === 'running' ? '▸ ' : s === 'failed' ? '✕ ' : '');
const logNote = ref('');
async function copyLog() {
    try {
        const text = await (await fetch(`/operation/log?operation=${v.value.id}`, { credentials: 'same-origin' })).text();
        await navigator.clipboard.writeText(text);
        logNote.value = 'Copied.';
    } catch {
        logNote.value = 'Could not copy; use Open the full log.';
    }
}
const send = usePost();
const retry = () => send('/operation/retry', { operation: v.value.id });

// A server that could not be made: change where and how big, then try again. The sizes follow the region.
const pick = ref(null);
const sizes = ref(null);
const choice = computed(() => pick.value ?? { region: v.value.retryServer?.region, size: v.value.retryServer?.size });
async function changeRegion(region) {
    pick.value = { region, size: choice.value.size };
    const r = await getJson('/apps/new/sizes', { provider: v.value.retryServer.provider, region });
    if (!r.ok) return;
    sizes.value = r.data.sizes;
    if (!(choice.value.size in r.data.sizes)) pick.value = { region, size: Object.keys(r.data.sizes)[0] ?? choice.value.size };
}
const retryWithServer = () => send('/operation/retry', { operation: v.value.id, region: choice.value.region, size: choice.value.size });
</script>

<template>
  <Head :title="v.missing ? 'No such operation' : v.title" />
  <PageHead v-if="v.missing" title="No such operation">
    <template #crumbs><Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }]" /></template>
  </PageHead>
  <template v-else>
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-6">
      <div class="flex min-w-0 flex-col gap-2.5">
        <Crumbs :items="crumbs" />
        <div class="flex flex-wrap items-center gap-3.5">
          <h1 class="m-0 font-display text-[clamp(36px,6vw,52px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ v.title }}<span :class="v.running ? 'text-running' : 'text-butter'" aria-hidden="true">_</span></h1>
          <Badge :status="v.badge.class || 'neutral'">{{ v.badge.label }} · {{ v.elapsed }}</Badge>
        </div>
        <span class="text-[13px] text-muted">
          <template v-if="v.release">Release <span class="font-semibold text-ink-strong">{{ v.release }}</span> · </template>
          {{ v.by }}
          <template v-if="v.commit"> · commit <span class="font-semibold text-ink-strong">{{ v.commit }}</span> </template>
        </span>
      </div>
      <span v-if="v.running" class="max-w-[300px] text-[13px] text-faint">You can leave this page. The work runs on the control plane and its log is kept.</span>
      <Btn v-if="v.retryable && !v.retryServer" primary @click="retry">Retry from the failed step</Btn>
    </div>

    <Notice v-if="v.retryServer" tone="plain">
      <span class="flex flex-wrap items-end gap-3">
        <Field label="Region" v-slot="{ id }"><select :id="id" :value="choice.region" @change="changeRegion($event.target.value)"><option v-for="(label, k) in v.retryServer.regions" :key="k" :value="k">{{ label }}</option></select></Field>
        <Field label="Size" v-slot="{ id }"><select :id="id" :value="choice.size" @change="pick = { region: choice.region, size: $event.target.value }"><option v-for="(label, k) in (sizes ?? v.retryServer.sizes)" :key="k" :value="k">{{ label }}</option></select></Field>
        <Btn primary @click="retryWithServer">Retry with this server</Btn>
      </span>
    </Notice>

    <ol class="m-0 grid list-none grid-cols-[repeat(auto-fit,minmax(130px,1fr))] gap-2.5 p-0" aria-label="Stages">
      <li v-for="stage in v.stages" :key="stage.n" class="flex flex-col gap-1.5 rounded-[14px] border border-dashed border-line px-3.5 py-3" :class="style(stage.status).box" :aria-current="stage.status === 'running' ? 'step' : undefined">
        <span class="font-mono text-[10px] tracking-[0.1em]" :class="style(stage.status).n">{{ stage.n }}</span>
        <span :class="style(stage.status).name">{{ mark(stage.status) }}{{ stage.name }}</span>
        <span class="font-mono text-[11px]" :class="style(stage.status).time">{{ stage.time }}</span>
      </li>
    </ol>

    <DeployKeyNotice v-if="v.awaitingKey" :awaiting="v.awaitingKey" />
    <Notice v-if="v.error" tone="bad">
      <span class="min-w-0 break-words whitespace-pre-wrap">{{ v.error }}</span>
      <Btn small :data-copy="v.error">Copy</Btn>
    </Notice>
    <Notice v-if="v.status === 'succeeded'" tone="plain"><span><b>Done</b> in {{ v.elapsed }}.<template v-if="v.release"> Release {{ v.release }} is now the live one.</template></span>
      <span class="flex flex-wrap gap-2">
        <template v-if="v.site"><Btn small :href="`/site?site=${v.site.id}`" inertia>Back to {{ v.site.name }}</Btn> <Btn small :href="`/site/deployments?site=${v.site.id}`" inertia>Deployments</Btn></template>
        <Btn v-if="v.service" small :href="`/service?service=${v.service.id}`">Open {{ v.service.name }}</Btn>
      </span></Notice>
    <Notice v-else-if="v.status === 'failed'" tone="plain"><span>Nothing was changed beyond the step that failed. Read the message above; fix the cause, then try again from <Link v-if="v.site" :href="`/site?site=${v.site.id}`">{{ v.site.name }}</Link><a v-else-if="v.service" :href="`/service?service=${v.service.id}`">{{ v.service.name }}</a><template v-else> the server page </template>.</span></Notice>

    <section class="overflow-hidden rounded-panel border border-line bg-well shadow-panel" aria-labelledby="log-h">
      <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-5 pt-5 font-mono text-[11px] tracking-[0.06em] text-muted sm:px-7 sm:pt-[22px]"><h2 id="log-h" class="m-0 font-display text-[22px] leading-[1.22] font-semibold tracking-[0.005em] break-words text-ink-strong normal-case">Log <template v-if="v.node">· {{ v.node }}</template></h2><span class="font-display text-sm font-medium tracking-normal text-muted italic">Each step as the node reports it</span></div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 pt-3 text-[13px] sm:px-7">
        <a :href="`/operation/log?operation=${v.id}`" target="_blank" rel="noopener">Open the full log</a>
        <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-accent underline-offset-2 hover:underline" @click="copyLog">Copy the full log</button>
        <span v-if="logNote" role="status" class="text-muted">{{ logNote }}</span>
        <span v-else-if="v.lineCount > v.lines.length" class="text-muted">Showing the latest {{ v.lines.length }} of {{ v.lineCount }} lines.</span>
      </div>
      <pre class="m-0 overflow-x-auto px-5 pt-4 pb-5 font-mono text-[12.5px] leading-[1.75] whitespace-pre-wrap break-words text-[var(--log-text)] sm:px-7 sm:pt-[18px] sm:pb-6" aria-live="polite"><span v-for="l in v.lines" :key="l.i" class="block min-h-[1.75em]"><span class="text-faint">{{ l.time }}</span>  <span :class="l.action === 'error' ? 'text-failed' : 'text-accent'">{{ l.stage.padEnd(8) }}</span> {{ l.action.padEnd(7) }} {{ l.text }}</span><span v-if="v.lines.length === 0" class="block min-h-[1.75em] text-faint">Waiting to start…</span><span v-if="v.running" class="block min-h-[1.75em]"><span class="bg-accent text-well"> </span></span></pre>
    </section>
  </template>
</template>
