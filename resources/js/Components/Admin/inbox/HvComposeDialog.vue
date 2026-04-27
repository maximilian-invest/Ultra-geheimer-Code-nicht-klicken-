<script setup>
import { ref, inject, onMounted, onBeforeUnmount } from 'vue'
import { Send, Paperclip } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import RichTextEditor from '@/Components/RichTextEditor.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const open = ref(false)
const sending = ref(false)
const draft = ref({
  property_id: null,
  manager: null,
  to: '',
  subject: '',
  body: '',
  attachments: [],
  source_email_id: null,
})

function handleOpenEvent(ev) {
  const d = ev.detail || {}
  draft.value = {
    property_id: d.property_id,
    manager: d.manager,
    to: d.manager?.email || '',
    subject: d.subject || '',
    body: d.body || '',
    attachments: d.attachments || [],
    source_email_id: d.source_email_id || null,
  }
  open.value = true
}

onMounted(() => window.addEventListener('open-hv-compose', handleOpenEvent))
onBeforeUnmount(() => window.removeEventListener('open-hv-compose', handleOpenEvent))

async function onSend() {
  if (sending.value) return
  if (!draft.value.subject.trim() || !draft.value.body.trim()) {
    toast('Betreff und Nachricht sind erforderlich')
    return
  }
  sending.value = true
  try {
    const r = await fetch(API.value + '&action=send_to_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: draft.value.property_id,
        subject: draft.value.subject,
        body: draft.value.body,
        attachment_file_ids: draft.value.attachments,
        source_email_id: draft.value.source_email_id,
      }),
    })
    const d = await r.json()
    if (d.success) {
      toast('An Hausverwaltung gesendet')
      open.value = false
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    sending.value = false
  }
}

function onCancel() { open.value = false }
</script>

<template>
  <Dialog :open="open" @update:open="open = $event">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>An Hausverwaltung senden</DialogTitle>
        <DialogDescription>
          Entwurf vor dem Senden prüfen. Der Versand erstellt einen neuen HV-Thread.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3 py-2">
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">An</label>
          <Input :model-value="draft.to + ' (' + (draft.manager?.company_name || '') + ')'" disabled />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Betreff</label>
          <Input v-model="draft.subject" placeholder="Betreff eintragen" />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Nachricht</label>
          <RichTextEditor
            v-model="draft.body"
            placeholder="Nachricht"
            min-height="260px"
          />
        </div>

        <div v-if="draft.attachments.length" class="text-xs text-muted-foreground flex items-center gap-2">
          <Paperclip class="w-3.5 h-3.5" />
          <span>{{ draft.attachments.length }} Anhang (Alleinvermittlungsauftrag)</span>
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="onCancel" :disabled="sending">Abbrechen</Button>
        <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white"
                @click="onSend" :disabled="sending">
          <Send class="w-3.5 h-3.5 mr-1.5" />
          <span v-if="sending">Sende…</span>
          <span v-else>Senden</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
