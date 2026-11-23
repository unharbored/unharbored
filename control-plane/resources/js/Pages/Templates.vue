<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';

import Btn from '../Components/ui/Btn.vue';
import Chip from '../Components/ui/Chip.vue';
import Empty from '../Components/ui/Empty.vue';
import Field from '../Components/ui/Field.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';

defineOptions({ layout: Console });

const props = defineProps({ cards: Array, categories: Array, canInstall: Boolean, failures: Array });

const search = ref('');
const category = ref('');

const shown = computed(() => {
    const needle = search.value.toLowerCase();
    return props.cards.filter((c) => (category.value === '' || c.category === category.value)
        && (needle === '' || `${c.name} ${c.description} ${c.category}`.toLowerCase().includes(needle)));
});

const pick = (c) => { category.value = category.value === c ? '' : c; };
</script>

<template>
  <Head title="Templates" />
  <PageHead title="Templates" lede="Install a service on one of your servers. Each template says what it downloads, what it needs and what it creates before anything happens.">
    <template #crumbs><span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase"><Link href="/services">services</Link> <span class="text-[var(--sep)]" aria-hidden="true">/</span> templates</span></template>
    <template #actions><Btn href="/apps/new">Or deploy an application</Btn></template>
  </PageHead>

  <Panel title="Catalogue">
    <Field label="Search" v-slot="{ id }"><input :id="id" v-model="search" type="search" placeholder="storage, runner, git" autocomplete="off"></Field>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Categories">
      <button v-for="c in categories" :key="c" type="button" class="cursor-pointer rounded-full border border-line bg-[var(--neutral-bg)] px-[11px] py-[5px] font-mono text-[10.5px] tracking-[0.04em] whitespace-nowrap text-muted aria-pressed:border-accent-line aria-pressed:text-accent" :aria-pressed="category === c ? 'true' : 'false'" @click="pick(c)">{{ category === c ? '● ' : '' }}{{ c }}</button>
    </div>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-4">
      <article v-for="c in shown" :key="c.id" class="flex flex-col gap-4 overflow-hidden rounded-panel border border-line bg-panel px-5 pt-4 pb-[22px] shadow-panel sm:px-7 sm:pt-[18px] sm:pb-6" :aria-labelledby="`tpl-${c.id}`">
        <div class="flex justify-between gap-3"><h3 :id="`tpl-${c.id}`" class="m-0 text-base font-semibold text-ink-strong">{{ c.name }}</h3><Chip v-if="c.memory" title="Memory the server needs">{{ c.memory }} memory</Chip></div>
        <span class="text-[13px] text-faint">{{ c.category }} · version {{ c.version }}</span>
        <p class="m-0">{{ c.description }}</p>
        <div class="flex flex-wrap gap-2">
          <Chip v-if="c.runtime === 'native'">installs directly</Chip>
          <Chip v-else-if="c.runtime === 'container'">container</Chip>
          <Chip v-if="c.label" class="!text-running">{{ c.label }}<template v-if="c.tier === 'community'"> · {{ c.publisher }}</template></Chip>
        </div>
        <template v-if="c.state === 'ready'">
          <Btn v-if="canInstall" primary :href="`/services/new?template=${c.id}`">Install</Btn>
          <span v-else class="text-[13px] text-faint">A Manager installs services.</span>
        </template>
        <template v-else-if="c.state === 'needs-containers'">
          <Btn v-if="canInstall" href="/servers">Prepare a server for containers first</Btn>
          <span v-else class="text-[13px] text-faint">Needs a server prepared for containers. A Manager prepares one.</span>
        </template>
        <Btn v-else-if="c.state === 'container'" disabled>Coming with container support</Btn>
        <Btn v-else disabled>Not yet available</Btn>
      </article>
      <Empty v-if="shown.length === 0">No template matches.</Empty>
    </div>
  </Panel>

  <Panel v-if="failures.length > 0" title="Templates that could not be loaded" note="Only Managers see this">
    <ul class="m-0 list-none p-0">
      <li v-for="(f, i) in failures" :key="i" class="text-failed">{{ f.source }}<template v-if="f.file !== ''"> · {{ f.file }}</template> <template v-if="f.line">line {{ f.line }}</template>: {{ f.reason }}</li>
    </ul>
  </Panel>
</template>
