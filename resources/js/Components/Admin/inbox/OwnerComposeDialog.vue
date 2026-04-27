<script setup>
import { ref, computed, inject, onMounted, onBeforeUnmount } from 'vue'
import { Send, Paperclip, Upload, X, FileText, Check } from 'lucide-vue-next'
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

// Anhang-Modell
//   uploadFiles: frische File-Objekte aus dem File-Input
//   propertyFiles: bereits hochgeladene Property-Files (vom Server geladen)
//   selectedFileIds: IDs aus property_files (Set)
//   selectedDocIds: IDs aus portal_documents (Set, im Backend mit doc_-Prefix)
const uploadFiles = ref([]) // [{ file: File, name: string, size: number }]
const propertyFiles = ref([]) // [{ id, label, filename, file_size, source }]
const propertyFilesLoading = ref(false)
const selectedFileIds = ref(new Set())
const selectedDocIds = ref(new Set())
const fileInput = ref(null)

const totalAttachmentCount = computed(() =>
  uploadFiles.value.length + selectedFileIds.value.size + selectedDocIds.value.size
)

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
  // item.id ist entweder int (property_files) oder "doc_<n>" (portal_documents)
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
  draft.value = {
    property_id: d.property_id,
    owner: d.owner || null,
    to: d.to || d.owner?.email || '',
    subject: d.subject || '',
    body: d.body || '',
  }
  uploadFiles.value = []
  selectedFileIds.value = new Set()
  selectedDocIds.value = new Set()
  propertyFiles.value = []
  open.value = true
  loadPropertyFiles()
}

onMounted(() => window.addEventListener('open-owner-compose', handleOpenEvent))
onBeforeUnmount(() => window.removeEventListener('open-owner-compose', handleOpenEvent))

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
    for (const a of uploadFiles.value) {
      fd.append('attachments[]', a.file, a.name)
    }
    for (const id of selectedFileIds.value) {
      fd.append('file_ids[]', String(id))
    }
    for (const id of selectedDocIds.value) {
      fd.append('doc_ids[]', String(id))
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
    <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
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
            rows="12"
            class="w-full text-sm rounded-md border border-input px-3 py-2 bg-background font-sans leading-relaxed whitespace-pre-wrap"
            placeholder="Nachricht"
          ></textarea>
        </div>

        <!-- Anhaenge: Property-Files (auswählbar) + neue Uploads -->
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-xs font-medium text-muted-foreground">Anhänge</label>
            <span v-if="totalAttachmentCount" class="text-[10px] text-[#EE7600] font-medium tabular-nums">
              {{ totalAttachmentCount }} ausgewählt
            </span>
          </div>

          <!-- Bereits hochgeladene Dateien aus dem Property-Detail "Dateien"-Tab -->
          <div v-if="draft.property_id" class="rounded-lg p-2.5 mb-2 bg-zinc-50/60" style="border:1px solid hsl(240 5.9% 90%)">
            <div class="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold mb-1.5">
              Aus bestehenden Dateien wählen
            </div>
            <div v-if="propertyFilesLoading" class="text-[11px] text-muted-foreground py-1">
              Lade Dateien…
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

          <!-- Frische Uploads -->
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
