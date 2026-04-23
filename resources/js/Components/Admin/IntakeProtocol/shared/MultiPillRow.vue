<script setup>
// Multi-Select Pills. modelValue ist Array<string> (direkt) oder JSON-String
// (fuer form.flooring, form.bathroom_equipment — dort ist das DB-Feld ein String).
// Wir geben beim Toggle wieder in der gleichen Form zurueck wie wir's bekommen.
//
// IMPORTANT: Laut MEMORY verliert ToggleGroup mit `useForwardPropsEmits` + spread
// bei async-geladenen Werten die Reaktivitaet. Wir binden :model-value explizit.
import { computed } from 'vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const props = defineProps({
  modelValue: { type: [Array, String], default: () => [] },
  options: { type: Array, required: true },  // [{value, label}] oder [string,...]
});
const emit = defineEmits(['update:modelValue']);

function normalize(opt) {
  if (typeof opt === 'string') return { value: opt, label: opt };
  return opt;
}

const isStringMode = computed(() => typeof props.modelValue === 'string');

const selected = computed(() => {
  const v = props.modelValue;
  if (Array.isArray(v)) return v;
  if (typeof v === 'string' && v.trim() !== '') {
    // Erst JSON versuchen, sonst Komma-Split (Legacy-Freitext).
    try {
      const parsed = JSON.parse(v);
      if (Array.isArray(parsed)) return parsed;
    } catch {}
    return v.split(/[,;]/).map(s => s.trim()).filter(Boolean);
  }
  return [];
});

function onUpdate(newArr) {
  const arr = Array.isArray(newArr) ? newArr : [];
  emit('update:modelValue', isStringMode.value ? JSON.stringify(arr) : arr);
}
</script>

<template>
  <ToggleGroup
    type="multiple"
    variant="outline"
    size="sm"
    :model-value="selected"
    @update:model-value="onUpdate"
    class="flex-wrap justify-start"
  >
    <!-- Design: siehe PillRow.vue — subtile Border + Shadow + Primary-Fill -->
    <ToggleGroupItem
      v-for="(opt, i) in options" :key="i"
      :value="normalize(opt).value"
      class="rounded-full px-3.5 h-9 text-xs font-medium border border-border bg-card shadow-sm transition-all hover:shadow data-[state=on]:bg-orange-500 data-[state=on]:text-white data-[state=on]:border-transparent data-[state=on]:shadow-md data-[state=on]:shadow-orange-500/40"
    >
      {{ normalize(opt).label }}
    </ToggleGroupItem>
  </ToggleGroup>
</template>
