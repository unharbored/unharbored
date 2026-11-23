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

/**
 * A new application from a Dockerfile in a repository, or from a Docker image, in two steps: the form,
 * then a review. The registry token lives only here, and is cleared when going back or creating.
 */
const props = defineProps({ kind: String, options: Object });

const opts = ref(props.options);
const step = ref(1);
const errors = ref({});
const busy = ref(false);
const s = reactive({
    repo: '', branch: '', filter: '', other: '', git: {}, otherError: '', name: '', node: '', project: '',
    dockerfile: '', context: '', containerPort: '', domain: '', health: '', memory: '', readOnly: false, volumes: '', envText: '',
    image: '', registryUser: '', registryToken: '',
});
const branches = ref([]);
const detection = ref({});
const review = ref({});

const slug = (text) => text.toLowerCase().replace(/[^a-z0-9-]+/gi, '-').replace(/^-+|-+$/g, '');
const repoBase = (repo) => slug(repo.substring(repo.lastIndexOf('/') + 1));
const hasGit = computed(() => Object.keys(s.git).length > 0);
const visibleRepos = computed(() => (opts.value.repos ?? []).filter((r) => s.filter === '' || r.full_name.toLowerCase().includes(s.filter.toLowerCase())));
const projectValue = computed(() => (s.project !== '' ? s.project : String(opts.value.currentProject ?? '')));
const anyUnprepared = computed(() => (opts.value.servers ?? []).some((sv) => !sv.prepared));

watch(() => [s.repo, s.git], async () => {
    if (s.repo === '') { branches.value = []; return; }
    const { data } = await postJson('/apps/container/branches', { repo: s.repo, git: s.git });
    branches.value = Array.isArray(data) ? data : [];
}, { deep: true });
let timer;
watch(() => [s.repo, s.branch, s.git, s.dockerfile], () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const { data } = await postJson('/apps/container/detection', { repo: s.repo, branch: s.branch, git: s.git, dockerfile: s.dockerfile });
        detection.value = data && !Array.isArray(data) ? data : {};
    }, 250);
}, { deep: true });

function pick(repo, branch) {
    s.git = {}; s.otherError = ''; s.repo = repo; s.branch = branch; s.name = repoBase(repo);
}
async function useOther() {
    const { data } = await postJson('/apps/container/inspect', { input: s.other });
    if (data?.error) { s.otherError = data.error; return; }
    s.otherError = ''; s.git = data; s.repo = data.display; s.branch = data.default ?? data.branches?.[0] ?? 'main'; s.name = repoBase(data.display);
}
/** After preparing a server in another tab: look at the servers again without losing what was typed. */
async function recheck() {
    const { ok, data } = await getJson('/apps/container/options', { kind: props.kind });
    if (ok) opts.value = data;
}

function input() {
    const env = {};
    for (const line of s.envText.split(/\r?\n|\r/)) {
        const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/);
        if (m) env[m[1].toUpperCase()] = m[2].trim().replace(/^[ "']+|[ "']+$/g, '');
    }
    return {
        kind: props.kind, name: s.name, node: s.node, project: s.project !== '' ? Number(s.project) : Number(opts.value.currentProject ?? 0),
        domain: s.domain, git: s.git, repository: s.repo, branch: s.branch, dockerfile: s.dockerfile, context: s.context,
        container_port: s.containerPort !== '' ? s.containerPort : (detection.value.port ?? ''),
        health: s.health, memory: s.memory, read_only: s.readOnly, volumes: s.volumes, env,
        image: s.image, registry_user: s.registryUser, registry_token: s.registryToken,
    };
}
async function toReview() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/apps/container/check', { input: input() });
    busy.value = false;
    errors.value = e;
    if (ok) { review.value = data; step.value = 2; }
}
/** Back to the form: the typed registry token is not kept for it, type it again. */
function back() { step.value = 1; s.registryToken = ''; }
async function create() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/apps/container/create', { input: input() });
    errors.value = e;
    if (ok) { s.registryToken = ''; window.location.href = `/operation?operation=${data.operation}`; return; }
    busy.value = false;
}

const heading = computed(() => (props.kind === 'image' ? 'From a Docker image' : 'From a Dockerfile'));
</script>

<template>
  <Head :title="heading" />
  <PageHead :title="heading" :lede="kind === 'image' ? 'Run a published image as one container, pinned by digest.' : 'Build the Dockerfile in a repository on the server and run it as one container.'">
    <template #crumbs><span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase"><a href="/sites">sites</a> <span class="text-line-control" aria-hidden="true">/</span> {{ kind === 'image' ? 'from a Docker image' : 'from a Dockerfile' }}</span></template>
    <template #actions>
      <div class="flex flex-wrap gap-2">
        <Btn href="/apps/new">Or a Laravel, Node or Go application</Btn>
        <Btn href="/sites">Cancel</Btn>
      </div>
    </template>
  </PageHead>

  <ol aria-label="Steps" class="m-0 flex list-none flex-wrap gap-2 p-0">
    <li><Badge :status="step === 1 ? 'live' : 'neutral'">1 Details</Badge></li>
    <li><Badge :status="step === 2 ? 'live' : 'neutral'">2 Review</Badge></li>
  </ol>

  <template v-if="step === 1">
    <template v-if="kind === 'dockerfile'">
      <Panel title="Repository" note="From your GitHub App, or any repository on any Git host">
          <span v-if="!opts.appReady" class="text-[13px] text-faint">GitHub is not connected, so your repositories cannot be listed here. You can still paste any repository's address below.</span>
          <template v-else>
            <span v-if="opts.error" class="text-failed">{{ opts.error }}</span>
            <Field label="Filter" v-slot="{ id }"><input :id="id" v-model="s.filter" placeholder="owner/repository" autocomplete="off"></Field>
            <div class="flex max-h-[260px] flex-col overflow-y-auto border border-line">
              <button v-for="r in visibleRepos" :key="r.full_name" type="button" class="flex cursor-pointer justify-between gap-3 border-x-0 border-t-0 border-b border-line bg-transparent px-3.5 py-2.5 text-left text-inherit [font:inherit]" @click="pick(r.full_name, r.default_branch)">
                <span>{{ s.repo === r.full_name && !hasGit ? '● ' : '' }}{{ r.full_name }}</span><span class="text-faint">{{ r.private ? 'private' : 'public' }}</span>
              </button>
            </div>
          </template>
          <div :class="formRow">
            <Field label="Another repository" v-slot="{ id }"><input :id="id" v-model="s.other" placeholder="https://gitlab.com/group/app, git@host:team/app.git, or owner/repo" autocomplete="off" spellcheck="false"></Field>
            <Btn @click="useOther">Use this repository</Btn>
          </div>
          <FormError v-if="s.otherError !== ''" :message="s.otherError" />
          <span v-if="hasGit" class="text-[13px] text-faint"><b class="font-semibold text-ink-strong">{{ s.git.display }}</b> · {{ s.git.note }}</span>
          <FormError :message="errors.repository" />
          <Field v-if="s.repo !== ''" label="Branch" v-slot="{ id }">
            <select :id="id" v-model="s.branch"><option v-for="b in branches" :key="b" :value="b">{{ b }}</option></select></Field>
      </Panel>

      <Panel v-if="s.repo !== '' && s.branch !== ''" :title="`On ${s.branch}`">
          <span v-if="detection.error" class="text-failed">{{ detection.error }}</span>
          <template v-else-if="detection.found">
            <p class="text-accent">We found a Dockerfile (<span class="font-mono">{{ detection.file }}</span>)<template v-if="detection.port"> that exposes port <b>{{ detection.port }}</b><template v-if="detection.ports.length > 1"> (also {{ detection.ports.slice(1).join(', ') }})</template></template><template v-else> with no EXPOSE line: enter the port it listens on below </template>.</p>
            <p v-if="detection.compose" class="text-[13px] text-faint">A <span class="font-mono">{{ detection.compose }}</span> is in the repository. It is noticed and never run: this deploys the one Dockerfile as one container.</p>
          </template>
          <p v-else class="text-running">No Dockerfile at <span class="font-mono">{{ detection.file ?? 'Dockerfile' }}</span> on this branch (or the repository could not be read yet: a repository that needs a deploy key is read at the first deploy). You can still continue; the deploy fails with a clear message if the file is not there.</p>
      </Panel>
    </template>
    <Panel v-else title="Image" note="name:tag, from Docker Hub or any registry">
        <Notice role="note">Unharbored has not reviewed an image you name. It runs with no added privileges and a memory limit and is reachable only through the web server, but its code is whoever published it.</Notice>
        <Field label="Image" hint="The version (for example :2.1) is looked up once, now. The application then runs that exact build, so it never changes until you press Update." :error="errors.image" v-slot="{ id }"><input :id="id" v-model="s.image" placeholder="ghcr.io/acme/app:2.1" autocomplete="off" spellcheck="false"></Field>
        <div :class="formRow">
          <Field label="Registry user (private registries only)" :error="errors.registry_user" v-slot="{ id }"><input :id="id" v-model="s.registryUser" autocomplete="off" spellcheck="false"></Field>
          <Field label="Registry token" hint="Kept in the vault. Never shown again, never put in a command or a log." v-slot="{ id }"><input :id="id" v-model="s.registryToken" type="password" autocomplete="off" spellcheck="false"></Field>
        </div>
    </Panel>

    <Panel title="Application" note="Runs as one container, published on 127.0.0.1 only">
        <Field label="Name" :error="errors.name" v-slot="{ id }"><input :id="id" v-model="s.name" autocomplete="off" spellcheck="false"></Field>
        <fieldset class="flex flex-col gap-[7px] border-0 p-0">
          <legend :class="labelClass">Server (prepared for containers)</legend>
          <label v-for="sv in opts.servers ?? []" :key="sv.id" :class="check"><input v-model="s.node" type="radio" :value="sv.id" :disabled="!sv.prepared"> <span>{{ sv.name }} <span class="text-[13px] text-faint">{{ sv.memory }}</span>
            <template v-if="!sv.prepared"> <span class="text-[13px] text-failed">Not prepared for containers.</span><template v-if="opts.canPrepare"> <a :href="`/server/containers?node=${sv.id}`" target="_blank" rel="noopener">Prepare it (opens in a new tab)</a></template></template>
            <template v-else-if="kind === 'dockerfile' && sv.small"> <span class="text-[13px] text-running">Under 2 GB: building here uses the server's memory.</span></template></span></label>
          <span v-if="(opts.servers ?? []).length === 0" class="text-[13px] text-faint">This team has no servers yet.</span>
          <span v-if="anyUnprepared" class="text-[13px] text-faint">Preparing a server in the other tab? What you typed here is kept. <Btn small @click="recheck">Check the servers again</Btn></span>
          <FormError :message="errors.node" />
        </fieldset>
        <div v-if="kind === 'dockerfile'" :class="formRow">
          <Field label="Dockerfile path" :error="errors.dockerfile" v-slot="{ id }"><input :id="id" v-model="s.dockerfile" placeholder="Dockerfile" autocomplete="off" spellcheck="false"></Field>
          <Field label="Build context" :error="errors.context" v-slot="{ id }"><input :id="id" v-model="s.context" placeholder="." autocomplete="off" spellcheck="false"></Field>
        </div>
        <div :class="formRow">
          <Field label="Container port" hint="The port your app listens on inside the container (a web server such as nginx uses 80). Blank: the detected one." :error="errors.container_port" v-slot="{ id }"><input :id="id" v-model="s.containerPort" type="number" :placeholder="String(detection.port ?? 8080)" autocomplete="off"></Field>
          <Field label="Health check path" hint="A page that answers with success while the app is healthy, for example /health. Blank: it is enough that the port answers." :error="errors.health" v-slot="{ id }"><input :id="id" v-model="s.health" placeholder="blank: the port answers" autocomplete="off" spellcheck="false"></Field>
          <Field label="Memory limit" hint="The most memory the container may use. If it goes over, it is stopped and started again." :error="errors.memory" v-slot="{ id }"><input :id="id" v-model="s.memory" placeholder="512MB" autocomplete="off"></Field>
        </div>
        <Field label="Domain" hint="Point your domain at this server first: an A record, or AAAA for a server with only IPv6. With no domain you get a temporary address you can open right away." :error="errors.domain" v-slot="{ id }"><input :id="id" v-model="s.domain" placeholder="blank: a temporary address is made for you" autocomplete="off" spellcheck="false"></Field>
        <Field label="Folders that keep data (one per line, inside the container)" hint="Each folder is kept on the server between deploys, so what your app saves there is not lost. Nothing from the server's own folders is ever shared with it." :error="errors.volumes" v-slot="{ id }"><textarea :id="id" v-model="s.volumes" rows="2" placeholder="/data" spellcheck="false"></textarea></Field>
        <Toggle v-model="s.readOnly">Read-only root filesystem (a /tmp is provided). Off by default: not every image runs with it.</Toggle>
        <Field label="Variables (NAME=value, one per line)" hint="Passed to the container only through a private file, never as arguments. More can be added later on the Variables page." :error="errors.env" v-slot="{ id }"><textarea :id="id" v-model="s.envText" rows="4" spellcheck="false" autocomplete="off"></textarea></Field>
        <Field v-if="(opts.projects ?? []).length > 1" label="Project" v-slot="{ id }">
          <select :id="id" :value="projectValue" @change="s.project = $event.target.value"><option v-for="pr in opts.projects" :key="pr.id" :value="String(pr.id)">{{ pr.name }}</option></select></Field>
        <p class="text-[13px] text-faint">Not available for a container application in this version: a scheduler, queue workers, background processes, previews, a load balancer and autoscaling.</p>
        <div class="flex flex-wrap gap-2"><Btn primary :disabled="busy" @click="toReview">Review →</Btn></div>
    </Panel>
  </template>

  <Panel v-else :title="`Ready to create ${review.name ?? ''}`" note="Nothing has been created yet">
      <Notice v-for="w in review.warnings ?? []" :key="w" alert>{{ w }}</Notice>
      <dl class="m-0 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-[22px] gap-y-4">
        <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">{{ review.kind === 'image' ? 'Image' : 'Repository' }}</h2></dt><dd class="m-0 text-[15px] font-semibold break-words text-ink-strong font-mono">{{ review.kind === 'image' ? review.image : `${review.repository} · ${review.branch}` }}</dd></div>
        <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Server</h2></dt><dd class="m-0 text-[15px] font-semibold break-words text-ink-strong">{{ review.node }}</dd></div>
        <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Address</h2></dt><dd class="m-0 text-[15px] font-semibold break-words text-ink-strong">{{ review.domain ? review.domain : 'an sslip.io address' }}</dd></div>
      </dl>
      <h3 class="m-0 text-base font-semibold text-ink-strong">What will happen</h3>
      <ul class="m-0 flex list-disc flex-col gap-1.5 pl-5">
        <li v-if="review.kind === 'dockerfile'">The repository is checked out on the server and <span class="font-mono">{{ review.settings?.dockerfile }}</span> is built there (<span class="font-mono">docker build</span>, context <span class="font-mono">{{ review.settings?.context ?? '.' }}</span>), then started on a free port beside the old container; nginx switches only when the health check passes.</li>
        <template v-else>
          <li>The image is pulled on the first deploy and the digest the tag has then is pinned: <b>{{ review.digest ?? 'resolved at the first pull' }}</b>. Later deploys run that digest.</li>
          <li v-if="review.registry">Pulled with the registry login <b>{{ review.registry.user }}</b> on {{ review.registry.server }}. The token is kept in the vault and used for the pull only.</li>
        </template>
        <li>Container port <b>{{ review.settings?.container_port }}</b>, memory limit <b>{{ review.settings?.memory }}</b>, {{ review.settings?.health ? `health check ${review.settings.health}` : 'health check: the port answers' }}.</li>
        <li>No added privileges, no host network, no access to the Docker socket or the server's folders; reachable on 127.0.0.1 only, through nginx with HTTPS.{{ review.settings?.read_only ? ' Read-only root filesystem.' : '' }}</li>
        <li v-if="(review.settings?.volumes ?? []).length > 0">Data kept in Docker volumes for {{ review.settings.volumes.join(', ') }}.</li>
      </ul>
      <FormError v-for="(message, key) in errors" :key="key" :message="message" />
      <div class="flex flex-wrap gap-2">
        <Btn @click="back">← Back</Btn>
        <Btn primary :disabled="busy" @click="create">Create and deploy</Btn>
      </div>
  </Panel>
</template>
