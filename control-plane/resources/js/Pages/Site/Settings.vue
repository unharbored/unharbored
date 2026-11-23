<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import Btn from '../../Components/ui/Btn.vue';
import Notice from '../../Components/ui/Notice.vue';
import PageHead from '../../Components/ui/PageHead.vue';
import NavItem from '../../Components/SiteSettings/NavItem.vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

defineOptions({ layout: Console });

const props = defineProps({ missing: Boolean, tab: String, site: Object, data: Object });

const page = usePage();
const canEdit = computed(() => page.props.can.edit);

// One component per tab, loaded when its tab opens.
const tabs = {
    general: defineAsyncComponent(() => import('../../Components/SiteSettings/General.vue')),
    env: defineAsyncComponent(() => import('../../Components/SiteSettings/Env.vue')),
    domain: defineAsyncComponent(() => import('../../Components/SiteSettings/Domain.vue')),
    database: defineAsyncComponent(() => import('../../Components/SiteSettings/Database.vue')),
    servers: defineAsyncComponent(() => import('../../Components/SiteSettings/Servers.vue')),
    processes: defineAsyncComponent(() => import('../../Components/SiteSettings/Processes.vue')),
    build: defineAsyncComponent(() => import('../../Components/SiteSettings/Build.vue')),
    scheduler: defineAsyncComponent(() => import('../../Components/SiteSettings/Scheduler.vue')),
    web: defineAsyncComponent(() => import('../../Components/SiteSettings/Web.vue')),
    script: defineAsyncComponent(() => import('../../Components/SiteSettings/Script.vue')),
    runtime: defineAsyncComponent(() => import('../../Components/SiteSettings/Runtime.vue')),
    previews: defineAsyncComponent(() => import('../../Components/SiteSettings/Previews.vue')),
    source: defineAsyncComponent(() => import('../../Components/SiteSettings/Source.vue')),
    delete: defineAsyncComponent(() => import('../../Components/SiteSettings/Delete.vue')),
};

// The small section headings in the side menu.
const navLabel = 'px-2.5 pt-3.5 pb-1.5 font-mono text-[10px] tracking-[0.18em] text-muted uppercase max-[720px]:hidden';
const href = (tab) => `/site/settings?site=${props.site.id}&tab=${tab}`;
const container = computed(() => ['dockerfile', 'image'].includes(props.site.runtime));
const can = (capability) => (props.site.capabilities ?? []).includes(capability);
const isPreview = computed(() => !!props.site.parent);

const { ask, send } = useSiteSettings(() => props.site?.id);

async function deployNow() {
    if (await ask({ title: 'Deploy now?', message: `Deploy ${props.site.branch} to ${props.site.name} now?`, ok: 'Deploy' })) {
        send('deploy');
    }
}
</script>

<template>
  <Head title="Settings" />
  <PageHead v-if="missing" title="No such site">
    <template #crumbs><span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase"><a href="/sites">sites</a></span></template>
  </PageHead>
  <template v-else>
    <p v-if="isPreview" class="m-0 max-w-[64ch] leading-[1.65] text-muted">A preview of <Link :href="`/site/settings?site=${site.parent.id}&tab=previews`">{{ site.parent.name }}</Link> at {{ site.branch }}{{ site.previewIdle ? `, deleted after ${site.previewIdle} days without a deploy` : ', kept until deleted' }}.</p>

    <Notice v-if="site.pending" tone="plain">
      <span><b>! pending</b> &nbsp;Changes saved {{ site.pendingSince }} reach the server on the next deploy.</span>
      <Btn small primary @click="deployNow">Deploy now</Btn>
    </Notice>

    <Notice v-if="!canEdit" tone="plain">You can read these settings but not change them. Changing an application needs a Developer, and some settings need a Manager.</Notice>
    <div class="flex flex-wrap items-start gap-8">
      <nav class="sticky top-4 flex max-w-[220px] flex-[1_1_190px] flex-col gap-0.5 max-[720px]:static max-[720px]:max-w-none max-[720px]:flex-row max-[720px]:flex-wrap" aria-label="Settings sections">
        <h1 class="m-0 mb-3.5 px-2.5 font-display text-[28px] leading-[1.05] font-semibold tracking-[-0.02em] text-ink-strong max-[720px]:basis-full">Settings</h1>
        <NavItem v-if="!isPreview && site.projects.length > 1" :href="href('general')" :current="tab === 'general'">General</NavItem>
        <span :class="navLabel">Environment</span>
        <NavItem :href="href('env')" :current="tab === 'env'">Variables</NavItem>
        <NavItem :href="href('domain')" :current="tab === 'domain'">Domain &amp; HTTPS</NavItem>
        <NavItem :href="href('database')" :current="tab === 'database'">Database &amp; backups</NavItem>
        <template v-if="container">
          <!-- A container application is one container on one server: no scaling, workers, build presets, scheduler, server script or previews. -->
          <NavItem plain :href="`/site/container?site=${site.id}`">Container</NavItem>
          <NavItem :href="href('web')" :current="tab === 'web'">Web server</NavItem>
        </template>
        <template v-else>
          <NavItem :href="href('servers')" :current="tab === 'servers'">Servers &amp; scaling</NavItem>
          <NavItem :href="href('processes')" :current="tab === 'processes'">Workers &amp; processes</NavItem>
          <NavItem :href="href('build')" :current="tab === 'build'">Type &amp; build</NavItem>
          <NavItem v-if="can('scheduler')" :href="href('scheduler')" :current="tab === 'scheduler'">Scheduler</NavItem>
          <NavItem :href="href('web')" :current="tab === 'web'">Web server</NavItem>
          <NavItem :href="href('script')" :current="tab === 'script'">Server script</NavItem>
          <NavItem v-if="can('php-version')" :href="href('runtime')" :current="tab === 'runtime'">PHP version</NavItem>
          <NavItem v-if="!isPreview" :href="href('previews')" :current="tab === 'previews'">Previews</NavItem>
        </template>
        <span :class="navLabel">Application</span>
        <NavItem v-if="site.runtime !== 'image'" :href="href('source')" :current="tab === 'source'">Repository &amp; Git</NavItem>
        <NavItem v-if="!isPreview" :href="href('delete')" :current="tab === 'delete'">Delete application</NavItem>
      </nav>
      <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-6">
        <fieldset class="m-0 min-w-0 border-0 p-0 disabled:opacity-70" :disabled="!canEdit">
          <component :is="tabs[tab]" v-if="tabs[tab]" :key="`${site.id}-${tab}`" :site="site" :data="data" />
        </fieldset>
      </div>
    </div>
  </template>
</template>
