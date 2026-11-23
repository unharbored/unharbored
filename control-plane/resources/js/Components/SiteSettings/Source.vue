<script setup>
import Toggle from '../ui/Toggle.vue';
import { onMounted, ref } from 'vue';
import Btn from '../ui/Btn.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, lookup, error } = useSiteSettings(() => props.site.id);

const sourceText = { github_app: 'Cloned with the GitHub App', https: 'Public: cloned with no login' };
const branch = ref(props.site.branch);
const root = ref(props.data.root ?? '');
// Read from the host when the tab opens; until then, or if it cannot be read, the name is typed.
const branches = ref([]);
onMounted(async () => {
    const { ok, data } = await lookup('branches');
    if (ok && Array.isArray(data.branches)) branches.value = data.branches;
});
const togglePush = (on) => send('push', { on });
</script>

<template>
  <Panel id="source" title="Source">
      <div class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 font-mono text-[13px] text-faint">
        <span class="font-semibold text-ink-strong">{{ data.repo }}</span>
        <span>Branch <b class="font-semibold text-ink-strong">{{ site.branch }}</b></span>
        <span>{{ sourceText[data.source] ?? 'Cloned with a deploy key' }}</span>
      </div>
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="send('branch', { branch })">
        <Field v-slot="{ id }" label="Branch to deploy" :error="error('branch')" class="min-w-[220px]"><select v-if="branches.length" :id="id" v-model="branch">
            <option v-for="b in branches" :key="b" :value="b">{{ b }}</option>
            <option v-if="!branches.includes(site.branch)" :value="site.branch">{{ site.branch }} (not in the repository)</option>
          </select>
          <input v-else :id="id" v-model="branch" type="text" autocomplete="off" spellcheck="false"></Field>
        <Btn small type="submit" :disabled="branch.trim() === '' || branch === site.branch">Change branch</Btn>
      </form>
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="send('root', { root })">
        <Field v-slot="{ id }" label="Application folder" :error="error('root')" class="min-w-[220px]"
          hint="The folder of the repository that holds the application: where its composer.json or package.json is. Not the web folder (public). Leave empty for the top of the repository. Applies on the next deploy."><input :id="id" v-model="root" type="text" placeholder="the top of the repository" autocomplete="off" spellcheck="false"></Field>
        <Btn small type="submit" :disabled="root.trim() === (data.root ?? '')">Save folder</Btn>
      </form>
      <template v-if="data.source === 'deploy_key'">
        <div><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Deploy key</span> <span class="text-faint text-[13px]">· add it on {{ data.gitHost ?? 'the host' }} for this repository as a deploy key with write access off</span>
          <pre class="whitespace-pre-wrap overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)] break-all mt-1.5">{{ data.deployKey }}</pre></div>
        <div><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Host key</span> <span class="text-faint text-[13px]">· {{ data.gitHost }}'s SSH key was pinned when the application was made; a different one is refused</span>
          <pre class="whitespace-pre-wrap overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)] break-all mt-1.5">{{ data.hostKey }}</pre></div>
      </template>
      <Toggle :model-value="data.push" @update:model-value="togglePush">Deploy when {{ site.branch }} {{ data.source === 'https' ? 'has a new commit' : 'is pushed' }}</Toggle>
      <span v-if="data.source === 'https'" class="text-faint text-[13px]">Checked every five minutes: a repository you do not own cannot send this application webhooks.</span>
      <div v-if="data.hook"><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Webhook</span> <span class="text-faint text-[13px]">· for pushes from {{ data.gitHost ?? 'the host' }}: a webhook on push events, content type JSON. GitLab, Gitea, Forgejo, Bitbucket and GitHub are understood; if the host asks for a secret, use the last part of this address.</span>
        <pre class="whitespace-pre-wrap overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)] break-all mt-1.5">{{ data.hook }}</pre></div>
      <div v-if="data.hookScript"><span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Plain Git server</span> <span class="text-faint text-[13px]">· no web hooks there? Save this as <span class="font-mono">hooks/post-receive</span> in the bare repository and make it executable</span>
        <pre class="whitespace-pre-wrap overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)] break-all mt-1.5">{{ data.hookScript }}</pre></div>
      <div v-if="data.source !== 'github_app' && data.gitHost === 'github.com' && data.appReady" class="flex flex-wrap gap-2"><Btn small @click="send('github-app')">Clone with the GitHub App instead</Btn></div>
      <FormError :message="error('source')" />
      <span class="text-faint text-[13px]">With the GitHub App, each clone uses a one-hour, read-only token for this repository. Nothing long-lived is stored on the server. A deploy key stays on the server, read-only for one repository.</span>
  </Panel>
</template>
