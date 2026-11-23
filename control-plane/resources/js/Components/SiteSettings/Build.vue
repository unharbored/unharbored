<script setup>
import Toggle from '../ui/Toggle.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { computed, reactive, ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, error } = useSiteSettings(() => props.site.id);

const rt = reactive({ ...props.data.rt });
const preset = computed(() => props.data.presets[rt.runtime ?? 'php'] ?? null);

let saved = JSON.stringify(rt);

// The credentials are never sent back to the page, so the box starts empty; saving replaces them, saving it empty removes them.
const build = reactive({ ...props.data.buildScript });
const resetBuild = () => send('build-script', { script: '', node: '' }, { onSuccess: () => { build.script = props.data.buildScript.script; build.node = ''; } });
const composerAuth = ref('');
const removeAuth = () => send('composer-auth', { auth: '' });

// One Save for the whole tab. Each part goes to its own action, in turn, and a failure stops the rest so its message is the one shown.
// The type and folder go last, and only when they changed, because saving them rewrites the web server.
function save() {
    const steps = [];
    if (!preset.value) {
        steps.push((next) => send('build-script', { script: build.script, node: build.node }, { onSuccess: next }));
        if (composerAuth.value.trim() !== '') steps.push((next) => send('composer-auth', { auth: composerAuth.value }, { onSuccess: () => { composerAuth.value = ''; next(); } }));
    }
    if (preset.value || JSON.stringify(rt) !== saved) steps.push((next) => send('runtime', { rt: { ...rt } }, { onSuccess: () => { saved = JSON.stringify(rt); next(); } }));
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
        <p class="text-faint text-[13px]">Blank fields use the defaults shown. Commands run in the release's folder on the server, as the deploy user, with your variables set. The change applies on the next deploy; the web server is rewritten now.</p>
        <div v-if="preset.tool || rt.runtime === 'static'" class="flex flex-wrap items-end gap-3"><Field :label="`${(preset.tool || 'node').charAt(0).toUpperCase() + (preset.tool || 'node').slice(1)} version`" class="flex-[1_1_180px]" v-slot="{ id }"><input :id="id" v-model="rt.version" :placeholder="preset.version || '22'" autocomplete="off" spellcheck="false"></Field></div>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Install" v-slot="{ id }"><input :id="id" v-model="rt.install" :placeholder="preset.install || 'nothing'" autocomplete="off" spellcheck="false">
            <span class="text-faint text-[13px]">Fetches dependencies, such as <code>npm ci</code>. Runs first.</span></Field>
          <Field label="Build commands" class="w-full" v-slot="{ id }"><textarea :id="id" v-model="rt.build" rows="4" class="font-mono" :placeholder="preset.build || 'nothing'" autocomplete="off" spellcheck="false" />
            <span class="text-faint text-[13px]">Makes the files to serve, such as <code>npm run build</code>. Runs after Install, one command per line; it stops at the first that fails and leaves the live release alone.</span></Field>
        </div>
        <template v-if="preset.start">
          <div class="flex flex-wrap items-end gap-3">
            <Field label="Start" v-slot="{ id }"><input :id="id" v-model="rt.start" :placeholder="preset.start" autocomplete="off" spellcheck="false"></Field>
            <Field label="Health check path" v-slot="{ id }"><input :id="id" v-model="rt.health" :placeholder="preset.health" autocomplete="off" spellcheck="false"></Field>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <Field label="Before the switch, once (such as a migration)" v-slot="{ id }"><input :id="id" v-model="rt.release" placeholder="nothing" autocomplete="off" spellcheck="false"></Field>
            <Field label="Port" v-slot="{ id }"><input :id="id" v-model="rt.port" type="number" :placeholder="data.runtimePort ?? ''"></Field>
          </div>
          <p class="text-faint text-[13px]">Started with <code>PORT</code> set. The process restarts on each deploy, so there is a few seconds' gap while it starts; the deploy waits for the health check.</p>
        </template>
        <div v-else class="flex flex-wrap items-end gap-3">
          <Field label="Folder to serve" v-slot="{ id }"><input :id="id" v-model="rt.output" :placeholder="preset.output" autocomplete="off" spellcheck="false"></Field>
          <Toggle v-model="rt.spa">Send unknown paths to index.html</Toggle>
        </div>
      </template>
      <p v-else class="text-faint text-[13px]">Laravel is built with Composer and Artisan, served by PHP-FPM, and gets queue workers and a scheduler.</p>
      <div v-if="!preset" class="flex flex-col gap-2.5">
        <Field label="Build commands" :error="error('buildScript')">
          <template #default="{ id }"><textarea :id="id" v-model="build.script" rows="8" class="font-mono" autocomplete="off" spellcheck="false" /></template>
          <template #hint>Run in each new release, before migrations and the caches, one command per line, stopping at the first that fails (a failure leaves the live release alone). The first line is the default: Composer. Add your own after it, such as <code>npm ci</code> and <code>npm run build</code> for a Vite app (a "Vite manifest not found" error means that is missing), or edit any line. <code>$PHP</code> is this application's PHP, <code>$COMPOSER</code> runs Composer with it, and the Composer credentials below are in place. Migrations, <code>artisan optimize</code> and the switch to the new release always follow.</template>
        </Field>
        <div class="flex flex-wrap items-end gap-3">
          <Field v-slot="{ id }" label="Node version" class="flex-[0_1_140px]"><input :id="id" v-model="build.node" placeholder="automatic" autocomplete="off" spellcheck="false"></Field>
          <Btn v-if="data.buildScript.custom" small @click="resetBuild">Back to the default</Btn>
        </div>
        <span class="text-faint text-[13px]">Node is installed for you, at this version, when the commands use npm, yarn, pnpm or node; type a version such as 22 to always have it.</span>
      </div>
      <div v-if="!preset" class="flex flex-col gap-2.5">
        <Field label="Composer credentials" :error="error('composerAuth')">
          <template #default="{ id }"><textarea :id="id" v-model="composerAuth" rows="4" class="font-mono" placeholder='{"http-basic": {"nova.laravel.com": {"username": "you@example.com", "password": "licence-key"}}}' autocomplete="off" spellcheck="false" /></template>
          <template #hint>For private packages: the text of an <code>auth.json</code> (GitHub, GitLab or Bitbucket tokens, or <code>http-basic</code> for a package server). Kept encrypted and never shown again. It goes to the servers with the next deploy, where only this application's deploy user can read it. Type new ones here and press Save to replace them.</template>
        </Field>
        <span v-if="data.composerShared.length" class="text-[13px] text-faint">Already given to every deploy by your team: <span class="font-mono">{{ data.composerShared.join(' · ') }}</span>. This application's own win over them for the same host. Manage them under Account, Composer.</span>
        <span v-if="data.composerAuth.length" class="text-[13px] text-faint">Set now: <span class="font-mono">{{ data.composerAuth.join(' · ') }}</span></span>
        <div v-if="data.composerAuth.length" class="flex flex-wrap gap-2"><Btn small danger @click="removeAuth">Remove this application's credentials</Btn></div>
      </div>
      <FormError :message="error('runtime')" />
      <FormError :message="error('root')" />
      <div class="flex flex-wrap gap-2"><Btn primary @click="save">Save</Btn></div>
  </Panel>
</template>
