<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Panel from '../Components/ui/Panel.vue';
import PageHead from '../Components/ui/PageHead.vue';
import FormError from '../Components/ui/FormError.vue';
import Field from '../Components/ui/Field.vue';
import Btn from '../Components/ui/Btn.vue';
import Badge from '../Components/ui/Badge.vue';
import Notice from '../Components/ui/Notice.vue';
import { postJson } from '../composables/useJson.js';

defineOptions({ layout: Console });

const check = 'inline-flex min-h-11 cursor-pointer items-center gap-2.5';
const labelClass = 'font-mono text-[10px] tracking-[0.18em] text-muted uppercase';

/**
 * Installing a template in two steps: the form, generated from the definition's inputs, then a review of
 * everything that will be created. Secret inputs stay in this page, are sent only to check and create, and
 * are cleared when the person goes back.
 */
const props = defineProps({ template: String, form: Object, servers: Array, newServer: { type: Object, default: () => ({}) } });

const step = ref(1);
const errors = ref({});
const busy = ref(false);
const review = ref({});
const servers = ref(props.servers);
const values = reactive({});
const secrets = reactive({});
const s = reactive({ node: '', project: '', acknowledged: false, portsApproved: false });

// A server made for this service: what the chosen provider offers, filtered to sizes big enough.
const NEW = '__new';
const providers = Object.keys(props.newServer);
const n = reactive({ provider: providers[0] ?? '', region: '', size: '' });
const choice = computed(() => props.newServer[n.provider] ?? { regions: {}, sizes: {} });
watch(() => n.provider, () => { n.region = choice.value.region ?? ''; n.size = choice.value.size ?? ''; }, { immediate: true });

// Every input starts blank: blank means the default.
for (const f of props.form.fields ?? []) {
    (f.type === 'secret' ? secrets : values)[f.key] = '';
}

const projectValue = computed(() => (s.project !== '' ? s.project : String(props.form.currentProject ?? '')));
const anyNotFitting = computed(() => servers.value.some((sv) => !sv.fits));

// The disk check follows the storage size typed.
let timer;
watch(() => ({ ...values }), () => {
    clearTimeout(timer);
    timer = setTimeout(recheck, 400);
}, { deep: true });
/** After fixing a server in another tab: look at the servers again without losing what was typed. */
async function recheck() {
    const { ok, data } = await postJson('/services/new/servers', { template: props.template, values });
    if (ok) servers.value = data;
}

const body = () => ({ template: props.template, values, secrets, node: s.node === NEW ? '' : s.node, newServer: s.node === NEW ? { ...n } : undefined, project: s.project, acknowledged: s.acknowledged, portsApproved: s.portsApproved });
async function toReview() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/services/new/check', body());
    busy.value = false;
    errors.value = e;
    if (ok) { review.value = data; step.value = 2; }
}
/** Back to the form: a typed secret is not kept for it, type it again. */
function back() {
    step.value = 1;
    for (const k of Object.keys(secrets)) secrets[k] = '';
}
async function create() {
    busy.value = true;
    const { ok, data, errors: e } = await postJson('/services/new/create', body());
    errors.value = e;
    if (ok) {
        for (const k of Object.keys(secrets)) secrets[k] = '';
        window.location.href = `/operation?operation=${data.operation}`;
        return;
    }
    busy.value = false;
}
</script>

<template>
  <Head :title="`Install ${form.name ?? 'a template'}`" />
  <PageHead :title="`Install ${form.name ?? 'a template'}`">
    <template #crumbs><span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase"><a href="/templates">templates</a> <span class="text-line-control" aria-hidden="true">/</span> {{ form.name ?? 'install' }}</span></template>
    <p v-if="form.description" class="m-0 max-w-[64ch] leading-[1.65] text-muted">{{ form.description }} Needs a server with at least {{ form.memory }} of memory.<template v-if="form.label"> <span class="text-running">{{ form.label }}</span></template></p>
    <template #actions><Btn href="/templates">Cancel</Btn></template>
  </PageHead>

  <Notice v-if="form.error" alert>{{ form.error }} <a href="/templates">Back to the catalogue</a></Notice>
  <Notice v-else-if="!form.canInstall">Installing a service needs the Manager role in this team. Ask a Manager to do it.</Notice>
  <template v-else>
    <ol aria-label="Steps" class="m-0 flex list-none flex-wrap gap-2 p-0">
      <li><Badge :status="step === 1 ? 'live' : 'neutral'">1 Details</Badge></li>
      <li><Badge :status="step === 2 ? 'live' : 'neutral'">2 Review</Badge></li>
    </ol>

    <Panel v-if="step === 1" title="Details" note="Blank fields use the default shown">
        <Field v-for="f in form.fields ?? []" :key="f.key" :label="`${f.label}${f.required ? ' (required)' : ''}`" :hint="[f.help, f.generated ? 'Leave it blank for a free name on the server\'s address (sslip.io); it needs no DNS record.' : '', f.type === 'secret' ? 'Kept in the vault. Never shown again, never stored with the service\'s settings.' : ''].filter(Boolean).join(' ')" :error="errors[`values.${f.key}`] || errors[`secrets.${f.key}`]" v-slot="{ id }">
          <select v-if="f.type === 'choice'" :id="id" v-model="values[f.key]"><option value="">Default: {{ f.default }}</option><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
          <input v-else-if="f.type === 'secret'" :id="id" v-model="secrets[f.key]" type="password" autocomplete="off" spellcheck="false">
          <input v-else :id="id" v-model="values[f.key]" :type="f.type === 'number' ? 'number' : 'text'" :placeholder="f.generated ? 'Blank: a free name is made for you' : (f.default ?? '')" autocomplete="off" spellcheck="false">
        </Field>

        <Field v-if="(form.projects ?? []).length > 1" label="Project" v-slot="{ id }">
          <select :id="id" :value="projectValue" @change="s.project = $event.target.value"><option v-for="pr in form.projects" :key="pr.id" :value="String(pr.id)">{{ pr.name }}</option></select></Field>

        <fieldset class="flex flex-col gap-[7px] border-0 p-0">
          <legend :class="labelClass">Server</legend>
          <label v-for="sv in servers" :key="sv.id" :class="check"><input v-model="s.node" type="radio" :value="sv.id" :disabled="!sv.fits"> <span>{{ sv.name }} <span class="text-[13px] text-faint">{{ sv.memory }} · {{ sv.disk }}</span>
            <template v-if="!sv.fits"> <span class="text-[13px] text-failed">Does not fit: {{ sv.reason }}</span><template v-if="sv.prepare && form.canInstall"> <a class="text-[13px]" :href="`/server/containers?node=${sv.id}`" target="_blank" rel="noopener">Prepare this server for containers first (opens in a new tab)</a></template></template>
            <template v-else> <span class="text-[13px] text-accent">fits</span></template></span></label>
          <label v-if="providers.length > 0" :class="check"><input v-model="s.node" type="radio" :value="NEW"> <span>A new server for this service <span class="text-[13px] text-faint">made when you press Create, and billed by its provider</span></span></label>
          <div v-if="s.node === NEW" class="grid gap-3 border-l border-line pl-4 sm:grid-cols-3">
            <Field label="Provider" v-slot="{ id }"><select :id="id" v-model="n.provider"><option v-for="p in providers" :key="p" :value="p">{{ newServer[p].label }}</option></select></Field>
            <Field label="Region" v-slot="{ id }"><select :id="id" v-model="n.region"><option v-for="(label, slug) in choice.regions" :key="slug" :value="slug">{{ label }}</option></select></Field>
            <Field label="Size" :hint="`Big enough for ${form.memory} of memory.`" v-slot="{ id }"><select :id="id" v-model="n.size"><option v-for="(label, slug) in choice.sizes" :key="slug" :value="slug">{{ label }}</option></select></Field>
          </div>
          <span v-if="servers.length === 0 && providers.length === 0" class="text-[13px] text-faint">This team has no servers yet.</span>
          <span v-if="anyNotFitting" class="text-[13px] text-faint">Something to fix on a server? What you typed here is kept. <Btn small @click="recheck">Check the servers again</Btn></span>
          <FormError :message="errors.node" />
        </fieldset>

        <div v-if="(form.extraPorts ?? []).length > 0" class="flex flex-col gap-[7px]">
          <span :class="labelClass">Extra ports</span>
          <ul class="m-0 list-disc pl-5"><li v-for="p in form.extraPorts" :key="p.port">{{ p.port }}/{{ p.proto }} · {{ p.label }}</li></ul>
          <label :class="check"><input v-model="s.portsApproved" type="checkbox"> I approve opening these ports in the server's firewall</label>
          <FormError :message="errors.portsApproved" />
        </div>
        <div v-if="form.acknowledge" class="flex flex-col gap-[7px]">
          <label :class="check"><input v-model="s.acknowledged" type="checkbox"> {{ form.acknowledge }}</label>
          <FormError :message="errors.acknowledged" />
        </div>

        <FormError :message="errors.form" />
        <div class="flex flex-wrap gap-2"><Btn primary :disabled="busy" @click="toReview">Review →</Btn></div>
    </Panel>

    <Panel v-else :title="`Ready to create ${review.service ?? ''}`" note="Nothing has been created yet">
        <Notice v-if="review.warning" alert>{{ review.warning }}</Notice>
        <dl class="m-0 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-[22px] gap-y-4">
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px] [&_dd]:m-0 [&_dd]:break-words"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Service</h2></dt><dd class="text-[15px] font-semibold text-ink-strong">{{ review.service }}</dd><dd class="text-[13px] text-faint">{{ review.template }} version {{ review.version }}<template v-if="review.label"> · {{ review.label }}</template></dd></div>
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px] [&_dd]:m-0 [&_dd]:break-words"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Server</h2></dt><dd class="text-[15px] font-semibold text-ink-strong">{{ review.server }}</dd><dd class="text-[13px] text-faint">{{ review.ip ?? (review.newServer ? 'made when you press Create' : 'public address not known') }}</dd></div>
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px] [&_dd]:m-0 [&_dd]:break-words"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Project</h2></dt><dd class="text-[15px] font-semibold text-ink-strong">{{ review.project }}</dd></div>
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px] [&_dd]:m-0 [&_dd]:break-words"><dt><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Data folder</h2></dt><dd class="text-[15px] font-semibold text-ink-strong font-mono">{{ review.data }}</dd><dd v-if="review.storage" class="text-[13px] text-faint">{{ review.storage }} of storage</dd></div>
        </dl>
        <h3 class="m-0 text-base font-semibold text-ink-strong">What will be created</h3>
        <ul class="m-0 flex list-disc flex-col gap-1.5 pl-5">
          <li v-if="review.newServer">{{ review.server }}, set up first and billed by its provider until you destroy it. Free names are made from its address once it exists.</li>
          <li>A service called <b>{{ review.service }}</b>, started and kept running by the server's service manager.</li>
          <li v-for="e in review.endpoints ?? []" :key="e.domain">Address <b>https://{{ [e.domain, ...(e.aliases ?? [])].join(', https://') }}</b> (port {{ e.port }} on the server, behind the web server) with {{ (e.aliases ?? []).length ? 'one certificate for all of them' : 'its own certificate' }}.</li>
          <li v-if="(review.secrets ?? []).length > 0">Generated credentials ({{ review.secrets.join(', ') }}), kept in the vault. You can reveal them on the service page; each reveal is recorded.</li>
          <template v-if="review.container">
            <li>Runs as the container <b>cm-svc-{{ review.service }}</b> from the image <span class="font-mono text-[13px]">{{ review.container.image }}</span>, pinned by digest <span class="font-mono text-[13px]">{{ review.container.digest }}</span> (checked after the pull).</li>
            <li>Memory limit <b>{{ review.container.memory }}</b><template v-if="review.container.cpu">, up to {{ review.container.cpu }} CPU </template>. No added privileges, no access to the Docker socket or to the server's folders.</li>
            <li>Data in the Docker volume <template v-for="v in review.container.volumes" :key="v"><span class="font-mono text-[13px]">{{ v }}</span> </template>(kept unless you choose to delete it).</li>
            <li>Its port is reachable on <b>127.0.0.1 only</b> (a port Unharbored picks on the server); the web server is the only way in, so it is never open to the internet by itself.</li>
          </template>
          <li v-else>Downloaded from <b>{{ review.download?.host }}</b><template v-if="review.download?.sha256">, checked against SHA-256 <span class="font-mono text-[13px]">{{ review.download.sha256 }}</span>, version {{ review.version }} of the template </template>.</li>
          <li v-if="review.backup">Backups: you chose <b>{{ review.backup }}</b>.<template v-if="review.backup === 'none'"> It is left out of the nightly backup; you can still press Back up now on its page.</template><template v-else-if="review.backup === 'spaces'"> Every night its data is archived on the server and copied to the backup bucket (set one up with backup:offsite; without it the copy stays on the server), and 14 days are kept.</template><template v-else> Every night its data is archived on the server's own disk and 14 days are kept; that protects against a bad update or a mistake, not against losing the server.</template> A backup is also taken before every update and every restore.</li>
        </ul>
        <template v-if="(review.dns ?? []).length > 0">
        <h3 class="m-0 text-base font-semibold text-ink-strong">DNS records needed</h3>
        <ul class="m-0 flex list-disc flex-col gap-1.5 pl-5">
          <li v-for="d in review.dns ?? []" :key="d.domain"><span class="font-mono">{{ d.domain }}</span> · {{ d.type }} record <template v-if="d.value">→ <span class="font-mono">{{ d.value }}</span></template><template v-else>→ the server's public address</template></li>
        </ul>
        <p class="text-[13px] text-faint">DNS must point at the server before the certificate step can succeed. If it does not yet, the install stops there; point the records and install again.</p>
        </template>
        <template v-if="(review.extraPorts ?? []).length > 0">
          <h3 class="m-0 text-base font-semibold text-ink-strong">Extra ports you approved</h3>
          <ul class="m-0 flex list-disc flex-col gap-1.5 pl-5"><li v-for="p in review.extraPorts" :key="p.port">{{ p.port }}/{{ p.proto }} · {{ p.label }}</li></ul>
        </template>
        <p class="text-accent">{{ review.role }}</p>
        <FormError :message="errors.form" />
        <div class="flex flex-wrap gap-2">
          <Btn @click="back">← Back</Btn>
          <Btn primary :disabled="busy" @click="create">Create and start</Btn>
        </div>
    </Panel>
  </template>
</template>
