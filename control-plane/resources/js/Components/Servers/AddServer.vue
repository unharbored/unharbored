<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { confirm } from '../../composables/useConsole.js';
import Drawer from '../ui/Drawer.vue';
import Field from '../ui/Field.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Toggle from '../ui/Toggle.vue';

const props = defineProps({ open: Boolean, catalogs: Object, newKeys: Array });
const emit = defineEmits(['close']);

const form = useForm({ name: '' });
// "incus-host": the machine runs Incus and holds servers in containers; nothing else runs on it.
const role = ref('');
const hostable = computed(() => Object.fromEntries(Object.entries(props.catalogs).filter(([key]) => key !== 'incus')));
const first = () => ('digitalocean' in props.catalogs ? 'digitalocean' : Object.keys(props.catalogs)[0] ?? 'digitalocean');
const choice = reactive({ provider: first(), region: props.catalogs[first()]?.region ?? '', size: props.catalogs[first()]?.size ?? '' });
const catalog = computed(() => props.catalogs[choice.provider] ?? null);
const price = computed(() => {
    const size = catalog.value?.sizes?.[choice.size] ?? '';
    const m = size.match(/~\$[\d.,]+\/mo/);

    return m ? `Will cost about ${m[0].slice(1)}.` : (catalog.value?.billed === false ? 'No monthly price: it runs on a machine you already pay for.' : '');
});

function setProvider() {
    const c = props.catalogs[choice.provider];
    if (c) {
        choice.region = c.region;
        choice.size = c.size;
    }
}

// An Incus host is a machine of its own, never a server on Incus.
function onRole() {
    if (role.value === 'incus-host' && !(choice.provider in hostable.value)) {
        choice.provider = Object.keys(hostable.value)[0];
        setProvider();
    }
}

// Until a switch is touched, the team's defaults apply.
const keysChosen = ref(false);
const picked = ref([]);
const isOn = (k) => (keysChosen.value ? picked.value.includes(k.id) : k.default);
function toggleKey(k) {
    if (!keysChosen.value) {
        picked.value = props.newKeys.filter((x) => x.default).map((x) => x.id);
        keysChosen.value = true;
    }
    picked.value = picked.value.includes(k.id) ? picked.value.filter((id) => id !== k.id) : [...picked.value, k.id];
}

async function create() {
    const message = role.value === 'incus-host'
        ? `Create an Incus host on ${catalog.value?.label ?? 'this provider'}? It bills that account from now until it is destroyed. Unharbored installs Incus on it and connects; nothing else runs on it.`
        : catalog.value?.billed === false
        ? `Create this server on your ${catalog.value.label} host?`
        : `Create this server? It bills your ${catalog.value?.label ?? 'provider'} account from now until it is destroyed.`;
    if (!(await confirm({ message }))) {
        return;
    }
    router.post('/servers', { name: form.name, region: choice.region, size: choice.size, keys: keysChosen.value ? picked.value : [], chosen: keysChosen.value, provider: choice.provider, role: role.value || null },
        { preserveScroll: true, onError: (e) => form.setError(e) });
}
</script>

<template>
  <Drawer :open="open" title="New server" note="Preflight runs first; nothing is created if it fails" @close="emit('close')">
      <div class="flex flex-col gap-3">
        <Field label="Name" v-slot="{ id }"><input :id="id" v-model="form.name" placeholder="web-2" autocomplete="off"></Field>
        <Field v-if="Object.keys(hostable).length > 0" label="What it is for" v-slot="{ id }">
          <select :id="id" v-model="role" @change="onRole">
            <option value="">Applications and services</option>
            <option value="incus-host">An Incus host: servers live inside it as containers</option>
          </select></Field>
        <span v-if="role === 'incus-host'" class="text-[13px] text-faint">Unharbored installs Incus, connects to it and sets up the IPv4 ingress. Nothing else runs on this machine; your servers do, picked with Incus as their provider. The provider must give it an IPv6 /64.</span>
        <Field v-if="Object.keys(catalogs).length > 1" label="Provider" v-slot="{ id }">
          <select :id="id" v-model="choice.provider" @change="setProvider"><option v-for="(c, key) in (role === 'incus-host' ? hostable : catalogs)" :key="key" :value="key">{{ c.label }}</option></select></Field>
        <Field label="Region" v-slot="{ id }">
          <select :id="id" v-model="choice.region"><option v-for="(label, slug) in catalog?.regions ?? {}" :key="slug" :value="slug">{{ slug }} · {{ label }}</option></select></Field>
        <Field label="Size" v-slot="{ id }">
          <select :id="id" v-model="choice.size"><option v-for="(label, slug) in catalog?.sizes ?? {}" :key="slug" :value="slug">{{ label }}</option></select></Field>
        <span v-if="catalog?.capacity && role !== 'incus-host'" class="text-[13px] text-faint">{{ catalog.capacity }}</span>
        <div v-if="catalog?.warning && role !== 'incus-host'" class="rounded-md border border-running-line bg-running-bg px-3 py-2.5 text-[13px]" role="note">
          <b class="font-semibold text-running">Reachable over IPv6 only, for now.</b> {{ catalog.warning }}
        </div>
      </div>
      <FormError :message="form.errors.name" />
      <div class="flex flex-col gap-1.5">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">SSH keys for the login user</span>
        <div v-for="k in newKeys" :key="k.id" class="flex items-center justify-between gap-3">
          <span><b class="font-semibold text-ink-strong">{{ k.name }}</b> <span class="text-[13px] text-faint">· {{ k.type }}<template v-if="k.default"> · on new servers by default </template></span></span>
          <Toggle :checked="isOn(k)" :label="`Install ${k.name} on the new server`" :title="isOn(k) ? 'Will be installed for the login user' : 'Will not be installed'" @click="toggleKey(k)" />
        </div>
        <span v-if="newKeys.length === 0" class="text-[13px] text-faint">No keys yet. Add one on the Account page, or install one on this server once it exists.</span>
      </div>
      <span class="text-[13px] text-faint">Creates the server, waits for its agent to enroll, then installs <template v-if="role === 'incus-host'">Incus instead of the server stack.</template><template v-else>the server stack.</template><template v-if="catalog?.billed !== false"> Billing starts when it is created.</template> The login user takes only the keys switched on here; with none, nobody can log in until a key is added on its card.</span>
  <template #footer>
      <span class="mr-auto text-[13px] text-faint">{{ price }}</span>
      <Btn @click="emit('close')">Cancel</Btn>
      <Btn primary @click="create">+ Create server</Btn>
    </template>
  </Drawer>
</template>
