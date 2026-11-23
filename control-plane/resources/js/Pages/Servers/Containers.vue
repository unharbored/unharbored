<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import { usePolling } from '../../composables/usePolling.js';
import Panel from '../../Components/ui/Panel.vue';
import PageHead from '../../Components/ui/PageHead.vue';
import Notice from '../../Components/ui/Notice.vue';
import Btn from '../../Components/ui/Btn.vue';
import Badge from '../../Components/ui/Badge.vue';
import DataTable from '../../Components/ui/DataTable.vue';
import FormError from '../../Components/ui/FormError.vue';
import Crumbs from '../../Components/ui/Crumbs.vue';
import { useKeptErrors } from '../../Components/Servers/api.js';

defineOptions({ layout: Console });

const props = defineProps({ node: String, view: Object });
const page = usePage();
const can = computed(() => page.props.can);
const { errors, clearErrors } = useKeptErrors();
const acknowledgeSmall = ref(false);

usePolling(['view'], 5000);

const post = (action, data = {}) => {
    clearErrors();
    router.post(`/server/containers/${action}`, { node: props.node, ...data }, { preserveScroll: true });
};
</script>

<template>
  <Head title="Containers" />
  <PageHead v-if="view.missing" title="No such server">
    <template #crumbs><Crumbs :items="[{ label: 'servers', href: '/servers', inertia: true }]" /></template>
  </PageHead>
  <template v-else>
    <PageHead :title="`Containers on ${view.name}`" lede="Container services run under Docker, each in its own container, behind the web server. Only Unharbored drives Docker here.">
      <template #crumbs><Crumbs :items="[{ label: 'servers', href: '/servers', inertia: true }, { label: view.name }, { label: 'containers' }]" /></template>
    </PageHead>

    <Notice v-if="view.running" tone="plain">An operation is running on this server. <a :href="`/operation?operation=${view.running.id}`">{{ view.running.title }}</a></Notice>

    <Panel v-if="!view.status.prepared" title="Not prepared for containers">
        <p>Preparing installs Docker Engine from Docker's own signed repository (its signing key is checked against a pinned fingerprint), keeps containers running while Docker restarts, rotates its logs, and leaves no network port open. It does not touch your applications.</p>
        <Notice v-if="view.small" tone="plain" alert>This server has {{ view.memory }} of memory. Docker uses roughly 100 MB of it before any container runs (an estimate, not yet measured on a server this size).
          <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5"><input v-model="acknowledgeSmall" type="checkbox"> I accept this on a server this small</label></Notice>
        <FormError :message="errors.prepare" />
        <div v-if="can.infrastructure" class="flex flex-wrap gap-2"><Btn primary :disabled="view.running !== null || (view.small && !acknowledgeSmall)" @click="post('prepare', { acknowledgeSmall })">Prepare for containers</Btn></div>
        <span v-else class="text-[13px] text-faint">Preparing a server needs the Manager role in this team.</span>
    </Panel>
    <template v-else>
      <Panel :title="`Docker ${view.status.docker_version}`">
        <template #note><template v-if="view.status.read_ago">last read {{ view.status.read_ago }}</template><template v-else> not read yet </template></template>
          <FormError :message="errors.refresh" />
          <div class="flex flex-wrap gap-2"><Btn @click="post('refresh')">Refresh</Btn></div>
          <DataTable>
            <thead><tr><th scope="col">Container</th><th scope="col">Service</th><th scope="col">State</th><th scope="col">Memory</th><th scope="col">Image</th><th scope="col">Address</th></tr></thead>
            <tbody>
              <tr v-for="c in view.rows" :key="c.name">
                <td class="font-mono text-[13px]">{{ c.name }}</td>
                <td><a v-if="c.service" :href="`/service?service=${c.service.id}`"><b>{{ c.service.name }}</b></a><span v-else class="text-[13px] text-faint">not a known service</span></td>
                <td><Badge :status="c.state === 'running' ? 'live' : 'neutral'">{{ c.state }}</Badge> <span class="text-[13px] text-faint">{{ c.status }}</span></td>
                <td class="text-[13px]">{{ c.memory ?? 'not running' }}</td>
                <td class="font-mono text-[13px]">{{ c.image }}</td>
                <td class="font-mono text-[13px]"><template v-if="c.service">{{ c.service.addresses.join(', ') }}</template></td>
              </tr>
              <tr v-if="view.rows.length === 0"><td colspan="6" class="!px-6 !py-12 text-center font-display text-base font-medium text-faint italic">No containers here yet. <a href="/templates">Install a template that runs in a container.</a></td></tr>
            </tbody>
          </DataTable>
      </Panel>

      <Panel title="Disk used by Docker">
          <DataTable>
            <thead><tr><th scope="col">Kind</th><th scope="col">Count</th><th scope="col">Size</th><th scope="col">Reclaimable</th></tr></thead>
            <tbody>
              <tr v-for="d in view.status.df" :key="d.type"><td>{{ d.type }}</td><td>{{ d.total }}</td><td>{{ d.size }}</td><td>{{ d.reclaimable }}</td></tr>
              <tr v-if="view.status.df.length === 0"><td colspan="4" class="!px-6 !py-12 text-center font-display text-base font-medium text-faint italic">Not read yet. Use Refresh.</td></tr>
            </tbody>
          </DataTable>
          <p class="m-0 text-[13px] text-faint">Clean up removes only images nothing uses any more (dangling) and the build cache. It never touches volumes, running containers or the images they use. Docker updates and routine cleanup are part of the maintenance work planned in the roadmap.</p>
          <FormError :message="errors.cleanup" />
          <div v-if="can.infrastructure" class="flex flex-wrap gap-2"><Btn :disabled="view.running !== null" @click="post('cleanup')">Clean up unused layers</Btn></div>
      </Panel>
    </template>

    <Panel title="Not offered">
      <p class="m-0 text-[13px] text-faint">Privileged containers, access to the Docker socket and mounting the server's own folders are never available, to a template or to a person. Containers get named volumes only, and every port they publish is bound to 127.0.0.1.</p>
    </Panel>

    <Panel title="Recent operations" flush>
      <ol class="m-0 list-none py-1.5 pr-0 pl-0">
        <li v-for="o in view.operations" :key="o.id" class="flex flex-wrap gap-x-5 gap-y-1 px-[18px] py-2.5">
          <span class="flex-[0_0_96px] text-faint">{{ o.ago }}</span>
          <span class="flex-[1_1_320px]"><a :href="`/operation?operation=${o.id}`"><b>{{ o.title }}</b></a> <Badge :status="o.badge.class || 'neutral'">{{ o.badge.label }}</Badge></span>
        </li>
        <li v-if="view.operations.length === 0" class="px-6 py-12 text-center font-display text-base font-medium text-faint italic">Nothing has run here yet.</li>
      </ol>
    </Panel>
  </template>
</template>
