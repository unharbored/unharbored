<script setup>
import { Form } from '@inertiajs/vue3';
import Btn from '../ui/Btn.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
</script>

<template>
  <Panel title="Change password" note="At least 12 characters. Other sessions are signed out.">
    <Form action="/account/password" method="post" class="flex flex-col gap-4" reset-on-success #default="{ errors }">
      <Field label="New password" :error="errors.password" v-slot="{ id }">
        <input :id="id" name="password" type="password" autocomplete="new-password" minlength="12" required>
      </Field>
      <Field label="New password again" v-slot="{ id }">
        <input :id="id" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
      </Field>
      <div class="flex flex-wrap gap-2"><Btn primary type="submit">Change password</Btn></div>
    </Form>
  </Panel>
</template>
