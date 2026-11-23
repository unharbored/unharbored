<script setup>
/**
 * A tooltip around a button: <Tip title="Why it is off"><Btn disabled>…</Btn></Tip>.
 * A disabled button gets no hover events of its own, so it lets them through to this wrapper.
 */
defineProps({ title: String });
</script>

<template>
  <span
    class="inline-flex has-[>[aria-disabled=true]]:cursor-not-allowed has-[>[disabled]]:cursor-not-allowed [&>[aria-disabled=true]]:pointer-events-none [&>[disabled]]:pointer-events-none"
    :title="title"
  ><slot /></span>
</template>
