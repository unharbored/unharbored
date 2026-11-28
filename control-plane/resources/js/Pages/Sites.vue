<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { usePolling } from '../composables/usePolling';
import Panel from '../Components/ui/Panel.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Field from '../Components/ui/Field.vue';
import Btn from '../Components/ui/Btn.vue';
import Badge from '../Components/ui/Badge.vue';
import Chip from '../Components/ui/Chip.vue';
import FormError from '../Components/ui/FormError.vue';
import Empty from '../Components/ui/Empty.vue';
import { tone } from '../Components/ui/tone.js';

defineOptions({ layout: Console });

const props = defineProps({
    sites: Array,
    archived: { type: Array, default: () => [] },
    activity: Array,
    start: Object,
    form: Object,
    filter: Object,
    canCreate: Boolean,
    canAudit: Boolean,
});

usePolling(['sites', 'activity', 'archived'], 3000);

const creating = ref(false);
const site = useForm({ server: '', name: '', domain: '', repository: '', branch: 'main', app: true });
const create = () => site.post('/sites');
</script>

<template>
  <Head title="Applications" />
  <PageHead title="Applications">
    <p v-if="filter.name" class="m-0 max-w-[64ch] leading-[1.65] text-muted">Project: <b class="font-semibold text-ink-strong">{{ filter.name }}</b> · <a href="/sites?project=">Show all projects</a></p>
    <p class="m-0 max-w-[64ch] leading-[1.65] text-muted">{{ sites.length }} {{ sites.length === 1 ? 'application' : 'applications' }}. Pushes to each one's branch deploy it where push-to-deploy is on.</p>
    <template #actions>
      <div v-if="canCreate" class="flex flex-wrap gap-2">
        <Btn @click="creating = !creating">{{ creating ? 'Cancel' : '+ Site on a server' }}</Btn>
        <Btn href="/apps/container?kind=dockerfile">From a Dockerfile</Btn>
        <Btn href="/apps/container?kind=image">From a Docker image</Btn>
        <Btn primary href="/apps/new">+ New application</Btn>
      </div>
    </template>
  </PageHead>

  <Panel v-if="creating" title="New site" note="Nothing reaches the server until the first deploy">
      <div class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
        <Field label="Server" v-slot="{ id }">
          <select :id="id" v-model="site.server"><option value="">Choose…</option><option v-for="srv in form.servers" :key="srv.id" :value="srv.id">{{ srv.name }}</option></select></Field>
        <Field label="Name" v-slot="{ id }"><input :id="id" v-model="site.name" placeholder="shop" autocomplete="off"></Field>
        <Field label="Domain" class="!flex-[2_1_220px]" v-slot="{ id }"><input :id="id" v-model="site.domain" placeholder="shop.example.com" autocomplete="off"></Field>
      </div>
      <div class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
        <Field label="Repository" class="!flex-[3_1_300px]" v-slot="{ id }"><input :id="id" v-model="site.repository" placeholder="git@github.com:you/shop.git, git@gitlab.com:team/app.git, or https://host/team/app.git (public)" autocomplete="off"></Field>
        <Field label="Branch" class="!flex-[0_1_140px]" v-slot="{ id }"><input :id="id" v-model="site.branch" autocomplete="off"></Field>
      </div>
      <label v-if="form.appReady" class="inline-flex min-h-11 cursor-pointer items-center gap-2.5"><input v-model="site.app" type="checkbox"> Clone with the GitHub App and deploy on every push (otherwise: a deploy key you add to the repository)</label>
      <FormError :message="site.errors.newServer" />
      <FormError :message="site.errors.newName" />
      <div class="flex flex-wrap gap-2"><Btn primary :disabled="site.processing" @click="create">Create site</Btn></div>
      <span class="text-[13px] text-faint">The name is its directory on the server: lowercase letters, digits and dashes. Point the domain at the server, then deploy; HTTPS is one click once it resolves.</span>
  </Panel>

  <Panel v-if="canCreate && start && !start.hasSites" title="Getting started" note="Your first application, step by step">
      <ol class="m-0 flex list-none flex-col gap-4 p-0">
        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2.5">
          <div class="min-w-[260px] flex-1"><b>{{ start.hasServer ? '✓ ' : '' }}Get a server.</b> <span class="text-muted">Connect a cloud account and we create one for you, or add a machine of your own (any Ubuntu 22.04, 24.04 or 26.04 server you can reach as root).</span></div>
          <div v-if="!start.hasServer" class="flex shrink-0 flex-wrap gap-2"><Btn small href="/account?tab=providers">Connect a cloud account</Btn><Btn small href="/servers">Add your own server</Btn></div>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2.5">
          <div class="min-w-[260px] flex-1"><b>{{ start.hasGitHub ? '✓ ' : '' }}Say where your code is.</b> <span class="text-muted">Connect GitHub to pick a repository from a list, or just paste any repository's address when you create the application.</span></div>
          <div v-if="!start.hasGitHub" class="flex shrink-0 flex-wrap gap-2"><Btn small href="/account?tab=github">Connect GitHub</Btn></div>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2.5">
          <div class="min-w-[260px] flex-1"><b>Create your first application.</b> <span class="text-muted">Choose the repository and a server; we show you what will be created before anything happens.</span></div>
          <div class="flex shrink-0 flex-wrap gap-2"><Btn small primary href="/apps/new">New application</Btn></div>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2.5">
          <div class="min-w-[260px] flex-1"><b>{{ start.hasAlerts ? '✓ ' : '' }}Decide where problems are reported.</b> <span class="text-muted">A Slack or Discord webhook, or an email address.</span></div>
          <div v-if="!start.hasAlerts" class="flex shrink-0 flex-wrap gap-2"><Btn small href="/alerts" inertia>Set up alerts</Btn></div>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2.5">
          <div class="min-w-[260px] flex-1"><b>{{ start.hasTeam ? '✓ ' : '' }}Invite your team</b> <span class="text-muted">(optional). Give people the access they need, from view-only up.</span></div>
          <div v-if="!start.hasTeam" class="flex shrink-0 flex-wrap gap-2"><Btn small href="/account?tab=people" inertia>Invite someone</Btn></div>
        </li>
      </ol>
  </Panel>

  <Panel :title="filter.name ? filter.name : 'All sites'" flush>
    <template #actions><span class="font-mono text-[11px] tracking-[0.06em]">{{ sites.length }} rows</span></template>
    <div class="overflow-x-auto">
      <table
        class="w-full border-collapse text-sm sm:min-w-[820px] [&_td]:border-b [&_td]:border-line-soft [&_td]:px-5 [&_td]:py-3.5 [&_td]:align-middle [&_th]:border-b [&_th]:border-dashed [&_th]:border-line [&_th]:px-5 [&_th]:pt-3.5 [&_th]:pb-2.5 [&_th]:text-left [&_th]:font-mono [&_th]:text-[9.5px] [&_th]:font-normal [&_th]:tracking-[0.18em] [&_th]:text-muted [&_th]:uppercase [&_tr:last-child_td]:border-b-0 [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-[var(--nav-hover)] max-sm:[&_tbody]:block max-sm:[&_td]:flex max-sm:[&_td]:items-baseline max-sm:[&_td]:justify-between max-sm:[&_td]:gap-3 max-sm:[&_td]:!border-0 max-sm:[&_td]:!px-0 max-sm:[&_td]:!py-1 max-sm:[&_td]:text-right max-sm:[&_td]:before:flex-[0_0_96px] max-sm:[&_td]:before:text-left max-sm:[&_td]:before:font-mono max-sm:[&_td]:before:text-[11px] max-sm:[&_td]:before:tracking-[0.06em] max-sm:[&_td]:before:text-muted max-sm:[&_td]:before:uppercase max-sm:[&_td]:before:content-[attr(data-label)] max-sm:[&_thead]:sr-only max-sm:[&_tr]:block max-sm:[&_tr]:border-b max-sm:[&_tr]:border-line-soft max-sm:[&_tr]:px-4 max-sm:[&_tr]:py-3 max-sm:[&_tr:last-child]:border-b-0 max-sm:block"
      >
        <thead><tr><th scope="col">Site</th><th scope="col">Server</th><th scope="col">Live</th><th scope="col">Last operation</th><th scope="col">Source</th></tr></thead>
        <tbody>
          <tr v-for="s in sites" :key="s.id">
            <td data-label="Site"><div class="flex flex-col gap-[3px]"><Link class="font-semibold text-ink-strong" :href="`/site?site=${s.id}`"><b>{{ s.name }}</b></Link><Chip v-if="s.type" class="self-start">{{ s.type }}</Chip><span class="text-[13px] text-faint">{{ s.url }}</span></div></td>
            <td data-label="Server"><a href="/servers">{{ s.node }}</a></td>
            <td class="font-semibold text-ink-strong" data-label="Live">{{ s.commit }}</td>
            <td data-label="Last operation">
              <div class="flex items-center gap-2.5">
                <Link v-if="s.awaitingKey" :href="`/site?site=${s.id}`" :class="tone.warn">Needs a deploy key — add it</Link>
                <template v-else-if="s.badge">
                  <Badge :status="s.badge.class || 'neutral'">{{ s.badge.label }}</Badge>
                  <Link :class="s.running ? tone.warn : tone.faint" :href="`/operation?operation=${s.operationId}`">{{ s.detail }}</Link>
                </template>
                <span v-else class="text-faint">{{ s.detail }}</span>
              </div>
            </td>
            <td class="text-muted" data-label="Source">{{ s.source }} <template v-if="s.push"> · push to <span class="font-semibold text-ink-strong">{{ s.branch }}</span> </template></td>
          </tr>
          <tr v-if="sites.length === 0"><td colspan="5" class="max-sm:!block max-sm:!text-left max-sm:before:content-none"><Empty>No applications yet.<template v-if="canCreate"> Start with "+ New application" above.</template></Empty></td></tr>
        </tbody>
      </table>
    </div>
  </Panel>

  <details v-if="archived.length > 0" class="rounded-lg border border-line bg-surface">
    <summary class="cursor-pointer px-5 py-3.5 font-mono text-[11px] tracking-[0.12em] text-muted uppercase">Archived · {{ archived.length }}</summary>
    <p class="m-0 px-5 pb-2 text-[13px] text-muted">Unharbored no longer manages these. They keep running on their servers exactly as they were.</p>
    <ul class="m-0 list-none p-0">
      <li v-for="a in archived" :key="a.id" class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-5 py-3">
        <span><Link class="font-semibold text-ink-strong" :href="`/site/settings?site=${a.id}&tab=delete`">{{ a.name }}</Link> <span class="text-[13px] text-faint">{{ a.url }} · {{ a.node }} · archived {{ a.since }}</span></span>
        <Btn v-if="canCreate" small @click="router.post(`/site/settings/${a.id}/restore`, {}, { preserveScroll: true })">Restore</Btn>
      </li>
    </ul>
  </details>

  <Panel title="Recent activity" flush>
    <template #actions><a v-if="canAudit" href="/audit">Full audit log →</a></template>
    <ol class="m-0 list-none py-1.5 pr-0 pl-0">
      <li v-for="item in activity" :key="item.id" class="flex flex-wrap gap-x-5 gap-y-1 px-[18px] py-2.5">
        <span class="flex-[0_0_96px] text-faint">{{ item.ago }}</span>
        <span class="flex-[1_1_320px]">
          <Link :href="`/operation?operation=${item.id}`"><b>{{ item.title }}</b></Link>
          <span class="text-muted"> · {{ item.by }}</span>
          <template v-if="item.commit"> <span class="text-muted">·</span> <span class="font-semibold text-ink-strong">{{ item.commit }}</span> </template>
          <span v-if="item.status === 'failed'" class="text-failed"> · failed</span>
          <span v-if="item.status === 'running' || item.status === 'queued'" class="text-running"> · {{ item.status }}</span>
        </span>
      </li>
      <li v-if="activity.length === 0"><Empty>Nothing has happened yet.</Empty></li>
    </ol>
  </Panel>
</template>
