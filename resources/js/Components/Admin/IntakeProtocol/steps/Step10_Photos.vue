<script setup>
defineProps({
  form: Object,
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
  disclaimerText: String,
});
</script>

<template>
  <div class="p-4">
    <p class="text-sm text-muted-foreground">Step 10 — wird im nächsten Task gefüllt.</p>
  </div>
</template>
