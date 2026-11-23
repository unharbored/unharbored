<script setup>
import { Form, usePage } from '@inertiajs/vue3';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';
import DataTable from '../ui/DataTable.vue';
import Disclosure from '../ui/Disclosure.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import FormRow from '../ui/FormRow.vue';
import Panel from '../ui/Panel.vue';
import { selectClass } from '../Shell/classes';

defineProps({ people: Object, userId: Number });

const page = usePage();
const can = (permission) => page.props.can[permission];
const ucfirst = (s) => s.charAt(0).toUpperCase() + s.slice(1);
</script>

<template>
  <Panel title="People" :note="`${people.users.length} ${people.users.length === 1 ? 'person' : 'people'}`" flush>
    <DataTable class="[&_table]:min-w-0">
      <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <tr v-for="u in people.users" :key="u.id">
          <td class="font-semibold text-ink-strong">{{ u.name }} <Badge v-if="u.id === userId" plain>You</Badge></td>
          <td class="text-muted">{{ u.email }}</td>
          <td class="text-muted">
            <Form v-if="can('members') && u.id !== userId && (u.role !== 'owner' || can('ownership'))" :action="`/account/users/${u.id}/role`" method="post" class="inline-flex items-center gap-1.5">
              <label class="sr-only" :for="`role-${u.id}`">Role for {{ u.email }}</label>
              <select :id="`role-${u.id}`" name="role" :class="selectClass"><option v-for="r in people.allRoles" :key="r" :value="r" :selected="u.role === r">{{ ucfirst(r) }}</option></select>
              <Btn small type="submit">Save</Btn>
            </Form>
            <template v-else>{{ ucfirst(u.role) }}</template>
          </td>
          <td class="text-right">
            <Disclosure v-if="can('members') && u.id !== userId" label="Remove" small danger>
              <Form :action="`/account/users/${u.id}`" method="delete" class="flex flex-col items-end gap-2">
                <span class="text-[13px] text-faint">Signs {{ u.email }} out and deletes their account.</span>
                <Btn small danger type="submit">Yes, remove</Btn>
              </Form>
            </Disclosure>
          </td>
        </tr>
        <tr v-for="i in people.invites" :key="`i${i.id}`">
          <td class="text-faint">Invited</td>
          <td class="text-muted" colspan="2">{{ i.email }} <span class="text-[13px] text-faint">· {{ ucfirst(i.role) }} · by {{ i.by }} · expires {{ i.expires }}</span></td>
          <td class="text-right"><Form v-if="can('members')" :action="`/account/invitations/${i.id}`" method="delete"><Btn small type="submit">Withdraw</Btn></Form></td>
        </tr>
      </tbody>
    </DataTable>
    <Form v-if="can('members')" action="/account/invitations" method="post" class="flex flex-col gap-4 px-5 pt-4 pb-[22px] sm:px-7 sm:pt-[18px] sm:pb-6" reset-on-success #default="{ errors }">
      <FormRow>
        <Field label="Invite by email" v-slot="{ id }"><input :id="id" name="email" type="email" placeholder="name@example.com" autocomplete="off" list="invite-suggestions" required>
          <datalist id="invite-suggestions"><option v-for="s in people.suggestions" :key="s.email" :value="s.email">{{ s.name }}</option></datalist></Field>
        <Field label="Role" v-slot="{ id }"><select :id="id" name="role"><option v-for="r in people.roles" :key="r" :value="r" :selected="r === 'developer'">{{ ucfirst(r) }}</option></select></Field>
        <Btn primary type="submit">Invite</Btn>
      </FormRow>
      <FormError :message="errors.email" />
      <FormError :message="errors.role" />
      <span class="text-[13px] text-faint">They get a link to choose their name and password. Viewer reads only, Developer deploys and edits, Manager also creates and deletes and manages people, Owner also has billing.</span>
    </Form>
  </Panel>
</template>
