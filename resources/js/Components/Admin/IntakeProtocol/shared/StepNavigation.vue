<script setup>
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { ChevronRight, ChevronLeft, Check } from 'lucide-vue-next';

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
    <!-- Zurueck: deutlich abgegrenzter Outline-Button -->
    <Button
      variant="outline"
      size="lg"
      class="flex-1 border-2 border-border hover:border-foreground/30"
      :disabled="currentStep === 1"
      @click="$emit('prev')"
    >
      <ChevronLeft class="mr-1 size-4" />
      Zurück
    </Button>

    <!-- Weiter / Absenden: primary action in Orange (SR-Homes Brand),
         klare Shadow und weisser Text — unmoeglich zu uebersehen. -->
    <Button
      size="lg"
      class="flex-[2] bg-orange-500 hover:bg-orange-600 text-white shadow-md shadow-orange-500/30 font-semibold disabled:bg-orange-300 disabled:text-white disabled:opacity-100 disabled:shadow-none"
      :disabled="nextDisabled"
      @click="$emit('next')"
    >
      <Check v-if="isLast" class="mr-1 size-4" />
      {{ isLast ? 'Absenden' : submitLabel }}
      <ChevronRight v-if="!isLast" class="ml-1 size-4" />
    </Button>
  </div>
</template>
