<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { usePolling } from '../composables/usePolling';
import { usePost } from '../composables/usePost';
import { useConfirmedPost } from '../composables/useConfirmedPost';
import PageHead from '../Components/ui/PageHead.vue';
import Btn from '../Components/ui/Btn.vue';
import Empty from '../Components/ui/Empty.vue';
import Crumbs from '../Components/ui/Crumbs.vue';
import DeployList from '../Components/Site/DeployList.vue';
import DeployRow from '../Components/Site/DeployRow.vue';

defineOptions({ layout: Console });

const props = defineProps({ view: Object });
const v = computed(() => props.view);

// Polling reloads the current address, so "Show 25 more" (which puts the limit in it) is kept.
usePolling(['view'], 3000);

const send = usePost();
const post = (url) => send(url, { site: v.value.id });

const ask = useConfirmedPost(post);
const deploy = () => ask({ message: `Deploy ${v.value.branch} to ${v.value.name}?`, ok: 'Deploy' }, '/site/deploy');
const rollback = (release) => ask({ message: `Roll ${v.value.name} back to ${release}?`, ok: 'Roll back' }, '/site/rollback');
function more() {
    router.get('/site/deployments', { site: v.value.id, limit: v.value.rows.length + 25 }, { preserveScroll: true, preserveState: true, only: ['view'] });
}
</script>

<template>
  <Head title="Deployments" />
  <PageHead v-if="v.missing" title="No such site">
    <template #crumbs><Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }]" /></template>
  </PageHead>
  <template v-else>
    <PageHead title="Deployments" :lede="`Every deploy and rollback of ${v.name}, newest first. The newest five releases are kept on the server, so only the previous one can be rolled back to.`">
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <Btn v-if="v.running" :href="`/operation?operation=${v.running.id}`" inertia><span class="text-running">▸</span> {{ v.running.title }}</Btn>
          <Btn v-else-if="v.canDeploy" primary @click="deploy">↑ Deploy</Btn>
        </div>
      </template>
    </PageHead>

    <section aria-labelledby="dep-h" class="flex flex-col gap-3">
      <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5"><h2 id="dep-h" class="m-0 font-display text-[22px] font-semibold text-ink-strong">{{ v.total }} {{ v.total === 1 ? 'deployment' : 'deployments' }}</h2><span class="text-[13px] text-faint">Showing {{ v.rows.length }}</span></div>
      <DeployList>
        <DeployRow v-for="r in v.rows" :key="r.id" :state="r.state" :label="r.label || 'Done'" :commit="r.commit" :href="`/operation?operation=${r.id}`" :text="r.kind === 'rollback' ? 'Rolled back to ' + (r.release || 'the previous release') : (r.release ?? 'Deploy')" :badge="r.label" :badge-status="r.class">
          <template #meta>
            <span>from <code>{{ v.branch }}</code> · {{ r.by }}</span>
            <span>{{ r.ago }}<template v-if="r.took"> · {{ r.took }}</template></span>
          </template>
          <Btn v-if="r.rollback && v.canDeploy" small @click="rollback(r.release)">↺ Roll back</Btn>
        </DeployRow>
        <li v-if="v.rows.length === 0"><Empty>No deploys yet.</Empty></li>
      </DeployList>
      <div v-if="v.total > v.rows.length"><Btn small @click="more">Show 25 more</Btn></div>
    </section>
  </template>
</template>
