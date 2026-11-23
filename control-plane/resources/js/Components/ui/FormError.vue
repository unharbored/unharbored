<script setup>
/** An error line that shows only when there is a message: <FormError :message="errors.remove" />. */
defineProps({ message: String });
</script>

<template>
  <p v-if="message" class="m-0 text-[13px] text-failed" role="alert">{{ message }}</p>
</template>
