<script setup>
import { computed } from 'vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const props = defineProps({
  docKey: { type: String, required: true },
  label: { type: String, required: true },
  modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

// ToggleGroup gibt bei Click auf aktiven Wert `undefined` zurueck → wir lassen
// den Toggle-Zustand erhalten, denn der Dokumentstatus ist pflicht-dreistufig.
function onUpdate(v) {
  if (v === undefined || v === null) return;
  emit('update:modelValue', v);
}

const borderClass = computed(() => {
  if (props.modelValue === 'available') return 'border-l-green-500 bg-green-50/60';
  if (props.modelValue === 'missing') return 'border-l-red-500 bg-red-50/60';
  if (props.modelValue === 'na') return 'border-l-zinc-400 bg-zinc-100/60';
  return 'border-l-transparent bg-zinc-50/40';
});

const labelClass = computed(() => {
  if (props.modelValue === 'available') return 'text-green-900 font-medium';
  if (props.modelValue === 'missing') return 'text-red-900 font-medium';
  return '';
});
</script>

<template>
  <div :class="[
    'rounded-md border border-border/40 border-l-4 p-2 flex items-center gap-2',
    borderClass,
  ]">
    <div class="flex-1 text-sm" :class="labelClass">
      {{ label }}
    </div>
    <ToggleGroup
      type="single"
      size="sm"
      :model-value="modelValue || undefined"
      @update:model-value="onUpdate"
      class="shrink-0"
    >
      <ToggleGroupItem value="available" aria-label="Vorhanden"
        class="h-7 px-2 text-[10px] font-semibold data-[state=on]:bg-green-600 data-[state=on]:text-white data-[state=on]:border-green-600">
        Da
      </ToggleGroupItem>
      <ToggleGroupItem value="missing" aria-label="Fehlt"
        class="h-7 px-2 text-[10px] font-semibold data-[state=on]:bg-red-600 data-[state=on]:text-white data-[state=on]:border-red-600">
        Fehlt
      </ToggleGroupItem>
      <ToggleGroupItem value="na" aria-label="Nicht anwendbar"
        class="h-7 px-2 text-[10px] font-semibold data-[state=on]:bg-zinc-600 data-[state=on]:text-white data-[state=on]:border-zinc-600">
        N/A
      </ToggleGroupItem>
    </ToggleGroup>
  </div>
</template>
