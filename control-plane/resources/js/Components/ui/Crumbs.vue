<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * The small trail above a title: <Crumbs :items="[{ label: 'sites', href: '/sites', inertia: true }, { label: 'shop' }]" />.
 * An item with no href is plain text (the page you are on). `inertia` makes the link a client-side visit.
 */
defineProps({ items: Array });
</script>

<template>
  <span class="m-0 font-mono text-[10px] tracking-[0.2em] text-accent uppercase">
    <template v-for="(item, i) in items" :key="i">
      <span v-if="i > 0" class="text-line-control" aria-hidden="true"> / </span>
      <component :is="item.inertia ? Link : 'a'" v-if="item.href" :href="item.href">{{ item.label }}</component>
      <template v-else>{{ item.label }}</template>
    </template>
  </span>
</template>
