<script setup>
import Toggle from '../ui/Toggle.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { computed, reactive, ref, watch } from 'vue';
import FormRow from '../ui/FormRow.vue';
import { confirm } from '../../composables/useConsole';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, error } = useSiteSettings(() => props.site.id);

const rt = reactive({ ...props.data.rt });
const preset = computed(() => props.data.presets[rt.runtime ?? 'php'] ?? null);

// The runtime's defaults are filled in as real text. A field equal to its default is sent blank, so it stays "default" and later changes to the default still apply.
const textFields = ['version', 'install', 'build', 'start', 'health', 'output'];
const defaultsOf = (p) => ({ version: p.version || '22', install: p.install || '', build: p.build || '', start: p.start || '', health: p.health || '', output: p.output || '' });
const fillDefaults = (overwrite) => {
    const d = preset.value ? defaultsOf(preset.value) : null;
    if (d) textFields.forEach((k) => { if (overwrite || !rt[k]) rt[k] = d[k]; });
};
fillDefaults(false);
watch(() => rt.runtime, () => { if (preset.value) { fillDefaults(true); rt.release = ''; rt.port = ''; rt.spa = false; } });
const toSend = () => {
    const out = { ...rt };
    if (preset.value) { const d = defaultsOf(preset.value); textFields.forEach((k) => { if (out[k] === d[k]) out[k] = ''; }); }
    return out;
};

let saved = JSON.stringify(rt);

const build = reactive({ ...props.data.buildScript });
const resetBuild = () => send('build-script', { script: '', node: '' }, { onSuccess: () => { build.script = props.data.buildScript.default; build.node = ''; } });
const buildChanged = computed(() => build.script.trim() !== props.data.buildScript.default || props.data.buildScript.custom);

// Credentials are kept per host. Passwords and tokens are never sent back to the page; Edit asks for them again and replaces that host only.
const typeLabels = { 'http-basic': 'Username and password', 'github-oauth': 'GitHub token', 'gitlab-token': 'GitLab token', bearer: 'Bearer token' };
const cred = reactive({ type: 'http-basic', host: '', username: '', secret: '' });
const advanced = ref(false);
const composerAuth = ref('');
const editHost = (h) => { Object.assign(cred, { type: h.type, host: h.host, username: h.username, secret: '' }); };
const saveHost = () => send('composer-host', { ...cred }, { onSuccess: () => { Object.assign(cred, { host: '', username: '', secret: '' }); } });
const removeHost = async (h) => {
    if (await confirm({ title: 'Remove it?', message: `Remove the credentials for ${h.host}? The next deploy removes them from the servers, so a deploy that needs them will fail.`, ok: 'Remove', tone: 'danger' })) {
        send('composer-host-remove', { type: h.type, host: h.host });
    }
};
const pickType = () => { if (cred.type === 'github-oauth' && !cred.host) cred.host = 'github.com'; if (cred.type === 'gitlab-token' && !cred.host) cred.host = 'gitlab.com'; };
const saveRaw = () => send('composer-auth', { auth: composerAuth.value }, { onSuccess: () => { composerAuth.value = ''; } });

// One Save for the whole tab. Each part goes to its own action, in turn, and a failure stops the rest so its message is the one shown.
// The type and folder go last, and only when they changed, because saving them rewrites the web server.
function save() {
    const steps = [];
    if (!preset.value) {
        steps.push((next) => send('build-script', { script: build.script, node: build.node }, { onSuccess: next }));
    }
    if (preset.value || JSON.stringify(rt) !== saved) steps.push((next) => send('runtime', { rt: toSend() }, { onSuccess: () => { saved = JSON.stringify(rt); next(); } }));
    const run = (i) => steps[i]?.(() => run(i + 1));
    run(0);
}
</script>

<template>
  <Panel id="build" title="Type &amp; build" note="what the application is, and how a release is made and started">
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Application folder" v-slot="{ id }"><input :id="id" v-model="rt.root" placeholder="the top of the repository" autocomplete="off" spellcheck="false">
          <span class="text-faint text-[13px]">The folder of the repository that holds the application (where its <code>composer.json</code> or <code>package.json</code> is), such as <code>apps/web</code>; not the web folder <code>public</code>. Also under Repository &amp; Git. The whole repository is still cloned. Install, build and start run in this folder, the web server serves from it, and <code>.env</code> and <code>storage</code> are linked inside it. Applies on the next deploy.</span></Field>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Type" v-slot="{ id }">
          <select :id="id" v-model="rt.runtime"><option v-for="(label, key) in data.runtimes" :key="key" :value="key">{{ label }}</option></select></Field>
      </div>
      <template v-if="preset">
        <p class="text-faint text-[13px]">The defaults are filled in; clear a field to go back to its default. Commands run in the release's folder on the server, as the deploy user, with your variables set. The change applies on the next deploy; the web server is rewritten now.</p>
        <div v-if="preset.tool || rt.runtime === 'static'" class="flex flex-wrap items-end gap-3"><Field :label="`${(preset.tool || 'node').charAt(0).toUpperCase() + (preset.tool || 'node').slice(1)} version`" class="flex-[1_1_180px]" v-slot="{ id }"><input :id="id" v-model="rt.version" autocomplete="off" spellcheck="false"></Field></div>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Install" v-slot="{ id }"><input :id="id" v-model="rt.install" placeholder="nothing" autocomplete="off" spellcheck="false">
            <span class="text-faint text-[13px]">Fetches dependencies, such as <code>npm ci</code>. Runs first.</span></Field>
          <Field label="Build commands" class="w-full" v-slot="{ id }"><textarea :id="id" v-model="rt.build" rows="4" class="font-mono" placeholder="nothing" autocomplete="off" spellcheck="false" />
            <span class="text-faint text-[13px]">Makes the files to serve, such as <code>npm run build</code>. Runs after Install, one command per line; it stops at the first that fails and leaves the live release alone.</span></Field>
        </div>
        <template v-if="preset.start">
          <div class="flex flex-wrap items-end gap-3">
            <Field label="Start" v-slot="{ id }"><input :id="id" v-model="rt.start" autocomplete="off" spellcheck="false"></Field>
            <Field label="Health check path" v-slot="{ id }"><input :id="id" v-model="rt.health" autocomplete="off" spellcheck="false"></Field>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <Field label="Before the switch, once (such as a migration)" v-slot="{ id }"><input :id="id" v-model="rt.release" placeholder="nothing" autocomplete="off" spellcheck="false"></Field>
            <Field label="Port" v-slot="{ id }"><input :id="id" v-model="rt.port" type="number" :placeholder="data.runtimePort ?? ''"></Field>
          </div>
          <p class="text-faint text-[13px]">Started with <code>PORT</code> set. The process restarts on each deploy, so there is a few seconds' gap while it starts; the deploy waits for the health check.</p>
        </template>
        <div v-else class="flex flex-wrap items-end gap-3">
          <Field label="Folder to serve" v-slot="{ id }"><input :id="id" v-model="rt.output" autocomplete="off" spellcheck="false"></Field>
          <Toggle v-model="rt.spa">Send unknown paths to index.html</Toggle>
        </div>
      </template>
      <p v-else class="text-faint text-[13px]">Laravel is built with Composer and Artisan, served by PHP-FPM, and gets queue workers and a scheduler.</p>
      <div v-if="!preset" class="flex flex-col gap-2.5">
        <Field label="Build commands" :error="error('buildScript')">
          <template #default="{ id }"><textarea :id="id" v-model="build.script" rows="8" class="font-mono" autocomplete="off" spellcheck="false" /></template>
          <template #hint>Run in each new release, before migrations and the caches, one command per line, stopping at the first that fails (a failure leaves the live release alone). This is the default, and it is yours to edit: add your own lines after the Composer one, such as <code>npm ci</code> and <code>npm run build</code> for a Vite app (a "Vite manifest not found" error means that is missing), or edit any line. <code>$PHP</code> is this application's PHP, <code>$COMPOSER</code> runs Composer with it, and the Composer credentials below are in place.</template>
        </Field>
        <ul class="text-faint text-[13px] list-disc pl-5">
          <li>After these, fixed: <code>artisan migrate --force</code> (on the primary server only, after the automatic database backup if backups are on)</li>
          <li>then <code>artisan optimize</code>, then the switch to the new release.</li>
        </ul>
        <div class="flex flex-wrap items-end gap-3">
          <Field v-slot="{ id }" label="Node version" class="flex-[0_1_140px]"><input :id="id" v-model="build.node" placeholder="automatic" autocomplete="off" spellcheck="false"></Field>
          <Btn v-if="buildChanged" small @click="resetBuild">Back to the default</Btn>
        </div>
        <span class="text-faint text-[13px]">Node is installed for you, at this version, when the commands use npm, yarn, pnpm or node; type a version such as 22 to always have it.</span>
      </div>
      <div v-if="!preset" class="flex flex-col gap-2.5">
        <b class="font-semibold text-ink-strong">Composer credentials</b>
        <FormError :message="error('composerAuth')" />
        <div v-for="h in data.composerAuth" :key="h.type + h.host" class="flex flex-wrap items-center justify-between gap-3">
          <span><span class="font-mono">{{ h.host }}</span> <span class="text-faint text-[13px]">· {{ typeLabels[h.type] || h.type }}<template v-if="h.username"> · {{ h.username }}</template> · secret kept encrypted</span></span>
          <span class="flex gap-2"><Btn small @click="editHost(h)">Edit</Btn><Btn small danger @click="removeHost(h)">Remove</Btn></span>
        </div>
        <p v-if="!data.composerAuth.length" class="text-faint text-[13px]">None for this application.</p>
        <FormRow>
          <Field label="Type" v-slot="{ id }"><select :id="id" v-model="cred.type" @change="pickType"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></Field>
          <Field label="Host" v-slot="{ id }"><input :id="id" v-model="cred.host" placeholder="nova.laravel.com" autocomplete="off" spellcheck="false"></Field>
        </FormRow>
        <FormRow>
          <Field v-if="cred.type === 'http-basic'" label="Username" v-slot="{ id }"><input :id="id" v-model="cred.username" placeholder="you@example.com" autocomplete="off"></Field>
          <Field :label="cred.type === 'http-basic' ? 'Password or licence key' : 'Token'" v-slot="{ id }"><input :id="id" v-model="cred.secret" type="password" autocomplete="new-password"></Field>
        </FormRow>
        <span class="text-faint text-[13px]">For private packages. Saving a host again replaces that host only; the others stay. Kept encrypted and never shown again, so Edit asks for the secret again. It goes to the servers with the next deploy, where only this application's deploy user can read it.</span>
        <div class="flex flex-wrap gap-2"><Btn small primary @click="saveHost">Save credentials</Btn><Btn small @click="advanced = !advanced">{{ advanced ? 'Hide advanced' : 'Advanced: paste an auth.json' }}</Btn></div>
        <template v-if="advanced">
          <textarea v-model="composerAuth" rows="4" class="font-mono" placeholder='{"http-basic": {"nova.laravel.com": {"username": "you@example.com", "password": "licence-key"}}}' autocomplete="off" spellcheck="false" />
          <span class="text-faint text-[13px]">This replaces every credential of this application. Sent empty, it removes them all.</span>
          <div class="flex flex-wrap gap-2"><Btn small @click="saveRaw">Replace all with this</Btn></div>
        </template>
        <span v-if="data.composerShared.length" class="text-[13px] text-faint">Already given to every deploy by your team: <span class="font-mono">{{ data.composerShared.join(' · ') }}</span>. This application's own win over them for the same host. Manage them under Account, Composer.</span>
      </div>
      <FormError :message="error('runtime')" />
      <FormError :message="error('root')" />
      <div class="flex flex-wrap gap-2"><Btn primary @click="save">Save</Btn></div>
  </Panel>
</template>
