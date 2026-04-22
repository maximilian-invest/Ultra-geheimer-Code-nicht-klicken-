<script setup>
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

defineProps({
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
</script>

<template>
  <div class="rounded-md border p-2 flex items-center gap-2">
    <div class="flex-1 text-sm">
      {{ label }}
    </div>
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      :model-value="modelValue || undefined"
      @update:model-value="onUpdate"
      class="shrink-0"
    >
      <ToggleGroupItem value="available" aria-label="Vorhanden" class="px-2 text-xs">
        Da
      </ToggleGroupItem>
      <ToggleGroupItem value="missing" aria-label="Fehlt" class="px-2 text-xs">
        Fehlt
      </ToggleGroupItem>
      <ToggleGroupItem value="na" aria-label="Nicht anwendbar" class="px-2 text-xs">
        N/A
      </ToggleGroupItem>
    </ToggleGroup>
  </div>
</template>
