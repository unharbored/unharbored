<script setup>
import { Head } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Badge from '../Components/ui/Badge.vue';
import Btn from '../Components/ui/Btn.vue';
import Check from '../Components/ui/Check.vue';
import DataTable from '../Components/ui/DataTable.vue';
import Empty from '../Components/ui/Empty.vue';
import Field from '../Components/ui/Field.vue';
import FormRow from '../Components/ui/FormRow.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { useFilters } from '../composables/useFilters';

defineOptions({ layout: Console });

const props = defineProps({ filters: Object, records: Object, chain: Object });

const form = useFilters(props.filters, ['filters', 'records']);
</script>

<template>
  <Head title="Audit log" />
  <PageHead title="Audit log" lede="Every change and every secret read, append-only and hash-chained.">
    <template #actions>
      <template v-if="chain">
        <Badge v-if="chain.intact" status="live" role="status">Chain intact · {{ chain.checked }} records</Badge>
        <Badge v-else status="failed" role="alert">Chain broken · {{ chain.describe }}</Badge>
      </template>
    </template>
  </PageHead>

  <FormRow role="search" @submit.prevent>
    <Field label="Search" class="!flex-[2_1_240px]" v-slot="{ id }"><input :id="id" v-model="form.q" type="search" placeholder="site.deployed, web-1, 2f09d0d…"></Field>
    <Field label="Resource" v-slot="{ id }">
      <select :id="id" v-model="form.resource">
        <option value="">All</option><option value="site">Sites</option><option value="node">Nodes</option>
        <option value="credential">Credentials</option><option value="github_app">GitHub</option><option value="repository">Repositories</option>
      </select>
    </Field>
    <Check v-model="form.failed" class="self-end">Failed only</Check>
  </FormRow>

  <Panel title="Records" flush>
    <template #note>{{ records.rows.length }} of {{ records.total }}</template>
    <DataTable>
      <thead><tr><th scope="col">#</th><th scope="col">When</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Resource</th><th scope="col">Detail</th><th scope="col">Hash</th></tr></thead>
      <tbody>
        <tr v-for="r in records.rows" :key="r.seq">
          <td class="text-faint">{{ r.seq }}</td>
          <td class="whitespace-nowrap">{{ r.when }}</td>
          <td>{{ r.actor }}</td>
          <td :class="r.failed ? 'text-failed' : 'font-semibold text-ink-strong'">{{ r.action }}</td>
          <td class="text-muted">{{ r.resource }}</td>
          <td class="text-muted">{{ r.detail }}</td>
          <td class="whitespace-nowrap text-faint">{{ r.hash }}</td>
        </tr>
        <tr v-if="records.rows.length === 0"><td colspan="7"><Empty>No records match.</Empty></td></tr>
      </tbody>
    </DataTable>
  </Panel>
  <div v-if="records.rows.length < records.total" class="flex items-center justify-between gap-3">
    <span class="text-faint">Showing {{ records.rows.length }} of {{ records.total }}</span>
    <Btn @click="form.limit += 50">Load older</Btn>
  </div>
</template>
