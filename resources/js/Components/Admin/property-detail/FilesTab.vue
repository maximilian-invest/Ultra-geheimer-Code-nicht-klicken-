<script setup>
import { ref, onMounted, watch, inject } from "vue";
import { FileText, Upload, Trash2, Download } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

const files = ref([]);
const uploading = ref(false);
const dragOver = ref(false);
const fileInput = ref(null);

function fileIcon(ext) {
  const map = { pdf: '📄', doc: '📝', docx: '📝', xls: '📊', xlsx: '📊', jpg: '🖼️', jpeg: '🖼️', png: '🖼️' };
  return map[ext?.toLowerCase()] || '📎';
}

function getExt(filename) {
  if (!filename) return '';
  const parts = filename.split('.');
  return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

async function loadFiles() {
  if (!props.property?.id) return;
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    files.value = d.files || [];
  } catch (e) {
    files.value = [];
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

async function deleteFile(f) {
  if (!confirm('Datei wirklich löschen?')) return;
  try {
    const r = await fetch(API.value + '&action=delete_property_file', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: f.id }),
    });
    const d = await r.json();
    if (d.success) {
      files.value = files.value.filter(x => x.id !== f.id);
      toast('Datei gelöscht');
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch (e) {
    toast('Fehler: ' + e.message);
  }
}

onMounted(() => loadFiles());
watch(() => props.property?.id, () => loadFiles());
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

    <!-- Dropzone -->
    <div
      @drop.prevent="handleUpload"
      @dragover.prevent="dragOver = true"
      @dragleave="dragOver = false"
      @click="fileInput?.click()"
      :class="[
        'border-2 border-dashed rounded-xl p-8 text-center transition-all duration-200 cursor-pointer select-none',
        dragOver
          ? 'border-zinc-800 bg-zinc-50'
          : 'border-zinc-200 hover:border-zinc-400 hover:bg-zinc-50/50'
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

    <!-- File list -->
    <div v-if="files.length" class="divide-y divide-border/50 rounded-lg border border-border/50 overflow-hidden">
      <div
        v-for="f in files"
        :key="f.id"
        class="group flex items-center gap-3 px-4 py-3 bg-background hover:bg-zinc-50 transition-colors"
      >
        <!-- Icon -->
        <span class="text-lg shrink-0">{{ fileIcon(getExt(f.original_name || f.filename)) }}</span>

        <!-- Name + ext (clickable to view) -->
        <a :href="'/storage/' + f.path" target="_blank" class="flex-1 min-w-0 hover:text-blue-600 transition-colors cursor-pointer">
          <p class="text-sm font-medium text-zinc-900 truncate hover:underline">{{ f.label || f.original_name || f.filename }}</p>
        </a>

        <!-- Extension badge -->
        <Badge variant="secondary" class="text-[10px] uppercase shrink-0">
          {{ getExt(f.original_name || f.filename) || '?' }}
        </Badge>

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
          variant="ghost"
          size="icon"
          class="h-8 w-8 opacity-0 group-hover:opacity-100 transition-opacity text-red-500 hover:text-red-600 hover:bg-red-50 shrink-0"
          @click="deleteFile(f)"
        >
          <Trash2 class="w-4 h-4" />
        </Button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!uploading" class="flex flex-col items-center justify-center py-10 text-center">
      <FileText class="w-8 h-8 text-zinc-300 mb-2" />
      <p class="text-sm text-zinc-400">Keine Dateien vorhanden.</p>
    </div>
  </template>
  </div>
</template>
