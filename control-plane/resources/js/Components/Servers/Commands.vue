<script setup>
import { statusClass } from './status.js';
import { note } from './serverActions.js';
import Empty from '../ui/Empty.vue';
import Btn from '../ui/Btn.vue';

defineProps({ commands: Object, consoleReady: Boolean, consoleNote: String });
const emit = defineEmits(['console']);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h2 class="m-0 font-body text-[17px] font-semibold text-ink-strong">Commands</h2>
      <p class="m-0 text-sm text-muted">What has been run on this server: operations, and the consoles opened on it.</p>
    </div>
    <Btn primary :disabled="!consoleReady" @click="emit('console')">Open console</Btn>
  </div>
  <p v-if="consoleNote" :class="note" class="m-0">{{ consoleNote }}</p>

  <section class="flex flex-col gap-2.5">
    <h3 class="m-0 font-body text-[15px] font-semibold text-ink-strong">Operations</h3>
    <div class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
      <Empty v-if="commands.operations.length === 0">Nothing has been run on this server yet.</Empty>
      <ul v-else class="m-0 list-none p-0">
        <li v-for="op in commands.operations" :key="op.id" class="flex flex-wrap justify-between gap-3 border-b border-line-soft px-[18px] py-3 text-sm last:border-b-0">
          <a :href="`/operation?operation=${op.id}`">{{ op.title }}</a>
          <span class="text-[13px]" :class="statusClass(op.status)">{{ op.status }} · {{ op.actor }} · {{ op.ago }}</span>
        </li>
      </ul>
    </div>
  </section>

  <section class="flex flex-col gap-2.5">
    <h3 class="m-0 font-body text-[15px] font-semibold text-ink-strong">Consoles</h3>
    <div class="overflow-hidden rounded-panel border border-line bg-panel shadow-panel">
      <Empty v-if="commands.sessions.length === 0">No console has been opened on this server.</Empty>
      <ul v-else class="m-0 list-none p-0">
        <li v-for="c in commands.sessions" :key="c.id" class="flex flex-wrap items-center gap-3 border-b border-line-soft px-[18px] py-3 font-mono text-sm last:border-b-0">
          <span class="size-[9px] flex-none rounded-full" :class="c.open ? 'bg-running' : (c.ended !== 'closed' && c.ended !== 'exited') ? 'bg-failed' : 'bg-faint'" :title="c.open ? 'open' : c.ended"></span>
          <span>Console {{ c.id.slice(0, 8) }}</span><span class="text-faint">as {{ c.as }}</span>
          <span class="ml-auto font-body text-[13px] whitespace-nowrap text-muted">{{ c.by }} · {{ c.ago }}</span>
        </li>
      </ul>
    </div>
    <p :class="note" class="m-0">Only that a console was opened is kept. What is typed in it is never stored.</p>
  </section>
</template>
