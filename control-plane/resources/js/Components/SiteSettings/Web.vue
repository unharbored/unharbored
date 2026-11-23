<script setup>
import Btn from '../ui/Btn.vue';
import Notice from '../ui/Notice.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const upload = ref(props.data.webUpload);
const timeout = ref(props.data.webTimeout);
const snippet = ref(props.data.webSnippet);
const authUser = ref('');
const authPassword = ref('');

const saveWeb = () => send('web', { upload: upload.value, timeout: timeout.value, snippet: snippet.value });
const saveAuth = () => send('auth', { user: authUser.value, password: authPassword.value }, { onSuccess: () => { authPassword.value = ''; } });
async function removeAuth() {
    if (await ask({ title: 'Remove the password?', message: `Remove the password? Anyone who has the address can open ${props.site.name} again.`, ok: 'Remove password', tone: 'danger' })) {
        send('auth/remove');
    }
}
</script>

<template>
  <Panel id="web" title="Web server">
    <template #note>nginx, for {{ site.domain }} only · applies without a deploy</template>
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Upload limit (MB)" v-slot="{ id }"><input :id="id" v-model.number="upload" type="number" min="1" max="2048">
          <span class="text-faint text-[13px]">nginx's <span class="font-mono">client_max_body_size</span>. nginx itself allows 1 MB; Unharbored's default is {{ data.webDefaults.upload }}.</span></Field>
        <Field label="Request timeout (seconds)" v-slot="{ id }"><input :id="id" v-model.number="timeout" type="number" min="5" max="3600">
          <span class="text-faint text-[13px]"><span class="font-mono">fastcgi_read_timeout</span>. Default {{ data.webDefaults.timeout }}.</span></Field>
      </div>
      <Notice v-if="data.webPhpNote !== ''" class="justify-start!">⚠ {{ data.webPhpNote }} <a href="/servers">Servers</a></Notice>
      <Field label="Custom snippet" v-slot="{ id }">
        <textarea :id="id" v-model="snippet" rows="9" spellcheck="false" :placeholder="'# Inside this application\'s server block.\nlocation = /health {\n    access_log off;\n    return 200 &quot;ok&quot;;\n}'"></textarea>
        <span class="text-faint text-[13px]">Survives deploys and HTTPS changes. nginx checks it before reloading; if it refuses, the old config is put back and nginx's message is shown. <span class="font-mono">include</span>, <span class="font-mono">listen</span>, <span class="font-mono">server_name</span> and the certificate lines are managed by cloud-manager.</span></Field>
      <FormError :message="error('web')" />
      <div class="flex flex-wrap gap-2"><Btn primary @click="saveWeb">Save and apply</Btn></div>
      <details>
        <summary class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase cursor-pointer">Generated configuration (read-only)</summary>
        <pre class="overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)] mt-2">{{ data.webPreview }}</pre>
      </details>
  </Panel>

  <Panel id="auth" title="Password protection" note="one username and password in front of the whole application · for staging and previews">
      <span v-if="data.balanced" class="text-faint text-[13px]">Not available behind a load balancer: its health checks would be refused and every server taken out of rotation.</span>
      <template v-else>
        <div v-if="data.basicAuthUser" class="flex justify-between gap-3 flex-wrap items-center">
          <span>Protected: visitors sign in as <b class="font-semibold text-ink-strong font-mono">{{ data.basicAuthUser }}</b></span>
          <Btn small danger @click="removeAuth">Remove password</Btn>
        </div>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Username" v-slot="{ id }"><input :id="id" v-model="authUser" class="font-mono" autocomplete="off" spellcheck="false" :placeholder="data.basicAuthUser ?? 'staging'"></Field>
          <Field label="Password" v-slot="{ id }"><input :id="id" v-model="authPassword" type="password" autocomplete="new-password" placeholder="8 to 72 characters"></Field>
          <Btn primary class="self-end" @click="saveAuth">{{ data.basicAuthUser ? 'Change password' : 'Protect' }}</Btn>
        </div>
        <span v-if="error('auth')" class="text-[13px] text-failed" role="alert">{{ error('auth') }}</span>
        <span class="text-faint text-[13px]">Applied to every server of this application without a deploy. Certificate renewal is never asked for the password. Only a hash is kept, so the password cannot be shown again.</span>
      </template>
  </Panel>
</template>
