<script setup>
import { ref, computed, onMounted, watch, inject } from "vue";
import { FileText, Upload, Trash2, Download, FileCheck2, FileX2, Pencil, Check, X, ExternalLink, FileDown } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

const files = ref([]);
const uploading = ref(false);
const dragOver = ref(false);
const fileInput = ref(null);

// Selection + delete dialog state.
const selectedIds = ref(new Set());
const showDeleteDialog = ref(false);
const deleteCandidates = ref([]); // array of file objects about to be deleted
const deleting = ref(false);

function fileIcon(ext) {
  const map = { pdf: '📄', doc: '📝', docx: '📝', xls: '📊', xlsx: '📊', jpg: '🖼️', jpeg: '🖼️', png: '🖼️' };
  return map[ext?.toLowerCase()] || '📎';
}

function getExt(filename) {
  if (!filename) return '';
  const parts = filename.split('.');
  return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

// Only real property_files can be deleted via this tab. Global or
// portal_documents arrive here with string-prefixed ids (e.g. "global_5")
// and must be managed in their own sections.
function isDeletable(f) {
  return typeof f.id === 'number' || /^\d+$/.test(String(f.id));
}

const deletableFiles = computed(() => files.value.filter(isDeletable));
const allSelected = computed(() =>
  deletableFiles.value.length > 0 && deletableFiles.value.every(f => selectedIds.value.has(f.id))
);
const anySelected = computed(() => selectedIds.value.size > 0);
const selectedCount = computed(() => selectedIds.value.size);

function toggleSelect(f) {
  const next = new Set(selectedIds.value);
  if (next.has(f.id)) next.delete(f.id); else next.add(f.id);
  selectedIds.value = next;
}

function toggleSelectAll() {
  if (allSelected.value) {
    selectedIds.value = new Set();
  } else {
    selectedIds.value = new Set(deletableFiles.value.map(f => f.id));
  }
}

function clearSelection() {
  selectedIds.value = new Set();
}

async function loadFiles() {
  if (!props.property?.id) return;
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    files.value = d.files || [];
    // Drop any now-stale selections.
    const alive = new Set(files.value.filter(isDeletable).map(f => f.id));
    selectedIds.value = new Set([...selectedIds.value].filter(id => alive.has(id)));
  } catch (e) {
    files.value = [];
    selectedIds.value = new Set();
  }
}

async function handleUpload(e) {
  const inputFiles = e.target?.files || e.dataTransfer?.files;
  if (!inputFiles || !inputFiles.length || !props.property?.id) return;
  dragOver.value = false;
  uploading.value = true;
  const total = inputFiles.length;
  let uploaded = 0;
  const propId = props.property.id;
  for (const file of inputFiles) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', propId);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        files.value.push(d.file);
        uploaded++;
      }
    } catch (err) {
      console.error('Upload failed:', err);
    }
  }
  uploading.value = false;
  if (e.target) e.target.value = '';
  toast(uploaded === total
    ? uploaded + ' Datei(en) hochgeladen'
    : uploaded + ' von ' + total + ' Datei(en) hochgeladen');
}

async function toggleWebsiteDownload(f) {
  try {
    const r = await fetch(API.value + '&action=toggle_website_download', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: f.id }),
    });
    const d = await r.json();
    if (d.success) {
      f.is_website_download = d.is_website_download;
      toast(d.is_website_download ? 'Download auf Website aktiviert' : 'Download von Website entfernt');
    }
  } catch (e) {
    console.error('Toggle failed:', e);
  }
}

const editingFileId = ref(null)
const editingLabel = ref('')

function startRename(f) {
  editingFileId.value = f.id
  editingLabel.value = f.label || f.original_name || f.filename || ''
}

function cancelRename() {
  editingFileId.value = null
  editingLabel.value = ''
}

async function saveRename(f) {
  const newLabel = editingLabel.value.trim()
  if (!newLabel) {
    toast('Name darf nicht leer sein')
    return
  }
  if (newLabel === (f.label || '')) {
    cancelRename()
    return
  }
  try {
    const r = await fetch(API.value + '&action=rename_property_file', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: f.id, label: newLabel }),
    })
    const d = await r.json()
    if (d.success) {
      f.label = d.label
      cancelRename()
      toast('Name geändert')
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

async function toggleAvaMarker(f) {
  const newState = !f.is_ava;
  try {
    const r = await fetch(API.value + '&action=mark_file_as_ava', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: f.id, is_ava: newState }),
    });
    const d = await r.json();
    if (d.success) {
      // Alle anderen entmarkieren wenn wir eine neue markieren
      if (newState) {
        files.value.forEach(other => { if (other.id !== f.id) other.is_ava = false; });
      }
      f.is_ava = newState;
      toast(newState ? 'Als Alleinvermittlungsauftrag markiert' : 'AVA-Markierung entfernt');
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch (e) {
    toast('Fehler: ' + e.message);
  }
}

function askDeleteOne(f) {
  deleteCandidates.value = [f];
  showDeleteDialog.value = true;
}

function askDeleteSelected() {
  const byId = new Map(files.value.map(f => [f.id, f]));
  const picked = [...selectedIds.value].map(id => byId.get(id)).filter(Boolean);
  if (!picked.length) return;
  deleteCandidates.value = picked;
  showDeleteDialog.value = true;
}

async function confirmDelete() {
  if (!deleteCandidates.value.length) return;
  deleting.value = true;
  let ok = 0;
  let failed = 0;
  for (const f of deleteCandidates.value) {
    try {
      const r = await fetch(API.value + '&action=delete_property_file', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_id: f.id }),
      });
      const d = await r.json();
      if (d.success) {
        ok++;
        files.value = files.value.filter(x => x.id !== f.id);
        const next = new Set(selectedIds.value);
        next.delete(f.id);
        selectedIds.value = next;
      } else {
        failed++;
      }
    } catch (e) {
      failed++;
    }
  }
  deleting.value = false;
  showDeleteDialog.value = false;
  deleteCandidates.value = [];
  if (failed === 0) {
    toast(ok === 1 ? 'Datei gelöscht' : ok + ' Dateien gelöscht');
  } else if (ok === 0) {
    toast('Löschen fehlgeschlagen');
  } else {
    toast(ok + ' gelöscht, ' + failed + ' fehlgeschlagen');
  }
}

onMounted(() => loadFiles());
watch(() => props.property?.id, () => { clearSelection(); loadFiles(); });
</script>

<template>
  <div class="space-y-6">
    <!-- Not saved yet -->
    <div v-if="!property?.id" class="flex flex-col items-center justify-center py-16 text-center">
      <Upload class="w-8 h-8 text-zinc-300 mb-3" />
      <p class="text-sm font-medium text-zinc-600">Objekt zuerst speichern</p>
      <p class="text-xs text-zinc-400 mt-1">Dateien koennen erst nach dem Speichern des Objekts hochgeladen werden.</p>
    </div>

    <template v-else>
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <h2 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Dateien</h2>
        <Badge variant="outline" class="text-[11px]">{{ files.length }}</Badge>
      </div>
      <Button size="sm" @click="fileInput?.click()">
        <Upload class="w-3.5 h-3.5 mr-1.5" />
        Datei hochladen
      </Button>
    </div>

    <!-- Exposé als virtuelles "Dokument" — ansehen im neuen Tab + PDF-Download -->
    <div class="rounded-lg p-4 bg-gradient-to-r from-orange-50 via-white to-white border border-orange-200 shadow-sm flex items-center gap-4">
      <div class="w-11 h-11 rounded-md bg-gradient-to-br from-orange-500 to-orange-600 text-white flex items-center justify-center font-serif font-semibold text-[15px] flex-shrink-0 shadow-sm">SR</div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
          <span class="text-sm font-semibold text-zinc-900">Exposé</span>
          <span class="text-[9px] font-bold tracking-wider bg-orange-500 text-white px-1.5 py-0.5 rounded">EXPOSÉ</span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">Automatisch generiertes Objekt-Exposé · immer aktuell aus den Property-Daten</div>
      </div>
      <a :href="`/admin/properties/${property.id}/expose/preview`" target="_blank" rel="noopener">
        <Button variant="outline" size="sm">
          <ExternalLink class="w-3.5 h-3.5 mr-1.5" />
          Öffnen
        </Button>
      </a>
      <a :href="`/admin/properties/${property.id}/expose/pdf`">
        <Button size="sm" class="bg-orange-500 hover:bg-orange-600 text-white">
          <FileDown class="w-3.5 h-3.5 mr-1.5" />
          PDF
        </Button>
      </a>
    </div>

    <!-- Dropzone -->
    <div
      @drop.prevent="handleUpload"
      @dragover.prevent="dragOver = true"
      @dragleave="dragOver = false"
      @click="fileInput?.click()"
      :class="[
        'border border-dashed rounded-xl p-8 text-center transition-all duration-200 cursor-pointer select-none',
        dragOver
          ? 'border-orange-400 bg-orange-50/60'
          : 'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50/50'
      ]"
    >
      <input
        ref="fileInput"
        type="file"
        multiple
        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
        class="hidden"
        @change="handleUpload"
      />
      <Upload class="w-7 h-7 mx-auto text-zinc-400 mb-2" />
      <p class="text-sm font-medium text-zinc-700">Dateien hierher ziehen oder klicken</p>
      <p class="text-xs text-zinc-400 mt-1">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</p>
    </div>

    <!-- Upload spinner -->
    <div v-if="uploading" class="flex items-center gap-2 text-sm text-zinc-500">
      <div class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></div>
      Lade hoch...
    </div>

    <!-- Bulk action bar -->
    <div v-if="anySelected" class="flex items-center justify-between rounded-lg border border-border/50 bg-zinc-50 px-4 py-2.5">
      <div class="flex items-center gap-3">
        <span class="text-sm font-medium text-zinc-900">{{ selectedCount }} ausgewählt</span>
        <button class="text-xs text-zinc-500 hover:text-zinc-900" @click="clearSelection">Abwählen</button>
      </div>
      <Button
        variant="outline"
        size="sm"
        class="text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200"
        @click="askDeleteSelected"
      >
        <Trash2 class="w-3.5 h-3.5 mr-1.5" />
        Löschen
      </Button>
    </div>

    <!-- File list -->
    <div v-if="files.length" class="divide-y divide-border/50 rounded-lg border border-border/50 overflow-hidden">
      <!-- List header (select-all) -->
      <div v-if="deletableFiles.length" class="flex items-center gap-3 px-4 py-2 bg-zinc-50/70 text-[11px] uppercase tracking-wide text-muted-foreground">
        <input
          type="checkbox"
          :checked="allSelected"
          :indeterminate.prop="anySelected && !allSelected"
          class="w-4 h-4 accent-zinc-900 cursor-pointer"
          @change="toggleSelectAll"
        />
        <span>Alle auswählen</span>
      </div>
      <div
        v-for="f in files"
        :key="f.id"
        class="group flex items-center gap-3 px-4 py-3 bg-background hover:bg-zinc-50 transition-colors"
      >
        <!-- Checkbox (only for real property_files) -->
        <input
          v-if="isDeletable(f)"
          type="checkbox"
          :checked="selectedIds.has(f.id)"
          class="w-4 h-4 accent-zinc-900 cursor-pointer shrink-0"
          @change="toggleSelect(f)"
        />
        <div v-else class="w-4 h-4 shrink-0"></div>

        <!-- Icon -->
        <span class="text-lg shrink-0">{{ fileIcon(getExt(f.original_name || f.filename)) }}</span>

        <!-- Name + ext (clickable to view OR inline rename) -->
        <div v-if="editingFileId === f.id" class="flex-1 min-w-0 flex items-center gap-1">
          <Input v-model="editingLabel" class="h-8 text-sm" @keyup.enter="saveRename(f)" @keyup.esc="cancelRename()" autofocus />
          <Button variant="ghost" size="icon" class="h-8 w-8 text-emerald-600" @click="saveRename(f)" title="Speichern">
            <Check class="w-4 h-4" />
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8 text-zinc-500" @click="cancelRename()" title="Abbrechen">
            <X class="w-4 h-4" />
          </Button>
        </div>
        <a v-else :href="'/storage/' + f.path" target="_blank" class="flex-1 min-w-0 hover:text-blue-600 transition-colors cursor-pointer">
          <div class="flex items-center gap-2">
            <p class="text-sm font-medium text-zinc-900 truncate hover:underline">{{ f.label || f.original_name || f.filename }}</p>
            <Badge v-if="f.is_ava" class="bg-[#fff7ed] text-[#c2410c] border-[#fed7aa] text-[10px] px-1.5 py-0 h-4 shrink-0">
              AVA
            </Badge>
          </div>
        </a>

        <!-- Extension badge -->
        <Badge v-if="editingFileId !== f.id" variant="secondary" class="text-[10px] uppercase shrink-0">
          {{ getExt(f.original_name || f.filename) || '?' }}
        </Badge>

        <!-- Rename -->
        <Button
          v-if="f.source === 'property_files' && editingFileId !== f.id"
          variant="ghost" size="icon"
          class="h-8 w-8 shrink-0 text-zinc-400 hover:text-zinc-700 opacity-0 group-hover:opacity-100 transition-opacity"
          title="Umbenennen"
          @click="startRename(f)"
        >
          <Pencil class="w-3.5 h-3.5" />
        </Button>

        <!-- AVA marker -->
        <Button
          v-if="f.source === 'property_files' && editingFileId !== f.id"
          variant="ghost" size="icon"
          class="h-8 w-8 shrink-0"
          :class="f.is_ava ? 'text-[#EE7600] hover:text-[#c2410c]' : 'text-zinc-400 hover:text-[#EE7600] opacity-0 group-hover:opacity-100 transition-opacity'"
          :title="f.is_ava ? 'Alleinvermittlungsauftrag-Markierung entfernen' : 'Als Alleinvermittlungsauftrag markieren'"
          @click="toggleAvaMarker(f)"
        >
          <FileCheck2 v-if="f.is_ava" class="w-4 h-4" />
          <FileX2 v-else class="w-4 h-4" />
        </Button>

        <!-- Website download toggle -->
        <div class="flex items-center gap-1.5 shrink-0">
          <span class="text-[11px] text-muted-foreground hidden sm:inline">Website</span>
          <Switch
            :checked="!!f.is_website_download"
            @update:checked="toggleWebsiteDownload(f)"
          />
        </div>

        <!-- Download -->
        <a :href="'/storage/' + f.path" :download="f.original_name || f.filename" class="h-8 w-8 flex items-center justify-center rounded-md opacity-0 group-hover:opacity-100 transition-opacity text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 shrink-0">
          <Download class="w-4 h-4" />
        </a>

        <!-- Delete -->
        <Button
          v-if="isDeletable(f)"
          variant="ghost"
          size="icon"
          class="h-8 w-8 opacity-0 group-hover:opacity-100 transition-opacity text-red-500 hover:text-red-600 hover:bg-red-50 shrink-0"
          @click="askDeleteOne(f)"
        >
          <Trash2 class="w-4 h-4" />
        </Button>
        <div v-else class="h-8 w-8 shrink-0"></div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!uploading" class="flex flex-col items-center justify-center py-10 text-center">
      <FileText class="w-8 h-8 text-zinc-300 mb-2" />
      <p class="text-sm text-zinc-400">Keine Dateien vorhanden.</p>
    </div>
    </template>

    <!-- Delete confirmation dialog -->
    <Dialog :open="showDeleteDialog" @update:open="(v) => { if (!v && !deleting) showDeleteDialog = false; }">
      <DialogContent class="max-w-md">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2">
            <Trash2 class="w-4 h-4 text-red-500" />
            {{ deleteCandidates.length === 1 ? 'Datei löschen?' : deleteCandidates.length + ' Dateien löschen?' }}
          </DialogTitle>
          <DialogDescription>
            Diese Aktion kann nicht rückgängig gemacht werden.
          </DialogDescription>
        </DialogHeader>

        <ul class="max-h-56 overflow-y-auto rounded-md border border-border/50 divide-y divide-border/50 text-sm">
          <li v-for="f in deleteCandidates" :key="f.id" class="flex items-center gap-2 px-3 py-2">
            <span class="shrink-0">{{ fileIcon(getExt(f.original_name || f.filename)) }}</span>
            <span class="truncate">{{ f.label || f.original_name || f.filename }}</span>
          </li>
        </ul>

        <DialogFooter class="gap-2">
          <Button variant="outline" size="sm" :disabled="deleting" @click="showDeleteDialog = false">Abbrechen</Button>
          <Button
            variant="destructive"
            size="sm"
            :disabled="deleting"
            @click="confirmDelete"
          >
            {{ deleting ? 'Lösche…' : (deleteCandidates.length === 1 ? 'Löschen' : deleteCandidates.length + ' löschen') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
