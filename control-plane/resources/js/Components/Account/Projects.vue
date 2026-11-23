<script setup>
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Btn from '../ui/Btn.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import FormRow from '../ui/FormRow.vue';
import { inputClass } from '../Shell/classes';
import Panel from '../ui/Panel.vue';
import { confirm } from '../../composables/useConsole';

defineProps({ projects: Array });

const page = usePage();
const errors = () => page.props.errors;
const create = useForm({ name: '' });
const editing = ref(0);
const editName = ref('');

function edit(p) {
    editing.value = p.id;
    editName.value = p.name;
}
function save() {
    router.patch(`/account/projects/${editing.value}`, { name: editName.value }, { preserveScroll: true, onSuccess: () => { editing.value = 0; } });
}
async function remove(p) {
    if (await confirm({ title: 'Delete it?', message: `Delete ${p.name}? It must have no applications.`, ok: 'Delete', tone: 'danger' })) {
        router.delete(`/account/projects/${p.id}`, { preserveScroll: true });
    }
}
</script>

<template>
  <Panel title="Projects" note="Projects group applications; they do not restrict access">
      <div v-for="p in projects" :key="p.id" class="flex flex-wrap items-center justify-between gap-3">
        <span v-if="editing === p.id"><input v-model="editName" :class="inputClass" aria-label="Project name" autocomplete="off"> <Btn small @click="save">Save</Btn></span>
        <template v-else>
          <span><b class="font-semibold text-ink-strong">{{ p.name }}</b> <span class="text-faint text-[13px]">· {{ p.applications }} {{ p.applications === 1 ? 'application' : 'applications' }}</span></span>
          <span v-if="p.slug !== 'default'" class="inline-flex gap-2">
            <Btn small @click="edit(p)">Rename</Btn>
            <Btn small danger @click="remove(p)">Delete</Btn>
          </span>
        </template>
      </div>
      <FormError :message="errors().editName" />
      <FormError :message="errors().delete" />
      <template v-if="$page.props.can.create">
        <FormRow tag="form" @submit.prevent="create.post('/account/projects', { preserveScroll: true, onSuccess: () => create.reset() })">
          <Field label="New project" v-slot="{ id }"><input :id="id" v-model="create.name" placeholder="A client or a product" maxlength="60" autocomplete="off"></Field>
          <Btn primary type="submit">Create project</Btn>
        </FormRow>
        <FormError :message="errors().newName" />
      </template>
  </Panel>
</template>
