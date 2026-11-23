<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '../Components/ui/Badge.vue';
import Btn from '../Components/ui/Btn.vue';
import DataTable from '../Components/ui/DataTable.vue';
import Empty from '../Components/ui/Empty.vue';
import Lede from '../Components/ui/Lede.vue';
import Panel from '../Components/ui/Panel.vue';
import Console from '../Layouts/Console.vue';
import PageHead from '../Components/ui/PageHead.vue';
import { usePolling } from '../composables/usePolling';

defineOptions({ layout: Console });

defineProps({ filter: Object, services: Array });

usePolling(['services', 'filter'], 3000);

const serverName = (node) => node.split('.')[0] || node;
const statusOf = (s) => (s.status === 'running' ? 'live' : ['installing', 'removing'].includes(s.status) ? 'running' : s.status === 'failed' ? 'failed' : 'neutral');
</script>

<template>
  <Head title="Services" />
  <PageHead title="Services">
    <Lede v-if="filter">Project: <b class="font-semibold text-ink-strong">{{ filter.name }}</b> · <a href="/services?project=">Show all projects</a></Lede>
    <Lede>{{ services.length }} {{ services.length === 1 ? 'service' : 'services' }}. Programs such as object storage that Unharbored installs and runs on your servers from a template.</Lede>
    <template #actions>
      <div class="flex flex-wrap gap-2">
        <Btn primary inertia href="/templates">+ Install from a template</Btn>
      </div>
    </template>
  </PageHead>

  <Panel :title="filter ? filter.name : 'All services'" :note="`${services.length} rows`" flush>
    <DataTable>
      <thead><tr><th scope="col">Service</th><th scope="col">Template</th><th scope="col">Server</th><th scope="col">Project</th><th scope="col">Status</th><th scope="col">Addresses</th><th scope="col">Server load</th></tr></thead>
      <tbody>
        <tr v-for="s in services" :key="s.id">
          <td><a class="font-semibold text-ink-strong" :href="`/service?service=${s.id}`"><b>{{ s.name }}</b></a></td>
          <td>{{ s.template }} <span class="text-[13px] text-faint">v{{ s.version }}</span></td>
          <td><a href="/servers">{{ serverName(s.server) }}</a></td>
          <td>{{ s.project?.name ?? '' }}</td>
          <td>
            <div class="flex flex-wrap items-center gap-2">
              <Badge :status="statusOf(s)">{{ s.status }}</Badge>
              <a v-if="s.update && s.update.allowed" class="no-underline" :href="`/service?service=${s.id}#update`"><Badge>update to v{{ s.update.version }}</Badge></a>
              <a v-if="s.operation" class="text-[13px] text-running" :href="`/operation?operation=${s.operation.id}`">operation {{ s.operation.id }}</a>
            </div>
          </td>
          <td>
            <div class="flex flex-col gap-[3px]">
              <span v-for="e in s.endpoints" :key="e.domain" class="text-[13px]">{{ e.tls ? 'https://' : 'http://' }}{{ e.domain }}</span>
            </div>
          </td>
          <td class="text-[13px] text-muted"><template v-if="s.memory_used !== null">memory {{ s.memory_used }}% used · {{ s.disk_free }} disk free</template><template v-else>no report yet</template></td>
        </tr>
        <tr v-if="services.length === 0"><td colspan="7"><Empty>No services yet. <Link href="/templates">Install one from the catalogue.</Link></Empty></td></tr>
      </tbody>
    </DataTable>
  </Panel>
</template>
