<script setup>
import { ref } from 'vue';
import { Form, router, usePage } from '@inertiajs/vue3';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';
import Chip from '../ui/Chip.vue';
import Disclosure from '../ui/Disclosure.vue';
import Field from '../ui/Field.vue';
import FormRow from '../ui/FormRow.vue';
import { inputClass } from '../Shell/classes';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { confirm } from '../../composables/useConsole';
import { usePost } from '../../composables/usePost';

defineProps({ providers: Object });

const page = usePage();
const err = (key) => page.props.errors[key];
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

const panels = (p) => [
    { kind: 'cloud', title: 'Cloud providers', note: 'Where servers, databases and storage are created. Keys are kept encrypted and never shown in full again.', keys: p.cloud },
    { kind: 'dns', title: 'DNS providers', note: "Where your domains' records are managed. Separate from where your servers run.", keys: p.dns },
];

const post = usePost();
const test = (url) => post(url, {}, { preserveState: false });
const copied = ref('');
async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = 'Copied.';
    } catch {
        copied.value = 'Select the command and copy it.';
    }
}

async function disconnect(url, name, what = 'The key is deleted from the vault.', ok = 'Disconnect', title = 'Disconnect?', tone = 'danger') {
    if (await confirm({ title, message: `${ok} ${name}? ${what}`, ok, tone })) {
        router.delete(url, { preserveScroll: true });
    }
}
</script>

<template>
  <Panel v-for="panel in panels(providers)" :id="panel.kind === 'cloud' ? 'provider-keys' : undefined" :key="panel.kind" :title="panel.title" :note="panel.note">
      <FormError :message="err(`provider_${panel.kind}`)" />
      <div v-for="k in panel.keys" :key="k.name" class="flex flex-col gap-2 rounded-md border border-line px-3 py-2.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span><b class="font-semibold text-ink-strong">{{ k.name }}</b> <Chip>{{ k.kind }}</Chip><br>
            <span class="text-faint text-[13px] font-mono">{{ k.hint ?? 'key hint not recorded' }}</span><span v-if="panel.kind === 'cloud' && k.account" class="text-faint text-[13px]"> · {{ k.account }}</span>
            <span v-if="k.verified" class="text-faint text-[13px]"> · verified {{ k.verified }}</span><br>
            <span class="text-faint text-[13px]"><template v-if="panel.kind === 'dns'">Domains: {{ k.domains.join(', ') }} · records for {{ plural(k.uses, 'application') }}</template><template v-else>Used by {{ plural(k.uses, 'server') }}, databases and buckets </template></span>
            <template v-if="k.error"><br><span class="text-[13px] text-failed">{{ k.error }}</span></template></span>
          <Badge :status="k.badge === 'plain' ? 'neutral' : k.badge" :plain="k.badge === 'plain'">{{ k.badgeLabel }}</Badge>
        </div>
        <div class="flex flex-wrap gap-2">
          <Btn small :title="`Ask ${k.name} whether the key still works`" @click="test(`/account/providers/${panel.kind}/test`)">Test connection</Btn>
          <Disclosure small title="Check a new key, then keep it in place of this one" label="Replace key…">
            <Form :action="`/account/providers/${panel.kind}`" method="post" class="flex flex-col gap-2.5">
              <Field v-if="panel.kind === 'cloud'" label="New API token" v-slot="{ id }"><input :id="id" name="token" type="password" autocomplete="off" required></Field>
              <template v-else>
                <Field label="Domain" v-slot="{ id }"><input :id="id" name="domain" :value="k.domains[0] ?? ''" required></Field>
                <FormRow><Field label="API key" v-slot="{ id }"><input :id="id" name="key" type="password" autocomplete="off" required></Field><Field label="API secret" v-slot="{ id }"><input :id="id" name="secret" type="password" autocomplete="off" required></Field></FormRow>
              </template>
              <Field label="Expires on "><template #label><span class="text-faint">(optional)</span></template><template #default="{ id }"><input :id="id" name="expires_at" type="date" :value="k.expires_at"></template></Field>
              <span class="text-faint text-[13px]">The old key keeps working until the new one is checked with {{ k.name }}.</span>
              <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Verify and save</Btn></div>
            </Form>
          </Disclosure>
          <Disclosure small title="The date you chose when you created the key; the provider does not report it" label="Change expiry…">
            <Form :action="`/account/providers/${panel.kind}/expiry`" method="post" class="flex flex-wrap items-end gap-3">
              <input name="expires_at" type="date" :value="k.expires_at" aria-label="Expires on" :class="inputClass"><Btn small type="submit">Save</Btn>
            </Form>
          </Disclosure>
          <span v-if="panel.kind === 'cloud' && providers.keyBlocked" class="inline-flex cursor-not-allowed *:pointer-events-none" :title="`Unavailable: ${providers.keyBlocked}`"><Btn small danger disabled>Disconnect</Btn></span>
          <Btn small danger v-else title="Delete the key from the vault" @click="disconnect(`/account/providers/${panel.kind}`, k.name)">Disconnect</Btn>
        </div>
      </div>
      <template v-if="panel.keys.length === 0">
        <p class="m-0 text-[13px] text-faint"><template v-if="panel.kind === 'cloud'">No cloud provider connected. Nothing can be created until one is.</template><template v-else>None connected. Unharbored shows the records to add at your registrar instead.</template></p>
        <Disclosure small primary :label="panel.kind === 'cloud' ? 'Connect DigitalOcean…' : 'Connect Spaceship…'">
          <Form :action="`/account/providers/${panel.kind}`" method="post" class="flex flex-col gap-2.5">
            <Field v-if="panel.kind === 'cloud'" label="API token" hint="Needs read and write. Only its first characters and last four are shown again." v-slot="{ id }"><input :id="id" name="token" type="password" autocomplete="off" placeholder="dop_v1_…" required></Field>
            <template v-else>
              <Field label="Domain" v-slot="{ id }"><input :id="id" name="domain" placeholder="example.com" required></Field>
              <FormRow><Field label="API key" v-slot="{ id }"><input :id="id" name="key" type="password" autocomplete="off" required></Field><Field label="API secret" v-slot="{ id }"><input :id="id" name="secret" type="password" autocomplete="off" required></Field></FormRow>
              <span class="text-faint text-[13px]">The key needs <span class="font-mono">dnsrecords:read</span> and <span class="font-mono">dnsrecords:write</span> only.</span>
            </template>
            <Field label="Expires on "><template #label><span class="text-faint">(optional)</span></template><template #default="{ id }"><input :id="id" name="expires_at" type="date"></template></Field>
            <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Verify and save</Btn></div>
          </Form>
          </Disclosure>
      </template>
      <span v-if="panel.kind === 'cloud'" class="text-faint text-[13px]">An application, and everything it runs on, stays on one cloud provider.</span>
      <span v-else class="text-faint text-[13px]">One domain per team for now. Every key is checked daily; you are alerted when one is rejected, expired or within {{ providers.soonDays }} days of its expiry.</span>
  </Panel>

  <Panel id="provider-aws" title="AWS, or an AWS-compatible service" note="EC2 servers, with local databases. Set an endpoint URL to use LocalStack or another service that speaks the AWS API.">
      <FormError :message="err('provider_aws')" />
      <div v-if="providers.aws" class="flex flex-wrap items-center justify-between gap-3">
        <span><b class="font-semibold text-ink-strong">AWS</b> <Chip>access key</Chip><br><span class="text-faint text-[13px] font-mono">{{ providers.aws.hint }}</span> <span class="text-faint text-[13px]">· {{ providers.aws.email }}</span><br><span class="text-faint text-[13px]">Used by {{ plural(providers.awsServers, 'server') }}</span></span>
        <span class="flex flex-wrap gap-2">
          <Btn small @click="test('/account/aws/test')">Test connection</Btn>
          <span v-if="providers.awsServers > 0" class="inline-flex cursor-not-allowed *:pointer-events-none" title="Unavailable: servers on AWS still need this key."><Btn small danger disabled>Disconnect</Btn></span>
          <Btn small danger v-else @click="disconnect('/account/aws', 'AWS')">Disconnect</Btn>
        </span>
      </div>
      <Disclosure small :primary="!providers.aws" :label="providers.aws ? 'Replace key…' : 'Connect AWS…'">
        <Form action="/account/aws" method="post" class="flex flex-col gap-2.5" autocomplete="off">
          <FormRow><Field label="Access key id" v-slot="{ id }"><input :id="id" name="access_key_id" required></Field><Field label="Secret access key" v-slot="{ id }"><input :id="id" name="secret_access_key" type="password" required></Field></FormRow>
          <FormRow><Field label="Default region" v-slot="{ id }"><input :id="id" name="region" value="us-east-1"></Field><Field label="Endpoint URL "><template #label><span class="text-faint">(optional)</span></template><template #default="{ id }"><input :id="id" name="endpoint" placeholder="http://localhost:4566"></template></Field></FormRow>
          <Field label="Image id "><template #label><span class="text-faint">(optional: the current Ubuntu 24.04 is used if empty)</span></template><template #default="{ id }"><input :id="id" name="ami" placeholder="ami-…"></template></Field>
          <span class="text-faint text-[13px]">The key is checked with the service first, and the old one stays until then. It needs EC2 (instances and security groups), STS and SSM read access.</span>
          <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Verify and save</Btn></div>
        </Form>
      </Disclosure>
  </Panel>

  <Panel id="provider-hetzner" title="Hetzner" note="Cloud servers, with local databases. One project per token.">
      <FormError :message="err('provider_hetzner')" />
      <div v-if="providers.hetzner" class="flex flex-wrap items-center justify-between gap-3">
        <span><b class="font-semibold text-ink-strong">Hetzner</b> <Chip>API token</Chip><br><span class="text-faint text-[13px] font-mono">{{ providers.hetzner.hint }}</span><br><span class="text-faint text-[13px]">Used by {{ plural(providers.hetznerServers, 'server') }}</span></span>
        <span class="flex flex-wrap gap-2">
          <Btn small @click="test('/account/hetzner/test')">Test connection</Btn>
          <span v-if="providers.hetznerServers > 0" class="inline-flex cursor-not-allowed *:pointer-events-none" title="Unavailable: servers on Hetzner still need this token."><Btn small danger disabled>Disconnect</Btn></span>
          <Btn small danger v-else @click="disconnect('/account/hetzner', 'Hetzner', 'The token is deleted from the vault.')">Disconnect</Btn>
        </span>
      </div>
      <Disclosure small :primary="!providers.hetzner" :label="providers.hetzner ? 'Replace token…' : 'Connect Hetzner…'">
        <Form action="/account/hetzner" method="post" class="flex flex-col gap-2.5" autocomplete="off">
          <Field label="API token" hint="Needs read and write, from the project's Security → API tokens. It is checked first, and the old one stays until then." v-slot="{ id }"><input :id="id" name="token" type="password" required></Field>
          <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Verify and save</Btn></div>
        </Form>
      </Disclosure>
  </Panel>

  <Panel v-for="p in providers.tokenProviders" :id="`provider-${p.id}`" :key="p.id" :title="p.label" :note="p.note">
      <FormError :message="err(`provider_${p.id}`)" />
      <div v-if="p.credential" class="flex flex-wrap items-center justify-between gap-3">
        <span><b class="font-semibold text-ink-strong">{{ p.label }}</b> <Chip>{{ p.chip }}</Chip><br><span class="text-faint text-[13px] font-mono">{{ p.credential.hint }}</span><template v-if="p.setup && p.credential.email"><br><span class="text-faint text-[13px]">{{ p.credential.email }}</span></template><br><span class="text-faint text-[13px]">Used by {{ plural(p.servers, 'server') }}</span></span>
        <span class="flex flex-wrap gap-2">
          <Btn small @click="test(`/account/token/${p.id}/test`)">Test connection</Btn>
          <span v-if="p.servers > 0" class="inline-flex cursor-not-allowed *:pointer-events-none" :title="`Unavailable: servers on ${p.label} still need this ${p.noun}.`"><Btn small danger disabled>Disconnect</Btn></span>
          <Btn small danger v-else @click="disconnect(`/account/token/${p.id}`, p.label, p.setup ? 'Unharbored\'s key is deleted from the vault. Run incus config trust remove on the host to revoke it there too.' : 'The token is deleted from the vault.')">Disconnect</Btn>
        </span>
      </div>
      <Disclosure small :primary="!p.credential" :label="p.credential ? `Replace ${p.noun}…` : `Connect ${p.label}…`">
        <Form :action="`/account/token/${p.id}`" method="post" class="flex flex-col gap-2.5" autocomplete="off">
          <template v-if="p.setup">
            <b class="font-semibold text-ink-strong">1. Set up the machine</b>
            <span class="text-[13px] text-muted">{{ p.setup.text }}</span>
            <span class="flex flex-wrap items-center gap-2 rounded-md border border-line bg-well px-3 py-2">
              <code class="flex-1 font-mono text-[13px] break-all">{{ p.setup.command }}</code>
              <Btn small type="button" @click="copy(p.setup.command)">Copy</Btn>
            </span>
            <span v-if="copied" class="text-[13px] text-faint" role="status">{{ copied }}</span>
            <span class="text-[13px] text-faint">{{ p.setup.needs }}</span>
            <b class="font-semibold text-ink-strong">2. Connect</b>
          </template>
          <Field :label="p.field" :hint="p.help" v-slot="{ id }">
            <textarea v-if="p.setup" :id="id" name="token" rows="3" required placeholder="uh-incus:…" spellcheck="false" class="font-mono text-[13px]"></textarea>
            <input v-else :id="id" name="token" type="password" required>
          </Field>
          <div class="flex flex-wrap gap-2"><Btn small primary type="submit">{{ p.setup ? 'Connect' : 'Verify and save' }}</Btn></div>
        </Form>
      </Disclosure>
  </Panel>

  <Panel id="provider-tailscale" title="Tailscale" note="Keep servers reachable only by people on your tailnet">
      <FormError :message="err('provider_tailscale')" />
      <div v-if="providers.tailscale" class="flex flex-wrap items-center justify-between gap-3">
        <span><b class="font-semibold text-ink-strong">Tailscale</b> <Chip>auth key</Chip><br><span class="text-faint text-[13px] font-mono">{{ providers.tailscale.hint }}</span><br><span class="text-faint text-[13px]">{{ plural(providers.tailscaleServers, 'server') }} on the tailnet</span></span>
        <Btn small danger @click="disconnect('/account/tailscale', 'the Tailscale key', 'Servers already on the tailnet stay on it, and no more can join.', 'Remove', 'Remove?', 'info')">Remove key</Btn>
      </div>
      <Disclosure small :primary="!providers.tailscale" :label="providers.tailscale ? 'Replace key…' : 'Add an auth key…'">
        <Form action="/account/tailscale" method="post" class="flex flex-col gap-2.5" autocomplete="off">
          <Field label="Auth key"><template #default="{ id }"><input :id="id" name="authkey" type="password" required placeholder="tskey-auth-…"></template>
            <template #hint>Create it in <a :href="providers.tailscaleKeysUrl" target="_blank" rel="noopener">Tailscale, Settings, Keys</a>: reusable, with a tag such as <span class="font-mono">tag:Unharbored</span> and an expiry. A tagged key keeps these servers out of any one person's account and lets your access rules name them. It is kept encrypted and never shown again.</template></Field>
          <span class="text-faint text-[13px]">Who may reach a server is decided in Tailscale, not here. Add or remove people and write access rules there.</span>
          <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Save key</Btn></div>
        </Form>
      </Disclosure>
  </Panel>
</template>
