<script setup>
import { computed, ref } from 'vue';
import { statusClass } from './status.js';
import ComputeDrawer from './ComputeDrawer.vue';
import SoftwareDrawer from './SoftwareDrawer.vue';
import InfoCard from '../ui/InfoCard.vue';

const props = defineProps({ node: Object, phpVersions: Array, errors: Object, readonly: Boolean, act: Function, clear: Function });
const emit = defineEmits(['firewall']);
const n = computed(() => props.node);
const drawer = ref(null);

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
const who = (isPrivate, list) => (isPrivate ? 'tailnet only' : list.length === 0 ? 'anyone' : plural(list.length, 'address', 'addresses'));
const firewall = computed(() => {
    const fw = n.value.access.firewall;

    return [
        ['Status', fw.enabled ? 'On' : 'Off', fw.enabled ? 'good' : 'faint'],
        ['SSH', fw.enabled ? who(fw.ssh_private, fw.ssh) : 'open', fw.enabled ? undefined : 'faint'],
        ['Web ports', fw.enabled ? who(fw.web_private, fw.web) : 'open', fw.enabled ? undefined : 'faint'],
        ['Tailscale', n.value.access.tailnet ? n.value.access.tailnet.name : 'not joined', n.value.access.tailnet ? 'good' : 'faint'],
    ];
});
const m = computed(() => n.value.metrics);
const compute = computed(() => [
    ['Size', n.value.compute.sizeLabel ?? n.value.compute.size ?? '—'],
    ['CPU', m.value ? `${m.value.cpu}%` : '—'],
    ['Memory', m.value ? `${m.value.mem ?? '?'}%` : '—'],
    ['Disk', m.value ? `${m.value.disk ?? '?'}% · ${m.value.diskFree}` : '—'],
]);
const software = computed(() => [
    ['PHP', n.value.php.join(' · ')],
    ['PostgreSQL', n.value.services.includes('pgsql') ? 'installed' : 'not installed', n.value.services.includes('pgsql') ? 'good' : 'faint'],
    ['MySQL', n.value.services.includes('mysql') ? 'installed' : 'not installed', n.value.services.includes('mysql') ? 'good' : 'faint'],
    ['Valkey', n.value.services.includes('valkey') ? 'installed' : 'not installed', n.value.services.includes('valkey') ? 'good' : 'faint'],
]);
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <InfoCard title="Compute" action="Edit" :rows="compute" @open="drawer = 'compute'" />
    <InfoCard title="Firewall" action="Edit" :rows="firewall" @open="emit('firewall')" />
    <InfoCard title="Applications" :count="n.sites.length">
      <div v-for="s in n.sites" :key="s.id" class="border-b border-line-soft px-[18px] py-2.5 text-[13px] last:border-b-0"><a :href="`/site?site=${s.id}`">{{ s.name }}</a></div>
      <div v-if="n.sites.length === 0" class="px-[18px] py-3 text-[13px] text-faint">No applications on this server.</div>
    </InfoCard>
    <InfoCard title="Software" action="Edit" :rows="software" @open="drawer = 'software'" />
  </div>

  <section v-if="n.ops.length > 0" class="flex flex-col gap-2.5">
    <h3 class="m-0 font-body text-[15px] font-semibold text-ink-strong">Recent activity on this server</h3>
    <ul class="m-0 list-none overflow-hidden rounded-panel border border-line bg-panel p-0">
      <li v-for="op in n.ops" :key="op.id" class="flex justify-between gap-3 border-b border-line-soft px-[18px] py-2.5 text-[13px] last:border-b-0">
        <a :href="`/operation?operation=${op.id}`">{{ op.title }}</a>
        <span :class="statusClass(op.status)">{{ op.status }} · {{ op.ago }}</span>
      </li>
    </ul>
  </section>

  <ComputeDrawer :open="drawer === 'compute'" :node="n" @close="drawer = null" />
  <SoftwareDrawer :open="drawer === 'software'" :node="n" :php-versions="phpVersions" :errors="errors" :readonly="readonly" :act="act" @close="drawer = null" @saved="clear" />
</template>
