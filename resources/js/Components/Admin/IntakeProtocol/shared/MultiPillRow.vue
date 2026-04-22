<script setup>
// Multi-Select Pills. modelValue ist Array<string> (direkt) oder JSON-String
// (fuer form.flooring, form.bathroom_equipment — dort ist das DB-Feld ein String).
// Wir geben beim Toggle wieder in der gleichen Form zurueck wie wir's bekommen.
import { computed } from 'vue';

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

function toggle(value) {
  const cur = [...selected.value];
  const idx = cur.indexOf(value);
  if (idx >= 0) cur.splice(idx, 1);
  else cur.push(value);
  // Ausgabe im gleichen Typ zurueck wie der Input
  emit('update:modelValue', isStringMode.value ? JSON.stringify(cur) : cur);
}
</script>

<template>
  <div class="flex flex-wrap gap-1.5">
    <button
      v-for="(opt, i) in options" :key="i"
      type="button"
      @click="toggle(normalize(opt).value)"
      :class="[
        'px-3 py-1.5 rounded-full text-[12px] font-medium transition-colors',
        selected.includes(normalize(opt).value)
          ? 'bg-[#EE7600] text-white'
          : 'bg-white border border-border text-foreground hover:border-[#EE7600]/40'
      ]"
    >{{ normalize(opt).label }}</button>
  </div>
</template>
