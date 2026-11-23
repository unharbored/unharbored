<script setup>
import { note } from './serverActions.js';
import Drawer from '../ui/Drawer.vue';
import Chip from '../ui/Chip.vue';
import Btn from '../ui/Btn.vue';

defineProps({ open: Boolean, node: Object });
const emit = defineEmits(['close']);
const rowClass = 'flex justify-between gap-3 border-b border-line-soft py-2.5 text-[13px] last:border-b-0';
</script>

<template>
  <Drawer :open="open" title="Compute" :note="`The machine ${node.name} runs on.`" @close="emit('close')">
    <div>
      <div :class="rowClass"><span class="text-muted">Provider</span><span>{{ node.compute.providerLabel ?? 'Your own server' }}</span></div>
      <div :class="rowClass"><span class="text-muted">Region</span><span>{{ node.compute.regionLabel ?? node.compute.region ?? '—' }}</span></div>
      <div :class="rowClass"><span class="text-muted">Size</span><span class="font-mono">{{ node.compute.sizeLabel ?? node.compute.size ?? '—' }}</span></div>
      <div :class="rowClass"><span class="text-muted">Price</span><span class="font-mono">{{ node.compute.price ? 'about $' + node.compute.price + '/mo' : (node.compute.price === 0 ? 'none: your own hardware' : '—') }}</span></div>
      <div :class="rowClass"><span class="text-muted">Address</span><span class="font-mono">{{ node.ip ?? '—' }}</span></div>
      <div v-if="node.facts" :class="rowClass"><span class="text-muted">Details</span><span class="flex flex-wrap justify-end gap-1.5"><Chip v-for="f in node.facts.split(' · ')" :key="f">{{ f }}</Chip></span></div>
    </div>
    <span :class="note">Resizing a server is not available yet. To change its size, make a new server of the size you want and move the application to it.</span>
    <template #footer><span class="mr-auto"></span><Btn @click="emit('close')">Close</Btn></template>
  </Drawer>
</template>
