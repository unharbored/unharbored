<script setup>
/**
 * A header switcher: a button that opens a small menu of links or buttons.
 *   <SwitchMenu title="…"><template #summary>demo</template><a href="…">Other</a></SwitchMenu>
 * Items are plain <a>/<button> children; aria-current="true" marks the one in use. The layout closes
 * every open one on an outside click or Escape (it finds them by data-switch).
 */
defineProps({ title: String });
</script>

<template>
  <details data-switch class="group relative">
    <summary
      :title="title"
      class="flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-[10px] border border-transparent px-2.5 text-ink-strong select-none hover:border-line hover:bg-raised group-open:border-line group-open:bg-raised max-sm:min-h-11 [&::-webkit-details-marker]:hidden"
    ><slot name="summary" /> <span class="text-faint" aria-hidden="true">⌄</span></summary>
    <div
      role="menu"
      class="absolute top-[calc(100%+6px)] left-0 z-20 flex min-w-[230px] flex-col rounded-[14px] border border-line bg-panel p-1.5 shadow-[0_14px_32px_rgba(2,8,6,0.45)] [&>a]:rounded-lg [&>a]:px-2.5 [&>a]:py-2 [&>a]:text-ink [&>a]:no-underline [&>a:hover]:bg-[var(--nav-hover)] [&>a:hover]:no-underline [&>a[aria-current=true]]:text-butter [&>button]:cursor-pointer [&>button]:rounded-lg [&>button]:border-0 [&>button]:bg-transparent [&>button]:px-2.5 [&>button]:py-2 [&>button]:text-left [&>button]:text-ink [&>button:hover]:bg-[var(--nav-hover)] [&>button[aria-current=true]]:text-butter"
    ><slot /></div>
  </details>
</template>
