<script setup>
import { Button } from '@/components/ui/button';
import { X } from 'lucide-vue-next';

defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  title: { type: String, default: '' },
});
defineEmits(['cancel']);
</script>

<template>
  <div class="bg-white dark:bg-zinc-950 border-b px-4 py-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-xs font-medium uppercase tracking-wide text-muted-foreground tabular-nums">
        Schritt {{ currentStep }}/{{ totalSteps }}
      </div>
      <Button variant="ghost" size="icon-sm" @click="$emit('cancel')" aria-label="Abbrechen">
        <X class="h-4 w-4" />
      </Button>
    </div>
    <div class="h-1 w-full bg-muted rounded-full overflow-hidden">
      <div class="h-full bg-primary transition-all duration-300"
           :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"></div>
    </div>
    <h2 v-if="title" class="text-lg font-semibold mt-3">{{ title }}</h2>
  </div>
</template>
