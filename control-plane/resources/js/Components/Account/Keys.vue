<script setup>
import { Form, usePage } from '@inertiajs/vue3';

import Btn from '../ui/Btn.vue';
import Check from '../ui/Check.vue';
import DataTable from '../ui/DataTable.vue';
import Disclosure from '../ui/Disclosure.vue';
import Field from '../ui/Field.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { usePost } from '../../composables/usePost';

defineProps({ keys: Array });

const page = usePage();
const post = usePost();
const toggleDefault = (k) => post(`/account/ssh-keys/${k.id}/default`, { on: k.default ? 0 : 1 }, { preserveState: false });
</script>

<template>
  <Panel title="SSH keys" note="The team's keys. Install each on the servers you choose.">
      <DataTable v-if="keys.length > 0" class="rounded-[14px] border border-line [&_table]:min-w-0">
        <tbody>
          <tr v-for="k in keys" :key="k.id">
            <td class="w-full"><span class="font-semibold text-ink-strong">{{ k.name }}</span><div class="font-mono text-[13px] break-words text-faint">{{ k.type }} · {{ k.fingerprint }} · added by {{ k.by }}</div></td>
            <td class="whitespace-nowrap text-muted">On {{ k.servers }} {{ k.servers === 1 ? 'server' : 'servers' }}</td>
            <td>
              <span class="inline-flex items-center gap-2">
                <span class="text-[13px] text-faint">New servers</span>
                <button type="button" class="relative h-[22px] w-10 flex-none cursor-pointer rounded-full border border-line-control bg-raised p-0 after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-muted after:transition after:content-[''] aria-checked:border-accent-line aria-checked:bg-accent-bg aria-checked:after:translate-x-[18px] aria-checked:after:bg-accent" role="switch" :aria-checked="k.default ? 'true' : 'false'" aria-label="Install on new servers" @click="toggleDefault(k)"></button>
              </span>
            </td>
            <td class="text-right">
              <Disclosure label="Remove" small danger>
                <Form :action="`/account/ssh-keys/${k.id}`" method="delete" class="flex flex-col items-end gap-2">
                  <span class="text-[13px] text-faint">Takes it off every server that has it.</span>
                  <Btn small danger type="submit">Yes, remove</Btn>
                </Form>
              </Disclosure>
            </td>
          </tr>
        </tbody>
      </DataTable>
      <span v-else class="text-[13px] text-faint">No keys yet.</span>
      <FormError :message="page.props.errors.key" />
      <Form action="/account/ssh-keys" method="post" class="flex flex-col gap-3" reset-on-success>
        <Field label="Add a key" v-slot="{ id }"><input :id="id" name="name" type="text" placeholder="Name, such as work-laptop" maxlength="60" required></Field>
        <div class="flex flex-col gap-[7px]">
          <textarea name="public_key" rows="3" spellcheck="false" aria-label="Public key" class="resize-y rounded-xl border border-line bg-raised px-3.5 py-3 font-mono text-[13px] leading-[1.6] text-ink-strong placeholder:text-faint" placeholder="ssh-ed25519 AAAAC3Nza… you@laptop" required></textarea>
          <span class="text-[13px] text-faint">Paste the public half, the file ending in <span class="font-mono">.pub</span>. A private key is refused. ed25519, ECDSA, or RSA of 3072 bits or more.</span>
          <FormError :message="page.props.errors.public_key" />
        </div>
        <div class="flex flex-wrap items-center gap-2"><Btn primary type="submit">Add key</Btn><Check name="install_on_new_servers" value="1" checked>Install on new servers</Check></div>
      </Form>
  </Panel>
</template>
