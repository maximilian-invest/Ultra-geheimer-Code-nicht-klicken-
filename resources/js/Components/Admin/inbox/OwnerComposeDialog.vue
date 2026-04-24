<script setup>
import { ref, inject, onMounted, onBeforeUnmount } from 'vue'
import { Send, Paperclip, X } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const API = inject('API')
const toast = inject('toast', () => {})

const open = ref(false)
const sending = ref(false)
const draft = ref({
  property_id: null,
  owner: null,
  to: '',
  subject: '',
  body: '',
})
const attachments = ref([]) // [{ file: File, size: number, name: string }]
const fileInput = ref(null)

function handleOpenEvent(ev) {
  const d = ev.detail || {}
  draft.value = {
    property_id: d.property_id,
    owner: d.owner || null,
    to: d.to || d.owner?.email || '',
    subject: d.subject || '',
    body: d.body || '',
  }
  attachments.value = []
  open.value = true
}

onMounted(() => window.addEventListener('open-owner-compose', handleOpenEvent))
onBeforeUnmount(() => window.removeEventListener('open-owner-compose', handleOpenEvent))

function onAddFiles(ev) {
  const files = Array.from(ev.target.files || [])
  for (const f of files) {
    attachments.value.push({ file: f, size: f.size, name: f.name })
  }
  if (fileInput.value) fileInput.value.value = ''
}

function removeAttachment(idx) {
  attachments.value.splice(idx, 1)
}

async function onSend() {
  if (sending.value) return
  const s = (draft.value.subject || '').trim()
  const b = (draft.value.body || '').trim()
  const to = (draft.value.to || '').trim()
  if (!to) { toast('Empfänger fehlt'); return }
  if (!s || !b) { toast('Betreff und Nachricht sind erforderlich'); return }

  sending.value = true
  try {
    const fd = new FormData()
    fd.append('property_id', String(draft.value.property_id || ''))
    fd.append('to', to)
    fd.append('subject', s)
    fd.append('body', b)
    for (const a of attachments.value) {
      fd.append('attachments[]', a.file, a.name)
    }

    const r = await fetch(API.value + '&action=send_to_owner', {
      method: 'POST',
      body: fd,
    })
    const d = await r.json()
    if (d.success) {
      toast('An Eigentümer:in gesendet')
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
        <DialogTitle>An Eigentümer:in senden</DialogTitle>
        <DialogDescription>
          Entwurf vor dem Senden prüfen. Der Versand wird als outbound-Mail in der Konversation protokolliert.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3 py-2">
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">An</label>
          <Input v-model="draft.to" placeholder="empfaenger@example.com" />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Betreff</label>
          <Input v-model="draft.subject" placeholder="Betreff eintragen" />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Nachricht</label>
          <textarea
            v-model="draft.body"
            rows="14"
            class="w-full text-sm rounded-md border border-input px-3 py-2 bg-background font-sans leading-relaxed whitespace-pre-wrap"
            placeholder="Nachricht"
          ></textarea>
        </div>

        <!-- Anhaenge -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-xs font-medium text-muted-foreground">Anhänge</label>
            <button
              type="button"
              class="text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1"
              @click="fileInput?.click()"
            >
              <Paperclip class="w-3.5 h-3.5" />
              Datei anhängen
            </button>
          </div>
          <input ref="fileInput" type="file" class="hidden" multiple @change="onAddFiles" />
          <div v-if="attachments.length" class="space-y-1">
            <div
              v-for="(a, idx) in attachments"
              :key="idx"
              class="flex items-center justify-between text-xs px-2 py-1 rounded-md bg-muted/50"
            >
              <span class="truncate">{{ a.name }}</span>
              <button class="text-muted-foreground hover:text-foreground ml-2" @click="removeAttachment(idx)">
                <X class="w-3 h-3" />
              </button>
            </div>
          </div>
          <div v-else class="text-[11px] text-muted-foreground">Keine Anhänge — z.B. Exposé-PDF per Klick anhängen.</div>
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="onCancel" :disabled="sending">Abbrechen</Button>
        <Button
          size="sm"
          class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white"
          @click="onSend"
          :disabled="sending"
        >
          <Send class="w-3.5 h-3.5 mr-1.5" />
          <span v-if="sending">Sende…</span>
          <span v-else>Senden</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
