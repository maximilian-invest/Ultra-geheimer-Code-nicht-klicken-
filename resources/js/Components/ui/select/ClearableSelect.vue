<script setup>
/**
 * ClearableSelect — Drop-in-Ersatz fuer <Select> + <SelectTrigger> + <SelectContent>,
 * der automatisch einen "— Keine Auswahl —"-Eintrag am Anfang der Liste
 * anbietet. Wird dieser gewaehlt, emittiert das Komponente `null` ueber v-model.
 *
 * Radix/shadcn-Select erlaubt keinen leeren String als SelectItem-Value; daher
 * nutzen wir intern das Sentinel '__none__' und konvertieren beim
 * v-model-Update zu null.
 *
 * Usage:
 *   <ClearableSelect v-model="form.status" :class="inputCls" placeholder="Wählen...">
 *     <SelectItem value="aktiv">Aktiv</SelectItem>
 *     <SelectItem value="inaktiv">Inaktiv</SelectItem>
 *   </ClearableSelect>
 */
import { computed } from 'vue'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './index.js'

const props = defineProps({
  modelValue: { default: null },
  placeholder: { type: String, default: 'Wählen...' },
  class: { type: String, default: 'h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80' },
  noneLabel: { type: String, default: '— Keine Auswahl —' },
})
const emit = defineEmits(['update:modelValue'])

const NONE = '__none__'
const internal = computed({
  get() {
    const v = props.modelValue
    if (v === null || v === undefined || v === '' || v === NONE) return NONE
    return String(v)
  },
  set(v) {
    emit('update:modelValue', v === NONE ? null : v)
  },
})
</script>

<template>
  <Select v-model="internal">
    <SelectTrigger :class="props.class">
      <SelectValue :placeholder="placeholder" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem :value="NONE" class="text-muted-foreground italic">{{ noneLabel }}</SelectItem>
      <slot />
    </SelectContent>
  </Select>
</template>
