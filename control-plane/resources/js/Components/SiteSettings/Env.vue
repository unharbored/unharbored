<script setup>
import RuleRow from '../ui/RuleRow.vue';
import Field from '../ui/Field.vue';
import Chip from '../ui/Chip.vue';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';
import Notice from '../ui/Notice.vue';
import Panel from '../ui/Panel.vue';
import { computed, ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, lookup, ask, error } = useSiteSettings(() => props.site.id);

const editing = ref(false);
const envText = ref('');
const injectedValues = ref({});
const revealed = computed(() => Object.keys(injectedValues.value).length > 0);
const marks = { added: '+', removed: '−', changed: '~' };

async function revealAndEdit() {
    const { ok, data } = await lookup('env/reveal');
    if (ok) {
        envText.value = data.text;
        editing.value = true;
    }
}
function save() {
    send('env', { envText: envText.value }, { onSuccess: () => { editing.value = false; envText.value = ''; } });
}
function cancel() {
    editing.value = false;
    envText.value = '';
}
async function applyNow() {
    if (await ask({ title: 'Apply the variables now?', message: `Put the saved variables on ${props.site.name}'s running servers without a deploy: the .env is rewritten, the config cache rebuilt, PHP-FPM reloaded and the queue workers restarted. Nothing is cloned, installed or migrated.`, ok: 'Apply now' })) {
        send('env/apply');
    }
}
async function showInjected() {
    const { ok, data } = await lookup('env/injected');
    if (ok) injectedValues.value = data.values;
}
async function cleanCopies() {
    if (await ask({ title: 'Remove the copies?', message: 'Remove these variables from yours? What Unharbored injects applies instead, on the next deploy.', ok: 'Remove' })) {
        send('env/copies/clean');
    }
}
async function dropOverride(key) {
    if (await ask({ title: 'Remove the override?', message: `Remove your ${key}? The injected value reaches the server on the next deploy.`, ok: 'Remove' })) {
        send('env/override/remove', { key });
    }
}
async function restore(version) {
    if (await ask({ title: 'Restore this version?', message: `Put version ${version} of your variables back? It becomes the newest version and reaches the server on the next deploy.`, ok: 'Restore' })) {
        send('env/restore', { version });
    }
}
const changes = (v) => Object.entries(marks).filter(([kind]) => (v.changes?.[kind] ?? []).length > 0).map(([kind, mark]) => ` · ${mark} ${v.changes[kind].join(', ')}`).join('');
</script>

<template>
  <Panel id="env" title="Custom environment variables" note="Yours. They win over injected variables, and Unharbored never rewrites them · applies on next deploy">
      <Notice v-if="data.envCopies.length > 0" alert class="flex-col items-start! justify-start!">
        <span>⚠ {{ data.envCopies.length }} of your variables only repeat what Unharbored injects: <template v-for="(k, i) in data.envCopies" :key="k"><span class="font-mono">{{ k }}</span><template v-if="i < data.envCopies.length - 1">, </template> </template>. An earlier fault copied them here when a domain or HTTPS changed. A copy wins over the real value, so a database attached afterwards would not take effect. A value you set yourself, one that differs, is not touched.</span>
        <Btn small @click="cleanCopies">Remove the copies</Btn>
      </Notice>
      <Notice v-if="data.overridden.length > 0" class="justify-start!">⚠ You are overriding {{ data.overridden.length }} injected {{ data.overridden.length === 1 ? 'variable' : 'variables' }}: <template v-for="(k, i) in data.overridden" :key="k"><span class="font-mono">{{ k }}</span><template v-if="i < data.overridden.length - 1">, </template> </template></Notice>
      <template v-if="editing">
        <Field :label="`Custom variables for ${site.name}`" :error="error('envText')" v-slot="{ id }">
          <textarea :id="id" v-model="envText" rows="14" spellcheck="false"></textarea>
        </Field>
        <div class="flex flex-wrap gap-2">
          <Btn primary @click="save">Save</Btn>
          <Btn @click="cancel">Cancel</Btn>
        </div>
      </template>
      <template v-else>
        <pre class="overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)]">{{ data.envMasked }}</pre>
        <span v-if="error('envText')" class="text-[13px] text-failed">{{ error('envText') }}</span>
        <div class="flex flex-wrap gap-2">
          <Btn @click="revealAndEdit">Reveal and edit</Btn>
          <Btn v-if="data.canApply" title="Put the saved variables on the running application now, with no deploy" @click="applyNow">Apply to the running app</Btn>
        </div>
        <span class="text-faint text-[13px]">Revealing is recorded in the audit log.<template v-if="data.canApply"> "Apply to the running app" takes a few seconds and needs no deploy; code changes still do.</template></span>
      </template>
      <details v-if="data.envVersions.length > 1">
        <summary class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase cursor-pointer">History · {{ data.envVersions.length }} versions</summary>
        <div class="flex flex-col gap-2 mt-2">
          <div v-for="v in data.envVersions" :key="v.version" class="flex justify-between gap-3 flex-wrap items-center">
            <span><b class="font-semibold text-ink-strong">v{{ v.version }}</b> <Chip>{{ v.layer === 'injected' ? 'injected' : 'yours' }}</Chip> <span class="text-faint text-[13px]">· {{ v.ago }} · {{ v.reason }}{{ changes(v) }}</span></span>
            <span v-if="v.layer === 'injected'" class="text-faint text-[13px]">rewritten by Unharbored</span>
            <span v-else-if="v.version === data.currentEnvVersion" class="text-accent text-[13px]">current</span>
            <Btn small v-else @click="restore(v.version)">Restore</Btn>
          </div>
        </div>
        <span class="text-faint text-[13px]">Key names only: values stay in the vault. The newest 20 versions are kept.</span>
      </details>
  </Panel>

  <Panel id="injected" title="Injected variables" note="Set by Unharbored from what this application uses. You can't edit them; you can override them above.">
      <div class="flex flex-col">
        <RuleRow v-for="r in data.injected" :key="r.key">
          <span class="min-w-0"><b class="font-semibold text-ink-strong font-mono">{{ r.key }}</b>
            <div class="text-faint text-[13px] font-mono break-words">{{ injectedValues[r.key] ?? r.shown }}</div></span>
          <span class="flex flex-wrap gap-2 items-center">
            <Chip>{{ r.source }}</Chip>
            <template v-if="r.overridden">
              <Badge status="running">Overridden by yours</Badge>
              <Btn small @click="dropOverride(r.key)">Remove override</Btn>
            </template>
          </span>
        </RuleRow>
        <span v-if="data.injected.length === 0" class="text-faint text-[13px]">Nothing is injected.</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <Btn small v-if="revealed" @click="injectedValues = {}">Hide values</Btn>
        <Btn small v-else @click="showInjected">Reveal values</Btn>
        <span class="text-faint text-[13px] self-center">Revealing is recorded in the audit log.</span>
      </div>
  </Panel>
</template>
