<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import Btn from '../../Components/ui/Btn.vue';
import Field from '../../Components/ui/Field.vue';
import FormError from '../../Components/ui/FormError.vue';
import Panel from '../../Components/ui/Panel.vue';
import PageHead from '../../Components/ui/PageHead.vue';

defineOptions({ layout: Console });

const props = defineProps({ old: Object, outboundIp: String, serverName: String });

const form = useForm({
    name: props.old?.name ?? '', host: props.old?.host ?? '', port: props.old?.port ?? 22, user: props.old?.user ?? 'root',
    private_key: '', passphrase: '', password: '', fingerprint: props.old?.fingerprint ?? '', role: props.old?.role ?? '',
});

// The key and password are never sent back by the server; on a refusal they are cleared here too.
const submit = () => form.post('/servers/connect', { onError: () => form.reset('private_key', 'passphrase', 'password') });
</script>

<template>
  <Head title="Connect a server over SSH" />
  <PageHead title="Connect a server">
    <p class="m-0 max-w-[64ch] leading-[1.65] text-muted">For a machine you already have, anywhere. Unharbored logs in over SSH <b>once</b>, checks it, installs the agent and then never uses SSH again. The key or password is held only until the install ends.</p>
  </PageHead>

  <Panel class="max-w-[720px]" title="Where and how to log in" note="Ubuntu 22.04, 24.04 or 26.04, root or passwordless sudo, ports 80 and 443 free" flush>
    <form class="flex flex-col gap-4 px-5 pt-4 pb-[22px] sm:px-7 sm:pt-[18px] sm:pb-6" autocomplete="off" @submit.prevent="submit">
      <p class="m-0 text-[13px] text-faint">If the server's firewall restricts SSH, allow <span class="font-semibold text-ink-strong">{{ outboundIp }}</span> to reach it. The server must also reach {{ serverName }} on ports 443 and 9443.</p>
      <div class="flex flex-wrap items-end gap-3 [&>.field]:flex-[1_1_180px]">
        <Field label="Name" v-slot="{ id }"><input :id="id" v-model="form.name" placeholder="hetzner-1" required></Field>
        <Field label="Host" v-slot="{ id }"><input :id="id" v-model="form.host" placeholder="203.0.113.7" required></Field>
        <Field label="Port" v-slot="{ id }"><input :id="id" v-model="form.port" type="number" min="1" max="65535"></Field>
        <Field label="User" v-slot="{ id }"><input :id="id" v-model="form.user"></Field>
      </div>
      <FormError :message="form.errors.name" />
      <FormError :message="form.errors.host" />
      <FormError :message="form.errors.port" />
      <FormError :message="form.errors.user" />

      <label class="flex items-start gap-2 text-[13px]"><input v-model="form.role" type="checkbox" true-value="incus-host" false-value="" class="mt-0.5">
        <span><b class="font-semibold text-ink-strong">Make it an Incus host.</b> <span class="text-faint">Unharbored installs Incus instead of the server stack and connects to it. Nothing else runs on the machine; your servers live inside it. It needs 2 cores, 4 GB memory, 30 GB free and an IPv6 /64.</span></span></label>
      <FormError :message="form.errors.role" />

      <Field label="Private key" v-slot="{ id }"><textarea :id="id" v-model="form.private_key" rows="6" spellcheck="false" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea></Field>
      <Field label="Key passphrase, if it has one" v-slot="{ id }"><input :id="id" v-model="form.passphrase" type="password"></Field>
      <Field label="Or a password instead of a key" v-slot="{ id }"><input :id="id" v-model="form.password" type="password"></Field>
      <FormError :message="form.errors.private_key" />

      <Field label="Expected host key fingerprint, optional" v-slot="{ id }"><input :id="id" v-model="form.fingerprint" placeholder="SHA256:…" spellcheck="false"></Field>
      <p class="m-0 text-[13px] text-faint">Without it, the host key is trusted the first time and shown in the operation log. With it, a different key stops everything before any login is sent.</p>
      <FormError :message="form.errors.fingerprint" />

      <div class="flex flex-wrap gap-2"><Btn type="submit" primary :disabled="form.processing">Connect and install</Btn> <Btn href="/servers" inertia>Cancel</Btn></div>
    </form>
  </Panel>
</template>
