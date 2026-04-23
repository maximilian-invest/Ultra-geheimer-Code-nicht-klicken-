<script setup>
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { ChevronRight, ChevronLeft } from 'lucide-vue-next';

const props = defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  nextDisabled: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Weiter' },
});
defineEmits(['prev', 'next']);

const isLast = computed(() => props.currentStep === props.totalSteps);
</script>

<template>
  <div
    class="bg-white dark:bg-zinc-950 border-t p-4 flex gap-2"
    style="padding-bottom: calc(1rem + env(safe-area-inset-bottom));"
  >
    <Button
      variant="outline"
      size="lg"
      class="flex-1"
      :disabled="currentStep === 1"
      @click="$emit('prev')"
    >
      <ChevronLeft class="mr-1 size-4" />
      Zurück
    </Button>
    <Button
      size="lg"
      class="flex-[2]"
      :disabled="nextDisabled"
      @click="$emit('next')"
    >
      {{ isLast ? 'Absenden' : submitLabel }}
      <ChevronRight v-if="!isLast" class="ml-1 size-4" />
    </Button>
  </div>
</template>
