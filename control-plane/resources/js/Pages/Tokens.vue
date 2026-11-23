<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Btn from '../Components/ui/Btn.vue';
import Field from '../Components/ui/Field.vue';
import FormError from '../Components/ui/FormError.vue';
import FormRow from '../Components/ui/FormRow.vue';
import Lede from '../Components/ui/Lede.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { confirm } from '../composables/useConsole';

defineOptions({ layout: Console });

defineProps({ base: String, tokens: Array, made: String });

const form = useForm({ name: '', scope: 'deploy' });

function create() {
    form.post('/tokens', { preserveScroll: true, onSuccess: () => form.reset('name') });
}

async function revoke(t) {
    if (await confirm({ title: 'Revoke it?', message: `Revoke ${t.name}? Anything using it stops working at once.`, ok: 'Revoke', tone: 'danger' })) {
        router.delete(`/tokens/${t.id}`, { preserveScroll: true });
    }
}
</script>

<template>
  <Head title="API tokens" />
  <PageHead title="API tokens">
    <Lede>For the <code>cloud</code> command line, its MCP server for coding agents, and the REST API at <code>/api/v1</code>. Everything a token does is in the audit log under its name.</Lede>
  </PageHead>

  <Panel v-if="made" title="Your new token">
    <template #note><span class="text-running">Shown once: copy it now</span></template>
    <pre class="m-0 overflow-x-auto font-mono text-[12.5px] leading-[1.75] break-all whitespace-pre-wrap text-[var(--log-text)]">{{ made }}</pre>
    <span class="text-[13px] text-faint">Then: <code>cloud login {{ base }}</code> and paste it, or send it as <code>Authorization: Bearer …</code>.</span>
  </Panel>

  <Panel title="New token">
    <FormRow tag="form" @submit.prevent="create">
      <Field label="Name: where it will be used" class="!flex-[2_1_220px]" v-slot="{ id }"><input :id="id" v-model="form.name" placeholder="laptop, CI, Claude Code" autocomplete="off"></Field>
      <Field label="Can" v-slot="{ id }">
        <select :id="id" v-model="form.scope"><option value="read">read: sites, logs, operations</option><option value="deploy">deploy: read, deploy, roll back, back up</option><option value="manage">manage: deploy, .env, artisan, restore</option></select>
      </Field>
      <Btn type="submit" primary :disabled="form.processing">Create token</Btn>
    </FormRow>
    <FormError :message="form.errors.name" />
    <p class="m-0 text-[13px] text-faint">A token reaches the whole team, every project. Projects group applications; they do not restrict access.</p>
  </Panel>

  <Panel title="Tokens" :note="String(tokens.length)">
    <div v-for="t in tokens" :key="t.id" class="flex flex-wrap items-center justify-between gap-3">
      <span><b class="font-semibold text-ink-strong">{{ t.name }}</b> <span class="text-[13px] text-faint">· {{ t.scope }} · {{ t.prefix }}… · made {{ t.created }} by {{ t.by }} · last used {{ t.used }}</span></span>
      <Btn small danger @click="revoke(t)">Revoke</Btn>
    </div>
    <span v-if="tokens.length === 0" class="text-faint">None yet.</span>
  </Panel>
</template>
