<script setup>
import Toggle from '../ui/Toggle.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const workers = ref(props.data.workers);
const workersMin = ref(props.data.workersMin);
const workersMax = ref(props.data.workersMax);
const daemonName = ref('');
const daemonCommand = ref('');
const daemonPort = ref('');
const daemonPath = ref('');

const applyWorkers = () => send('workers', { workers: workers.value });
const scaleWorkers = () => send('workers/scaling', { min: workersMin.value, max: workersMax.value });
async function stopScaling() {
    const count = Math.max(1, props.data.workers);
    if (await ask({ title: 'Fixed count?', message: `Go back to a fixed ${count} worker(s) per server?`, ok: 'Fixed count' })) {
        send('workers/fixed', { workers: count });
    }
}
function toggleReverb(on) {
    send('reverb', { on });
}
function newDaemon() {
    send('daemons', { daemonName: daemonName.value, daemonCommand: daemonCommand.value, daemonPort: daemonPort.value, daemonPath: daemonPath.value }, {
        onSuccess: () => { daemonName.value = ''; daemonCommand.value = ''; daemonPort.value = ''; daemonPath.value = ''; },
    });
}
async function dropDaemon(name) {
    if (await ask({ title: 'Remove the daemon?', message: `Remove daemon ${name}?`, ok: 'Remove', tone: 'danger' })) {
        send('daemons/remove', { name });
    }
}
</script>

<template>
  <Panel id="processes" title="Processes" note="Run as deploy from the live release · restart onto each new one">
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <div><b class="font-semibold text-ink-strong">Queue workers</b><br><span class="text-faint text-[13px]">queue:work under supervisor, on every server <template v-if="data.scaling"> · scaling between {{ data.scaling.min }} and {{ data.scaling.max }} per server, <b class="font-semibold text-ink-strong">{{ data.workers }}</b> running now <template v-if="data.scaling.idle"> · queue empty since {{ data.scaling.idle }}</template> </template></span></div>
        <div v-if="!data.scaling" class="flex flex-wrap items-end gap-3">
          <Field label="Processes" class="flex-[0_0_90px]" v-slot="{ id }"><input :id="id" v-model.number="workers" type="number" min="0" max="20"></Field>
          <Btn small @click="applyWorkers">Save</Btn>
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Fewest" v-slot="{ id }" class="flex-[0_0_90px]"><input :id="id" v-model.number="workersMin" type="number" min="0" max="20"></Field>
        <Field label="most" v-slot="{ id }" class="flex-[0_0_90px]"><input :id="id" v-model.number="workersMax" type="number" min="1" max="20"></Field>
        <Btn small @click="scaleWorkers">{{ data.scaling ? 'Change limits' : 'Scale on queue' }}</Btn>
        <Btn small v-if="data.scaling" @click="stopScaling">Fixed count</Btn>
      </div>
      <span class="text-faint text-[13px]">Checked every minute: a worker per {{ data.jobsPerWorker }} waiting jobs, never fewer while any wait, back to the fewest after {{ data.idleMinutes }} empty minutes. Fewest 0 stops them all when idle; the first job then waits up to a minute.</span>
      <FormError :message="error('workers')" />
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <div><b class="font-semibold text-ink-strong">Laravel Reverb</b><br><span class="text-faint text-[13px]">127.0.0.1:{{ data.reverbPort }} · wss://{{ site.domain }}/app</span></div>
        <Toggle :model-value="data.reverb" @update:model-value="toggleReverb">Enabled</Toggle>
      </div>
      <div class="flex flex-col gap-2.5">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Background processes</span>
        <div v-for="d in data.daemons" :key="d.name" class="flex justify-between gap-3 flex-wrap items-center rounded-sm border border-line px-3 py-2.5">
          <span><b class="font-semibold text-ink-strong">{{ d.name }}</b> &nbsp;<span class="text-muted">$ {{ d.command }}</span> <span class="text-faint">×{{ d.processes }}</span>
            <template v-if="d.port"><br><span class="text-faint text-[13px]">127.0.0.1:{{ d.port }}<template v-if="d.path"> · wss://{{ site.domain }}{{ d.path }}</template></span></template></span>
          <Btn small danger :aria-label="`Remove daemon ${d.name}`" @click="dropDaemon(d.name)">Remove</Btn>
        </div>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Name" v-slot="{ id }" class="flex-[1_1_140px]"><input :id="id" v-model="daemonName" placeholder="pulse"></Field>
          <Field label="Command" v-slot="{ id }" class="flex-[3_1_260px]"><input :id="id" v-model="daemonCommand" placeholder="php artisan pulse:check"></Field>
          <Field class="flex-[0_1_110px]"><template #label>Port <span class="text-faint">(optional)</span></template><template #default="{ id }"><input :id="id" v-model="daemonPort" inputmode="numeric" placeholder="8082"></template></Field>
          <Field class="flex-[1_1_150px]"><template #label>Public path <span class="text-faint">(optional)</span></template><template #default="{ id }"><input :id="id" v-model="daemonPath" placeholder="/game-ws"></template></Field>
          <Btn @click="newDaemon">+ Add process</Btn>
        </div>
        <span class="text-faint text-[13px]">Runs as the deploy user from the live release and restarts on every deploy. A port is passed to it as <span class="font-mono">PORT</span> and as <span class="font-mono">{port}</span> in the command, and stays on this server only. A public path also puts it on this application's domain over its certificate, WebSockets included, so a page can open <span class="font-mono">wss://{{ site.domain }}/game-ws</span>.</span>
        <span v-if="error('daemonName')" class="text-[13px] text-failed">{{ error('daemonName') }}</span>
        <span v-if="error('daemonCommand')" class="text-[13px] text-failed">{{ error('daemonCommand') }}</span>
      </div>
  </Panel>
</template>
