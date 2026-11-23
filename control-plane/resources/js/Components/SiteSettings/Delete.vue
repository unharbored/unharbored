<script setup>
import Field from '../ui/Field.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { computed, ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const deleteData = ref(false);
const blocked = computed(() => props.data.previews.length > 0);

async function deleteApp() {
    const data = deleteData.value ? 'Its database, uploaded files and stored secrets are erased for good.' : 'Its data is kept.';
    if (await ask({ title: `Delete ${props.site.name}?`, message: `${props.site.name} stops serving and leaves this console. ${data} Servers are not destroyed.`, ok: 'Delete application', tone: 'danger' })) {
        send('delete', { deleteData: deleteData.value });
    }
}
</script>

<template>
  <Panel id="delete" title="Delete application" note="Stops it everywhere">
      <p v-if="data.previews.length > 0" class="text-[13px] text-failed" role="alert">Delete its {{ data.previews.length }} preview{{ data.previews.length === 1 ? '' : 's' }} first, from the <a :href="`/site/settings?site=${site.id}&tab=previews`">Previews tab</a>.</p>
      <p class="text-muted text-[13px] mt-0">Deleting {{ site.name }} will:</p>
      <ul class="text-[13px] mt-0">
        <li>stop serving <b>{{ site.domain }}</b> on every server it runs on;</li>
        <li>remove it from this console (the audit log keeps what was done);</li>
        <li v-if="deleteData" class="text-failed"><b>erase for good</b> its database, uploaded files and stored secrets;</li>
        <li v-else>leave its files on each server, its database on its cluster, and its .env and deploy key in the vault, so its data can still be recovered by hand;</li>
        <li>never destroy a server or a shared database or cache cluster.</li>
      </ul>
      <p v-if="data.pool || data.balancer" class="text-faint text-[13px]">Its own {{ data.pool ? 'autoscale pool and Droplets' : '' }}{{ data.pool && data.balancer ? ' and ' : '' }}{{ data.balancer ? 'load balancer' : '' }} are deleted with it.</p>
      <p v-if="data.pool && !data.uploadsOnSpaces" class="text-faint text-[13px]">This application autoscales with uploads on its own servers, so deleting it requires the box below: without it, the member servers' uploads would be lost.</p>
      <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5"><input v-model="deleteData" type="checkbox"> Also erase its data for good: database, uploaded files and stored secrets. This cannot be undone.</label>
      <div class="flex flex-wrap gap-2">
        <Btn danger :disabled="blocked" @click="deleteApp">Delete application</Btn>
      </div>
      <FormError :message="error('delete')" />
  </Panel>
</template>
