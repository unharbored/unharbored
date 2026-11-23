<script setup>
/**
 * A table that scrolls sideways on a narrow screen. Put a normal <thead>/<tbody> inside; the head and
 * cell styling comes from here. <DataTable><thead><tr><th>Name</th></tr></thead><tbody>…</tbody></DataTable>
 */
</script>

<template>
  <div class="overflow-x-auto">
    <table
      class="w-full min-w-[820px] border-collapse text-sm [&_td]:border-b [&_td]:border-line-soft [&_td]:px-5 [&_td]:py-3.5 [&_td]:align-middle [&_th]:border-b [&_th]:border-dashed [&_th]:border-line [&_th]:px-5 [&_th]:pt-3.5 [&_th]:pb-2.5 [&_th]:text-left [&_th]:font-mono [&_th]:text-[9.5px] [&_th]:font-normal [&_th]:tracking-[0.18em] [&_th]:text-muted [&_th]:uppercase [&_tr:last-child_td]:border-b-0 [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-[var(--nav-hover)]"
    ><slot /></table>
  </div>
</template>
