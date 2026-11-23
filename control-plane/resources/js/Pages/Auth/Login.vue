<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Guest from '../../Layouts/Guest.vue';
import AuthCard from '../../Components/Shell/AuthCard.vue';
import Btn from '../../Components/ui/Btn.vue';
import Field from '../../Components/ui/Field.vue';
import FormError from '../../Components/ui/FormError.vue';

defineOptions({ layout: Guest });

const form = useForm({ email: '', password: '' });

function submit() {
    form.post('/login', { onError: () => form.reset('password') });
}
</script>

<template>
  <Head title="Sign in" />
  <AuthCard as="form" label="auth" detail="password" title="Sign in" @submit.prevent="submit">
    <Field label="Email" v-slot="{ id }">
      <input :id="id" v-model="form.email" name="email" type="email" autocomplete="username" required autofocus>
    </Field>
    <Field label="Password" v-slot="{ id }">
      <input :id="id" v-model="form.password" name="password" type="password" autocomplete="current-password" required>
    </Field>
    <FormError :message="form.errors.email" />
    <Btn type="submit" primary :disabled="form.processing">Sign in →</Btn>
  </AuthCard>
</template>
