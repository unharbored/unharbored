<script setup>
import Toggle from '../ui/Toggle.vue';
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { serverUrl } from './api.js';
import { check, note } from './serverActions.js';
import Drawer from '../ui/Drawer.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import Btn from '../ui/Btn.vue';

const props = defineProps({ open: Boolean, node: Object, errors: Object, readonly: Boolean, unavailable: Boolean, tip: String });
const emit = defineEmits(['close', 'act']);

const fw = reactive({ enabled: false, ssh: '', web: '', sshPrivate: false, webPrivate: false, reach: false });
watch(() => props.open, (now) => {
    if (now) {
        const f = props.node.access.firewall;
        Object.assign(fw, { enabled: f.enabled, ssh: f.ssh.join('\n'), web: f.web.join('\n'), sshPrivate: f.ssh_private, webPrivate: f.web_private, reach: false });
    }
});

function save() {
    emit('act');
    router.post(serverUrl(props.node.id, 'firewall'), { enabled: fw.enabled, ssh: fw.ssh, web: fw.web, sshPrivate: fw.sshPrivate, webPrivate: fw.webPrivate, reach: fw.reach }, { preserveScroll: true });
}
</script>

<template>
  <Drawer :open="open" title="Firewall" note="Rules are rebuilt from these lists each time, as an operation." @close="emit('close')">
    <fieldset class="ro m-0 flex min-w-0 flex-col gap-3 border-0 p-0 disabled:opacity-70" :disabled="readonly">
      <Toggle v-model="fw.enabled">Turn the firewall on</Toggle>
      <Field label="Who may use SSH" v-slot="{ id }"><textarea :id="id" v-model="fw.ssh" rows="4" class="font-mono" spellcheck="false" placeholder="203.0.113.7&#10;198.51.100.0/24"></textarea>
        <span :class="note">One address or network per line. Empty: anyone.</span></Field>
      <Field label="Who may open the web ports (80, 443)" v-slot="{ id }"><textarea :id="id" v-model="fw.web" rows="4" class="font-mono" spellcheck="false" placeholder="empty: anyone"></textarea>
        <span :class="note">Restricting this hides the application from everyone else. The private network (10.0.0.0/8) is always let through, for a load balancer and a pool's other servers.</span></Field>
      <div v-if="node.access.tailnet" class="flex flex-col gap-1.5">
        <Toggle v-model="fw.sshPrivate">SSH from the tailnet only <span :class="note">(replaces the SSH list)</span></Toggle>
        <label :class="check"><input v-model="fw.reach" type="checkbox"> I can already reach <span class="font-mono">{{ node.access.tailnet.address }}</span> from my own computer over the tailnet</label>
        <Toggle v-model="fw.webPrivate">Web ports from the tailnet only <span :class="note">(hides every application here from everyone outside the tailnet; replaces the web list)</span></Toggle>
      </div>
      <FormError :message="errors.firewall" />
      <span :class="note">Containers are only ever reached through the web server. Docker publishes ports around this firewall, so every container port we publish is bound to 127.0.0.1 and ufw is not what keeps it private.</span>
      <span :class="note">Management keeps working whatever you choose: the agent connects out, and a console from the browser still reaches the server if SSH is closed to you. Before narrowing SSH, make sure your own address is in the list.</span>
    </fieldset>
    <template #footer>
      <span v-if="unavailable" class="mr-auto text-[13px] text-faint">{{ tip }}</span>
      <Btn @click="emit('close')">Cancel</Btn>
      <Btn primary :disabled="readonly || unavailable" @click="save">Save and apply</Btn>
    </template>
  </Drawer>
</template>
