<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { serverUrl } from './api.js';
import Field from '../ui/Field.vue';
import { statusClass } from './status.js';
import { note } from './serverActions.js';
import Tip from '../ui/Tip.vue';
import Btn from '../ui/Btn.vue';
import Badge from '../ui/Badge.vue';
import FormError from '../ui/FormError.vue';

const props = defineProps({ node: Object, underneath: Object, errors: Object, unavailable: Boolean, readonly: Boolean, act: Function });
const n = computed(() => props.node);
const blockerLines = computed(() => [...new Set([...n.value.blockers, ...(n.value.stale ? [] : n.value.destroyBlockers)])]);
const provider = computed(() => n.value.compute.providerLabel ?? 'the provider');

const providerBill = computed(() => (n.value.compute.providerLabel
    ? `It keeps costing money at ${n.value.compute.providerLabel}.`
    : 'It is a server of your own: it is yours already.'));
const archiveMessage = computed(() => {
    const apps = n.value.archivesWith;
    const alongside = apps.length > 0 ? ` Its ${apps.length === 1 ? 'application' : `${apps.length} applications`} (${apps.join(', ')}) ${apps.length === 1 ? 'is' : 'are'} archived with it.` : '';

    return `Unharbored stops managing ${n.value.name}. It keeps running and nothing is deleted.${alongside} You can restore it any time.`;
});

const reviewing = ref(false);
const typed = ref('');
const choices = reactive({});
const u = computed(() => props.underneath);
const stuck = computed(() => (u.value?.blockers ?? []).length > 0);

/** Opens the review with each item on its safest choice: a backup first when one is offered. */
function review() {
    for (const item of u.value.items) {
        if (item.choices.length > 0) choices[item.key] = item.choices[0].value;
    }
    typed.value = '';
    reviewing.value = true;
}

function remove() {
    router.post(serverUrl(n.value.id, 'delete'), { confirm: typed.value, choices: { ...choices } }, { preserveScroll: true });
}

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
          <div>
            <p :class="title">{{ n.archived ? 'Archived' : 'Archive server' }}</p>
            <p v-if="n.archived" :class="note">Unharbored no longer manages {{ n.name }}. It keeps running as it was. Restore brings it back under management; nothing is sent to the machine.</p>
            <template v-else>
              <p :class="note">Unharbored stops managing it. It keeps running and nothing is deleted. {{ providerBill }}</p>
              <p :class="note">Unharbored stops: monitoring and its alerts, backups, scaling, agent upgrades, deploys of its applications, and firewall changes.</p>
              <p :class="note">Its applications are archived with it<template v-if="n.archivesWith.length > 0"> ({{ n.archivesWith.join(', ') }})</template>, so the certificate renewal Unharbored does for them stops too: their certificates may expire unless the server renews them itself. Restoring the server restores them. A database server that applications on other servers use cannot be archived.</p>
              <p :class="note">You can delete it from here later too.</p>
            </template>
          </div>
          <Btn v-if="n.archived" small @click="act('restore')">Restore</Btn>
          <Btn v-else small @click="act('archive', {}, archiveMessage, 'info', 'Archive server')">Archive server</Btn>
        </div>
        <div :class="set">
          <div><p :class="title">Disconnect</p><p :class="note">Uninstall the agent and revoke its certificate. The machine keeps running and billing.</p></div>
          <Tip :title="n.tips.disconnect"><Btn small danger :disabled="n.blockers.length > 0 || n.busy" @click="act('disconnect', {}, `Disconnect ${n.name} from Unharbored? Its agent is uninstalled if reachable and its certificate revoked. The machine keeps running and billing.`)">Disconnect</Btn></Tip>
        </div>
        <div :class="set">
          <div><p :class="title">Delete server</p><p :class="note">Deletes {{ n.name }} and everything on it. You see the whole list and decide before anything happens. Cannot be undone.</p></div>
          <Btn small danger :disabled="!u || n.busy || reviewing" @click="review">Review and delete</Btn>
        </div>
        <div v-if="reviewing && u" class="flex flex-col gap-4 border-t border-failed-line px-[18px] py-4">
          <h4 class="m-0 font-body text-[15px] font-semibold text-ink-strong">Deleting {{ u.server }} also deletes:</h4>
          <div v-if="stuck" class="rounded-panel border border-failed-line px-3 py-2 text-failed">
            <p class="m-0 font-semibold">Resolve these first. Nothing is deleted until they are.</p>
            <div v-for="why in u.blockers" :key="why">• {{ why }}</div>
          </div>
          <p v-if="u.items.length === 0" :class="note">Nothing else is on this server.</p>
          <div v-for="item in u.items" :key="item.key" class="flex flex-col gap-1 border-b border-line-soft pb-3 last:border-b-0">
            <p :class="title">{{ item.name }} <Badge>{{ item.type.replaceAll('_', ' ') }}</Badge> <Badge v-if="item.costs" status="failed">{{ item.costNote }}</Badge></p>
            <p :class="note">{{ item.does }}</p>
            <p v-if="item.choices.length > 1" :class="note">{{ item.detail }}</p>
            <label v-for="c in item.choices" :key="c.value" class="inline-flex min-h-8 cursor-pointer items-center gap-2.5" v-show="item.choices.length > 1">
              <input type="radio" :name="item.key" :value="c.value" v-model="choices[item.key]"> {{ c.label }}
            </label>
            <p v-if="item.choices.length === 1" :class="note">{{ item.choices[0].label }}. {{ item.detail }}</p>
            <p v-if="item.choices.length === 0" :class="note">{{ item.detail }}</p>
          </div>
          <div class="flex flex-col gap-1">
            <p :class="title">What this does</p>
            <p :class="note">{{ u.summary.server }}</p>
            <p v-for="line in u.summary.stops" :key="line" :class="note">Stops billing: {{ line }}</p>
            <p v-for="line in u.summary.keeps" :key="line" class="m-0 text-[13px] font-semibold text-failed">Keeps billing: {{ line }}</p>
          </div>
          <Field :label="`Type ${u.server} to confirm`" v-slot="{ id }"><input :id="id" v-model="typed" autocomplete="off" spellcheck="false"></Field>
          <div class="flex gap-3">
            <Btn small danger :disabled="stuck || typed !== u.server" @click="remove">Delete {{ u.server }}</Btn>
            <Btn small @click="reviewing = false">Cancel</Btn>
          </div>
        </div>
      </template>
    </div>
    <FormError :message="errors.remove" />
    <FormError :message="errors.destroy" />
    <FormError :message="errors.delete" />
    <FormError :message="errors.archive" />
  </fieldset>
</template>
