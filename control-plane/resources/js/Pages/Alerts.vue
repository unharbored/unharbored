<script setup>
import { onMounted, onUnmounted } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import Btn from '../Components/ui/Btn.vue';
import Check from '../Components/ui/Check.vue';
import Field from '../Components/ui/Field.vue';
import FormError from '../Components/ui/FormError.vue';
import FormRow from '../Components/ui/FormRow.vue';
import Notice from '../Components/ui/Notice.vue';
import PageHead from '../Components/ui/PageHead.vue';
import Panel from '../Components/ui/Panel.vue';
import { usePost } from '../composables/usePost';

defineOptions({ layout: Console });

const props = defineProps({
    open: Array,
    recent: Array,
    webhookSet: Boolean,
    email: String,
    mailReady: Boolean,
    rules: Object,
});

const webhook = useForm({ url: '' });
const email = useForm({ email: '' });
const rules = useForm({ ...props.rules });
const post = usePost();

function saveWebhook() {
    webhook.post('/alerts/webhook', { preserveScroll: true, onSuccess: () => webhook.reset() });
}
function saveEmail() {
    email.post('/alerts/email', { preserveScroll: true });
}
function saveRules() {
    rules.post('/alerts/rules', { preserveScroll: true });
}
function sendTest() {
    post('/alerts/test');
}

// What is open changes without anyone touching the page: look again every ten seconds.
let timer;
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['open', 'recent'], preserveScroll: true }), 10000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
  <Head title="Alerts" />
  <PageHead title="Alerts" lede="Checked every minute against the thresholds below. Each alert is sent once when it starts and once when it clears." />
  <Notice v-if="!webhookSet && !email"><span><b>No place to send alerts is set.</b> Problems will show on this page, but nobody will be told when one starts. Add a Slack or Discord webhook or an email address under "Where alerts go" below.</span></Notice>

  <Panel title="Open" :note="String(open.length)">
    <div v-for="a in open" :key="a.id" class="flex flex-wrap items-center justify-between gap-3">
      <span><b :class="a.severity === 'critical' ? 'text-failed' : 'text-running'">● {{ a.title }}</b> <span class="text-[13px] text-faint">· {{ a.detail }} · since {{ a.opened }}</span></span>
      <Btn v-if="a.link" small :href="a.link">Look</Btn>
    </div>
    <template v-if="open.length === 0">
      <span v-if="webhookSet || email" class="text-accent">● Nothing needs you.</span>
      <span v-else class="text-muted">No open alerts right now.</span>
    </template>
  </Panel>

  <Panel title="Where alerts go" note="Either, both, or neither: open alerts always show here">
    <FormRow tag="form" @submit.prevent="saveWebhook">
      <Field :label="`Webhook URL · ${webhookSet ? 'set; paste a new one to replace it, or save empty to remove' : 'Slack or Discord incoming webhook'}`" v-slot="{ id }">
        <input :id="id" v-model="webhook.url" type="url" placeholder="https://hooks.slack.com/services/…" autocomplete="off">
      </Field>
      <Btn type="submit" :disabled="webhook.processing">Save</Btn>
    </FormRow>
    <FormError :message="webhook.errors.webhook" />
    <FormRow tag="form" @submit.prevent="saveEmail">
      <Field :label="`Email · ${props.email ? `to ${props.email}` : 'not set'}`" v-slot="{ id }">
        <input :id="id" v-model="email.email" type="email" placeholder="you@example.com" autocomplete="off">
      </Field>
      <Btn type="submit" :disabled="email.processing">Save</Btn>
    </FormRow>
    <FormError :message="email.errors.email" />
    <span v-if="!mailReady" class="text-[13px] text-faint">Outgoing email is not set up on this installation, so email alerts are skipped for now. Use a webhook, or ask whoever runs this control plane to set up mail.</span>
    <div class="flex flex-wrap gap-2"><Btn small @click="sendTest">Send a test alert</Btn></div>
  </Panel>

  <Panel title="When to alert" note="0 turns a rule off">
    <FormRow tag="form" @submit.prevent="saveRules">
      <Field label="Offline, minutes" class="!flex-[0_1_150px]" v-slot="{ id }"><input :id="id" v-model.number="rules.offline_minutes" type="number" min="0" max="120"></Field>
      <Field label="Disk full, %" class="!flex-[0_1_150px]" v-slot="{ id }"><input :id="id" v-model.number="rules.disk_percent" type="number" min="0" max="99"></Field>
      <Field label="CPU above, %" class="!flex-[0_1_150px]" v-slot="{ id }"><input :id="id" v-model.number="rules.cpu_percent" type="number" min="0" max="100"></Field>
      <Field label="for, minutes" class="!flex-[0_1_150px]" v-slot="{ id }"><input :id="id" v-model.number="rules.cpu_minutes" type="number" min="2" max="120"></Field>
      <Field label="Certificate ends within, days" class="!flex-[0_1_150px]" v-slot="{ id }"><input :id="id" v-model.number="rules.certificate_days" type="number" min="0" max="90"></Field>
      <Check v-model="rules.failed_operations" class="self-end">Failed operations</Check>
      <Btn type="submit" :disabled="rules.processing">Save</Btn>
    </FormRow>
    <FormError :message="rules.errors.rules" />
  </Panel>

  <Panel title="Recent" note="Last 30 cleared">
    <div v-for="a in recent" :key="a.id" class="flex flex-wrap items-center justify-between gap-3">
      <span><span :class="a.severity === 'critical' ? 'text-failed' : 'text-running'">{{ a.title }}</span> <span class="text-[13px] text-faint">· {{ a.opened }}, cleared {{ a.resolved }}</span></span>
      <Btn v-if="a.link" small :href="a.link">Look</Btn>
    </div>
    <span v-if="recent.length === 0" class="text-faint">None yet.</span>
  </Panel>
</template>
