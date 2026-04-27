<script setup>
import { ref, computed, inject, onMounted, onBeforeUnmount } from 'vue'
import { Send, Paperclip, Upload, X, FileText, Check, Users } from 'lucide-vue-next'
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

// recipients: [{ email, name }]
const draft = ref({
  property_id: null,
  recipients: [],
  subject: '',
  body: '',
})

// Per-Empfaenger Toggle (Bulk-Modus): true = wird mitgesendet
const recipientEnabled = ref({})

const uploadFiles = ref([])
const propertyFiles = ref([])
const propertyFilesLoading = ref(false)
const selectedFileIds = ref(new Set())
const selectedDocIds = ref(new Set())
const fileInput = ref(null)

const totalAttachmentCount = computed(() =>
  uploadFiles.value.length + selectedFileIds.value.size + selectedDocIds.value.size
)

const activeRecipients = computed(() =>
  draft.value.recipients.filter(r => recipientEnabled.value[r.email] !== false && r.email)
)

const isBulk = computed(() => draft.value.recipients.length > 1)

function fmtBytes(n) {
  if (!n) return ''
  if (n < 1024) return n + ' B'
  if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB'
  return (n / 1024 / 1024).toFixed(1) + ' MB'
}

async function loadPropertyFiles() {
  if (!draft.value.property_id) {
    propertyFiles.value = []
    return
  }
  propertyFilesLoading.value = true
  try {
    const r = await fetch(API.value + '&action=get_property_files&property_id=' + draft.value.property_id)
    const d = await r.json()
    propertyFiles.value = d.files || []
  } catch (e) {
    propertyFiles.value = []
  } finally {
    propertyFilesLoading.value = false
  }
}

function toggleFile(item) {
  const idStr = String(item.id)
  if (idStr.startsWith('doc_')) {
    const numId = parseInt(idStr.replace('doc_', ''))
    if (selectedDocIds.value.has(numId)) selectedDocIds.value.delete(numId)
    else selectedDocIds.value.add(numId)
    selectedDocIds.value = new Set(selectedDocIds.value)
  } else {
    const numId = parseInt(idStr)
    if (selectedFileIds.value.has(numId)) selectedFileIds.value.delete(numId)
    else selectedFileIds.value.add(numId)
    selectedFileIds.value = new Set(selectedFileIds.value)
  }
}

function isSelected(item) {
  const idStr = String(item.id)
  if (idStr.startsWith('doc_')) {
    return selectedDocIds.value.has(parseInt(idStr.replace('doc_', '')))
  }
  return selectedFileIds.value.has(parseInt(idStr))
}

function handleOpenEvent(ev) {
  const d = ev.detail || {}
  // Empfaenger normalisieren: entweder array of {email, name} oder single
  let recipients = []
  if (Array.isArray(d.recipients)) {
    recipients = d.recipients.filter(r => r && r.email).map(r => ({
      email: String(r.email).trim(),
      name: String(r.name || '').trim(),
    }))
  } else if (d.to) {
    recipients = [{ email: String(d.to).trim(), name: String(d.name || '').trim() }]
  }

  draft.value = {
    property_id: d.property_id,
    recipients,
    subject: d.subject || '',
    body: d.body || '',
  }
  // Standardmaessig alle aktiv.
  recipientEnabled.value = Object.fromEntries(recipients.map(r => [r.email, true]))

  uploadFiles.value = []
  selectedFileIds.value = new Set()
  selectedDocIds.value = new Set()
  propertyFiles.value = []
  open.value = true
  loadPropertyFiles()
}

onMounted(() => window.addEventListener('open-buyer-compose', handleOpenEvent))
onBeforeUnmount(() => window.removeEventListener('open-buyer-compose', handleOpenEvent))

function onAddFiles(ev) {
  const files = Array.from(ev.target.files || [])
  for (const f of files) {
    uploadFiles.value.push({ file: f, size: f.size, name: f.name })
  }
  if (fileInput.value) fileInput.value.value = ''
}

function removeUpload(idx) {
  uploadFiles.value.splice(idx, 1)
}

function toggleRecipient(email) {
  recipientEnabled.value[email] = !recipientEnabled.value[email]
  recipientEnabled.value = { ...recipientEnabled.value }
}

async function onSend() {
  if (sending.value) return
  const s = (draft.value.subject || '').trim()
  const b = (draft.value.body || '').trim()
  const recipients = activeRecipients.value
  if (recipients.length === 0) { toast('Mindestens ein Empfaenger erforderlich'); return }
  if (!s || !b) { toast('Betreff und Nachricht sind erforderlich'); return }

  sending.value = true
  try {
    const fd = new FormData()
    fd.append('property_id', String(draft.value.property_id || ''))
    fd.append('subject', s)
    fd.append('body', b)
    for (const r of recipients) {
      fd.append('to[]', r.email)
      fd.append('to_names[]', r.name || '')
    }
    for (const a of uploadFiles.value) {
      fd.append('attachments[]', a.file, a.name)
    }
    for (const id of selectedFileIds.value) {
      fd.append('file_ids[]', String(id))
    }
    for (const id of selectedDocIds.value) {
      fd.append('doc_ids[]', String(id))
    }

    const r = await fetch(API.value + '&action=send_to_buyer', {
      method: 'POST',
      body: fd,
    })
    const d = await r.json()
    if (d.success) {
      const sentCount = (d.sent || []).length
      const failedCount = (d.failed || []).length
      if (failedCount > 0) {
        toast(`${sentCount} gesendet, ${failedCount} fehlgeschlagen`, 'error')
      } else {
        toast(sentCount > 1 ? `An ${sentCount} Kaeufer:innen gesendet` : 'An Kaeufer:in gesendet')
      }
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
    <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Users v-if="isBulk" class="w-4 h-4" />
          {{ isBulk ? 'An alle Kaeufer:innen senden' : 'An Kaeufer:in senden' }}
        </DialogTitle>
        <DialogDescription>
          <template v-if="isBulk">
            Pro Empfaenger:in wird eine eigene Mail versandt (kein BCC). E-Mail-Adressen bleiben untereinander privat.
          </template>
          <template v-else>
            Entwurf vor dem Senden pruefen. Der Versand wird als outbound-Mail in der Konversation protokolliert.
          </template>
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3 py-2">
        <!-- Empfaengerliste mit Toggle -->
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1.5 block">
            Empfaenger:innen
            <span v-if="isBulk" class="ml-1 text-[10px] text-[#EE7600]">{{ activeRecipients.length }}/{{ draft.recipients.length }} aktiv</span>
          </label>
          <div v-if="!draft.recipients.length" class="text-xs text-muted-foreground italic">Keine Empfaenger:innen</div>
          <div v-else class="space-y-1">
            <div
              v-for="r in draft.recipients"
              :key="r.email"
              class="flex items-center gap-2 px-2.5 py-1.5 rounded-md text-xs"
              :class="recipientEnabled[r.email] !== false
                ? 'bg-zinc-50 border border-zinc-200'
                : 'bg-zinc-50/40 border border-zinc-100 opacity-50'"
            >
              <button
                v-if="isBulk"
                type="button"
                class="w-4 h-4 rounded flex items-center justify-center shrink-0"
                :class="recipientEnabled[r.email] !== false
                  ? 'bg-[#EE7600] border border-[#EE7600]'
                  : 'bg-white border border-zinc-300'"
                @click="toggleRecipient(r.email)"
              >
                <Check v-if="recipientEnabled[r.email] !== false" class="w-3 h-3 text-white" />
              </button>
              <span v-if="r.name" class="font-medium text-zinc-900 shrink-0">{{ r.name }}</span>
              <span class="text-muted-foreground truncate">{{ r.email }}</span>
            </div>
          </div>
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
            min-height="280px"
          />
        </div>

        <!-- Anhaenge -->
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-xs font-medium text-muted-foreground">Anhaenge</label>
            <span v-if="totalAttachmentCount" class="text-[10px] text-[#EE7600] font-medium tabular-nums">
              {{ totalAttachmentCount }} ausgewaehlt
            </span>
          </div>

          <div v-if="draft.property_id" class="rounded-lg p-2.5 mb-2 bg-zinc-50/60" style="border:1px solid hsl(240 5.9% 90%)">
            <div class="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold mb-1.5">
              Aus bestehenden Dateien waehlen
            </div>
            <div v-if="propertyFilesLoading" class="text-[11px] text-muted-foreground py-1">
              Lade Dateien...
            </div>
            <div v-else-if="!propertyFiles.length" class="text-[11px] text-muted-foreground py-1">
              Keine Dateien zu diesem Objekt hinterlegt.
            </div>
            <div v-else class="space-y-1">
              <button
                v-for="f in propertyFiles"
                :key="String(f.id)"
                type="button"
                @click="toggleFile(f)"
                class="w-full flex items-center gap-2 text-left text-xs px-2 py-1.5 rounded-md transition-colors"
                :class="isSelected(f)
                  ? 'bg-[#fff7ed] border border-[#EE7600]/30 text-zinc-900'
                  : 'hover:bg-zinc-100 border border-transparent'"
              >
                <span
                  class="w-4 h-4 rounded border flex items-center justify-center shrink-0"
                  :class="isSelected(f)
                    ? 'bg-[#EE7600] border-[#EE7600]'
                    : 'bg-white border-zinc-300'"
                >
                  <Check v-if="isSelected(f)" class="w-3 h-3 text-white" />
                </span>
                <FileText class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                <span class="flex-1 min-w-0 truncate">
                  <span class="font-medium">{{ f.label || f.filename }}</span>
                  <span v-if="f.label && f.label !== f.filename" class="text-muted-foreground"> · {{ f.filename }}</span>
                </span>
                <span class="text-[10px] text-muted-foreground tabular-nums shrink-0">{{ fmtBytes(f.file_size) }}</span>
              </button>
            </div>
          </div>

          <div v-if="uploadFiles.length" class="space-y-1 mb-2">
            <div
              v-for="(a, idx) in uploadFiles"
              :key="idx"
              class="flex items-center gap-2 text-xs px-2 py-1.5 rounded-md bg-emerald-50 border border-emerald-200"
            >
              <Upload class="w-3.5 h-3.5 text-emerald-600 shrink-0" />
              <span class="flex-1 truncate">{{ a.name }}</span>
              <span class="text-[10px] text-muted-foreground tabular-nums">{{ fmtBytes(a.size) }}</span>
              <button class="text-muted-foreground hover:text-foreground ml-1" @click="removeUpload(idx)">
                <X class="w-3 h-3" />
              </button>
            </div>
          </div>

          <button
            type="button"
            class="text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1"
            @click="fileInput?.click()"
          >
            <Paperclip class="w-3.5 h-3.5" />
            Neue Datei hochladen
          </button>
          <input ref="fileInput" type="file" class="hidden" multiple @change="onAddFiles" />
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="onCancel" :disabled="sending">Abbrechen</Button>
        <Button
          size="sm"
          class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white"
          @click="onSend"
          :disabled="sending || activeRecipients.length === 0"
        >
          <Send class="w-3.5 h-3.5 mr-1.5" />
          <span v-if="sending">Sende...</span>
          <span v-else-if="isBulk">An {{ activeRecipients.length }} senden</span>
          <span v-else>Senden</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
