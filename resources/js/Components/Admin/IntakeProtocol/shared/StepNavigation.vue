<script setup>
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { ChevronRight } from 'lucide-vue-next';

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
  <div class="sticky bottom-0 bg-background border-t p-4 flex gap-2">
    <Button
      variant="outline"
      size="lg"
      class="flex-1"
      :disabled="currentStep === 1"
      @click="$emit('prev')"
    >
      Zurück
    </Button>
    <Button
      size="lg"
      class="flex-[2]"
      :disabled="nextDisabled"
      @click="$emit('next')"
    >
      {{ isLast ? 'Absenden' : submitLabel }}
      <ChevronRight v-if="!isLast" class="ml-1 h-4 w-4" />
    </Button>
  </div>
</template>
