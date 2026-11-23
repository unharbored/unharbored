<script setup>
import Toggle from '../Components/ui/Toggle.vue';
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Panel from '../Components/ui/Panel.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Field from '../Components/ui/Field.vue';
import FormError from '../Components/ui/FormError.vue';
import Btn from '../Components/ui/Btn.vue';
import Badge from '../Components/ui/Badge.vue';
import Notice from '../Components/ui/Notice.vue';
import { getJson, postJson } from '../composables/useJson.js';

defineOptions({ layout: Console });

const formRow = 'flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]';
const check = 'inline-flex min-h-11 cursor-pointer items-center gap-2.5';
const labelClass = 'font-mono text-[10px] tracking-[0.18em] text-muted uppercase';
const listRow = 'flex justify-between gap-3 border-x-0 border-t-0 border-b px-3.5 py-2.5 text-left text-ink [font:inherit] cursor-pointer';

const props = defineProps({ options: Object });

/** A new application in three steps: where the code is, how it runs and with what data, then a review. */
const opts = ref(props.options);
const step = ref(1);
const errors = ref({});
const busy = ref(false);

const s = reactive({
    repo: '', branch: '', runtime: '', rootDir: '', rootSet: false, customDir: '',
    rtVersion: '', rtInstall: '', rtBuild: '', rtRelease: '', rtStart: '', rtOutput: '', rtHealth: '', rtSpa: false,
    name: '', project: '', compute: 'single', serverMode: 'new', node: '', provider: 'digitalocean',
    region: 'nyc3', size: 's-1vcpu-2gb', poolMin: 1, poolMax: 4, poolCpu: 70, poolSize: 's-2vcpu-4gb',
    database: 'sqlite', cache: 'database', storage: 'disk', dbSize: 'db-s-1vcpu-1gb', cacheSize: 'db-s-1vcpu-1gb',
    envText: '', previews: false, backups: false, filter: '', account: '', other: '', git: {}, otherError: '',
});
const branches = ref([]);
const detected = ref({});
const apps = ref({});
const review = ref({});

const slug = (text) => text.toLowerCase().replace(/[^a-z0-9-]+/gi, '-');
const repoBase = (repo) => slug(repo.substring(repo.lastIndexOf('/') + 1));
const hasGit = computed(() => Object.keys(s.git).length > 0);

// What the branch holds, looked up whenever the repository, branch or folder changes.
let timer;
// The sizes the chosen region sells; the whole list until that is known. A size that is not offered is swapped for one that is.
const offered = ref(null);
watch(() => [s.provider, s.region], async () => {
    const r = await getJson('/apps/new/sizes', { provider: s.provider, region: s.region });
    if (!r.ok) { offered.value = null; return; }
    offered.value = r.data.sizes;
    const keys = Object.keys(r.data.sizes);
    if (keys.length === 0) return;
    if (!keys.includes(s.size)) s.size = keys[1] ?? keys[0];
    if (!keys.includes(s.poolSize)) s.poolSize = keys[2] ?? keys[0];
}, { immediate: true });
const sizeChoices = computed(() => offered.value ?? opts.value.sizes ?? {});

watch(() => [s.repo, s.git], async () => {
    if (s.repo === '') { branches.value = []; return; }
    const { data } = await postJson('/apps/new/branches', { repo: s.repo, git: s.git });
    branches.value = Array.isArray(data) ? data : [];
}, { deep: true });
watch(() => [s.repo, s.branch, s.git, s.rootDir, s.rootSet], () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const body = { repo: s.repo, branch: s.branch, git: s.git, rootDir: s.rootDir, rootSet: s.rootSet };
        const [a, d] = await Promise.all([postJson('/apps/new/apps', body), postJson('/apps/new/detected', body)]);
        apps.value = a.data && !Array.isArray(a.data) ? a.data : {};
        detected.value = d.data && !Array.isArray(d.data) ? d.data : {};
    }, 250);
}, { deep: true });

const found = computed(() => apps.value.app ?? null);
const kind = computed(() => (s.runtime !== '' ? s.runtime : (found.value?.runtime ?? '')));
const preset = computed(() => opts.value.presets?.[kind.value] ?? {});
const suggestion = computed(() => {
    if (s.runtime !== '') return '';
    const config = Object.entries(found.value?.config ?? {}).filter(([, v]) => v !== '' && v !== false);
    return config.map(([k, v]) => `${k} ${typeof v === 'boolean' ? 'on' : v}`).join(', ');
});
const visibleRepos = computed(() => (opts.value.repos ?? []).filter((r) => (s.account === '' || r.full_name.startsWith(`${s.account}/`)) && (s.filter === '' || r.full_name.toLowerCase().includes(s.filter.toLowerCase()))));
// One way to choose at a time: from the GitHub App's list, or by typing an address.
const fromList = ref(null);
const listing = computed(() => fromList.value ?? !!opts.value.appReady);
const noServer = computed(() => opts.value.providers && opts.value.method === 'ssh' && opts.value.providers.length <= 1 && (opts.value.servers ?? []).length === 0);
const projectValue = computed(() => (s.project !== '' ? s.project : String(opts.value.currentProject ?? '')));
const isNew = (value) => value.startsWith('new:');

/** A new repository starts over: what was detected, not what was chosen for the last one. */
function forgetChoices() {
    Object.assign(s, { rootDir: '', rootSet: false, customDir: '', runtime: '', rtVersion: '', rtInstall: '', rtBuild: '', rtRelease: '', rtStart: '', rtOutput: '', rtHealth: '', rtSpa: false });
}
function pick(repo, branch) {
    s.git = {}; s.otherError = ''; s.repo = repo; s.branch = branch; s.name = repoBase(repo); s.envText = '';
    forgetChoices();
}
async function useOther() {
    const { data } = await postJson('/apps/new/inspect', { input: s.other });
    if (data?.error) { s.otherError = data.error; return; }
    s.otherError = ''; s.git = data; s.repo = data.display; s.branch = data.default ?? data.branches?.[0] ?? 'main';
    s.name = repoBase(data.display); s.envText = '';
    forgetChoices();
}
function chooseDir(dir) {
    Object.assign(s, { rootDir: dir, rootSet: true, runtime: '', rtVersion: '', rtInstall: '', rtBuild: '', rtRelease: '', rtStart: '', rtOutput: '', rtHealth: '', rtSpa: false });
    const base = repoBase(s.repo);
    s.name = (dir === '' ? base : `${base}-${slug(dir.replace(/\/+$/, '').split('/').pop())}`).replace(/^-+|-+$/g, '');
}
function useDir() {
    chooseDir(s.customDir.replace(/^[\s/]+|[\s/]+$/g, ''));
    s.customDir = '';
}
function suggestEnv() {
    s.envText = Object.entries(detected.value.env ?? {}).map(([k, v]) => `${k}=${v}`).join('\n');
}
async function setProvider(id) {
    s.provider = id;
    const [d, o] = await Promise.all([getJson('/apps/new/defaults', { provider: id }), getJson('/apps/new/options', { provider: id, repositories: 0 })]);
    if (d.ok) Object.assign(s, { region: d.data.region, size: d.data.size, poolSize: d.data.poolSize });
    if (o.ok) {
        // The repositories were loaded with the page and do not change with the provider; asking GitHub again is the slow part.
        opts.value = { ...opts.value, ...o.data };
        errors.value = { ...errors.value, provider: undefined };
    } else {
        // Never leave the last provider's servers and databases showing under this one.
        opts.value = { ...opts.value, provider: id, servers: [], clusters: [] };
        errors.value = { ...errors.value, provider: "Couldn't load this provider's servers. Choose it again to retry." };
    }
    Object.assign(s, { compute: 'single', serverMode: 'new', node: '', database: 'sqlite', cache: 'database', storage: 'disk' });
}
function choose(compute) {
    s.compute = compute;
    if (compute !== 'autoscale') return;
    s.serverMode = 'new';
    if (s.storage === 'disk') s.storage = 'bucket';
    for (const c of opts.value.clusters ?? []) {
        if (s.database === 'sqlite' && ['pg', 'mysql'].includes(c.engine)) s.database = String(c.id);
        if (s.cache === 'database' && c.engine === 'valkey') s.cache = String(c.id);
    }
    // Autoscaling needs a database every server shares: with none to pick, make one.
    if (s.database === 'sqlite' && (opts.value.supplies ?? []).includes('database:pg')) s.database = 'new:pg';
}

/** The answers, as the planner takes them. */
function input() {
    const runtime = s.runtime !== '' ? s.runtime : (found.value?.runtime ?? '');
    const own = {};
    for (const [k, v] of Object.entries({ version: s.rtVersion, install: s.rtInstall, build: s.rtBuild, release: s.rtRelease, start: s.rtStart, output: s.rtOutput, health: s.rtHealth, spa: s.rtSpa })) {
        if (v) own[k] = v;
    }
    const config = Object.keys(own).length === 0 && s.runtime === '' ? (found.value?.config ?? {}) : own;
    const env = {};
    for (const line of s.envText.split(/\r?\n|\r/)) {
        const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/);
        if (m) env[m[1].toUpperCase()] = m[2].trim().replace(/^[ "']+|[ "']+$/g, '');
    }
    const projects = opts.value.projects ?? [];
    return {
        project: s.project !== '' ? Number(s.project) : (projects.length > 1 ? Number(opts.value.currentProject ?? 0) : 0),
        provider: s.provider, git: s.git, name: s.name, repository: s.repo, branch: s.branch, runtime, runtime_config: config,
        root_dir: 'dir' in apps.value ? apps.value.dir : (s.rootSet ? s.rootDir : ''), root_chosen: !('dir' in apps.value) || apps.value.dir !== null, compute: s.compute,
        server: (s.serverMode === 'existing' || opts.value.method === 'ssh') ? { mode: 'existing', node: s.node } : { mode: 'new', region: s.region, size: s.size },
        pool: { min: s.poolMin, max: s.poolMax, cpu: s.poolCpu, size: s.poolSize },
        database: s.database, cache: s.cache, database_size: s.dbSize, cache_size: s.cacheSize, storage: s.storage, env, previews: s.previews, backups: s.backups,
    };
}

function top() { window.scrollTo(0, 0); }
async function next() {
    busy.value = true;
    const { ok, errors: e } = await postJson('/apps/new/source', { input: input() });
    busy.value = false;
    errors.value = e;
    if (ok) { step.value = 2; top(); }
}
function back() { step.value = Math.max(1, step.value - 1); top(); }
async function toReview() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/apps/new/check', { input: input() });
    busy.value = false;
    errors.value = e;
    if (ok) { review.value = data; step.value = 3; top(); }
}
async function create() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/apps/new/create', { input: input() });
    errors.value = e;
    if (ok) { window.location.href = `/operation?operation=${data.operation}`; return; }
    busy.value = false;
}

const dbOptionNote = computed(() => s.database === 'none' || s.cache === 'none');
const monthly = computed(() => review.value.monthly ?? { low: 0, high: 0 });
</script>

<template>
  <Head title="New application" />
  <PageHead title="New application">
    <template #crumbs><span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase"><a href="/sites">sites</a> <span class="text-line-control" aria-hidden="true">/</span> new application</span></template>
    <template #actions>
      <div class="flex flex-wrap gap-2">
        <Btn href="/templates">Or install a template</Btn>
        <Btn href="/sites">Cancel</Btn>
      </div>
    </template>
  </PageHead>

  <ol aria-label="Steps" class="m-0 flex list-none flex-wrap gap-2 p-0">
    <li><Badge :status="step === 1 ? 'live' : 'neutral'">1 Source</Badge></li>
    <li><Badge :status="step === 2 ? 'live' : 'neutral'">2 Compute &amp; data</Badge></li>
    <li><Badge :status="step === 3 ? 'live' : 'neutral'">3 Review</Badge></li>
  </ol>

  <Notice v-if="noServer"><span><b>You need a server first.</b> No cloud provider is connected and no server of your own has been added, so nothing can be created yet.</span>
    <span><Btn href="/account?tab=providers" small>Connect a cloud provider</Btn> <Btn href="/servers" small>Add your own server</Btn></span></Notice>

  <template v-if="step === 1">
    <Panel title="Repository" :note="listing ? 'From your GitHub App' : 'Any repository on any Git host'">
        <span v-if="!opts.appReady" class="text-[13px] text-faint">GitHub is not connected, so your repositories cannot be listed here (an owner can connect it under Account, GitHub). You can still paste any repository's address below.</span>
        <template v-if="listing">
          <span v-if="opts.error" class="text-failed">{{ opts.error }}</span>
          <Field v-if="(opts.owners ?? []).length > 1" label="Account" v-slot="{ id }"><select :id="id" v-model="s.account"><option value="">All accounts</option><option v-for="o in opts.owners" :key="o" :value="o">{{ o }}</option></select></Field>
          <Field label="Filter" v-slot="{ id }"><input :id="id" v-model="s.filter" placeholder="owner/repository" autocomplete="off"></Field>
          <div class="flex max-h-80 flex-col overflow-y-auto border border-line">
            <button v-for="r in visibleRepos" :key="r.full_name" type="button" :class="[listRow, 'border-line-soft', s.repo === r.full_name && !hasGit ? 'bg-[var(--nav-active)]' : 'bg-transparent']" @click="pick(r.full_name, r.default_branch)">
              <span>{{ s.repo === r.full_name && !hasGit ? '● ' : '' }}{{ r.full_name }}</span><span class="text-faint">{{ r.private ? 'private' : 'public' }}</span>
            </button>
          </div>
          <span class="text-[13px] text-faint">Not listed? Give the GitHub App access to it on GitHub, then reload. Or <a href="#" @click.prevent="fromList = false">use an address from any Git host</a>.</span>
        </template>
        <template v-else>
        <div :class="formRow">
          <Field label="Repository address" v-slot="{ id }"><input :id="id" v-model="s.other" placeholder="https://gitlab.com/group/app, git@host:team/app.git, or owner/repo" autocomplete="off" spellcheck="false"></Field>
          <Btn @click="useOther">Use this repository</Btn>
        </div>
        <FormError v-if="s.otherError !== ''" :message="s.otherError" />
        <span v-if="opts.appReady" class="text-[13px] text-faint"><a href="#" @click.prevent="fromList = true">Choose from my GitHub repositories instead</a>.</span>
        </template>
        <span v-if="hasGit" class="text-[13px] text-faint"><b class="font-semibold text-ink-strong">{{ s.git.display }}</b> · {{ s.git.note }}</span>
    </Panel>

    <Panel v-if="s.repo !== ''" :title="`On ${s.branch}`">
        <span v-if="apps.error" class="text-failed">{{ apps.error }}</span>
        <div v-if="(apps.apps ?? []).length > 0" class="flex flex-col gap-[7px]">
          <span :class="labelClass">{{ apps.apps.length > 1 ? 'Which application is this?' : 'Found in the repository' }}</span>
          <span class="text-[13px] text-faint">Found by reading each folder's own files. The whole repository is still cloned, so the folders beside it keep working.</span>
          <div class="flex flex-col border border-line">
            <template v-for="a in apps.apps" :key="a.dir">
              <div v-if="a.library || a.runtime === null" class="flex justify-between gap-3 border-b border-line px-3.5 py-2.5 opacity-70"><span><span class="font-mono">{{ a.dir === '' ? '/ (top of the repository)' : a.dir }}</span> · {{ a.label }}</span><span class="text-faint">nothing to deploy</span></div>
              <button v-else type="button" :class="[listRow, 'border-line', apps.dir === a.dir ? 'bg-accent-bg' : 'bg-panel']" @click="chooseDir(a.dir)">
                <span>{{ apps.dir === a.dir ? '● ' : '○ ' }}<span class="font-mono">{{ a.dir === '' ? '/ (top of the repository)' : a.dir }}</span> · <b class="font-semibold text-ink-strong">{{ a.label }}</b></span><span class="font-mono text-faint">{{ a.file }}</span>
              </button>
            </template>
          </div>
        </div>
        <span v-else-if="apps.listed === false && kind === '' && !apps.error" class="text-[13px] text-faint">The files of this repository cannot be read from here, so nothing was detected. Choose what it is below.</span>
        <div :class="formRow">
          <Field :label="`${(apps.apps ?? []).length > 0 ? 'Another folder' : 'Application folder'}`" v-slot="{ id }"><input :id="id" v-model="s.customDir" :placeholder="(apps.dir ?? '') !== '' ? `now: ${apps.dir}` : 'the top of the repository'" autocomplete="off" spellcheck="false"></Field>
          <Btn @click="useDir">{{ s.customDir.trim() === '' ? 'Use the top of the repository' : 'Use this folder' }}</Btn>
        </div>
        <span class="text-[13px] text-faint">The folder that holds the application (where its composer.json or package.json is), not the web folder (public). Empty means the top of the repository.</span>
        <FormError :message="errors.root_dir" />
        <span v-if="detected.error" class="text-failed">{{ detected.error }}</span>
        <div v-else-if="kind === 'php'" :class="formRow">
          <div class="flex flex-col gap-[7px]"><span :class="labelClass">Framework</span><span>{{ detected.framework ?? 'not found' }}</span></div>
          <div class="flex flex-col gap-[7px]"><span :class="labelClass">PHP</span><span>{{ detected.php ?? '—' }}</span></div>
          <div class="flex flex-col gap-[7px]"><span :class="labelClass">Front end</span><span>{{ detected.frontend ?? 'none' }}</span></div>
        </div>
        <div :class="formRow">
          <Field label="Application name" v-slot="{ id }"><input :id="id" v-model="s.name" autocomplete="off"></Field>
          <Field v-if="(opts.projects ?? []).length > 1" label="Project" :error="errors.project" v-slot="{ id }">
            <select :id="id" :value="projectValue" @change="s.project = $event.target.value"><option v-for="pr in opts.projects" :key="pr.id" :value="String(pr.id)">{{ pr.name }}</option></select></Field>
          <Field label="Production branch" v-slot="{ id }">
            <select v-if="branches.length > 0" :id="id" v-model="s.branch"><option v-for="b in branches" :key="b" :value="b">{{ b }}</option></select>
            <input v-else :id="id" v-model="s.branch" autocomplete="off" spellcheck="false">
          </Field>
        </div>
        <div :class="formRow">
          <Field :label="`Type${found?.runtime != null && s.runtime === '' ? ` · detected from ${found.file}` : ''}`" hint="Detection is a suggestion. Pick another type and the commands start from that type's defaults." v-slot="{ id }">
            <select :id="id" v-model="s.runtime"><option value="">{{ found?.runtime != null ? `Detected: ${found.label}` : 'Choose a type…' }}</option><option v-for="(label, key) in (opts.runtimes ?? { php: 'Laravel (PHP)' })" :key="key" :value="key">{{ label }}</option></select>
          </Field>
        </div>
        <template v-if="kind !== 'php' && kind !== ''">
          <p class="text-[13px] text-faint">Blank fields use the {{ opts.runtimes?.[kind] ?? kind }} defaults shown.{{ suggestion !== '' ? ` Detection suggests ${suggestion}.` : '' }} Commands run in the repository on the server, as the deploy user.</p>
          <div v-if="preset.tool || kind === 'static'" :class="formRow"><Field :label="`${(preset.tool || 'node').charAt(0).toUpperCase() + (preset.tool || 'node').slice(1)} version`" v-slot="{ id }"><input :id="id" v-model="s.rtVersion" :placeholder="preset.version || '22'" autocomplete="off" spellcheck="false"></Field></div>
          <div :class="formRow">
            <Field label="Install" v-slot="{ id }"><input :id="id" v-model="s.rtInstall" :placeholder="preset.install || 'nothing'" autocomplete="off" spellcheck="false">
              <span class="text-[13px] text-faint">Fetches dependencies, such as <code>npm ci</code>. Runs first.</span></Field>
            <Field label="Build commands" v-slot="{ id }"><textarea :id="id" v-model="s.rtBuild" rows="4" class="font-mono" :placeholder="preset.build || 'nothing'" autocomplete="off" spellcheck="false" />
              <span class="text-[13px] text-faint">Makes the files to serve. Runs after Install, one command per line; stops at the first that fails.</span></Field>
          </div>
          <template v-if="preset.start">
            <div :class="formRow">
              <Field label="Start" v-slot="{ id }"><input :id="id" v-model="s.rtStart" :placeholder="preset.start" autocomplete="off" spellcheck="false"></Field>
              <Field label="Health check path" v-slot="{ id }"><input :id="id" v-model="s.rtHealth" :placeholder="preset.health" autocomplete="off" spellcheck="false"></Field>
            </div>
            <div :class="formRow"><Field label="Before the switch, once (such as a migration)" v-slot="{ id }"><input :id="id" v-model="s.rtRelease" placeholder="nothing" autocomplete="off" spellcheck="false"></Field></div>
            <p class="text-[13px] text-faint">Your application is started with <code>PORT</code> set, and with the variables you give it.</p>
          </template>
          <div v-else :class="formRow">
            <Field label="Folder to serve" v-slot="{ id }"><input :id="id" v-model="s.rtOutput" :placeholder="preset.output" autocomplete="off" spellcheck="false"></Field>
            <Toggle v-model="s.rtSpa">Send unknown paths to index.html</Toggle>
          </div>
        </template>
        <FormError :message="errors.name" />
        <FormError :message="errors.runtime" />
        <div class="flex flex-wrap gap-2"><Btn primary :disabled="busy" @click="next">Continue →</Btn></div>
    </Panel>
  </template>

  <template v-if="step === 2">
    <Panel v-if="(opts.providers ?? []).length > 1" title="Provider" note="Everything this application owns lives on one provider">
      <div :class="formRow">
        <Btn v-for="p in opts.providers" :key="p.id" :primary="s.provider === p.id" @click="setProvider(p.id)">{{ p.label }}</Btn>
      </div>
    </Panel>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-3">
      <template v-for="[shape, title, blurb] in [['single', 'Single server', 'Everything on one server: web, queue, scheduler. Simplest and cheapest.'], ['autoscale', 'Autoscaling', 'A primary server and a pool behind a load balancer that grows and shrinks with CPU.']]" :key="shape">
        <button type="button" :disabled="!(opts.shapes?.[shape]?.available ?? true)" :class="['flex flex-col gap-2 p-4 text-left text-ink [font:inherit]', (opts.shapes?.[shape]?.available ?? true) ? 'cursor-pointer' : 'cursor-not-allowed opacity-60', s.compute === shape ? 'border-2 border-accent bg-accent-bg' : 'border border-line-strong bg-panel']" @click="choose(shape)">
          <b>{{ s.compute === shape ? '●' : '○' }} {{ title }}{{ (opts.shapes?.[shape]?.available ?? true) ? '' : ' (locked)' }}</b>
          <span class="text-muted">{{ blurb }}</span>
          <span v-if="!(opts.shapes?.[shape]?.available ?? true)" class="text-faint">{{ opts.shapes?.[shape]?.reason ?? '' }}</span>
        </button>
      </template>
    </div>
    <span class="text-[13px] text-faint">This can't be changed after the app is created: switching later means creating a new app.</span>

    <Panel :title="`${s.compute === 'autoscale' ? 'Primary server' : 'Server'}`">
        <div v-if="s.compute === 'single' && (opts.method ?? 'api') === 'api'" :class="formRow">
          <label :class="check"><input v-model="s.serverMode" type="radio" name="na-mode" value="new"> New server</label>
          <label :class="check"><input v-model="s.serverMode" type="radio" name="na-mode" value="existing"> An existing server</label>
        </div>
        <Field v-if="(s.serverMode === 'existing' || (opts.method ?? 'api') === 'ssh') && s.compute === 'single'" label="Server" v-slot="{ id }">
          <select :id="id" v-model="s.node"><option value="">Choose…</option><option v-for="sv in opts.servers ?? []" :key="sv.id" :value="sv.id">{{ sv.name }}</option></select></Field>
        <div v-else :class="formRow">
          <Field label="Region" v-slot="{ id }">
            <select :id="id" v-model="s.region"><option v-for="(label, k) in opts.regions ?? {}" :key="k" :value="k">{{ label }}</option></select></Field>
          <Field label="Size" v-slot="{ id }">
            <select :id="id" v-model="s.size"><option v-for="(label, k) in sizeChoices" :key="k" :value="k">{{ label }}</option></select></Field>
        </div>
        <FormError :message="errors.provider" />
        <FormError :message="errors.server" />
    </Panel>

    <Panel v-if="s.compute === 'autoscale'" title="Pool" note="Added beside the primary, behind a load balancer (~$12/mo)">
        <div :class="formRow">
          <Field label="Fewest" v-slot="{ id }"><input :id="id" v-model.number="s.poolMin" type="number" min="0" max="20"></Field>
          <Field label="Most" v-slot="{ id }"><input :id="id" v-model.number="s.poolMax" type="number" min="1" max="20"></Field>
          <Field label="Add one above % CPU" v-slot="{ id }"><input :id="id" v-model.number="s.poolCpu" type="number" min="20" max="95"></Field>
          <Field label="Size of each" v-slot="{ id }">
            <select :id="id" v-model="s.poolSize"><option v-for="(label, k) in sizeChoices" :key="k" :value="k">{{ label }}</option></select></Field>
        </div>
        <FormError :message="errors.pool" />
    </Panel>

    <Panel v-if="kind !== 'static'" title="Data">
        <div :class="formRow">
          <Field label="Database" v-slot="{ id }">
            <select :id="id" v-model="s.database">
              <template v-if="s.compute === 'single'"><option value="sqlite">SQLite on the server</option><option value="local:pgsql">PostgreSQL on this server (managed by Unharbored)</option><option value="local:mysql">MySQL on this server (managed by Unharbored)</option></template>
              <template v-for="c in opts.clusters ?? []" :key="c.id"><option v-if="c.engine === 'pg' || c.engine === 'mysql'" :value="String(c.id)">{{ c.engine === 'pg' ? 'PostgreSQL' : 'MySQL' }} on {{ c.slug }}</option></template>
              <option v-if="(opts.supplies ?? []).includes('database:pg')" value="new:pg">A new PostgreSQL cluster</option>
              <option v-if="(opts.supplies ?? []).includes('database:mysql')" value="new:mysql">A new MySQL cluster</option>
              <option value="none">None: managed by you</option>
            </select></Field>
          <Field label="Cache, sessions and queue" v-slot="{ id }">
            <select :id="id" v-model="s.cache">
              <option value="database" :disabled="s.database === 'none'">In the database{{ s.database === 'none' ? ' (needs a database)' : '' }}</option>
              <option v-if="s.compute === 'single'" value="local:valkey">Valkey on this server (managed by Unharbored)</option>
              <template v-for="c in opts.clusters ?? []" :key="c.id"><option v-if="c.engine === 'valkey'" :value="String(c.id)">Valkey on {{ c.slug }}</option></template>
              <option v-if="(opts.supplies ?? []).includes('database:valkey')" value="new:valkey">A new Valkey cluster</option>
              <option value="none">None: managed by you</option>
            </select></Field>
          <Field label="File uploads" v-slot="{ id }">
            <select :id="id" v-model="s.storage"><option value="disk">{{ s.compute === 'autoscale' ? "Each server's disk" : "The server's disk" }}</option><option v-if="(opts.supplies ?? []).includes('object_storage')" value="bucket">An object storage bucket (~$5/mo)</option></select></Field>
        </div>
        <Field v-if="isNew(s.database)" label="New database cluster size" v-slot="{ id }"><select :id="id" v-model="s.dbSize"><option v-for="(label, k) in opts.clusterSizes ?? {}" :key="k" :value="k">{{ label }}</option></select></Field>
        <Field v-if="s.cache === 'new:valkey'" label="New Valkey cluster size" v-slot="{ id }"><select :id="id" v-model="s.cacheSize"><option v-for="(label, k) in opts.clusterSizes ?? {}" :key="k" :value="k">{{ label }}</option></select></Field>
        <span v-if="isNew(s.database) || s.cache === 'new:valkey'" class="text-[13px] text-faint">A new cluster is made beside the server and takes DigitalOcean several minutes to come online; the application is created once it is. It stays after the application is deleted, and other applications can use it.</span>
        <span v-if="s.compute === 'autoscale'" class="text-[13px] text-faint">With more than one server, anything kept on a server's own disk is not shared with the others.</span>
        <span v-if="s.database.startsWith('local:') || s.cache === 'local:valkey'" class="text-[13px] text-faint">Installed on the same server, listening on 127.0.0.1 only. One machine holds the data: backups are kept on its own disk unless a backup bucket is set up. On a 1 GB server, a database and Valkey beside PHP will be tight.</span>
        <span v-if="dbOptionNote" class="text-[13px] text-faint">None: Unharbored installs nothing, sets no variables for it and takes no backups. Set what the app needs yourself under Variables.</span>
        <span v-if="s.compute === 'autoscale' && !opts.hasDatabase" class="text-[13px] text-faint">No database cluster yet: choose a new one above, or <a href="/databases">create one</a> first.</span>
        <FormError :message="errors.database" />
        <FormError :message="errors.cache" />
        <FormError :message="errors.storage" />
    </Panel>

    <Panel title="Variables" :note="kind === 'static' ? '' : 'Database, cache, storage, URL and app key are set for you'">
        <div v-if="kind !== 'static' && Object.keys(detected.env ?? {}).length > 0 && s.envText === ''" class="flex flex-wrap gap-2"><Btn small @click="suggestEnv">Start from .env.example ({{ Object.keys(detected.env).length }} keys)</Btn></div>
        <Field v-if="kind !== 'static'" label="KEY=value, one per line" v-slot="{ id }"><textarea :id="id" v-model="s.envText" rows="8" spellcheck="false" placeholder="STRIPE_SECRET=sk_live_…"></textarea></Field>
        <FormError v-if="kind !== 'static'" :message="errors.env" />
        <Toggle v-model="s.previews">Preview pull requests into {{ s.branch }}</Toggle>
        <FormError :message="errors.previews" />
        <Toggle v-if="kind !== 'static'" v-model="s.backups">Back up the database nightly and before each deploy (off until you need it)</Toggle>
    </Panel>

    <div class="flex flex-wrap justify-between gap-2">
      <Btn @click="back">← Back</Btn>
      <Btn primary :disabled="busy" @click="toReview">Review →</Btn>
    </div>
    <FormError :message="errors.name" />
    <FormError :message="errors.repository" />
    <FormError :message="errors.branch" />
    <FormError :message="errors.compute" />
  </template>

  <template v-if="step === 3">
    <Panel :title="`Ready to create ${review.name ?? ''}`">
      <template #actions><span class="font-semibold text-ink-strong">~${{ monthly.low }}{{ monthly.high !== monthly.low ? ` – $${monthly.high}` : '' }}/mo new</span></template>
        <div class="flex justify-between gap-3"><span class="text-faint">Code</span><span>{{ review.repository ?? '' }} · {{ review.branch ?? '' }} · deploys on push</span></div>
        <div class="flex justify-between gap-3"><span class="text-faint">Runs on</span><span>{{ review.compute === 'autoscale' ? `Autoscaling: primary + pool of ${review.pool?.min ?? 0}–${review.pool?.max ?? 0}` : 'Single server' }} · {{ review.server?.mode === 'new' ? `new ${review.server?.slug ?? ''} in ${review.server?.region ?? ''}` : `on ${review.server?.node ?? ''}` }}</span></div>
        <div class="flex justify-between gap-3"><span class="text-faint">Data</span><span>{{ review.summary ?? '' }}</span></div>
        <Notice v-for="w in review.warnings ?? []" :key="w" role="note" class="!justify-start">{{ w }}</Notice>
        <div class="flex justify-between gap-3"><span class="text-faint">Variables</span><span>{{ Object.keys(review.env ?? {}).length }} set</span></div>
        <div class="flex justify-between gap-3"><span class="text-faint">Steps</span><span>{{ (review.steps ?? []).join(' → ') }}</span></div>
        <span class="text-[13px] text-faint">Fixed once created: {{ review.compute === 'autoscale' ? 'autoscaling' : 'single server' }}. Sizes can still change. Each step is an operation you can watch; a failed step stops there and says why.</span>
        <FormError v-for="(message, key) in errors" :key="key" :message="message" />
    </Panel>
    <div class="flex flex-wrap justify-between gap-2">
      <Btn @click="back">← Back</Btn>
      <Btn primary :disabled="busy" @click="create">Create application</Btn>
    </div>
  </template>
</template>
