<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import Console from '../Layouts/Console.vue';
import Btn from '../Components/ui/Btn.vue';
import Field from '../Components/ui/Field.vue';
import FormRow from '../Components/ui/FormRow.vue';
import Lede from '../Components/ui/Lede.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { useFilters } from '../composables/useFilters';
import { confirm } from '../composables/useConsole';

defineOptions({ layout: Console });

const props = defineProps({ filters: Object, choices: Object, lines: Object, app: String, canClear: Boolean });

const form = useFilters(props.filters, ['filters', 'choices', 'lines'], 250);
// Live: look again every two seconds, not while the browser tab is hidden. Lines reach the panel within a few seconds of
// being written, because each server sends what it has gathered every five. Pause stops the page moving while you read.
const live = ref(true);
let timer;
onMounted(() => {
    timer = setInterval(() => {
        if (live.value && document.visibilityState === 'visible') {
            router.reload({ only: ['lines', 'choices'], preserveScroll: true, preserveState: true, async: true });
        }
    }, 2000);
});
onUnmounted(() => clearInterval(timer));

async function clearLogs() {
    if (await confirm({ title: 'Clear the logs?', message: `Delete the logs ${props.app} has in Unharbored: its Laravel, worker and process logs and its nginx error log? The server-wide PHP and nginx logs are shared and stay, and so do the log files on the server, which keep growing and show up here again from the next line written. This cannot be undone.`, ok: 'Clear logs', tone: 'danger' })) {
        router.post('/site/logs/clear', { site: props.filters.site });
    }
}

const levelClass = { bad: 'text-failed', warn: 'text-running' };
</script>

<template>
  <Head title="Logs" />
  <PageHead :title="app ? `Logs · ${app}` : 'Logs'">
    <Lede v-if="app">Only {{ app }}'s own Laravel, worker and process logs, and its nginx errors, newest first. Passwords, tokens and keys are removed on the server before a line is sent. Kept a week. <Link href="/logs">All team logs</Link></Lede>
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

  <div class="flex flex-wrap items-center gap-2 pb-3">
    <Btn small :aria-pressed="live" @click="live = !live">{{ live ? 'Pause' : 'Watch live' }}</Btn>
    <span class="text-faint text-[13px]" role="status">{{ live ? 'Live: updating every 2 seconds' : 'Paused' }}</span>
    <Btn v-if="canClear" small danger class="ml-auto" @click="clearLogs">Clear logs</Btn>
  </div>

  <Panel title="Lines" :note="`${lines.rows.length} of ${lines.total} · UTC · ${live ? 'live' : 'paused'}`" flush class="!bg-well">
    <pre class="m-0 max-h-[65vh] overflow-auto px-5 pt-4 pb-5 font-mono text-[12.5px] leading-[1.75] break-words whitespace-pre-wrap text-[var(--log-text)] sm:px-7 sm:pt-[18px] sm:pb-6"><span v-for="l in lines.rows" :key="l.id" class="block"><span class="text-faint">{{ l.at }}</span> <span class="text-muted">{{ l.server }} {{ l.source }}</span> <details v-if="l.rest !== ''" class="inline"><summary :class="levelClass[l.level]" class="inline cursor-pointer" title="Show the rest of this record">{{ l.head }}</summary><span class="text-faint">{{ l.rest }}</span></details><span v-else :class="levelClass[l.level]">{{ l.text }}</span></span><span v-if="lines.rows.length === 0" class="text-faint">Nothing matches.</span></pre>
    <div v-if="lines.total > lines.rows.length" class="flex flex-wrap items-center gap-3 px-5 pt-3 pb-[22px] sm:px-7 sm:pb-6">
      <Btn v-if="form.limit < 1000" small @click="form.limit = Math.min(form.limit + 200, 1000)">Load older</Btn>
      <span v-else class="text-faint text-[13px]">Showing the newest 1000. Narrow the search or the time range to see older lines.</span>
    </div>
  </Panel>
</template>
