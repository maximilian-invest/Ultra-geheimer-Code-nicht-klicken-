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
  <div
    :class="[
      'rounded-md border p-2 flex items-center gap-2 transition-colors',
      modelValue === 'available' && 'border-l-4 border-l-emerald-500',
      modelValue === 'missing' && 'border-l-4 border-l-destructive',
      modelValue === 'na' && 'border-l-4 border-l-muted-foreground/40',
    ]"
  >
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
      <!-- Semantische Farb-Kodierung: Da=grün, Fehlt=rot, N/A=grau.
           Gleiche Design-Richtlinie: subtile Border + Shadow-Fill bei aktiv. -->
      <ToggleGroupItem
        value="available" aria-label="Vorhanden"
        class="px-2.5 h-8 text-xs font-medium border border-border bg-card shadow-sm transition-all hover:shadow data-[state=on]:bg-emerald-600 data-[state=on]:text-white data-[state=on]:border-transparent data-[state=on]:shadow-md data-[state=on]:shadow-emerald-500/25"
      >
        Da
      </ToggleGroupItem>
      <ToggleGroupItem
        value="missing" aria-label="Fehlt"
        class="px-2.5 h-8 text-xs font-medium border border-border bg-card shadow-sm transition-all hover:shadow data-[state=on]:bg-destructive data-[state=on]:text-destructive-foreground data-[state=on]:border-transparent data-[state=on]:shadow-md data-[state=on]:shadow-destructive/25"
      >
        Fehlt
      </ToggleGroupItem>
      <ToggleGroupItem
        value="na" aria-label="Nicht anwendbar"
        class="px-2.5 h-8 text-xs font-medium border border-border bg-card shadow-sm transition-all hover:shadow data-[state=on]:bg-muted-foreground data-[state=on]:text-background data-[state=on]:border-transparent data-[state=on]:shadow-md"
      >
        N/A
      </ToggleGroupItem>
    </ToggleGroup>
  </div>
</template>
