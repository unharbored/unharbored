<script setup>
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

async function savePhp(version) {
    if (await ask({ title: `Run on PHP ${version}?`, message: `Run ${props.site.name} on PHP ${version}? It applies on the next deploy, which rebuilds dependencies under ${version}.`, ok: `Use PHP ${version}` })) {
        send('php', { version });
    }
}
</script>

<template>
  <Panel id="runtime" title="PHP version" note="Applies on next deploy">
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <span>Running: <b class="font-semibold text-ink-strong">{{ data.phpVersion }}</b> <span class="text-faint text-[13px]">· change takes effect on the next deploy</span></span>
        <div class="flex flex-wrap gap-2">
          <template v-for="v in data.phpVersions" :key="v">
            <Btn small v-if="v === data.phpVersion" disabled>{{ v }} · current</Btn>
            <Btn small v-else @click="savePhp(v)">{{ v }} <template v-if="v === data.phpDefault"> · default </template></Btn>
          </template>
        </div>
      </div>
      <FormError :message="error('phpVersion')" />
      <span class="text-faint text-[13px]">Each application on a server keeps its own version; the next deploy installs dependencies under the chosen one and switches web, workers and the scheduler together.</span>
      <div class="mt-2.5">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Installed on its servers</span>
        <div v-for="s in data.phpInstalled" :key="s.node" class="flex justify-between gap-3"><span class="text-faint text-[13px]">{{ s.node }}</span><span class="text-faint text-[13px]">{{ s.versions.join(', ') }}</span></div>
        <span class="text-faint text-[13px]">Install another version on the <a href="/servers">Servers</a> page.</span>
      </div>
  </Panel>
</template>
