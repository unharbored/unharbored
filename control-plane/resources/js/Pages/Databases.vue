<script setup>
import { computed, watch } from 'vue';
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

defineOptions({ layout: Console });

const props = defineProps({ clusters: Array, choices: Object });

const first = Object.keys(props.choices)[0] ?? 'digitalocean';
const form = useForm({ name: '', provider: first, engine: 'pg', region: props.choices[first]?.region ?? '', size: props.choices[first]?.size ?? '' });
const choice = computed(() => props.choices[form.provider] ?? { label: '', managed: true, engines: {}, regions: {}, sizes: {} });

// Another provider has other regions and sizes: start from its own.
watch(() => form.provider, (id) => {
    form.region = props.choices[id].region;
    form.size = props.choices[id].size;
    if (!(form.engine in props.choices[id].engines)) form.engine = Object.keys(props.choices[id].engines)[0];
});

usePolling(['clusters'], 5000);

async function create() {
    const where = choice.value.label;
    const message = choice.value.managed
        ? `It bills your ${where} account from now until it is destroyed.`
        : `This creates a server at ${where} that Unharbored sets up and runs. It bills your ${where} account from now until it is destroyed, and there is no automatic failover or provider backup: your sites' nightly dumps are the backup.`;
    if (await confirm({ title: 'Create this cluster?', message, ok: 'Create' })) {
        form.post('/databases');
    }
}

async function destroy(c) {
    if (await confirm({ title: 'Destroy it?', message: `Destroy ${c.slug}? Every database on it is deleted and cannot be recovered.`, ok: 'Destroy', tone: 'danger' })) {
        router.delete(`/databases/${c.id}`);
    }
}
</script>

<template>
  <Head title="Databases" />
  <PageHead title="Databases" lede="Shared by the whole team, not filtered by project. Managed clusters at DigitalOcean, or PostgreSQL and MySQL on a server Unharbored runs at any other provider. Each attached site gets its own database and user, and the cluster admits only its servers." />
  <FormError :message="$page.props.errors.destroy" />

  <Panel v-for="c in clusters" :key="c.id" :title="c.slug">
    <template #actions>
      <span v-if="c.state === 'active'" class="font-mono text-[10.5px] tracking-[0.13em] text-accent uppercase">● online</span>
      <span v-else-if="c.state === 'provisioning' || c.state === 'pending'" class="font-mono text-[10.5px] tracking-[0.13em] text-running uppercase">● creating</span>
      <span v-else class="font-mono text-[10.5px] tracking-[0.13em] text-failed uppercase">● {{ c.state }}</span>
    </template>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <span class="text-muted">{{ c.engine }}<template v-if="c.version"> {{ c.version }}</template> · {{ choices[c.provider]?.label ?? c.provider }} · {{ c.selfRun ? 'a server we run' : 'managed' }} · {{ c.where }}<template v-if="c.monthly"> · ${{ c.monthly }}/mo </template></span>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="c.operation" small :href="`/operation?operation=${c.operation.id}`">Last operation · {{ c.operation.status }}</Btn>
        <Btn small danger :disabled="c.sites.length > 0 || c.state === 'provisioning'" @click="destroy(c)">Destroy</Btn>
      </div>
    </div>
    <span v-if="c.host" class="text-[13px] text-faint">{{ c.host }} · TLS required · only attached servers may connect</span>
    <div><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Sites</span><br>
      <template v-for="(s, i) in c.sites" :key="s.id"><a :href="`/site/settings?site=${s.id}#database`">{{ s.name }}</a><template v-if="i < c.sites.length - 1">, </template></template>
      <span v-if="c.sites.length === 0" class="text-faint">none yet: attach one from a site's settings</span>
    </div>
  </Panel>
  <div v-if="clusters.length === 0" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel"><Empty>No database clusters. Sites use SQLite on their server until one is attached.</Empty></div>

  <Panel v-if="Object.keys(choices).length === 0" title="Create a cluster">
    <span class="text-[13px] text-faint">Connect a provider under Account › Providers first.</span>
  </Panel>
  <Panel v-else title="Create a cluster" :note="`Billed by ${choice.label} from creation until it is destroyed`">
    <FormRow tag="form" @submit.prevent="create">
      <Field v-if="Object.keys(choices).length > 1" label="Provider" v-slot="{ id }">
        <select :id="id" v-model="form.provider"><option v-for="(c, k) in choices" :key="k" :value="k">{{ c.label }}</option></select>
      </Field>
      <Field label="Name" v-slot="{ id }"><input :id="id" v-model="form.name" placeholder="main" autocomplete="off"></Field>
      <Field label="Engine" v-slot="{ id }">
        <select :id="id" v-model="form.engine"><option v-for="(label, k) in choice.engines" :key="k" :value="k">{{ label }}</option></select>
      </Field>
      <Field label="Region" v-slot="{ id }">
        <select :id="id" v-model="form.region"><option v-for="(label, k) in choice.regions" :key="k" :value="k">{{ k }} · {{ label }}</option></select>
      </Field>
      <Field label="Size" v-slot="{ id }">
        <select :id="id" v-model="form.size"><option v-for="(label, k) in choice.sizes" :key="k" :value="k">{{ label }}</option></select>
      </Field>
      <Btn type="submit" primary :disabled="form.processing">+ Create cluster</Btn>
    </FormRow>
    <FormError :message="form.errors.name" />
    <span class="text-[13px] text-faint">Use the same region as the servers that will use it.
      <template v-if="choice.managed">DigitalOcean takes about five minutes to bring a cluster online.</template>
      <template v-else>A new server takes a few minutes to start and be set up. It is one machine: no failover, and the provider does not back it up.</template></span>
  </Panel>
</template>
