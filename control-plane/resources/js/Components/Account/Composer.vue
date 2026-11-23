<script setup>
import { Form, router, usePage } from '@inertiajs/vue3';
import Btn from '../ui/Btn.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import FormRow from '../ui/FormRow.vue';
import Panel from '../ui/Panel.vue';
import { confirm } from '../../composables/useConsole';

defineProps({ composer: Object });

const page = usePage();

async function remove(scope, host) {
    if (await confirm({ title: 'Remove it?', message: `Remove the credentials for ${host} from ${scope.label.toLowerCase()}? Applications stop getting them with their next deploy, so a deploy that needs them will fail.`, ok: 'Remove', tone: 'danger' })) {
        router.delete('/account/composer', { data: { scope: scope.key, host }, preserveScroll: true });
    }
}
</script>

<template>
  <Panel id="composer" title="Composer credentials" note="A username and password for a private package server, given to every deploy of the team or of one project">
      <FormError :message="page.props.errors.composer" />
      <template v-for="scope in composer.scopes" :key="scope.key">
        <div v-if="scope.hosts.length" class="flex flex-col gap-1.5">
          <b class="font-semibold text-ink-strong">{{ scope.label }}</b>
          <div v-for="h in scope.hosts" :key="h.host" class="flex flex-wrap items-center justify-between gap-3">
            <span><span class="font-mono">{{ h.host }}</span> <span class="text-faint text-[13px]">· {{ h.username }} · password kept encrypted</span></span>
            <Btn small danger @click="remove(scope, h.host)">Remove</Btn>
          </div>
        </div>
      </template>
      <p v-if="composer.scopes.every((s) => s.hosts.length === 0)" class="text-faint">None yet.</p>
      <Form action="/account/composer" method="post" class="flex flex-col gap-2.5" autocomplete="off" :reset-on-success="['password']">
        <FormRow>
          <Field label="For" v-slot="{ id }"><select :id="id" name="scope"><option v-for="s in composer.scopes" :key="s.key" :value="s.key">{{ s.label }}</option></select></Field>
          <Field label="Host" v-slot="{ id }"><input :id="id" name="host" placeholder="nova.laravel.com" required spellcheck="false"></Field>
        </FormRow>
        <FormRow>
          <Field label="Username" v-slot="{ id }"><input :id="id" name="username" placeholder="you@example.com" required></Field>
          <Field label="Password or licence key" v-slot="{ id }"><input :id="id" name="password" type="password" required></Field>
        </FormRow>
        <span class="text-faint text-[13px]">Saving a host again replaces it. Each application gets the team's credentials, then its project's, then its own on top, host by host. They go to the servers with the next deploy, where only the application's deploy user can read them. GitHub, GitLab and token credentials still go in the application's own box on its Build tab.</span>
        <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Save credentials</Btn></div>
      </Form>
  </Panel>
</template>
