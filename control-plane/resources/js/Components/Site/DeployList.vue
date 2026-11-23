<script setup>
/** The framed list of deploys on an application's pages: <DeployList><DeployRow …/> <li v-if="none"><Empty>…</Empty></li></DeployList>. */
</script>

<template>
  <ul class="m-0 list-none overflow-hidden rounded-[18px] border border-line bg-panel p-0"><slot /></ul>
</template>
