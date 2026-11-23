<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * A panel that slides over the right of the page, over a dimmed backdrop:
 *   <Drawer :open="open" title="Firewall" note="Rules are rebuilt each time." @close="open = false">
 *     body…
 *     <template #footer>…buttons…</template>
 *   </Drawer>
 * #tabs sits under the title. `expandable` adds a Full screen button that makes the drawer cover the whole
 * screen, and back; `wide` starts it wider. Escape leaves full screen first, then closes.
 */
const props = defineProps({ open: Boolean, title: String, note: String, wide: Boolean, expandable: Boolean });
const emit = defineEmits(['close']);
const full = ref(false);

watch(() => props.open, (now) => {
    if (!now) {
        full.value = false;
    }
});

function key(e) {
    if (e.key !== 'Escape' || !props.open) {
        return;
    }
    if (full.value) {
        full.value = false;
    } else {
        emit('close');
    }
}
document.addEventListener('keydown', key);
onBeforeUnmount(() => document.removeEventListener('keydown', key));
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[60] bg-black/55" aria-hidden="true" @click="emit('close')"></div>
    <aside
      v-if="open"
      class="fixed z-[61] flex flex-col border border-line bg-panel shadow-panel"
      :class="full ? 'inset-0 rounded-none border-0' : ['top-3 right-3 bottom-3 max-w-[calc(100vw-24px)] rounded-[18px]', wide ? 'w-[760px]' : 'w-[480px]']"
      role="dialog" aria-modal="true" :aria-label="title"
    >
      <header class="flex flex-none items-start gap-3 px-6 pt-5">
        <div class="min-w-0 flex-1">
          <h2 class="m-0 font-display text-[21px] leading-tight font-semibold text-ink-strong">{{ title }}</h2>
          <p v-if="note" class="m-0 mt-0.5 text-[13px] text-faint">{{ note }}</p>
        </div>
        <button v-if="expandable" type="button" class="min-h-[34px] cursor-pointer rounded-[10px] border border-line bg-raised px-3 text-[13px] text-ink hover:border-line-control" @click="full = !full">{{ full ? '⤡ Narrow' : '⤢ Full screen' }}</button>
        <button type="button" class="size-[34px] cursor-pointer rounded-[10px] border border-line bg-raised text-lg leading-none text-muted hover:border-line-control hover:text-ink" aria-label="Close" @click="emit('close')">&times;</button>
      </header>
      <div v-if="$slots.tabs" class="flex-none px-4 pt-3"><slot name="tabs" /></div>
      <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-auto px-6 py-3"><slot /></div>
      <footer v-if="$slots.footer" class="flex flex-none flex-wrap items-center gap-2.5 border-t border-line-soft px-6 py-3.5"><slot name="footer" /></footer>
    </aside>
  </Teleport>
</template>
