<script setup>
import Toggle from '../ui/Toggle.vue';
import { Form, usePage } from '@inertiajs/vue3';
import Btn from '../ui/Btn.vue';
import Disclosure from '../ui/Disclosure.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import FormRow from '../ui/FormRow.vue';
import Panel from '../ui/Panel.vue';
import { usePost } from '../../composables/usePost';

defineProps({ org: String, team: Object });

const page = usePage();
const can = (permission) => page.props.can[permission];
const post = usePost();
const toggleConsoles = (on) => post('/account/consoles', on ? { enabled: 1 } : {}, { preserveState: false });
</script>

<template>
  <Panel title="Team" note="The name shown in the header">
    <template #actions>
      <details v-if="team.canCreateTeam" class="relative [&::-webkit-details-marker]:hidden">
        <summary class="inline-flex min-h-[38px] cursor-pointer list-none items-center justify-center rounded-[11px] border border-line bg-raised px-[13px] py-[7px] text-[13px] font-medium text-ink select-none [&::-webkit-details-marker]:hidden" aria-label="New team" title="New team">+</summary>
        <Form action="/organization" method="post" class="absolute top-9 right-0 z-[5] flex w-[280px] flex-col gap-2.5 rounded-[14px] border border-line bg-panel p-4 shadow-panel">
          <Field label="New team" v-slot="{ id }"><input :id="id" name="new_organization" type="text" maxlength="80" required></Field>
          <span class="text-[13px] text-faint">Its own servers, keys and people. You are its Owner.</span>
          <div class="flex flex-wrap gap-2"><Btn small primary type="submit">Create</Btn></div>
        </Form>
      </details>
    </template>
    <FormError :message="page.props.errors.new_organization" />
    <div class="flex items-center justify-between gap-3">
      <span class="text-base font-semibold text-ink-strong">{{ org }}</span>
      <Disclosure v-if="can('members')" label="Rename" small :open="!!page.props.errors.name">
        <Form action="/account/organization" method="post">
          <FormRow>
            <Field label="Name" v-slot="{ id }"><input :id="id" name="name" type="text" :value="org" maxlength="80" required></Field>
            <Btn primary type="submit">Save</Btn>
          </FormRow>
          <FormError :message="page.props.errors.name" />
        </Form>
      </Disclosure>
    </div>
  </Panel>

  <Panel v-if="can('members')" title="Consoles" note="A shell on a server, from the browser">
      <Toggle :model-value="team.consoleEnabled" @update:model-value="toggleConsoles">Let people open a console on this team's servers. It runs as <code>deploy</code>, or as root when asked for. Each one is in the audit log with who opened it; what is typed is not kept.</Toggle>
  </Panel>
</template>
