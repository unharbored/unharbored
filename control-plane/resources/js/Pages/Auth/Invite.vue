<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Guest from '../../Layouts/Guest.vue';
import AuthCard from '../../Components/Shell/AuthCard.vue';
import Btn from '../../Components/ui/Btn.vue';
import Field from '../../Components/ui/Field.vue';
import Lede from '../../Components/ui/Lede.vue';

defineOptions({ layout: Guest });

const props = defineProps({ invitation: Object, token: String });

const form = useForm({ name: '', password: '', password_confirmation: '' });

function submit() {
    form.post(`/invite/${props.token}`, { onError: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
  <Head title="Join" />
  <AuthCard v-if="invitation" as="form" label="invitation" :detail="invitation.email" title="Join" @submit.prevent="submit">
    <Lede>{{ invitation.invited_by }} invited you. Choose how you will sign in.</Lede>
    <Field label="Your name" :error="form.errors.name" v-slot="{ id }">
      <input :id="id" v-model="form.name" name="name" type="text" autocomplete="name" required autofocus>
    </Field>
    <Field label="Password" :error="form.errors.password" v-slot="{ id }">
      <input :id="id" v-model="form.password" name="password" type="password" autocomplete="new-password" minlength="12" required>
    </Field>
    <Field label="Password again" v-slot="{ id }">
      <input :id="id" v-model="form.password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
    </Field>
    <Btn type="submit" primary :disabled="form.processing">Create account →</Btn>
  </AuthCard>
  <AuthCard v-else label="invitation" detail="not valid" title="Link not valid">
    <Lede>This invitation was already used, has expired, or was withdrawn. Ask whoever invited you for a new one.</Lede>
    <Btn href="/login" inertia>Sign in</Btn>
  </AuthCard>
</template>
