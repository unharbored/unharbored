<script setup>
import Toggle from '../ui/Toggle.vue';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { computed, ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, lookup, ask, error } = useSiteSettings(() => props.site.id);

const tasks = ref(props.data.scheduleTasks);
const age = ref(props.data.scheduleAge);
const shown = ref(null);
const job = ref(null);
const timeFrequencies = ['daily', 'weekly', 'monthly'];
const blank = () => ({ id: 0, name: '', command: '', frequency: 'daily', at: '02:30', cron: '', everywhere: false, monitored: true });
const open = computed(() => job.value !== null);

async function toggleScheduler() {
    const on = !props.data.scheduler;
    if (await ask({ title: on ? 'Turn the scheduler on?' : 'Turn the scheduler off?', message: on ? 'Turn the scheduler on?' : 'Turn the scheduler off? Scheduled tasks will not run.', ok: on ? 'Turn on' : 'Turn off', tone: on ? 'info' : 'danger' })) {
        send('scheduler', { on });
    }
}
function toggleMonitor() {
    send('scheduler/monitor', { on: !props.data.schedulerMonitored });
}
async function refreshTasks() {
    const { ok, data } = await lookup('scheduler/tasks');
    if (ok) {
        tasks.value = data.tasks;
        age.value = 'just now';
    }
}
function newJob() {
    job.value = blank();
    shown.value = null;
}
function editJob(j) {
    job.value = { id: j.id, name: j.name, command: j.command, frequency: j.frequency, at: j.at ?? '02:30', cron: j.frequency === 'custom' ? j.cron : '', everywhere: !!j.everywhere, monitored: !!j.monitored };
    shown.value = null;
}
function saveJob() {
    send('jobs', job.value);
}
async function showOutput(j) {
    const { ok, data } = await lookup(`jobs/${j.id}/output`);
    if (ok) shown.value = data;
}
async function showNext(j) {
    const { ok, data } = await lookup(`jobs/${j.id}/next`);
    if (ok) shown.value = data;
}
async function runNow(j) {
    if (await ask({ title: 'Run it now?', message: `Run ${j.name} now?`, ok: 'Run now' })) {
        const { ok, data } = await lookup(`jobs/${j.id}/run`);
        if (ok) shown.value = data;
    }
}
function pauseJob(j) {
    send(`jobs/${j.id}/pause`, { paused: !j.paused });
}
async function deleteJob(j) {
    if (await ask({ title: 'Delete the job?', message: `Delete ${j.name}?`, ok: 'Delete', tone: 'danger' })) {
        send(`jobs/${j.id}/drop`);
    }
}
const badgeClass = (j) => (j.paused ? 'plain' : j.state === 'installed' ? 'live' : j.state === 'failed' ? 'failed' : 'plain');
const badgeProps = (j) => { const s = badgeClass(j); return { status: s === 'plain' ? 'neutral' : s, plain: s === 'plain' }; };
const badgeText = (j) => (j.paused ? 'Paused' : j.state === 'pending' ? 'Pending' : j.state === 'failed' ? 'Failed' : 'Installed');
</script>

<template>
  <Panel id="scheduler" title="Laravel scheduler" note="Runs your application's scheduled tasks">
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <div><b class="font-semibold text-ink-strong">Run the Laravel scheduler</b><br><span class="text-faint text-[13px]"><span class="font-mono">{{ data.phpBinary }} artisan schedule:run</span> · every minute · as deploy · on the primary server only</span></div>
        <span><Badge v-bind="data.scheduler ? { status: 'live' } : { plain: true }">{{ data.scheduler ? 'On' : 'Off' }}</Badge>
          <Btn small :title="data.scheduler ? 'Removes the cron entry: scheduled tasks stop running' : 'Installs the cron entry on the primary server'" @click="toggleScheduler">{{ data.scheduler ? 'Turn off' : 'Turn on' }}</Btn></span>
      </div>
      <p v-if="!data.scheduler" class="text-faint text-[13px] m-0">Off: scheduled tasks will not run.</p>
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <div><b class="font-semibold text-ink-strong">Monitor with heartbeats</b><br><span class="text-faint text-[13px]">Alerts you if the scheduler stops running. Costs one request a minute.<template v-if="data.schedulerMonitored"> · <template v-if="data.schedulerPing"> last heard {{ data.schedulerPing }}</template><template v-else> not heard yet </template></template></span></div>
        <Btn small :title="data.schedulerMonitored ? 'Stop pinging after each run; no alert will be raised' : 'Ping a URL after each run and raise an alert if it goes quiet'" @click="toggleMonitor">{{ data.schedulerMonitored ? 'Stop monitoring' : 'Monitor' }}</Btn>
      </div>
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <b class="font-semibold text-ink-strong">Tasks in your code</b>
        <span class="text-faint text-[13px]">from <span class="font-mono">schedule:list</span><template v-if="age"> · {{ age }}</template>
          <Btn small class="ml-2" title="Run schedule:list on the primary server now" @click="refreshTasks">Refresh</Btn></span>
      </div>
      <div v-for="t in tasks" :key="t.command + t.expression" class="flex justify-between gap-3 flex-wrap items-center rounded-sm border border-line px-3 py-2.5">
        <span><b class="font-semibold text-ink-strong font-mono">{{ t.command }}</b><template v-if="t.description"><br><span class="text-faint text-[13px]">{{ t.description }}</span></template></span>
        <span class="text-muted text-[13px]"><span class="font-mono">{{ t.expression }}</span> · next {{ t.next }}</span>
      </div>
      <p v-if="tasks.length === 0" class="text-faint text-[13px] m-0">{{ age ? 'Your application schedules no tasks.' : 'Not read yet. Refresh asks the live release.' }}</p>
      <FormError :message="error('scheduler')" />
      <span class="text-faint text-[13px]">Change these in your application's code. Times are UTC, as the servers are.</span>
  </Panel>

  <Panel id="jobs" title="Custom jobs" note="Anything the Laravel schedule does not cover · run as deploy from the live release">
      <div class="flex flex-wrap gap-2"><Btn small primary title="Add a command to run on a schedule" @click="newJob">+ New job</Btn></div>
      <div v-for="j in data.jobs" :key="j.id" class="flex justify-between gap-3 flex-wrap items-center rounded-sm border border-line px-3 py-2.5">
        <span><b class="font-semibold text-ink-strong">{{ j.name }}</b><br><span class="text-faint text-[13px] font-mono">deploy · {{ j.command }}</span></span>
        <span class="text-muted text-[13px]">{{ data.jobFrequencies[j.frequency] ?? '' }}<template v-if="j.at"> {{ j.at }}</template> <span v-if="j.frequency === 'custom'" class="font-mono">{{ j.cron }}</span>
          <template v-if="j.everywhere"> · every server </template>
          <span class="text-faint" :title="j.monitored ? (j.pinged ? 'Heartbeat monitored; last heard ' + j.pinged : 'Heartbeat monitored; not heard yet') : 'Not monitored'">{{ j.monitored ? '♡ monitored' : '♡ off' }}</span>
          <Badge v-bind="badgeProps(j)" :title="j.state === 'failed' ? j.error : undefined">{{ badgeText(j) }}</Badge></span>
        <span class="flex flex-wrap gap-1.5">
          <Btn small title="The tail of the job's log on the primary server" @click="showOutput(j)">Output</Btn>
          <Btn small title="The next five times it is due, in UTC" @click="showNext(j)">Next runs</Btn>
          <Btn small title="Run the command once now as deploy and show what it prints" @click="runNow(j)">Run now</Btn>
          <Btn small :title="j.paused ? 'Put the job back in cron' : 'Keep the job but take it out of cron'" @click="pauseJob(j)">{{ j.paused ? 'Resume' : 'Pause' }}</Btn>
          <Btn small title="Change the job" @click="editJob(j)">Edit</Btn>
          <Btn small danger title="Remove the job and its cron line" :aria-label="`Delete ${j.name}`" @click="deleteJob(j)">Delete</Btn>
        </span>
      </div>
      <p v-if="data.jobs.length === 0" class="text-faint text-[13px] m-0">No custom jobs.</p>
      <Field v-if="shown" :label="shown.title" v-slot="{ id }"><pre :id="id" class="whitespace-pre-wrap m-0 overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)]">{{ shown.text }}</pre></Field>
      <div v-if="open" class="flex flex-col gap-3 border-t border-line pt-3">
        <b class="font-semibold text-ink-strong">{{ job.id ? 'Change job' : 'New job' }}</b>
        <Field label="Name" v-slot="{ id }"><input :id="id" v-model="job.name" placeholder="Prune exports"></Field>
        <Field label="Command" v-slot="{ id }"><input :id="id" v-model="job.command" class="font-mono" placeholder="{php} artisan exports:prune">
          <span class="text-faint text-[13px]">Runs from the live release. <span class="font-mono">{php}</span> is this application's PHP, currently <span class="font-mono">{{ data.phpBinary }}</span>.</span></Field>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Frequency" v-slot="{ id }">
            <select :id="id" v-model="job.frequency"><option v-for="(label, key) in data.jobFrequencies" :key="key" :value="key">{{ label }}</option></select></Field>
          <Field v-if="timeFrequencies.includes(job.frequency)" label="At (UTC)" class="flex-[1_1_180px]" v-slot="{ id }"><input :id="id" v-model="job.at" placeholder="02:30"></Field>
          <Field v-if="job.frequency === 'custom'" label="Cron expression" class="flex-[1_1_180px]" v-slot="{ id }"><input :id="id" v-model="job.cron" class="font-mono" placeholder="*/15 8-18 * * 1-5"></Field>
        </div>
        <Toggle v-if="data.multiServer" v-model="job.everywhere">Run on every server (otherwise the primary only)</Toggle>
        <Toggle v-model="job.monitored">Monitor with heartbeats: the job pings a URL after each successful run, and you are alerted if it goes quiet (more than {{ data.heartbeatGrace }} minutes late)</Toggle>
        <span v-if="error('job')" class="text-[13px] text-failed" role="alert">{{ error('job') }}</span>
        <div class="flex flex-wrap gap-2"><Btn @click="job = null">Cancel</Btn><Btn primary title="Save, then write the cron file to the servers" @click="saveJob">Save and apply</Btn></div>
      </div>
      <span class="text-faint text-[13px]">Each job runs under <span class="font-mono">flock -n</span>, so a run that outlasts its interval is not started again on top of itself. Output goes to a log on the server; Output shows its tail.</span>
  </Panel>
</template>
