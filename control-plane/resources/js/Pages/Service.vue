<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Panel from '../Components/ui/Panel.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Field from '../Components/ui/Field.vue';
import FormError from '../Components/ui/FormError.vue';
import Notice from '../Components/ui/Notice.vue';
import Btn from '../Components/ui/Btn.vue';
import Badge from '../Components/ui/Badge.vue';
import DataTable from '../Components/ui/DataTable.vue';
import Empty from '../Components/ui/Empty.vue';
import { usePolling } from '../composables/usePolling.js';
import { confirm } from '../composables/useConsole.js';
import { postJson } from '../composables/useJson.js';
import { usePost } from '../composables/usePost.js';

defineOptions({ layout: Console });

const check = 'inline-flex min-h-11 cursor-pointer items-center gap-2.5';
const crumbs = 'm-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase';

/**
 * One template service. Revealed secret values live only in this page's memory: they come back in the
 * answer to the reveal and are never part of the page's data.
 */
const props = defineProps({ service: Number, view: Object });
usePolling(['view'], 3000);

const page = usePage();
const can = computed(() => page.props.can);
const errors = computed(() => page.props.errors ?? {});
const v = computed(() => props.view);

const revealed = reactive({});
const revealError = ref('');
const removing = ref(false);
const dataChoice = ref('');
const confirmHost = ref(false);
const approvePorts = ref(false);
const ackUpdate = ref(false);
const newInputs = reactive({});
const restoring = ref(0);
const restoreName = ref('');

const url = (path) => `/service/${props.service}/${path}`;
const send = usePost();
const post = (path, data = {}) => send(url(path), data);

const status = computed(() => v.value.status);
const badgeStatus = computed(() => (status.value === 'running' ? 'live' : ['installing', 'removing'].includes(status.value) ? 'running' : status.value === 'failed' ? 'failed' : 'neutral'));

const start = () => post('act', { action: 'start' });
const restart = () => post('act', { action: 'restart' });
async function stop() {
    if (await confirm({ title: `Stop ${v.value.name}?`, message: 'Its addresses stop answering until it is started again.', ok: 'Stop' })) post('act', { action: 'stop' });
}
const backUpNow = () => post('backup');
async function applyUpdate() {
    const u = v.value.update_detail;
    if (await confirm({ title: `Update ${v.value.name}?`, message: `Update ${v.value.name} to version ${u.version}? A backup is taken first, and the service restarts briefly.`, ok: 'Update' })) {
        post('update', { confirmed: confirmHost.value, ports: approvePorts.value, ack: ackUpdate.value, inputs: { ...newInputs } });
    }
}
function askRestore(id) { restoring.value = id; restoreName.value = ''; }
function cancelRestore() { restoring.value = 0; restoreName.value = ''; }
const confirmRestore = () => post('restore', { backup: restoring.value, typed: restoreName.value });

async function showSecret(key) {
    revealError.value = '';
    const { ok, data, errors: e } = await postJson(url('reveal'), { key });
    if (ok) revealed[key] = data.value;
    else revealError.value = e.reveal ?? 'Could not reveal it.';
}
const hideSecret = (key) => delete revealed[key];

function toggleRemove() { removing.value = !removing.value; dataChoice.value = ''; }
async function confirmRemove() {
    const erase = dataChoice.value === 'delete';
    if (erase && !(await confirm({ title: `Delete ${v.value.name} and its data?`, message: `${v.value.name} stops and its data on the server is erased for good. This cannot be undone.`, ok: 'Delete and erase', tone: 'danger' }))) return;
    post('remove', { choice: dataChoice.value });
}
</script>

<template>
  <Head :title="v.missing ? 'No such service' : v.name" />
  <PageHead v-if="v.missing" title="No such service">
    <template #crumbs><span :class="crumbs"><a href="/services">services</a></span></template>
  </PageHead>

  <template v-else>
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-6">
      <div class="flex min-w-0 flex-col gap-2.5">
        <span :class="crumbs"><a href="/services">services</a> <span class="text-line-control" aria-hidden="true">/</span> {{ v.name }}</span>
        <div class="flex flex-wrap items-center gap-3.5">
          <h1 class="m-0 font-display text-[clamp(36px,6vw,52px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ v.name }}<span class="text-butter" aria-hidden="true">.</span></h1>
          <Badge :status="badgeStatus">{{ v.status }}</Badge>
        </div>
        <span class="text-[13px] text-muted">{{ v.template_name }} · version {{ v.version }} · on {{ v.server }}<template v-if="v.project"> · project {{ v.project.name }}</template></span>
      </div>
      <div v-if="can.deploy" class="flex flex-wrap gap-2">
        <Btn :disabled="v.busy || v.status === 'running' || !['running', 'stopped'].includes(v.status)" @click="start">Start</Btn>
        <Btn :disabled="v.busy || v.status !== 'running'" @click="stop">Stop</Btn>
        <Btn :disabled="v.busy || v.status !== 'running'" @click="restart">Restart</Btn>
      </div>
    </div>

    <Notice v-if="v.operation">An operation is running for this service. <a :href="`/operation?operation=${v.operation.id}`">Follow operation {{ v.operation.id }}</a></Notice>
    <Notice v-if="v.error" tone="bad">{{ v.error }}</Notice>
    <FormError :message="errors.action" />

    <Panel v-if="v.update_detail" id="update" :title="`Update available: version ${v.update_detail.version}`" :note="`you have version ${v.update_detail.from}`">
        <Notice v-if="!v.update_detail.allowed" tone="bad">This update cannot be applied: {{ v.update_detail.refused }}</Notice>
        <template v-else>
          <p class="m-0">What changes:</p>
          <ul class="m-0 list-disc pl-5"><li v-for="(change, at) in v.update_detail.changes" :key="at">{{ change }}</li></ul>
          <p class="text-[13px] text-muted">Nothing changes until you press Update. A backup of the data is taken first; if it fails, nothing is changed. The new version is downloaded and checked before the current one is stopped, the current one is kept, and if the new one does not answer it is put back automatically. The data and every existing password and key stay as they are.</p>
          <template v-if="can.infrastructure">
            <template v-if="v.update_detail.confirm">
              <Notice tone="bad"><b>Where the program comes from changes.</b> Read the lines above that name the host or the image repository before you go on.</Notice>
              <label :class="check"><input v-model="confirmHost" type="checkbox"> I have checked the new download host or image repository and want this update</label>
            </template>
            <label v-if="v.update_detail.ports.length > 0" :class="check"><input v-model="approvePorts" type="checkbox"> I approve opening {{ v.update_detail.ports.join(', ') }} in the server firewall</label>
            <label v-if="v.update_detail.acknowledge" :class="check"><input v-model="ackUpdate" type="checkbox"> {{ v.update_detail.acknowledge }}</label>
            <Field v-for="f in v.update_detail.inputs" :key="f.key" :label="`${f.label} (new in this version)`" v-slot="{ id }">
              <input :id="id" v-model="newInputs[f.key]" :type="f.secret ? 'password' : 'text'" :placeholder="f.default ?? ''" autocomplete="off" spellcheck="false"></Field>
            <FormError :message="errors.update" />
            <div class="flex flex-wrap gap-2"><Btn primary :disabled="v.busy || v.status !== 'running'" @click="applyUpdate">Update to version {{ v.update_detail.version }}</Btn></div>
            <span v-if="v.status !== 'running'" class="text-[13px] text-faint">Only a running service is updated.</span>
          </template>
          <span v-else class="text-[13px] text-faint">A Manager can update this service.</span>
        </template>
    </Panel>

    <Panel id="backups" title="Backups" :note="`${v.backup_choice === 'none' ? 'not in the nightly run ' : v.backup_choice === 'spaces' ? 'nightly, on the server and in the backup bucket ' : ' nightly, on the server '}· 14 days kept`">
        <p v-if="v.backup_choice === 'snapshot'" class="text-[13px] text-muted">Backups on the server's own disk protect against a bad update or a mistake, not against losing the server.</p>
        <DataTable>
          <thead><tr><th scope="col">When</th><th scope="col">Kind</th><th scope="col">Size</th><th scope="col">Where</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            <tr v-for="b in v.backups" :key="b.id">
              <td>{{ b.ago }} <span class="text-[13px] text-faint">{{ b.name }}</span></td>
              <td>{{ b.kind.replace(/_/g, ' ') }}</td>
              <td>{{ b.size }}</td>
              <td>{{ b.where }}</td>
              <td><Btn small v-if="can.infrastructure" :disabled="v.busy" @click="askRestore(b.id)">Restore</Btn></td>
            </tr>
            <tr v-if="v.backups.length === 0"><td colspan="5"><Empty>No backups yet.</Empty></td></tr>
          </tbody>
        </DataTable>
        <template v-if="can.infrastructure">
          <Notice v-if="restoring > 0" alert>Restoring replaces the data of {{ v.name }} with this backup and restarts it. What is there now is backed up first (a "pre restore" backup), so you can go back.
            <Field :label="`Type ${v.name} to confirm`" v-slot="{ id }"><input :id="id" v-model="restoreName" autocomplete="off" spellcheck="false"></Field>
            <div class="flex flex-wrap gap-2"><Btn @click="cancelRestore">Cancel</Btn> <Btn danger @click="confirmRestore">Restore {{ v.name }}</Btn></div></Notice>
          <FormError :message="errors.restore" />
          <FormError :message="errors.backup" />
          <div class="flex flex-wrap gap-2"><Btn :disabled="v.busy || !['running', 'stopped'].includes(v.status)" @click="backUpNow">Back up now</Btn></div>
        </template>
    </Panel>

    <Panel title="Addresses">
        <div v-for="e in v.endpoints" :key="e.port" class="flex justify-between gap-3">
          <span class="flex min-w-0 flex-col"><a v-for="d in [e.domain, ...(e.aliases ?? [])]" :key="d" :href="`https://${d}`" rel="noopener" target="_blank">https://{{ d }}</a></span>
          <span class="text-[13px] text-faint">certificate: {{ e.tls ? 'issued' : 'not issued yet' }} · <template v-if="v.runtime === 'container'">container port {{ e.container_port ?? '' }} published on {{ e.listen }} only </template><template v-else>port {{ e.port }} on the server </template></span>
        </div>
        <span v-if="v.endpoints.length === 0" class="text-[13px] text-faint">No addresses yet.</span>
        <div class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-[22px] gap-y-4">
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Memory</h2><span class="text-[15px] font-semibold break-words text-ink-strong"><template v-if="v.memory_used !== null">{{ v.memory_used }}% used </template><template v-else>not reported </template></span><span class="text-[13px] text-faint">on the whole server</span></div>
          <div class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Disk</h2><span class="text-[15px] font-semibold break-words text-ink-strong"><template v-if="v.disk_free">{{ v.disk_free }} free </template><template v-else>not reported </template></span><span class="text-[13px] text-faint">data in {{ v.data_path }}</span></div>
          <div v-if="v.runtime === 'container'" class="flex min-w-0 flex-col gap-1 border-t border-dashed border-line pt-[11px]"><h2 class="m-0 font-mono text-[9.5px] font-normal tracking-[0.18em] text-muted uppercase">Container</h2><span class="text-[15px] font-semibold break-words text-ink-strong font-mono">cm-svc-{{ v.name }}</span><span class="text-[13px] text-faint">image {{ v.image }} · <a :href="`/server/containers?node=${v.server}`">containers on this server</a></span></div>
        </div>
    </Panel>

    <Panel v-if="can['view.secrets'] && v.secrets.length > 0" title="Credentials" note="Each reveal is recorded in the audit log">
        <div v-for="key in v.secrets" :key="key" class="flex justify-between gap-3">
          <span><b>{{ key }}</b><template v-if="key in revealed"> <code class="font-mono">{{ revealed[key] }}</code></template></span>
          <Btn small v-if="key in revealed" @click="hideSecret(key)">Hide</Btn>
          <Btn small v-else @click="showSecret(key)">Reveal (audited)</Btn>
        </div>
        <FormError :message="revealError" />
    </Panel>

    <Panel title="Recent operations" flush>
      <ol class="m-0 list-none px-0 py-1.5">
        <li v-for="o in v.operations" :key="o.id" class="flex flex-wrap gap-x-5 gap-y-1 px-[18px] py-2.5">
          <span class="w-24 flex-none text-faint">{{ o.ago }}</span>
          <span class="flex-[1_1_320px]"><a :href="`/operation?operation=${o.id}`"><b>{{ o.title }}</b></a> <span class="text-muted">· {{ o.by }}</span> <Badge :status="o.badge.class">{{ o.badge.label }}</Badge></span>
        </li>
        <li v-if="v.operations.length === 0"><Empty>Nothing has run for this service yet.</Empty></li>
      </ol>
    </Panel>

    <Panel v-if="can.infrastructure" title="Delete">
        <Btn danger v-if="!removing" :disabled="v.busy" @click="toggleRemove">Delete this service</Btn>
        <template v-else>
          <p class="m-0">Deleting {{ v.name }} stops it and removes its addresses and certificates. What happens to its data is your decision:</p>
          <label :class="check"><input v-model="dataChoice" type="radio" name="data-choice" value="keep"> Keep the data ({{ v.data_path }}) on the server</label>
          <label :class="check"><input v-model="dataChoice" type="radio" name="data-choice" value="delete"> Delete the data too (cannot be undone)</label>
          <FormError :message="errors.dataChoice" />
          <div class="flex flex-wrap gap-2">
            <Btn @click="toggleRemove">Cancel</Btn>
            <Btn danger @click="confirmRemove">Delete {{ v.name }}</Btn>
          </div>
        </template>
    </Panel>
  </template>
</template>
