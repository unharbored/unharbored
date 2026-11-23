<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import Console from '../Layouts/Console.vue';
import FormError from '../Components/ui/FormError.vue';
import Lede from '../Components/ui/Lede.vue';
import Notice from '../Components/ui/Notice.vue';
import PageHead from '../Components/ui/PageHead.vue';
import { inputClass } from '../Components/Shell/classes';
import Team from '../Components/Account/Team.vue';
import People from '../Components/Account/People.vue';
import Projects from '../Components/Account/Projects.vue';
import Keys from '../Components/Account/Keys.vue';
import Providers from '../Components/Account/Providers.vue';
import Composer from '../Components/Account/Composer.vue';
import GitHub from '../Components/Account/GitHub.vue';
import Login from '../Components/Account/Login.vue';

defineOptions({ layout: Console });

const props = defineProps({
    tab: String, tabs: Array, org: String, email: String, userId: Number, myRole: String, invite: Object,
    team: Object, people: Object, projects: Array, sshKeys: Array, providers: Object, composer: Object, github: Object,
});

const page = usePage();
</script>

<template>
  <Head title="Account" />
  <PageHead title="Account">
    <Lede>Signed in as <span class="font-semibold text-ink-strong">{{ email }}</span>. You are {{ myRole === 'owner' ? 'an' : 'a' }} {{ myRole || 'visitor' }} of {{ org }}.</Lede>
  </PageHead>
  <Notice v-if="invite" class="!flex-col !items-start">
    <span><b>Invitation for {{ invite.email }}.</b> {{ invite.emailed ? 'It was emailed, and this is the same link.' : 'Mail is not set up here, so send them this link yourself.' }} It works once and expires in 7 days. It is not shown again.</span>
    <input :class="[inputClass, 'w-full font-mono']" readonly :value="invite.link" aria-label="Invitation link" @focus="$event.target.select()">
  </Notice>
  <FormError :message="page.props.errors.remove" />
  <div class="flex flex-wrap items-start gap-8">
    <nav class="flex flex-[1_1_190px] flex-col gap-0.5 sm:sticky sm:top-4 sm:max-w-[220px]" aria-label="Account sections">
      <span class="px-2.5 pt-0 pb-1.5 font-mono text-[10px] tracking-[0.18em] text-muted uppercase max-sm:hidden">Account</span>
      <Link v-for="t in tabs" :key="t.key" :href="`/account?tab=${t.key}`" class="rounded-[10px] px-3 py-[7px] text-sm text-muted no-underline hover:bg-[var(--nav-hover)] hover:text-ink-strong hover:no-underline aria-[current=page]:bg-raised aria-[current=page]:font-medium aria-[current=page]:text-ink-strong" :aria-current="tab === t.key ? 'page' : undefined">{{ t.label }}</Link>
    </nav>
    <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-6">
      <Projects v-if="tab === 'projects'" :projects="projects" />
      <Team v-if="tab === 'team'" :org="org" :team="team" />
      <People v-if="tab === 'people'" :people="people" :user-id="userId" />
      <Keys v-if="tab === 'keys'" :keys="sshKeys" />
      <Providers v-if="tab === 'providers'" :providers="providers" />
      <Composer v-if="tab === 'composer'" :composer="composer" />
      <GitHub v-if="tab === 'github'" :github="github" />
      <Login v-if="tab === 'login'" />
    </div>
  </div>
</template>
