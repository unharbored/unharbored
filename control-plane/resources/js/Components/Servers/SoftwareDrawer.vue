<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { json, serverUrl } from './api.js';
import { caption, note } from './serverActions.js';
import Drawer from '../ui/Drawer.vue';
import Tabs from '../ui/Tabs.vue';
import Tip from '../ui/Tip.vue';
import Field from '../ui/Field.vue';
import Notice from '../ui/Notice.vue';
import FormError from '../ui/FormError.vue';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';

const props = defineProps({ open: Boolean, node: Object, phpVersions: Array, errors: Object, readonly: Boolean, act: Function });
const emit = defineEmits(['close', 'saved']);

const tab = ref('php');
watch(() => props.open, (now) => {
    if (now) {
        tab.value = 'php';
        php.version = '';
    }
});

const services = { pgsql: 'PostgreSQL', mysql: 'MySQL', valkey: 'Valkey' };
const phpInstalled = computed(() => props.node.php.map((v) => props.node.phpPatches[v] ?? v).join(', '));

// The PHP settings form, for one branch at a time.
const php = reactive({ version: '', upload: '', post: '', memory: '', time: 60, children: 10, extra: '' });
const phpLoadFailed = ref(false);
async function editPhp(version) {
    phpLoadFailed.value = false;
    try {
        const s = await json('GET', serverUrl(props.node.id, `php/${version}`));
        Object.assign(php, { version, upload: s.upload_max_filesize, post: s.post_max_size, memory: s.memory_limit, time: Number(s.max_execution_time), children: Number(s.pm_max_children), extra: s.extra });
    } catch {
        phpLoadFailed.value = true;
    }
}
function savePhp() {
    emit('saved');
    router.post(serverUrl(props.node.id, `php/${php.version}/settings`), { upload: php.upload, post: php.post, memory: php.memory, time: php.time, children: php.children, extra: php.extra }, { preserveScroll: true });
}
</script>

<template>
  <Drawer :open="open" title="Software" :note="`What is installed on ${node.name}.`" @close="emit('close')">
    <template #tabs><Tabs :tabs="[['php', 'PHP'], ['services', 'Services'], ['containers', 'Containers']]" v-model="tab" label="Software" /></template>
    <fieldset class="ro m-0 flex min-w-0 flex-col gap-3 border-0 p-0 disabled:opacity-70" :disabled="readonly">
      <template v-if="tab === 'php'">
        <div><span :class="caption">PHP</span> <span class="text-muted">{{ phpInstalled }} installed · each version has its own FPM pool, so apps here can differ</span></div>
        <div v-for="v in phpVersions" :key="v" class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft pt-3">
          <span><b class="font-semibold text-ink-strong">PHP {{ v }}</b> <span v-if="node.php.includes(v)" :class="note">· {{ node.phpPatches[v] ?? 'installed' }}</span><span v-else :class="note">· not installed</span></span>
          <span class="flex flex-wrap gap-2">
            <template v-if="node.php.includes(v)">
              <Tip :title="`Upload size, memory, time limit and worker count for PHP ${v} on this server, and any php.ini directives of your own.`"><Btn small @click="editPhp(v)">Settings</Btn></Tip>
              <Tip :title="`Apply patch updates to PHP ${v} on this server. Every app using it restarts its workers.`"><Btn small @click="act(`php/${v}/update`, {}, `Update PHP ${v} on ${node.name}? Patch updates are shared by every app on this server using ${v}; their workers restart.`)">Update</Btn></Tip>
            </template>
            <Tip v-else :title="`Add PHP ${v} with its own FPM pool. Other versions keep running.`"><Btn small @click="act(`php/${v}/install`, {}, `Install PHP ${v} on ${node.name}? It gets its own FPM pool; other versions keep running.`)">Install</Btn></Tip>
          </span>
        </div>
        <FormError :message="errors.php" />
        <FormError v-if="phpLoadFailed" message="That PHP version's settings could not be read." />
        <div v-if="php.version !== '' && node.php.includes(php.version)" class="flex flex-col gap-3 rounded-[14px] border border-dashed border-line p-4">
          <span :class="caption">PHP {{ php.version }} settings on {{ node.name }}</span>
          <Field label="Upload max filesize" v-slot="{ id }"><input :id="id" v-model="php.upload"></Field>
          <Field label="Post max size" v-slot="{ id }"><input :id="id" v-model="php.post"></Field>
          <Field label="Memory limit" v-slot="{ id }"><input :id="id" v-model="php.memory"></Field>
          <Field label="Max execution time (s)" v-slot="{ id }"><input :id="id" v-model.number="php.time" type="number" min="0" max="3600"></Field>
          <Field label="FPM max children" v-slot="{ id }"><input :id="id" v-model.number="php.children" type="number" min="1" max="200"></Field>
          <Notice v-for="warning in node.phpWarnings[php.version] ?? []" :key="warning" class="!justify-start">⚠ {{ warning }}</Notice>
          <Field label="Extra directives" v-slot="{ id }"><textarea :id="id" v-model="php.extra" rows="5" spellcheck="false" placeholder="; Anything php.ini accepts.&#10;opcache.memory_consumption=256"></textarea></Field>
          <span :class="note">The limits above apply to web requests. Your own directives apply to web requests and the command line. PHP-FPM checks them before it reloads; if it objects, the old files are put back and its message is shown.</span>
          <FormError :message="errors.phpSettings" />
          <div class="flex flex-wrap gap-2"><Btn primary @click="savePhp">Save and apply</Btn><Btn @click="php.version = ''">Cancel</Btn></div>
        </div>
      </template>
      <template v-else-if="tab === 'services'">
        <span class="text-muted">PostgreSQL, MySQL and Valkey run on this server, listening on 127.0.0.1 only. Applications on a single server can use them; a new application sets one up for itself.</span>
        <div v-for="(label, svc) in services" :key="svc" class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft pt-3">
          <b class="font-semibold text-ink-strong">{{ label }}</b>
          <Badge v-if="node.services.includes(svc)" status="live">installed</Badge>
          <Tip v-else :title="`Install ${label} here. It binds 127.0.0.1 and opens no port. Backups stay on this server's disk unless a backup bucket is set up.`"><Btn small @click="act(`services/${svc}`, {}, `Install ${label} on ${node.name}? It listens on this server only.`)">Install</Btn></Tip>
        </div>
        <FormError :message="errors.service" />
      </template>
      <template v-else>
        <span class="text-muted">Docker Engine for container templates such as Uptime Kuma.</span>
        <div><Btn small :href="`/server/containers?node=${encodeURIComponent(node.id)}`">Containers on this server</Btn></div>
      </template>
    </fieldset>
    <template #footer><span class="mr-auto"></span><Btn @click="emit('close')">Close</Btn></template>
  </Drawer>
</template>
