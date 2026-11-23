<script setup>
import { Form } from '@inertiajs/vue3';
import Btn from '../ui/Btn.vue';
import Chip from '../ui/Chip.vue';
import FormError from '../ui/FormError.vue';
import Panel from '../ui/Panel.vue';
import { confirm } from '../../composables/useConsole';
import { router } from '@inertiajs/vue3';

defineProps({ github: Object });

async function stopUsing(login) {
    if (await confirm({ title: 'Stop using this account?', message: `Stop using the repositories of ${login}? Applications already deployed from it keep their code.`, ok: 'Stop using', tone: 'danger' })) {
        router.delete('/account/github/accounts', { data: { login }, preserveScroll: true });
    }
}
</script>

<template>
  <Panel v-if="github" id="github-accounts" title="GitHub accounts" note="Personal accounts and organisations whose repositories can be deployed.">
      <FormError :message="github.error" />
      <div v-for="a in github.accounts" :key="a.login" class="flex flex-wrap items-center justify-between gap-3 py-1.5">
        <span><b class="font-semibold text-ink-strong">{{ a.login }}</b> <Chip>{{ a.type === 'Organization' ? 'organisation' : 'personal' }}</Chip>
          <template v-if="a.approved"><Chip>in use</Chip></template>
          <template v-else><Chip>not in use</Chip><br><span class="text-[13px] text-faint">Installed by someone other than the app's owner, so its repositories are not listed until you approve it.</span></template></span>
        <span class="flex flex-wrap gap-2">
          <Form v-if="!a.approved" action="/account/github/accounts" method="post"><input type="hidden" name="login" :value="a.login"><Btn small primary type="submit">Use its repositories</Btn></Form>
          <Btn small danger v-else-if="!a.owner && !a.manual" @click="stopUsing(a.login)">Stop using</Btn>
        </span>
      </div>
      <p v-if="github.accounts.length === 0 && !github.error" class="text-faint">The app is not installed on any account yet.</p>
      <template v-if="github.install_url">
        <p><Btn small primary :href="github.install_url" target="_blank" rel="noopener">Add a GitHub account or organisation…</Btn></p>
        <span class="text-[13px] text-faint">GitHub asks which account to install on. An organisation needs the app to be public (GitHub, Settings, Developer settings, GitHub Apps, Advanced) and an organisation owner to approve it. Installs by the app's owner are used at once; reload this page after, and approve any other here. To remove an installation altogether, uninstall the app on GitHub.</span>
      </template>
  </Panel>
</template>
