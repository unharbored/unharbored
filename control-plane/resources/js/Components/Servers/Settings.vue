<script setup>
import { computed } from 'vue';
import { statusClass } from './status.js';
import { note } from './serverActions.js';
import Tip from '../ui/Tip.vue';
import Btn from '../ui/Btn.vue';
import Badge from '../ui/Badge.vue';
import FormError from '../ui/FormError.vue';

const props = defineProps({ node: Object, errors: Object, unavailable: Boolean, readonly: Boolean, act: Function });
const n = computed(() => props.node);
const blockerLines = computed(() => [...new Set([...n.value.blockers, ...(n.value.stale ? [] : n.value.destroyBlockers)])]);
const provider = computed(() => n.value.compute.providerLabel ?? 'the provider');

const set = 'flex flex-wrap items-center justify-between gap-4 border-b border-line-soft px-[18px] py-4 last:border-b-0';
const title = 'm-0 font-semibold text-ink-strong';
</script>

<template>
  <fieldset class="ro m-0 flex min-w-0 flex-col gap-5 border-0 p-0 disabled:opacity-70" :disabled="readonly">
    <div class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
      <div :class="set">
        <div><p :class="title">Server name</p><p :class="note">The name {{ n.name }} is fixed while applications run on it, and is used to identify it everywhere.</p></div>
        <span class="font-mono text-sm text-muted">{{ n.name }}</span>
      </div>
      <template v-if="!n.stale">
        <div :class="set">
          <div><p :class="title">Agent</p><p :class="note">{{ n.agent ?? 'unknown' }}<template v-if="n.upgrade"> · a newer build is available</template><template v-else> · up to date</template>. Last seen {{ n.lastSeen }}.</p></div>
          <Tip v-if="n.upgrade" :title="n.tips.upgrade"><Btn small primary :disabled="unavailable" @click="act('upgrade-agent', {}, `Upgrade the agent on ${n.name}? It restarts and reconnects.`)">Upgrade agent</Btn></Tip>
          <Tip v-else :title="n.tips.current"><Btn small aria-disabled="true">Agent up to date</Btn></Tip>
        </div>
        <div :class="set">
          <div><p :class="title">Repair stack</p><p :class="note">Re-apply the base server software (nginx, PHP-FPM, Composer, git, supervisor). Steps already in place change nothing, and your apps and data are not touched.</p></div>
          <Tip :title="n.tips.repair"><Btn small :disabled="unavailable" @click="act('repair', {}, `Re-apply the server stack on ${n.name}? Steps already in place change nothing.`)">Repair stack</Btn></Tip>
        </div>
      </template>
    </div>

    <div v-if="n.ops.length > 0" class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
      <div v-for="op in n.ops" :key="op.id" class="flex justify-between gap-3 border-b border-line-soft px-[18px] py-2.5 text-[13px] last:border-b-0">
        <a :href="`/operation?operation=${op.id}`">{{ op.title }}</a><span :class="statusClass(op.status)">{{ op.status }} · {{ op.ago }}</span>
      </div>
    </div>

    <h3 class="m-0 -mb-2 font-body text-[15px] font-semibold text-failed">Danger</h3>
    <div class="overflow-hidden rounded-panel border border-failed-line bg-failed-bg">
      <div v-if="!n.connected && !n.stale" class="border-b border-failed-line px-[18px] py-3" :class="note">Not connected: Repair stack and Upgrade agent need the agent online.</div>
      <div v-if="blockerLines.length > 0" class="border-b border-failed-line px-[18px] py-3" :class="note"><div v-for="why in blockerLines" :key="why">• {{ why }}</div></div>
      <template v-if="n.stale">
        <div :class="set">
          <div><p :class="title">Remove stale entry <Badge status="failed">Droplet deleted</Badge></p><p :class="note">Its server no longer exists at {{ provider }}. Removing the entry revokes its certificate and forgets the record. Nothing is sent to the machine.</p></div>
          <Tip :title="n.tips.removeStale"><Btn small danger :disabled="n.blockers.length > 0 || n.busy" @click="act('remove-stale', {}, `Remove the stale record for ${n.name}? Its server is already gone at ${provider}; this revokes its certificate and removes the entry. Nothing is sent to the machine.`, 'danger')">Remove stale entry</Btn></Tip>
        </div>
      </template>
      <template v-else>
        <div :class="set">
          <div><p :class="title">Disconnect</p><p :class="note">Uninstall the agent and revoke its certificate. The machine keeps running and billing.</p></div>
          <Tip :title="n.tips.disconnect"><Btn small danger :disabled="n.blockers.length > 0 || n.busy" @click="act('disconnect', {}, `Disconnect ${n.name} from Unharbored? Its agent is uninstalled if reachable and its certificate revoked. The machine keeps running and billing.`)">Disconnect</Btn></Tip>
        </div>
        <div :class="set">
          <div><p :class="title">Destroy server</p><p :class="note">Deletes the server at {{ provider }} and stops billing. Cannot be undone. Blocked while applications run on it.</p></div>
          <Tip :title="n.tips.destroy"><Btn small danger :disabled="n.destroyBlockers.length > 0" @click="act('destroy', {}, `Destroy ${n.name} at ${provider}? This deletes the server and stops its billing. It cannot be undone.`, 'danger', 'Destroy server')">Destroy server</Btn></Tip>
        </div>
      </template>
    </div>
    <FormError :message="errors.remove" />
    <FormError :message="errors.destroy" />
  </fieldset>
</template>
