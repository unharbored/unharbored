<script setup>
import { Head } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Btn from '../Components/ui/Btn.vue';
import Field from '../Components/ui/Field.vue';
import FormRow from '../Components/ui/FormRow.vue';
import Lede from '../Components/ui/Lede.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { useFilters } from '../composables/useFilters';
import { usePolling } from '../composables/usePolling';

defineOptions({ layout: Console });

const props = defineProps({ filters: Object, choices: Object, lines: Object });

const form = useFilters(props.filters, ['filters', 'choices', 'lines'], 250);
usePolling(['lines', 'choices'], 5000);

const levelClass = { bad: 'text-failed', warn: 'text-running' };
</script>

<template>
  <Head title="Logs" />
  <PageHead title="Logs">
    <Lede v-if="filters.site > 0">This application's Laravel and worker logs, and nginx's errors on its servers, newest first. Passwords, tokens and keys are removed on the server before a line is sent. Kept a week.</Lede>
    <Lede v-else>Each site's Laravel and worker logs and nginx's errors, from every server, newest first. Passwords, tokens and keys are removed on the server before a line is sent. Kept a week.</Lede>
  </PageHead>

  <FormRow>
    <Field label="Search" class="!flex-[2_1_240px]" v-slot="{ id }"><input :id="id" v-model="form.q" type="search" placeholder="SQLSTATE, 500, a request id…"></Field>
    <Field label="Server" v-slot="{ id }">
      <select :id="id" v-model="form.server"><option value="">All</option><option v-for="s in choices.servers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
    </Field>
    <Field label="Source" v-slot="{ id }">
      <select :id="id" v-model="form.source"><option value="">All</option><option v-for="s in choices.sources" :key="s" :value="s">{{ s }}</option></select>
    </Field>
    <Field label="Level" v-slot="{ id }">
      <select :id="id" v-model="form.level"><option value="">All</option><option value="warn">Warnings and errors</option><option value="bad">Errors only</option></select>
    </Field>
    <Field label="Since" v-slot="{ id }">
      <select :id="id" v-model="form.range"><option value="15m">15 minutes</option><option value="1h">1 hour</option><option value="24h">24 hours</option><option value="7d">7 days</option></select>
    </Field>
  </FormRow>

  <Panel title="Lines" :note="`${lines.rows.length} of ${lines.total} · UTC · updates every 5s`" flush class="!bg-well">
    <pre class="m-0 overflow-x-auto px-5 pt-4 pb-5 font-mono text-[12.5px] leading-[1.75] break-words whitespace-pre-wrap text-[var(--log-text)] sm:px-7 sm:pt-[18px] sm:pb-6"><span v-for="l in lines.rows" :key="l.id" class="block"><span class="text-faint">{{ l.at }}</span> <span class="text-muted">{{ l.server }} {{ l.source }}</span> <details v-if="l.rest !== ''" class="inline"><summary :class="levelClass[l.level]" class="inline cursor-pointer" title="Show the rest of this record">{{ l.head }}</summary><span class="text-faint">{{ l.rest }}</span></details><span v-else :class="levelClass[l.level]">{{ l.text }}</span></span><span v-if="lines.rows.length === 0" class="text-faint">Nothing matches.</span></pre>
    <div v-if="lines.total > lines.rows.length" class="px-5 pb-[22px] sm:px-7 sm:pb-6"><Btn small @click="form.limit += 200">Show 200 more</Btn></div>
  </Panel>
</template>
