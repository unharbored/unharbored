<script setup>
import Toggle from '../Components/ui/Toggle.vue';
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { confirm } from '../composables/useConsole';
import { usePolling } from '../composables/usePolling';
import Panel from '../Components/ui/Panel.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Field from '../Components/ui/Field.vue';
import Notice from '../Components/ui/Notice.vue';
import Btn from '../Components/ui/Btn.vue';
import FormError from '../Components/ui/FormError.vue';
import Crumbs from '../Components/ui/Crumbs.vue';
import Stat from '../Components/ui/Stat.vue';
import StatGrid from '../Components/ui/StatGrid.vue';

defineOptions({ layout: Console });

const props = defineProps({ view: Object, found: Object });
const v = computed(() => props.view);

// Polling refreshes what runs, not the form: the fields hold what was typed.
usePolling(['view'], 5000);

const form = useForm({ site: v.value.id ?? 0, ...(v.value.form ?? {}) });
const registry = useForm({ site: v.value.id ?? 0, registryUser: '', registryToken: '' });
const update = reactive({ found: null });
const errors = ref({});

function save() {
    form.post('/site/container/settings', { preserveScroll: true });
}
function login() {
    registry.post('/site/container/registry', { preserveScroll: true, onSuccess: () => { registry.registryToken = ''; } });
}
function check() {
    update.found = null;
    router.post('/site/container/check', { site: v.value.id }, {
        preserveScroll: true,
        onSuccess: (page) => { update.found = page.props.found; },
        onError: (e) => { errors.value = e; },
    });
}
async function confirmUpdate() {
    if (await confirm({ message: `Update ${v.value.name} to the new digest?`, ok: 'Update' })) {
        router.post('/site/container/update', { site: v.value.id, digest: update.found?.new ?? '' }, { onError: (e) => { errors.value = e; } });
    }
}
</script>

<template>
  <Head :title="v.missing ? 'No such container application' : v.name" />
  <PageHead v-if="v.missing" title="No such container application">
    <template #crumbs><Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }]" /></template>
  </PageHead>
  <template v-else>
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-6">
      <div class="flex min-w-0 flex-col gap-2.5">
        <Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }, { label: v.name, href: `/site?site=${v.id}`, inertia: true }, { label: 'container' }]" />
        <h1 class="m-0 font-display text-[clamp(28px,4.5vw,36px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ v.name }} <span class="font-medium text-muted">· {{ v.kind === 'image' ? 'Docker image' : 'Dockerfile' }}</span></h1>
      </div>
      <Btn :href="`/site?site=${v.id}`" inertia>Back</Btn>
    </div>
    <Notice v-if="v.pending" tone="plain">Changes are saved and reach the container on the next deploy.</Notice>

    <Panel title="What runs" note="One container, published on 127.0.0.1 only">
        <StatGrid>
          <template v-if="v.kind === 'image'">
            <Stat label="Image" big><span class="font-mono text-[13px]">{{ v.image }}</span></Stat>
            <Stat label="Pinned digest" big><span class="font-mono text-[13px]">{{ v.digest ?? 'not pulled yet' }}</span></Stat>
          </template>
          <Stat v-else label="Dockerfile" big><span class="font-mono text-[13px]">{{ v.dockerfile }} · context {{ v.context }}</span></Stat>
          <Stat label="Port" big>{{ v.port }}<template #hint>{{ v.hostPort === null ? 'not started yet' : 'on 127.0.0.1:' + v.hostPort }}</template></Stat>
          <Stat label="Kept images" big>{{ v.kept.length }}<template #hint>the live one and up to two to roll back to</template></Stat>
        </StatGrid>
        <div v-for="k in v.kept" :key="k.image" class="font-mono text-[13px] text-faint">{{ k.image }} <template v-if="k.commit">· {{ k.commit }}</template></div>
    </Panel>

    <Panel v-if="v.kind === 'image' && v.canDeploy" title="Update" note="The tag is looked up only when you ask">
        <p class="m-0 text-[13px] text-faint">The application keeps running the pinned digest. Check to pull <span class="font-mono">{{ v.image }}</span> again and see what the tag points at now; nothing changes until you confirm.</p>
        <div class="flex flex-wrap gap-2"><Btn @click="check">Check for an update</Btn></div>
        <FormError :message="errors.update" />
        <template v-if="update.found">
          <StatGrid>
            <Stat label="Running now" big><span class="font-mono text-[13px]">{{ update.found.old }}</span></Stat>
            <Stat label="The tag now" big><span class="font-mono text-[13px]">{{ update.found.new }}</span></Stat>
          </StatGrid>
          <template v-if="update.found.changed">
            <p class="m-0 text-running">The tag points at a different image than the one running. Update starts it beside the old one, switches when it is healthy, and keeps the old image for a rollback.</p>
            <div class="flex flex-wrap gap-2"><Btn primary @click="confirmUpdate">Update to this digest</Btn></div>
          </template>
          <p v-else class="m-0 text-accent">The tag still points at the digest this application runs.</p>
        </template>
    </Panel>

    <template v-if="v.canEdit">
      <Panel title="Settings" note="Applied on the next deploy">
          <div v-if="v.kind === 'dockerfile'" class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
            <Field label="Dockerfile path" :error="form.errors.dockerfile" v-slot="{ id }"><input :id="id" v-model="form.dockerfile" autocomplete="off" spellcheck="false"></Field>
            <Field label="Build context" :error="form.errors.context" v-slot="{ id }"><input :id="id" v-model="form.context" autocomplete="off" spellcheck="false"></Field>
          </div>
          <div class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
            <Field label="Container port" :error="form.errors.container_port" v-slot="{ id }"><input :id="id" v-model="form.port" type="number"></Field>
            <Field label="Health check path" :error="form.errors.health" v-slot="{ id }"><input :id="id" v-model="form.health" placeholder="blank: the port answers" spellcheck="false"></Field>
            <Field label="Memory limit" :error="form.errors.memory" v-slot="{ id }"><input :id="id" v-model="form.memory"></Field>
          </div>
          <Field label="Folders that keep data (one per line)" :error="form.errors.volumes" v-slot="{ id }"><textarea :id="id" v-model="form.volumes" rows="2" spellcheck="false"></textarea></Field>
          <Toggle v-model="form.readOnly">Read-only root filesystem</Toggle>
          <div class="flex flex-wrap gap-2"><Btn primary :disabled="form.processing" @click="save">Save settings</Btn></div>
      </Panel>

      <Panel v-if="v.kind === 'image'" title="Registry login" note="For a private registry">
          <p v-if="v.registry" class="m-0 text-[13px]">Logged in to <b>{{ v.registry.server }}</b> as <b>{{ v.registry.user }}</b>. The token is kept in the vault and is never shown.</p>
          <p v-else class="m-0 text-[13px] text-faint">No login: the image is pulled anonymously.</p>
          <div class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
            <Field label="User" :error="registry.errors.registry_user" v-slot="{ id }"><input :id="id" v-model="registry.registryUser" autocomplete="off" spellcheck="false"></Field>
            <Field label="Token" :error="registry.errors.registry_token" v-slot="{ id }"><input :id="id" v-model="registry.registryToken" type="password" autocomplete="off" spellcheck="false"></Field>
          </div>
          <div class="flex flex-wrap gap-2"><Btn :disabled="registry.processing" @click="login">{{ v.registry ? 'Change or remove login' : 'Save login' }}</Btn></div>
          <span class="text-[13px] text-faint">A blank user removes the login. The token is used for a pull only, through a private file, then removed from the server.</span>
      </Panel>
    </template>
  </template>
</template>
