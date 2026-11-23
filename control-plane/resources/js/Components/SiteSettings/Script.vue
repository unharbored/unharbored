<script setup>
import Notice from '../ui/Notice.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, lookup, ask, error } = useSiteSettings(() => props.site.id);

const text = ref(props.data.scriptText);

const save = () => send('script', { text: text.value, run: false });
async function saveAndRun() {
    if (await ask({ title: 'Run the script?', message: `Run the script as root on every server of ${props.site.name || 'this application'} now?`, ok: 'Save and run' })) {
        send('script', { text: text.value, run: true });
    }
}
async function load(version) {
    const { ok, data } = await lookup(`script/versions/${version}`);
    if (ok) text.value = data.text;
}
</script>

<template>
  <Panel id="script" title="Server setup script" note="Runs as root on each server that joins this application, before it takes traffic">
      <Notice class="justify-start!">It runs as <b>root</b>. Make it safe to run twice, and keep secrets out of it: it is stored as plain text, and every version is kept. A script that exits with an error stops the server from joining.</Notice>
      <Field label="Script" v-slot="{ id }">
        <textarea :id="id" v-model="text" rows="12" spellcheck="false" :placeholder="'#!/usr/bin/env bash\nset -euo pipefail\napt-get install -y ffmpeg imagemagick'"></textarea>
        <span class="text-faint text-[13px]">For what the stack does not install: <span class="font-mono">ffmpeg</span>, <span class="font-mono">imagemagick</span>, a font, a system package. Up to 15 minutes, output in the operation log. Not for build steps: those belong to deploys.</span></Field>
      <FormError :message="error('script')" />
      <div class="flex flex-wrap gap-2">
        <Btn title="Keep this as the newest version. It runs on servers as they join." @click="save">Save</Btn>
        <Btn primary title="Keep this version, then run it as root on every server of this application now" @click="saveAndRun">Save and run on all servers now</Btn>
      </div>
      <template v-if="data.scriptVersions.length > 0">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Versions</span>
        <div v-for="v in data.scriptVersions" :key="v.version" class="flex justify-between gap-3 flex-wrap items-center">
          <span><b class="font-semibold text-ink-strong">Version {{ v.version }}</b> <span class="text-faint text-[13px]">· {{ v.actor }} · {{ v.ago }} · {{ v.bytes }} bytes</span></span>
          <Btn small title="Put this version in the editor. Save to make it the newest." @click="load(v.version)">Load</Btn>
        </div>
      </template>
  </Panel>
</template>
