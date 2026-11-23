<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import { usePolling } from '../composables/usePolling';
import { usePost } from '../composables/usePost';
import { useConfirmedPost } from '../composables/useConfirmedPost';
import PageHead from '../Components/ui/PageHead.vue';
import Field from '../Components/ui/Field.vue';
import Notice from '../Components/ui/Notice.vue';
import Panel from '../Components/ui/Panel.vue';
import Btn from '../Components/ui/Btn.vue';
import Empty from '../Components/ui/Empty.vue';
import FormError from '../Components/ui/FormError.vue';
import Crumbs from '../Components/ui/Crumbs.vue';
import DeployList from '../Components/Site/DeployList.vue';
import DeployRow from '../Components/Site/DeployRow.vue';
import DeployKeyNotice from '../Components/Site/DeployKeyNotice.vue';
import { tone } from '../Components/ui/tone.js';

defineOptions({ layout: Console });

const props = defineProps({ view: Object, certSummary: Object });
const v = computed(() => props.view);

usePolling(['view'], 3000);

const send = usePost();
const post = (url, data = {}) => send(url, { site: v.value.id, ...data });

const ask = useConfirmedPost(post);
function deploy() {
    const c = v.value.container;
    const message = c && c.kind === 'image' ? `Run the pinned image of ${v.value.name} again?` : `Deploy ${v.value.branch} to ${v.value.name}?`;
    return ask({ message, ok: 'Deploy' }, '/site/deploy');
}
const rollback = (release) => ask({ message: `Roll ${v.value.name} back to ${release}?`, ok: 'Roll back' }, '/site/rollback');
const renew = () => ask({ message: "Renew now? This asks Let's Encrypt for a new certificate, which is limited to 5 a week per domain.", ok: 'Renew now' }, '/site/certificate/renew');
const revert = () => ask({ message: "Switch back to Let's Encrypt? A new certificate is issued, then the uploaded one is removed from every server.", ok: "Use Let's Encrypt" }, '/site/certificate/revert');

// The upload panel. The key is only ever in the textarea and in the POST body (never a URL), is cleared once the call
// succeeds, and is never echoed back.
const certOpen = ref(false);
const summary = ref(null);
const cert = useForm({ site: 0, certChain: '', certKey: '' });
function toggleCertPanel() {
    certOpen.value = !certOpen.value;
    cert.certKey = '';
    cert.clearErrors();
    summary.value = null;
}
function uploadCert() {
    summary.value = null;
    cert.site = v.value.id;
    cert.post('/site/certificate/upload', {
        preserveScroll: true,
        onSuccess: (page) => {
            summary.value = page.props.certSummary;
            cert.certChain = '';
            cert.certKey = '';
        },
    });
}
</script>

<template>
  <Head :title="v.missing ? 'No such site' : v.name" />
  <PageHead v-if="v.missing" title="No such site">
    <template #crumbs><Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }]" /></template>
  </PageHead>
  <template v-else>
    <DeployKeyNotice v-if="v.awaitingKey" :awaiting="v.awaitingKey" link />
    <Notice v-if="v.failedLast && !v.running" tone="bad">
      <span><b>{{ v.failedLast.title }} failed</b> {{ v.failedLast.ago }}.<template v-if="v.liveShort"> The site is still serving {{ v.liveShort }}<template v-if="v.liveCommit"> ({{ v.liveCommit }})</template>.</template> <span v-if="v.failedLast.why" class="font-mono">{{ v.failedLast.why }}</span></span>
      <Btn small :href="`/operation?operation=${v.failedLast.id}`" inertia>See what happened</Btn>
    </Notice>
    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-2.5 max-[600px]:grid-cols-1">
      <div class="flex min-w-0 items-center gap-3.5">
        <span class="inline-flex size-10 flex-none items-center justify-center rounded-xl border border-peri/45 bg-peri/15 font-display text-lg font-semibold text-peri" aria-hidden="true">{{ v.name.charAt(0).toUpperCase() }}</span>
        <h1 class="m-0 font-display text-[clamp(28px,4.5vw,36px)] leading-[1.05] font-semibold tracking-[-0.02em] break-words text-ink-strong">{{ v.name }} <span class="font-medium text-muted">· {{ v.env }}</span></h1>
      </div>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="v.running" :href="`/operation?operation=${v.running.id}`" inertia><span class="text-running">▸</span> {{ v.running.title }}</Btn>
        <template v-else>
          <Btn v-if="v.canDeploy" primary @click="deploy">↑ Deploy</Btn>
          <Btn v-if="v.container" :href="`/site/container?site=${v.id}`" inertia>Container</Btn>
        </template>
        <Btn :href="v.url" target="_blank" rel="noopener">Visit ↗</Btn>
      </div>
      <div class="col-span-full flex flex-wrap items-center gap-x-[18px] gap-y-1.5 font-mono text-[11px] text-faint max-sm:text-[11px] [&>span]:inline-flex [&>span]:max-w-full [&>span]:flex-wrap [&>span]:items-center [&>span]:gap-1.5 [&_code]:text-ink">
        <span><svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.7 18.3 5 18.3 5c.7 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .5Z"/></svg> {{ v.container && v.container.kind === 'image' ? v.container.image : `${v.repo}:${v.branch}` }}</span>
        <span>on <template v-for="(srv, i) in v.servers" :key="srv"><a :href="`/servers#node-${srv}`">{{ srv }}</a><template v-if="i < v.servers.length - 1">, </template></template></span>
        <span v-if="v.liveShort">live <b>{{ v.liveShort }}</b><template v-if="v.liveCommit"> {{ v.liveCommit }}</template><template v-if="v.liveAgo"> · {{ v.liveAgo }}</template></span>
        <span v-if="v.cert">certificate <b :class="tone[v.cert.class]">{{ v.cert.label }}</b>
          <span v-if="v.canRenew && !v.autoRenew" class="text-running"> auto-renew off</span>
          <Btn v-if="v.canDeploy" small @click="post('/site/certificate/check')">Refresh</Btn>
          <template v-if="v.canRenew && v.canDeploy">
            <Btn small @click="renew">Renew now</Btn>
            <Btn small title="Whether certbot renews this certificate by itself about 30 days before it ends. Off: renew by hand, and the expiry warning still applies." @click="post('/site/certificate/auto-renew')">Auto-renew: {{ v.autoRenew ? 'on, turn off' : 'off, turn on' }}</Btn>
          </template>
          <span v-if="v.certCustom" class="text-faint"> Custom certificate</span>
          <template v-if="v.canInfra">
            <Btn small @click="toggleCertPanel">Upload certificate</Btn>
            <Btn v-if="v.certCustom" small @click="revert">Use Let's Encrypt instead</Btn>
          </template>
        </span>
        <span v-if="v.container">runs a <b>{{ v.container.kind === 'image' ? 'Docker image' : 'Dockerfile' }}</b> in a container</span>
        <span v-else>push to deploy <b :class="v.push ? tone.ok : tone.faint">{{ v.push ? 'on' : 'off' }}</b></span>
        <span>{{ v.source }}</span>
      </div>
    </div>

    <Panel v-if="certOpen && v.canInfra" title="Upload certificate">
      <p class="m-0 text-faint">Paste the certificate chain (the site's certificate first, then its intermediates) and the private key, both as PEM. The key must have no passphrase. It is checked here, held encrypted only until it has been written to every server, and never shown again. Renewal is then up to you: this page warns before it ends.</p>
      <Field label="Certificate chain" :error="cert.errors.certChain" v-slot="{ id }">
        <textarea :id="id" v-model="cert.certChain" rows="8" spellcheck="false" autocomplete="off" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
      </Field>
      <Field label="Private key" :error="cert.errors.certKey" v-slot="{ id }">
        <textarea :id="id" v-model="cert.certKey" rows="6" spellcheck="false" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
      </Field>
      <FormError :message="cert.errors.certificate" />
      <div class="flex flex-wrap gap-2">
        <Btn primary :disabled="cert.processing" @click="uploadCert">Upload and apply</Btn>
        <Btn @click="toggleCertPanel">Close</Btn>
      </div>
      <template v-if="summary">
        <p class="m-0 text-accent">Queued. <Link :href="`/operation?operation=${summary.id}`">Watch it apply</Link></p>
        <ul class="m-0">
          <li>Issuer: {{ summary.issuer }}</li>
          <li>Covers: {{ summary.names.join(', ') }}</li>
          <li>Expires: {{ summary.expires_label }}</li>
          <li v-for="w in summary.warnings" :key="w" class="text-running">{{ w }}</li>
        </ul>
      </template>
    </Panel>

    <section
      class="grid grid-cols-[repeat(auto-fit,minmax(250px,1fr))] gap-6 rounded-panel border border-line bg-well bg-[radial-gradient(var(--dots)_1px,transparent_1.2px)] bg-[size:14px_14px] p-6"
      aria-label="What this environment runs on"
    >
      <div v-for="(cards, column) in v.topology" :key="column" class="relative flex min-w-0 flex-col gap-3 rounded-2xl bg-panel/70 p-3 [&:not(:first-child)]:before:absolute [&:not(:first-child)]:before:top-[58px] [&:not(:first-child)]:before:-left-6 [&:not(:first-child)]:before:w-6 [&:not(:first-child)]:before:border-t [&:not(:first-child)]:before:border-line-control [&:not(:first-child)]:before:content-[''] max-[900px]:before:hidden">
        <span class="px-1 font-mono text-[10px] tracking-[0.18em] text-muted uppercase">{{ column }}</span>
        <div v-for="card in cards" :key="card.title" class="overflow-hidden rounded-[14px] border border-line bg-panel shadow-[0_6px_16px_rgba(3,10,8,0.12)]">
          <div class="flex items-baseline justify-between gap-2 border-b border-line-soft px-3.5 py-[11px] text-ink-strong"><b class="font-semibold">{{ card.title }}</b><span v-if="card.note" class="font-mono text-[11px] text-faint">{{ card.note }}</span></div>
          <div v-for="(r, i) in card.rows" :key="i" class="flex items-center justify-between gap-3 border-t border-dashed border-line-soft px-3.5 py-[7px] text-[13px] first:border-t-0">
            <a v-if="r[3]" :href="r[3]">{{ r[0] }}</a><span v-else class="text-muted">{{ r[0] }}</span>
            <span class="inline-flex items-center gap-1.5 text-right break-words" :class="tone[r[2]] ?? 'text-ink'"><i v-if="r[2]" class="size-[7px] flex-none rounded-full bg-current" aria-hidden="true"></i>{{ r[1] }}</span>
          </div>
        </div>
      </div>
    </section>

    <div v-if="v.previews === 0" class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 rounded-2xl border border-dashed border-line-control bg-panel px-[22px] py-[18px]">
      <div class="flex flex-col gap-0.5">
        <b class="font-semibold text-ink-strong">Set up preview environments</b>
        <span class="text-[13px] text-muted">Deploy a branch or pull request to its own URL{{ v.static ? '' : ', with its own database,' }} before it reaches production.</span>
      </div>
      <Btn :href="`/site/settings?site=${v.id}&tab=previews`" inertia>Set up previews</Btn>
    </div>

    <section aria-labelledby="rel-h" class="flex flex-col gap-3">
      <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5"><h2 id="rel-h" class="m-0 font-display text-[22px] font-semibold text-ink-strong">Latest deployments</h2><span class="text-[13px] text-faint">{{ v.container ? 'The live image and the two before it are kept on the server' : 'The newest five are kept on the server' }}</span></div>
      <DeployList>
        <DeployRow v-for="r in v.releases" :key="r.id" :state="r.state" :label="r.label" :commit="r.commit" :href="`/operation?operation=${r.id}`" :text="r.release ?? 'Deploy'" :badge="r.label !== 'Kept' ? r.label : ''" :badge-status="r.class">
          <template #meta>
            <span><template v-if="v.container && v.container.kind === 'image'">pinned image </template><template v-else>from <code>{{ v.branch }}</code> </template>· {{ r.by }}</span>
            <span>{{ r.ago }}<template v-if="r.took"> · {{ r.took }}</template></span>
          </template>
          <Btn v-if="r.rollback && v.canDeploy" small @click="rollback(r.release)">↺ Roll back</Btn>
        </DeployRow>
        <li v-if="v.releases.length === 0"><Empty>No deploys yet.</Empty></li>
      </DeployList>
    </section>
  </template>
</template>
