<script setup>
defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  nextDisabled: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Weiter →' },
});
defineEmits(['prev', 'next']);
</script>

<template>
  <div class="sticky bottom-0 z-10 bg-white border-t border-border/60 px-4 py-3 flex gap-2">
    <button
      type="button"
      @click="$emit('prev')"
      :disabled="currentStep === 1"
      class="flex-1 h-11 rounded-xl border border-border text-foreground font-medium disabled:opacity-40 disabled:cursor-not-allowed"
    >Zurück</button>
    <button
      type="button"
      @click="$emit('next')"
      :disabled="nextDisabled"
      class="flex-[2] h-11 rounded-xl bg-zinc-900 text-white font-semibold disabled:opacity-50"
    >{{ currentStep === totalSteps ? 'Absenden' : submitLabel }}</button>
  </div>
</template>
