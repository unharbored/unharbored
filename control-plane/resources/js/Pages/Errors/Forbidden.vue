<script setup>
import { h } from 'vue';
import { Head } from '@inertiajs/vue3';
import Console from '../../Layouts/Console.vue';
import Guest from '../../Layouts/Guest.vue';
import Btn from '../../Components/ui/Btn.vue';
import PageHead from '../../Components/ui/PageHead.vue';

// A signed-in person gets the console's frame; someone with no team (or nobody) gets the plain one.
defineProps({ host: String, message: String });
defineOptions({ layout: (_h, page) => h(page.props.shell ? Console : Guest, () => page) });
</script>

<template>
  <Head title="Not allowed" />
  <PageHead title="Not allowed" />
  <div class="flex flex-col gap-4 overflow-hidden rounded-panel border border-line bg-panel px-5 py-[22px] shadow-panel sm:px-7 sm:py-6">
    <p class="m-0">{{ message ?? 'Your role in this team does not allow this. Ask a team owner or manager if you need it.' }}</p>
    <div class="flex flex-wrap gap-2">
      <Btn href="/sites">Back to applications</Btn>
      <Btn href="/account?tab=people">See who can change roles</Btn>
    </div>
  </div>
</template>
