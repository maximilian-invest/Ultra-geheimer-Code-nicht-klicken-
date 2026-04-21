<script setup>
import { ref, inject, computed } from 'vue'
import { Building2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import MissingManagerDialog from './MissingManagerDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  item: { type: Object, required: true },
  sourceEmailId: { type: [Number, String, null], default: null },
})

const missingMgrOpen = ref(false)
const loading = ref(false)

const propertyId = computed(() => Number(props.item?.property_id || 0))
const isVisible = computed(() => propertyId.value > 0)

async function onClick() {
  if (!propertyId.value) return
  // Backend ist source of truth — Frontend-property_manager_id kann bei
  // Race-Conditions stale sein. Einfach triggerDraft() aufrufen, Backend
  // meldet needs_manager=true wenn keine HV da ist.
  await triggerDraft()
}

async function onManagerAssigned(manager) {
  if (props.item) props.item.property_manager_id = manager.id
  missingMgrOpen.value = false
  await triggerDraft()
}

async function triggerDraft() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=contact_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: propertyId.value,
        template_kind: 'mieter_meldung',
        source_email_id: props.sourceEmailId,
      }),
    })
    const d = await r.json()

    // Backend meldet dass HV fehlt → Popup oeffnen
    if (d.needs_manager || (!d.success && /Hausverwaltung zugeordnet/i.test(d.error || ''))) {
      missingMgrOpen.value = true
      return
    }

    if (!d.success) {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
      return
    }

    window.dispatchEvent(new CustomEvent('open-hv-compose', {
      detail: {
        property_id: propertyId.value,
        manager: d.manager,
        subject: d.draft.subject,
        body: d.draft.body,
        attachments: d.draft.attachments || [],
        source_email_id: props.sourceEmailId,
      },
    }))
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Button v-if="isVisible"
          variant="outline" size="sm"
          class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white border-0 font-semibold shadow-sm"
          :disabled="loading" @click="onClick" title="Mieter-Meldung an Hausverwaltung weiterleiten">
    <Building2 class="w-3.5 h-3.5 mr-1.5" />
    {{ loading ? 'Lädt…' : 'An HV weiterleiten' }}
  </Button>

  <MissingManagerDialog
    v-model:open="missingMgrOpen"
    :property-id="propertyId"
    @assigned="onManagerAssigned"
  />
</template>
