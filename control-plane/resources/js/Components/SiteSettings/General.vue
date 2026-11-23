<script setup>
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, error } = useSiteSettings(() => props.site.id);

const moveTo = ref(props.site.project);

function move() {
    if (!moveTo.value) return;
    send('project', { project: moveTo.value });
}
</script>

<template>
  <Panel id="general" title="General" note="Projects group applications; they do not restrict access">
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Project" v-slot="{ id }">
          <select :id="id" v-model.number="moveTo"><option v-for="pr in site.projects" :key="pr.id" :value="pr.id">{{ pr.name }}</option></select></Field>
        <Btn @click="move">Move</Btn>
      </div>
      <FormError :message="error('moveProject')" />
      <span class="text-faint text-[13px]">Its previews move with it.</span>
  </Panel>
</template>
