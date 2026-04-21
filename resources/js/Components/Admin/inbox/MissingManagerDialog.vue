<script setup>
import { ref, inject } from 'vue'
import HausverwaltungFormDialog from '../HausverwaltungFormDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  propertyLabel: { type: String, default: '' },
})
const emit = defineEmits(['update:open', 'assigned'])

const saving = ref(false)

async function onSave(payload) {
  saving.value = true
  try {
    const r = await fetch(API.value + '&action=quick_create_and_assign_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, ...payload }),
    })
    const d = await r.json()
    if (d.success) {
      toast('Hausverwaltung angelegt')
      emit('assigned', {
        id: d.manager.id,
        company_name: d.manager.company_name,
        email: d.manager.email,
      })
      emit('update:open', false)
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <HausverwaltungFormDialog
    :open="open"
    :manager="null"
    :saving="saving"
    @update:open="emit('update:open', $event)"
    @save="onSave"
  />
</template>
