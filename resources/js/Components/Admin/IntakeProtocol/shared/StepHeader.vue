<script setup>
import { Button } from '@/components/ui/button';
import { X, Save } from 'lucide-vue-next';

defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  title: { type: String, default: '' },
});
defineEmits(['cancel', 'save-close']);
</script>

<template>
  <div class="bg-white dark:bg-zinc-950 border-b px-4 py-3">
    <div class="flex items-center justify-between mb-2 gap-2">
      <div class="text-xs font-medium uppercase tracking-wide text-muted-foreground tabular-nums">
        Schritt {{ currentStep }}/{{ totalSteps }}
      </div>
      <div class="flex items-center gap-1">
        <Button
          variant="ghost"
          size="sm"
          class="h-8 gap-1.5 text-xs"
          @click="$emit('save-close')"
        >
          <Save class="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Speichern & später</span>
          <span class="sm:hidden">Speichern</span>
        </Button>
        <Button variant="ghost" size="icon-sm" @click="$emit('cancel')" aria-label="Abbrechen">
          <X class="h-4 w-4" />
        </Button>
      </div>
    </div>
    <div class="h-1 w-full bg-muted rounded-full overflow-hidden">
      <div class="h-full bg-orange-500 transition-all duration-300"
           :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"></div>
    </div>
    <h2 v-if="title" class="text-lg font-semibold mt-3">{{ title }}</h2>
  </div>
</template>
