<script setup>
import { computed } from 'vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  multiline: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

function normalize(opt) {
  if (typeof opt === 'string') return { value: opt, label: opt };
  return opt;
}

// ToggleGroup gibt bei Klick auf bereits gewaehlten Wert `null`/leer zurueck
// (de-select). Fuer unsere semantisch "erforderliche Single-Choice"-Pills
// behalten wir den Wert aber — wer umschalten will, klickt einen anderen Pill.
const internal = computed({
  get: () => (props.modelValue === '' || props.modelValue == null) ? undefined : props.modelValue,
  set: (v) => {
    if (v === undefined || v === null || v === '') return;
    emit('update:modelValue', v);
  },
});
</script>

<template>
  <ToggleGroup
    type="single"
    variant="outline"
    size="sm"
    :model-value="internal"
    @update:model-value="(v) => internal = v"
    :class="['justify-start', multiline ? 'flex-wrap' : 'flex-nowrap']"
  >
    <ToggleGroupItem
      v-for="(opt, i) in options" :key="i"
      :value="normalize(opt).value"
      class="rounded-full px-3"
    >
      {{ normalize(opt).label }}
    </ToggleGroupItem>
  </ToggleGroup>
</template>
