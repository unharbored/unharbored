<script setup>
import { ref } from 'vue';
import { json, serverUrl } from './api.js';
import Btn from '../ui/Btn.vue';
import Tip from '../ui/Tip.vue';

const props = defineProps({ node: String, tip: String, rotatable: Boolean, rotateDisabled: Boolean });
const emit = defineEmits(['rotate']);

const shown = ref(null);
const failed = ref(false);

async function reveal() {
    failed.value = false;
    try {
        shown.value = (await json('POST', serverUrl(props.node, 'sudo/reveal'))).password;
    } catch {
        failed.value = true;
    }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <span class="text-muted">Sudo password
      <b class="font-mono font-semibold text-ink-strong">{{ shown ?? '••••••••••••••••••••••••' }}</b></span>
    <span class="flex flex-wrap gap-2">
      <Btn v-if="shown !== null" small @click="shown = null">Hide</Btn>
      <Btn v-else small @click="reveal">Reveal</Btn>
      <Tip v-if="rotatable" :title="tip"><Btn small :disabled="rotateDisabled" @click="emit('rotate')">Rotate</Btn></Tip>
    </span>
  </div>
  <span v-if="failed" class="text-[13px] text-failed" role="alert">The password could not be read.</span>
  <span class="text-[13px] text-faint">Revealing is recorded in the audit log.</span>
</template>
