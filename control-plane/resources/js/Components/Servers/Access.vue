<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { caption, note, row } from './serverActions.js';
import SudoPassword from './SudoPassword.vue';
import Panel from '../ui/Panel.vue';
import Badge from '../ui/Badge.vue';
import Chip from '../ui/Chip.vue';
import Btn from '../ui/Btn.vue';
import Tip from '../ui/Tip.vue';
import Toggle from '../ui/Toggle.vue';
import FormError from '../ui/FormError.vue';

const props = defineProps({ node: Object, errors: Object, unavailable: Boolean, readonly: Boolean, act: Function, busyReason: Function });
const emit = defineEmits(['firewall']);
const page = usePage();
const can = computed(() => page.props.can);
const n = computed(() => props.node);

const hardenTip = computed(() => (n.value.access.hardened
    ? props.busyReason('Allow login as root and with a password again.')
    : n.value.access.hardenBlock
        ? `Unavailable: ${n.value.access.hardenBlock}`
        : props.busyReason('Turn off root and password login. Only SSH keys get in. sshd checks the change first.')));

const tailnetTip = computed(() => (n.value.access.tailnet
    ? props.busyReason('Take this server off the tailnet. Tailnet only is switched off first.')
    : !n.value.access.tailscaleKey
        ? 'Save a Tailscale auth key under Account, Providers first.'
        : props.busyReason("Install Tailscale and join the team's tailnet.")));

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
const fwSummary = computed(() => {
    const fw = n.value.access.firewall;
    if (!fw.enabled) {
        return 'everything the server listens on is open';
    }
    const who = (isPrivate, list) => (isPrivate ? 'the tailnet only' : list.length === 0 ? 'anywhere' : plural(list.length, 'address', 'addresses'));

    return `SSH from ${who(fw.ssh_private, fw.ssh)}, web from ${who(fw.web_private, fw.web)}`;
});
</script>

<template>
  <Panel title="Login and access" note="For you, when you need a shell. Unharbored never uses SSH to command this server.">
    <fieldset class="ro m-0 flex min-w-0 flex-col gap-3 border-0 p-0 disabled:opacity-70" :disabled="readonly">
      <div :class="row">
        <span class="text-muted">Login user <b class="font-mono font-semibold text-ink-strong">{{ n.access.user }}</b><template v-if="n.access.ssh"> <Chip class="font-mono">{{ n.access.ssh }}</Chip></template> <span v-if="!n.access.ready" :class="note">· created when you install a key or Repair stack</span></span>
      </div>
      <SudoPassword v-if="can.infrastructure" :node="n.id" :tip="n.tips.rotate" rotatable :rotate-disabled="unavailable" @rotate="act('sudo/rotate', {}, `Make a new sudo password for ${n.name}? The old one stops working.`)" />
      <div :class="row">
        <span class="text-muted">Lock down SSH <span :class="note">· no login as root, no passwords: keys only</span></span>
        <span class="flex flex-wrap items-center gap-2">
          <Badge :status="n.access.hardened ? 'live' : 'neutral'" :plain="!n.access.hardened">{{ n.access.hardened ? 'Locked down' : 'Off' }}</Badge>
          <Tip v-if="n.access.hardened" :title="hardenTip"><Btn small :disabled="unavailable" @click="act('harden', { on: false })">Allow again</Btn></Tip>
          <Tip v-else :title="hardenTip"><Btn small :disabled="n.access.hardenBlock !== null || unavailable" @click="act('harden', { on: true }, `Turn off root and password login on ${n.name}? Only SSH keys will get in. Check first that you can log in as ${n.access.user} with your key: if you cannot, you will need your provider's console to get back in.`)">Lock down</Btn></Tip>
        </span>
      </div>
      <FormError :message="errors.harden" />
      <div :class="row">
        <span class="text-muted">Tailscale <span :class="note">· <template v-if="n.access.tailnet">on the tailnet as <span class="font-mono">{{ n.access.tailnet.name }}</span> ({{ n.access.tailnet.address }}). Who may reach it is set in Tailscale.</template><template v-else-if="n.access.tailscaleKey">not joined </template><template v-else> needs an auth key under Account, Providers </template></span></span>
        <span class="flex flex-wrap gap-2">
          <Tip v-if="n.access.tailnet" :title="tailnetTip"><Btn small :disabled="unavailable" @click="act('tailscale/leave', {}, `Take ${n.name} off the tailnet? Tailnet only is switched off first, so SSH and the web ports are open as the lists say.`)">Leave tailnet</Btn></Tip>
          <Tip v-else :title="tailnetTip"><Btn small :disabled="!n.access.tailscaleKey || unavailable" @click="act('tailscale/join')">Join tailnet</Btn></Tip>
        </span>
      </div>
      <FormError :message="errors.tailscale" />
      <div :class="row">
        <span class="text-muted">Firewall <span :class="note">· {{ fwSummary }}</span></span>
        <span class="flex flex-wrap items-center gap-2">
          <Badge :status="n.access.firewall.enabled ? 'live' : 'neutral'" :plain="!n.access.firewall.enabled">{{ n.access.firewall.enabled ? 'On' : 'Off' }}</Badge>
          <Btn small @click="emit('firewall')">Firewall settings</Btn>
        </span>
      </div>
    </fieldset>
    <div v-if="!can.infrastructure && can.console" class="pt-1"><SudoPassword :node="n.id" /></div>
  </Panel>

  <Panel title="SSH keys" note="The team's keys, and which of them can log in to this server">
    <fieldset class="ro m-0 flex min-w-0 flex-col gap-3 border-0 p-0 disabled:opacity-70" :disabled="readonly">
      <div v-for="key in n.access.keys" :key="key.id" :class="[row, 'border-t border-line-soft pt-3 first:border-t-0 first:pt-0']">
        <span><b class="font-semibold text-ink-strong">{{ key.name }}</b> <span class="font-mono" :class="note">{{ key.type }}</span></span>
        <span class="flex flex-wrap items-center gap-2">
          <Badge v-if="key.state === 'installed'" status="live">Installed</Badge><Badge v-else-if="key.state === 'pending'" status="running">Installing</Badge><Badge v-else-if="key.state === 'removing'" status="running">Removing</Badge><Badge v-else plain>Not installed</Badge>
          <Tip :title="n.tips.keys"><Toggle :checked="['installed', 'pending'].includes(key.state)" :label="`Installed on ${n.name}`" :disabled="unavailable" @click="act(`keys/${key.id}`, { installed: !['installed', 'pending'].includes(key.state) })" /></Tip>
        </span>
      </div>
      <span v-if="n.access.keys.length === 0" :class="note">No SSH keys yet. <a href="/account">Add one on the Account page</a>, then install it here.</span>
      <FormError :message="errors.access" />
    </fieldset>
  </Panel>
</template>
