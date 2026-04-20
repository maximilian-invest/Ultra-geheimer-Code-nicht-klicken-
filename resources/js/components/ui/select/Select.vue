<script setup>
import { computed, provide } from "vue";
import { SelectRoot } from "reka-ui";

/**
 * shadcn-vue Select mit optionaler `clearable`-Eigenschaft.
 *
 * Wenn clearable=true gesetzt ist:
 *   - SelectContent blendet automatisch "— Keine Auswahl —" an der Spitze ein
 *     (siehe SelectContent.vue via inject/provide).
 *   - Ein leerer/null/"" modelValue zeigt den Placeholder (Sentinel '__none__').
 *   - Waehlt der User den Keine-Eintrag, wird `null` emittiert — nicht der
 *     Sentinel-String — damit der Save-Flow keine Probleme hat.
 *
 * Ohne clearable verhaelt sich Select wie bisher (Radix-Standard).
 */
const props = defineProps({
  open: { type: Boolean, required: false },
  defaultOpen: { type: Boolean, required: false },
  defaultValue: { type: null, required: false },
  modelValue: { type: null, required: false },
  by: { type: [String, Function], required: false },
  dir: { type: String, required: false },
  multiple: { type: Boolean, required: false },
  autocomplete: { type: String, required: false },
  disabled: { type: Boolean, required: false },
  name: { type: String, required: false },
  required: { type: Boolean, required: false },
  clearable: { type: Boolean, default: false },
  noneLabel: { type: String, default: '— Keine Auswahl —' },
});
const emits = defineEmits(["update:modelValue", "update:open"]);

const NONE = '__none__';

// Kindern (insbesondere SelectContent) mitteilen ob clearable aktiv ist,
// damit der "— Keine —"-Eintrag automatisch eingefuegt wird.
provide('select-clearable', computed(() => ({
  enabled: props.clearable,
  noneValue: NONE,
  noneLabel: props.noneLabel,
})));

// modelValue <-> Sentinel-Konvertierung, wenn clearable
const internalModel = computed({
  get() {
    if (!props.clearable) return props.modelValue;
    const v = props.modelValue;
    if (v === null || v === undefined || v === '') return NONE;
    return v;
  },
  set(v) {
    if (props.clearable && v === NONE) {
      emits('update:modelValue', null);
    } else {
      emits('update:modelValue', v);
    }
  },
});
</script>

<template>
  <SelectRoot
    v-model="internalModel"
    :open="open"
    :default-open="defaultOpen"
    :default-value="defaultValue"
    :by="by"
    :dir="dir"
    :multiple="multiple"
    :autocomplete="autocomplete"
    :disabled="disabled"
    :name="name"
    :required="required"
    @update:open="emits('update:open', $event)"
  >
    <slot />
  </SelectRoot>
</template>
