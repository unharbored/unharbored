<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Btn from '../Components/ui/Btn.vue';
import Empty from '../Components/ui/Empty.vue';
import Field from '../Components/ui/Field.vue';
import FormError from '../Components/ui/FormError.vue';
import FormRow from '../Components/ui/FormRow.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { confirm } from '../composables/useConsole';
import { usePolling } from '../composables/usePolling';
import { getJson } from '../composables/useJson';

defineOptions({ layout: Console });

const props = defineProps({ clusters: Array, archived: { type: Array, default: () => [] }, choices: Object, byo: Object });

const first = Object.keys(props.choices)[0] ?? 'digitalocean';
const form = useForm({ name: '', provider: first, engine: 'pg', region: props.choices[first]?.region ?? '', size: props.choices[first]?.size ?? '', address: '' });
const choice = computed(() => props.choices[form.provider] ?? { label: '', managed: true, engines: {}, regions: {}, sizes: {} });

// Another provider has other regions and sizes: start from its own.
watch(() => form.provider, (id) => {
    form.region = props.choices[id].region;
    form.size = props.choices[id].size;
    if (!(form.engine in props.choices[id].engines)) form.engine = Object.keys(props.choices[id].engines)[0];
});

usePolling(['clusters', 'archived'], 5000);

async function create() {
    if (choice.value.custom) {
        form.post('/databases', { preserveScroll: true });
        return;
    }
    const where = choice.value.label;
    const message = choice.value.managed
        ? `It bills your ${where} account from now until it is destroyed.`
        : `This creates a server at ${where} that Unharbored sets up and runs. It bills your ${where} account from now until it is destroyed, and there is no automatic failover or provider backup: your sites' nightly dumps are the backup.`;
    if (await confirm({ title: 'Create this cluster?', message, ok: 'Create' })) {
        form.post('/databases');
    }
}

// Clusters already in the DigitalOcean account that Unharbored did not make. Asked for when wanted.
const found = ref(null);
const looking = ref(false);
const lookError = ref('');

async function look() {
    looking.value = true;
    lookError.value = '';
    const { ok, data } = await getJson('/databases/discover');
    looking.value = false;
    if (!ok) { lookError.value = data?.message ?? 'Could not look.'; return; }
    found.value = data.clusters;
}

async function bringIn(c) {
    if (await confirm({ title: 'Bring it in?', message: `${c.name} stays exactly as it is at DigitalOcean: nothing on it is changed. Unharbored can then give an application of yours its own database and user on it, and add that application's servers to its firewall. Destroying it here only forgets it.`, ok: 'Bring it in' })) {
        router.post('/databases/adopt', { cluster: c.id }, { preserveScroll: true, onSuccess: () => look() });
    }
}

const newAddress = ref({});
async function reissue(c) {
    const changed = c.ownServer && (newAddress.value[c.id] || '').trim() !== '';
    const message = `Makes a new certificate for ${c.slug}${changed ? ` at ${newAddress.value[c.id].trim()}` : ''} and gives it to every attached site. The database restarts for a few seconds. Use it when the server's address changed or the certificate is old.${changed ? ' Sites keep the old address until they are deployed.' : ''}`;
    if (await confirm({ title: 'New certificate?', message, ok: 'Make it' })) {
        router.post(`/databases/${c.id}/certificate`, changed ? { address: newAddress.value[c.id].trim() } : {});
    }
}

async function archive(c) {
    const bill = c.ownServer ? 'It is a server of your own: it is yours already.' : `It keeps costing money at ${props.choices[c.provider]?.label ?? c.provider}.`;
    const server = c.selfRun ? ' Its server is archived with it, and Unharbored stops its monitoring, alerts, backups and firewall changes.' : ' Unharbored stops attaching sites to it, and its backups and alerts.';
    const message = `Unharbored stops managing ${c.slug}. It keeps running and nothing is deleted. ${bill}${server} Sites that use it must be archived or detached first. You can restore it any time, and delete it from here later too.`;
    if (await confirm({ title: `Archive ${c.slug}?`, message, ok: 'Archive cluster' })) {
        router.post(`/databases/${c.id}/archive`, {}, { preserveScroll: true });
    }
}

async function destroy(c) {
    if (c.adopted) {
        if (await confirm({ title: 'Forget it?', message: `Forget ${c.slug}? It and every database on it stay at DigitalOcean untouched; Unharbored just stops tracking it.`, ok: 'Forget' })) {
            router.delete(`/databases/${c.id}`);
        }
        return;
    }
    if (c.ownServer) {
        if (await confirm({ title: 'Remove it?', message: `Remove ${c.slug}? Your server and the database on it are not touched: only Unharbored's agent is removed from the server, and the database stops being managed here. Delete the data yourself if you want it gone.`, ok: 'Remove' })) {
            router.delete(`/databases/${c.id}`);
        }
        return;
    }
    if (await confirm({ title: 'Destroy it?', message: `Destroy ${c.slug}? Every database on it is deleted and cannot be recovered.`, ok: 'Destroy', tone: 'danger' })) {
        router.delete(`/databases/${c.id}`);
    }
}
</script>

<template>
  <Head title="Databases" />
  <PageHead title="Databases" lede="Shared by the whole team, not filtered by project. Managed clusters at DigitalOcean, or PostgreSQL and MySQL on a server Unharbored runs at any other provider. Each attached site gets its own database and user, and the cluster admits only its servers." />
  <FormError :message="$page.props.errors.destroy" />
  <FormError :message="$page.props.errors.adopt" />
  <FormError :message="$page.props.errors.archive" />

  <Panel v-for="c in clusters" :key="c.id" :title="c.slug">
    <template #actions>
      <span v-if="c.state === 'active'" class="font-mono text-[10.5px] tracking-[0.13em] text-accent uppercase">● online</span>
      <span v-else-if="c.state === 'provisioning' || c.state === 'pending'" class="font-mono text-[10.5px] tracking-[0.13em] text-running uppercase">● creating</span>
      <span v-else class="font-mono text-[10.5px] tracking-[0.13em] text-failed uppercase">● {{ c.state }}</span>
    </template>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <span class="text-muted">{{ c.engine }}<template v-if="c.version"> {{ c.version }}</template> · {{ choices[c.provider]?.label ?? c.provider }} · {{ c.ownServer ? 'your own server' : c.selfRun ? 'a server we run' : c.adopted ? 'managed, brought in' : 'managed' }} · {{ c.where }}<template v-if="c.monthly"> · ${{ c.monthly }}/mo </template></span>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="c.operation" small :href="`/operation?operation=${c.operation.id}`">Last operation · {{ c.operation.status }}</Btn>
        <input v-if="c.selfRun && c.ownServer && c.state === 'active'" v-model="newAddress[c.id]" placeholder="new address, if it changed" class="w-48 rounded-md border border-line bg-transparent px-2 py-1 text-[13px]" />
        <Btn v-if="c.selfRun && c.state === 'active'" small @click="reissue(c)">New certificate</Btn>
        <Btn v-if="c.state === 'active'" small @click="archive(c)">Archive</Btn>
        <Btn small danger :disabled="c.sites.length > 0 || c.state === 'provisioning'" @click="destroy(c)">{{ c.adopted ? 'Forget' : c.ownServer ? 'Remove' : 'Destroy' }}</Btn>
      </div>
    </div>
    <span v-if="c.error" class="text-[13px] text-failed">{{ c.error }}</span>
    <span v-if="c.host" class="text-[13px] text-faint">{{ c.host }} · TLS required · only attached servers may connect</span>
    <div><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Sites</span><br>
      <template v-for="(s, i) in c.sites" :key="s.id"><a :href="`/site/settings?site=${s.id}#database`">{{ s.name }}</a><template v-if="i < c.sites.length - 1">, </template></template>
      <span v-if="c.sites.length === 0" class="text-faint">none yet: attach one from a site's settings</span>
    </div>
  </Panel>
  <div v-if="clusters.length === 0" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel"><Empty>No database clusters. Sites use SQLite on their server until one is attached.</Empty></div>

  <details v-if="archived.length > 0" class="rounded-lg border border-line bg-surface">
    <summary class="cursor-pointer px-5 py-3.5 font-mono text-[11px] tracking-[0.12em] text-muted uppercase">Archived · {{ archived.length }}</summary>
    <p class="m-0 px-5 pb-2 text-[13px] text-muted">Unharbored no longer manages these. They keep running, and keep costing money where they are hosted.</p>
    <ul class="m-0 list-none p-0">
      <li v-for="c in archived" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-5 py-3">
        <span><b class="font-semibold text-ink-strong">{{ c.slug }}</b> <span class="text-[13px] text-faint">{{ c.engine }} · {{ choices[c.provider]?.label ?? c.provider }} · {{ c.where }} · archived {{ c.since }}<template v-if="c.sites.length > 0"> · still used by {{ c.sites.map((s) => s.name).join(', ') }}</template></span></span>
        <div class="flex flex-wrap gap-2">
          <Btn small @click="router.post(`/databases/${c.id}/restore`, {}, { preserveScroll: true })">Restore</Btn>
          <Btn small danger :disabled="c.sites.length > 0" @click="destroy(c)">{{ c.adopted ? 'Forget' : c.ownServer ? 'Remove' : 'Destroy' }}</Btn>
        </div>
      </li>
    </ul>
  </details>

  <Panel v-if="choices.digitalocean" title="Bring in a cluster you already have" note="PostgreSQL and MySQL clusters in your DigitalOcean account that Unharbored did not make">
    <div class="flex flex-wrap items-center gap-3">
      <Btn small :disabled="looking" @click="look">{{ found === null ? 'Look in my DigitalOcean account' : 'Look again' }}</Btn>
      <span v-if="found !== null && found.length === 0" class="text-[13px] text-faint">No other PostgreSQL or MySQL clusters are online in the account.</span>
    </div>
    <FormError :message="lookError" />
    <div v-for="c in found ?? []" :key="c.id" class="flex flex-wrap items-center justify-between gap-3">
      <span><b>{{ c.name }}</b> <span class="text-muted">· {{ c.engine === 'pg' ? 'PostgreSQL' : 'MySQL' }}<template v-if="c.version"> {{ c.version }}</template> · {{ c.region }}<template v-if="c.nodes > 1"> · {{ c.nodes }} nodes</template></span></span>
      <Btn small primary @click="bringIn(c)">Bring it in</Btn>
    </div>
    <span class="text-[13px] text-faint">Nothing on the cluster changes when it is brought in. Applications you attach get a database and user of their own, and a name that already exists on the cluster is refused rather than touched. Valkey clusters are not offered: they have one password for everyone.</span>
  </Panel>

  <Panel v-if="Object.keys(choices).length === 0" title="Create a cluster">
    <span class="text-[13px] text-faint">Connect a provider under Account › Providers first.</span>
  </Panel>
  <Panel v-else title="Create a cluster" :note="choice.custom ? 'On a server you already have' : `Billed by ${choice.label} from creation until it is destroyed`">
    <FormRow tag="form" @submit.prevent="create">
      <Field v-if="Object.keys(choices).length > 1" label="Provider" v-slot="{ id }">
        <select :id="id" v-model="form.provider"><option v-for="(c, k) in choices" :key="k" :value="k">{{ c.label }}</option></select>
      </Field>
      <Field label="Name" v-slot="{ id }"><input :id="id" v-model="form.name" placeholder="main" autocomplete="off"></Field>
      <Field label="Engine" v-slot="{ id }">
        <select :id="id" v-model="form.engine"><option v-for="(label, k) in choice.engines" :key="k" :value="k">{{ label }}</option></select>
      </Field>
      <Field v-if="choice.custom" label="Address (only if it is not where the install command runs from)" v-slot="{ id }"><input :id="id" v-model="form.address" placeholder="203.0.113.9" autocomplete="off"></Field>
      <Field v-if="!choice.custom" label="Region" v-slot="{ id }">
        <select :id="id" v-model="form.region"><option v-for="(label, k) in choice.regions" :key="k" :value="k">{{ k }} · {{ label }}</option></select>
      </Field>
      <Field v-if="!choice.custom" label="Size" v-slot="{ id }">
        <select :id="id" v-model="form.size"><option v-for="(label, k) in choice.sizes" :key="k" :value="k">{{ label }}</option></select>
      </Field>
      <Btn type="submit" primary :disabled="form.processing">{{ choice.custom ? 'Get install command' : '+ Create cluster' }}</Btn>
    </FormRow>
    <FormError :message="form.errors.name" />
    <FormError :message="form.errors.address" />
    <template v-if="byo">
      <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Run this on the server, as root or with sudo</span>
      <pre class="m-0 overflow-x-auto rounded-[10px] border border-accent-line bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] break-all whitespace-pre-wrap text-ink">{{ byo.command }}</pre>
      <span class="text-[13px] text-faint">It works once, for 15 minutes. Use a freshly installed Ubuntu server: it is refused if it already runs a web server or a database. The server needs outbound access on ports 443 and 9443, and the database port (5432 or 3306) must be reachable from your application servers.</span>
      <div class="flex flex-wrap gap-2"><Btn primary :href="`/operation?operation=${byo.operation}`">Follow it →</Btn></div>
    </template>
    <span v-if="choice.custom" class="text-[13px] text-faint">A server you already have, with nothing else on it. Unharbored installs the database, turns TLS on and closes the firewall to everything but your attached servers. Nothing is billed by Unharbored, and removing the cluster later leaves your server and its data alone.</span>
    <span v-else class="text-[13px] text-faint">Use the same region as the servers that will use it.
      <template v-if="choice.managed">DigitalOcean takes about five minutes to bring a cluster online.</template>
      <template v-else>A new server takes a few minutes to start and be set up. It is one machine: no failover, and the provider does not back it up.</template></span>
  </Panel>
</template>
