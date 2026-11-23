<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import BrandMark from '../Components/Shell/BrandMark.vue';
import FormError from '../Components/ui/FormError.vue';
import SwitchMenu from '../Components/Shell/SwitchMenu.vue';
import { selectClass } from '../Components/Shell/classes';

const page = usePage();
const routeName = computed(() => page.props.routeName);
// The tab a sub-page belongs to (the containers view is under Servers).
const sectionOf = { 'server.containers': 'servers', 'servers.connect': 'servers', 'servers.show': 'servers', 'servers.pool': 'servers' };
const section = computed(() => sectionOf[routeName.value] ?? routeName.value);
const site = computed(() => page.props.shell.site);
const shell = computed(() => page.props.shell);
const can = computed(() => page.props.can);
const flash = computed(() => page.props.flash);
const flashError = computed(() => page.props.flashError);

const tabs = computed(() => {
    const r = shell.value.routes;
    return [
        ['sites', r.sites, 'Applications', 'G S'],
        ['services', r.services, 'Services', 'G E'],
        ['servers', r.servers, 'Servers', 'G V'],
        ['databases', r.databases, 'Databases', 'G D'],
        ['logs', r.logs, 'Logs', 'G O'],
        ['alerts', r.alerts, 'Alerts', 'G L'],
        ['audit', r.audit, 'Audit log', 'G A'],
        ['tokens', r.tokens, 'API tokens', 'G T'],
    ].filter(([key]) => (key === 'audit' ? can.value['audit.read'] : key === 'tokens' ? can.value['tokens.read'] : true));
});

// Operations running now, and those that finished in the last ten minutes: the way back to one after leaving its page.
const operations = computed(() => page.props.operations ?? []);
const running = computed(() => operations.value.filter((o) => o.status === 'queued' || o.status === 'running'));
const statusClass = (o) => (o.status === 'failed' ? 'text-failed' : o.status === 'succeeded' ? 'text-accent' : 'text-running');
let watching;
watch(running, (now) => {
    clearInterval(watching);
    if (now.length > 0) {
        watching = setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['operations'], preserveScroll: true, preserveState: true, async: true }), 3000);
    }
}, { immediate: true });
onUnmounted(() => clearInterval(watching));

const currentProject = computed(() => shell.value.projects.find((p) => p.id === shell.value.project));

function switchTeam(event) {
    router.post(shell.value.routes.switchTeam, { organization: event.target.value });
}
function chooseProject(id) {
    router.post(shell.value.routes.switchProject, { project: id ?? '' });
}
function setTheme(theme) {
    // The page's <html> is only written by a full load, so change it here as well as saving the choice.
    document.documentElement.dataset.theme = theme;
    router.post(shell.value.routes.theme, { theme }, { preserveScroll: true, preserveState: true });
}
function signOut() {
    router.post(shell.value.routes.logout);
}

// A switcher menu closes on a click anywhere outside it, or on Escape.
function closeMenus(e) {
    document.querySelectorAll('details[data-switch][open]').forEach((d) => {
        if (e.type === 'keydown' || !d.contains(e.target)) d.removeAttribute('open');
    });
}
// G then a letter goes to a tab, as each tab's title shows. Ignored while typing in a field.
let armed = 0;
function shortcuts(e) {
    const t = e.target;
    if (e.key === 'Escape') return closeMenus(e);
    if (e.metaKey || e.ctrlKey || e.altKey || (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)))) return;
    const k = e.key.toLowerCase();
    if (k === 'g') { armed = Date.now(); return; }
    const target = tabs.value.find(([, , , keys]) => keys.toLowerCase().endsWith(` ${k}`));
    if (armed && Date.now() - armed < 1000 && target) { armed = 0; router.visit(target[1]); }
}
onMounted(() => {
    document.addEventListener('click', closeMenus);
    document.addEventListener('keydown', shortcuts);
});
onUnmounted(() => {
    document.removeEventListener('click', closeMenus);
    document.removeEventListener('keydown', shortcuts);
});

// The tabs of an application's pages.
const siteTabs = computed(() => {
    const id = site.value.id;
    return [
        ['site', `/site?site=${id}`, 'Environment'],
        ['site.deployments', `/site/deployments?site=${id}`, 'Deployments'],
        ['site.logs', `/site/logs?site=${id}`, 'Logs'],
        ['site.console', `/site/console?site=${id}`, 'Commands'],
        ['site.settings', `/site/settings?site=${id}`, 'Settings'],
    ];
});

// What a header tab looks like; the one for the page showing gets an underline.
const tabClass = [
    'relative inline-flex items-center gap-1.5 rounded-t-lg px-3 py-3.5 font-mono text-xs tracking-[0.02em] whitespace-nowrap text-muted no-underline transition-colors max-sm:py-2.5',
    'hover:bg-[var(--nav-hover)] hover:text-ink hover:no-underline',
    'aria-[current=page]:text-ink aria-[current=page]:after:absolute aria-[current=page]:after:inset-x-3 aria-[current=page]:after:-bottom-px aria-[current=page]:after:h-0.5 aria-[current=page]:after:rounded-sm aria-[current=page]:after:bg-butter aria-[current=page]:after:content-[\'\']',
].join(' ');
const topLink = 'min-h-[38px] cursor-pointer rounded-[10px] border border-transparent bg-transparent px-3 py-2 font-mono text-[11px] tracking-[0.03em] text-muted no-underline transition-colors hover:border-line hover:bg-raised hover:text-ink hover:no-underline max-sm:min-h-11';
const topIcon = `${topLink} inline-flex w-[38px] items-center justify-center !px-0`;

const label = ([key, , name]) =>
    name + (key === 'alerts' && shell.value.openAlerts > 0 ? `, ${shell.value.openAlerts} open` : '');
</script>

<template>
  <a class="absolute top-[-48px] left-2 z-[100] rounded-lg bg-ink px-3.5 py-2.5 font-mono text-[13px] text-ground focus:top-2 focus:no-underline" href="#main">Skip to content</a>
  <header class="relative mx-auto w-full max-w-[1160px] px-4 sm:px-6">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2.5 border-b border-line-soft pt-[18px] pb-4 font-mono text-xs">
      <a class="flex flex-none items-center no-underline hover:no-underline" :href="shell.routes.sites" aria-label="Unharbored, applications"><BrandMark /></a>
      <span v-if="shell.teams.length > 1" class="inline-flex items-center gap-1.5 break-words text-muted">
        <label for="org-switch" class="sr-only">Team</label>
        <select id="org-switch" :class="selectClass" :value="shell.team.id" @change="switchTeam">
          <option v-for="t in shell.teams" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
      </span>
      <span v-else class="tracking-[0.01em] break-words text-muted">{{ shell.host }} · {{ shell.team.name }}</span>
      <template v-if="shell.projects.length > 1">
        <span class="text-[var(--sep)]" aria-hidden="true">/</span>
        <SwitchMenu id="project-switch" title="Projects group applications; they do not restrict access">
          <template #summary>{{ currentProject?.name ?? 'All projects' }}</template>
          <button type="button" role="menuitem" :aria-current="shell.project === null ? 'true' : undefined" @click="chooseProject(null)">All projects</button>
          <button v-for="p in shell.projects" :key="p.id" type="button" role="menuitem" :aria-current="p.id === shell.project ? 'true' : undefined" @click="chooseProject(p.id)">{{ p.name }} <span class="text-faint">· {{ p.applications }}</span></button>
          <span class="px-2.5 py-2 text-[13px] text-faint">Projects group applications; they do not restrict access</span>
        </SwitchMenu>
      </template>
      <template v-if="site">
        <span class="text-[var(--sep)]" aria-hidden="true">/</span>
        <SwitchMenu>
          <template #summary><span class="inline-flex size-5 flex-none items-center justify-center rounded-md border border-peri/45 bg-peri/15 font-display text-[11px] font-semibold text-peri" aria-hidden="true">{{ site.app.name.charAt(0).toUpperCase() }}</span>{{ site.app.name }}</template>
          <a v-for="a in site.apps" :key="a.id" role="menuitem" :href="`/site?site=${a.id}`" :aria-current="a.id === site.app.id ? 'true' : undefined">{{ a.name }}</a>
          <a role="menuitem" class="!text-faint" :href="site.newApp">+ New application</a>
        </SwitchMenu>
        <span class="text-[var(--sep)]" aria-hidden="true">/</span>
        <SwitchMenu>
          <template #summary><span class="inline-block size-2.5 rounded-[3px] bg-accent" aria-hidden="true"></span>{{ site.env.label }}</template>
          <a v-for="e in site.envs" :key="e.id" role="menuitem" :href="`/site?site=${e.id}`" :aria-current="e.id === site.env.id ? 'true' : undefined">{{ e.label }}<span v-if="e.preview" class="text-faint"> · preview</span></a>
        </SwitchMenu>
      </template>
      <span class="ml-auto flex flex-wrap items-center gap-2 text-muted">
        <SwitchMenu v-if="operations.length > 0" id="operations-menu" title="Operations running now and finished in the last ten minutes" class="mr-1">
          <template #summary>
            <span class="inline-block size-2 rounded-full" :class="running.length > 0 ? 'animate-pulse bg-running' : operations[0].status === 'failed' ? 'bg-failed' : 'bg-accent'" aria-hidden="true"></span>
            {{ running.length > 0 ? `${running.length} running` : operations[0].status === 'failed' ? 'Failed' : 'Done' }}
          </template>
          <Link v-for="o in operations" :key="o.id" role="menuitem" :href="`/operation?operation=${o.id}`">
            {{ o.title }}<br><span class="text-[12px]" :class="statusClass(o)">{{ o.status === 'running' && o.stage ? `running · ${o.stage}` : o.status }}</span>
          </Link>
        </SwitchMenu>
        <span class="mr-1 inline-flex items-center gap-[7px] text-[11px] whitespace-nowrap" :class="shell.gateway.up ? '' : 'text-failed'" :title="`${shell.gateway.nodes} ${shell.gateway.nodes === 1 ? 'node' : 'nodes'} connected`"><span class="size-[7px] rounded-full" :class="shell.gateway.up ? 'bg-accent shadow-[0_0_0_4px_var(--accent-bg)]' : 'bg-failed shadow-[0_0_0_4px_color-mix(in_srgb,var(--failed)_12%,transparent)]'" aria-hidden="true"></span>{{ shell.gateway.up ? `gateway · ${shell.gateway.nodes}` : 'gateway unreachable' }}</span>
        <button type="button" :class="[topIcon, 'theme-to-light']" aria-label="Switch colour theme" title="Switch to the light theme" @click="setTheme('light')"><svg class="block size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button>
        <button type="button" :class="[topIcon, 'theme-to-dark']" aria-label="Switch colour theme" title="Switch to the dark theme" @click="setTheme('dark')"><svg class="block size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/></svg></button>
        <a :class="topLink" :href="shell.routes.account">Account</a>
        <button type="button" :class="topLink" @click="signOut">Sign out</button>
      </span>
    </div>
    <nav class="mt-1.5 flex gap-0.5 overflow-x-auto border-b border-line-soft [scrollbar-width:none] max-sm:flex-wrap max-sm:overflow-x-visible [&::-webkit-scrollbar]:hidden" :aria-label="site ? 'Application' : 'Team'">
      <template v-if="site">
        <Link v-for="tab in siteTabs" :key="tab[0]" :class="tabClass" :href="tab[1]" :aria-current="routeName === tab[0] ? 'page' : undefined">{{ tab[2] }}</Link>
        <span v-if="routeName === 'operation'" :class="tabClass" aria-current="page">Operation</span>
      </template>
      <template v-else>
        <Link v-for="tab in tabs" :key="tab[0]" :class="tabClass" :href="tab[1]" :title="tab[3]" :aria-label="label(tab)" :aria-keyshortcuts="tab[3].toLowerCase().replace(' ', '')" :aria-current="section === tab[0] ? 'page' : undefined">{{ tab[2] }}<span v-if="tab[0] === 'alerts' && shell.openAlerts > 0" class="rounded-full bg-failed/15 px-1.5 py-px text-[10px] leading-normal text-failed">{{ shell.openAlerts }}</span></Link>
      </template>
    </nav>
  </header>
  <main id="main" class="mx-auto flex w-full max-w-[1160px] min-w-0 flex-col gap-7 px-4 pt-8 pb-16 focus:outline-none sm:px-6 sm:pt-11 sm:pb-20" tabindex="-1">
    <p v-if="flash" class="m-0 text-accent" role="status">{{ flash }}</p>
    <FormError :message="flashError" />
    <slot />
  </main>
</template>
