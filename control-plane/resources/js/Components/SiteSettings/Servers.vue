<script setup>
import Btn from '../ui/Btn.vue';
import Notice from '../ui/Notice.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const server = ref('');
const poolMin = ref(props.data.pool?.min ?? 1);
const poolMax = ref(props.data.pool?.max ?? 3);
const windowDays = ref('weekdays');
const windowFrom = ref(13);
const windowTo = ref(22);
const windowMin = ref(2);
const dayChoices = { every: 'Every day', weekdays: 'Weekdays', weekends: 'Weekends', mon: 'Mondays', tue: 'Tuesdays', wed: 'Wednesdays', thu: 'Thursdays', fri: 'Fridays', sat: 'Saturdays', sun: 'Sundays' };

async function removeServer(s) {
    if (await ask({ title: 'Remove the server?', message: `Stop serving ${props.site.name} from ${s.name}? Its nginx site, workers, schedule and files there are removed, and the server loses access to the site's database and cache unless another site there uses them.`, ok: 'Remove' })) {
        send('servers/remove', { node: s.id });
    }
}
async function addServer() {
    if (await ask({ title: 'Add the server?', message: `Add this server to ${props.site.name}? It is let through the site's cluster firewalls and the live branch is deployed to every server.`, ok: 'Add' })) {
        send('servers/add', { server: server.value });
    }
}
async function balance() {
    if (await ask({ title: 'Add a load balancer?', message: `Create a DigitalOcean Load Balancer for ${props.site.name}? It costs about $12 a month. You point the domain at it afterwards.`, ok: 'Add load balancer' })) {
        send('balancer');
    }
}
async function autoscale() {
    const message = props.data.pool
        ? 'Change the limits of the autoscale pool?'
        : `Let Unharbored add servers to ${props.site.name}? Each is the provider's smallest size, added above 60% CPU for five minutes, and joins the site once it has the live release.`;
    if (await ask({ title: props.data.pool ? 'Change the limits?' : 'Autoscale?', message, ok: props.data.pool ? 'Change limits' : 'Autoscale' })) {
        send('pool', { min: poolMin.value, max: poolMax.value });
    }
}
async function stopAutoscaling() {
    if (await ask({ title: 'Stop autoscaling?', message: `Stop autoscaling ${props.site.name}? The pool and every server it added are deleted.`, ok: 'Stop', tone: 'danger' })) {
        send('pool/remove');
    }
}
async function resume() {
    if (await ask({ title: 'Resume?', message: 'Carry on adding servers? Do this once what stopped it is put right.', ok: 'Resume' })) {
        send('pool/resume');
    }
}
async function dropWindow(i) {
    if (await ask({ title: 'Remove the window?', message: 'Remove this window?', ok: 'Remove', tone: 'danger' })) {
        send('windows/remove', { index: i });
    }
}
function newWindow() {
    send('windows', { days: windowDays.value, from: windowFrom.value, to: windowTo.value, min: windowMin.value });
}
const hours = (h) => String(h).padStart(2, '0') + ':00';
</script>

<template>
  <Panel id="servers" title="Servers" note="Every deploy builds on all of them, then switches all of them">
      <div v-for="s in data.servers" :key="s.id" class="flex justify-between gap-3 items-center">
        <span><b class="font-semibold text-ink-strong">{{ s.name }}</b> <span class="text-faint text-[13px]">{{ s.primary ? 'primary · migrations, scheduler, HTTPS certificate' : (s.pooled ? 'added by autoscaling' : (data.balancer ? 'web and workers, behind the balancer' : 'web and workers · port 80 only until a load balancer fronts the site')) }}</span></span>
        <Btn small v-if="!s.primary && !s.pooled" @click="removeServer(s)">Remove</Btn>
      </div>
      <div v-if="data.spare.length > 0" class="flex flex-wrap items-end gap-3">
        <Field label="Add a server" class="flex-[1_1_180px]" v-slot="{ id }">
          <select :id="id" v-model="server"><option value="">Choose a server…</option><option v-for="n in data.spare" :key="n.id" :value="n.id">{{ n.name }}</option></select></Field>
        <Btn @click="addServer">Add</Btn>
      </div>
      <span v-else class="text-faint text-[13px]">No other server to add. <a href="/servers">Create one</a>.</span>
      <span v-if="data.balancer">Load balancer: <b class="text-accent">{{ data.balancer }}</b> <span class="text-faint text-[13px]">· point {{ site.domain }} here · HTTPS ends at the balancer with the site's own certificate</span></span>
      <div v-else-if="data.servers.length > 1" class="flex justify-between gap-3 items-center">
        <span class="text-faint text-[13px]">A DigitalOcean Load Balancer (about $12 a month) spreads traffic over these servers and checks /up on each.</span>
        <Btn @click="balance">Add load balancer</Btn>
      </div>
      <FormError :message="error('server')" />
      <template v-if="data.balancer">
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Autoscale, fewest" v-slot="{ id }"><input :id="id" v-model.number="poolMin" type="number" min="1" max="10"></Field>
          <Field label="most" v-slot="{ id }"><input :id="id" v-model.number="poolMax" type="number" min="1" max="10"></Field>
          <Btn @click="autoscale">{{ data.pool ? 'Change limits' : 'Autoscale' }}</Btn>
          <Btn danger v-if="data.pool" @click="stopAutoscaling">Stop</Btn>
        </div>
        <span class="text-faint text-[13px]"><template v-if="data.pool">Autoscaling between {{ data.pool.min }} and {{ data.pool.max }} extra servers, adding one above {{ Math.round((data.pool.cpu ?? 0.6) * 100) }}% CPU.<template v-if="(data.pool.live_min ?? data.pool.min) != data.pool.min"> At least {{ data.pool.live_min }} now, as scheduled.</template> </template>Pool servers serve web and run workers; the primary keeps building, migrating and scheduling.</span>
        <span v-if="error('pool')" class="text-[13px] text-failed">{{ error('pool') }}</span>
        <Notice v-if="data.pool?.paused_at" tone="bad" class="flex-col items-start!">
          <span><b>Autoscaling has stopped adding servers.</b> {{ data.pool.paused_because ?? '' }}</span>
          <Btn small @click="resume">Resume</Btn>
        </Notice>
        <span v-else-if="(data.pool?.failures ?? 0) > 0" class="text-faint text-[13px]">{{ data.pool.failures }} server{{ data.pool.failures === 1 ? '' : 's' }} in a row could not join. It stops at {{ data.scalerLimit }}. Last: {{ data.pool.last_failure ?? '' }}</span>
        <template v-if="data.pool">
          <div v-for="(w, i) in data.pool.schedule ?? []" :key="i" class="flex justify-between gap-3 items-center">
            <span>At least <b class="font-semibold text-ink-strong">{{ w.min }}</b> <span class="text-faint text-[13px]">· {{ w.days === 'every' ? 'every day' : w.days }}, {{ hours(w.from) }}–{{ hours(w.to) }} UTC{{ w.to < w.from ? ', past midnight' : '' }}{{ w.to === w.from ? ', all day' : '' }}</span></span>
            <Btn small danger :aria-label="`Remove window ${i + 1}`" @click="dropWindow(i)">Remove</Btn>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <Field label="Busy hours" v-slot="{ id }">
              <select :id="id" v-model="windowDays"><option v-for="(label, k) in dayChoices" :key="k" :value="k">{{ label }}</option></select></Field>
            <Field label="from, UTC" v-slot="{ id }"><input :id="id" v-model.number="windowFrom" type="number" min="0" max="23"></Field>
            <Field label="to" v-slot="{ id }"><input :id="id" v-model.number="windowTo" type="number" min="0" max="23"></Field>
            <Field label="at least" v-slot="{ id }"><input :id="id" v-model.number="windowMin" type="number" min="1" max="10"></Field>
            <Btn @click="newWindow">+ Add window</Btn>
          </div>
          <span class="text-faint text-[13px]">Servers ready before the load arrives. Outside every window the pool's own fewest applies; DigitalOcean still adds more on CPU.</span>
          <span v-if="error('window')" class="text-[13px] text-failed">{{ error('window') }}</span>
        </template>
      </template>
      <span class="text-faint text-[13px]">Needs a managed database, which also holds sessions, cache and queue unless Valkey does. Uploaded files are still per server.</span>
  </Panel>
</template>
