<script setup>
import Field from '../ui/Field.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const newDomain = ref('');

async function moveDomain() {
    if (await ask({ title: 'Move the site?', message: `Move ${props.site.name} to the new domain? The old name stops being served.`, ok: 'Move site' })) {
        send('domain', { newDomain: newDomain.value });
    }
}
async function freeAddress() {
    if (await ask({ title: 'Use a free address?', message: `Serve ${props.site.name} at ${props.site.freeAddress}? It points at this server with no DNS to set up. You can move to your own domain later.`, ok: 'Use it' })) {
        send('domain/free');
    }
}
async function https() {
    if (await ask({ title: 'Turn on HTTPS?', message: `Issue a certificate for ${props.site.domain}? It must already point at this server.`, ok: 'Turn on HTTPS' })) {
        send('https');
    }
}
</script>

<template>
  <Panel id="domain" title="Domain &amp; HTTPS">
      <div v-if="site.freeAddress" class="flex flex-wrap items-center gap-3">
        <Btn primary @click="freeAddress">Use a free address</Btn>
        <span class="text-[13px] text-faint"><span class="font-mono">{{ site.freeAddress }}</span>: points at this server already, so there is nothing to set up. Move to a domain of your own whenever you like.</span>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <Field label="Move to domain" class="flex-[1_1_180px]" v-slot="{ id }"><input :id="id" v-model="newDomain" :placeholder="site.domain" autocomplete="off"></Field>
        <Btn @click="moveDomain">Move site</Btn>
      </div>
      <FormError :message="error('newDomain')" />
      <span class="text-faint text-[13px]">Moving checks the new name points at {{ site.node || 'this server' }} first, then issues a certificate and retires the old one.</span>
      <div class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 font-mono text-[13px] text-faint">
        <span>Domain: <b class="font-semibold text-ink-strong">{{ site.domain }}</b></span>
        <template v-if="site.tls">
          <span>HTTPS: <b class="text-accent">on</b></span><span>Let's Encrypt · renews itself</span><span>HTTP redirects to HTTPS</span>
        </template>
        <template v-else>
          <span>HTTPS: <b class="text-faint">off</b></span>
          <Btn small @click="https">Turn on HTTPS</Btn>
        </template>
      </div>
  </Panel>
</template>
