<script setup>
import { ref, watch, inject } from "vue";
import { X, Upload, Sparkles } from "lucide-vue-next";
import { Button } from "@/components/ui/button";

const props = defineProps({
  property: { type: Object, required: true },
  visible: { type: Boolean, default: false },
});

const emit = defineEmits(["parsed", "close"]);

const API = inject("API");
const toast = inject("toast");

const availableFiles = ref([]);
const selectedFileIds = ref([]);
const parseLoading = ref(false);
const uploading = ref(false);

watch(() => props.visible, (val) => {
  if (val) loadFiles();
});

async function loadFiles() {
  if (!props.property?.id) return;
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    availableFiles.value = d.files || [];
    selectedFileIds.value = availableFiles.value
      .filter(f => /expos/i.test(f.filename) || /expos/i.test(f.label || ''))
      .map(f => f.id);
  } catch (e) { availableFiles.value = []; }
}

async function runParse() {
  parseLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=parse_expose&property_id=" + props.property.id, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, file_ids: selectedFileIds.value }),
    });
    const txt = await r.text();
    if (txt.startsWith("<!") || txt.startsWith("<html")) { toast("Session abgelaufen"); return; }
    const d = JSON.parse(txt);
    if (d.error) { toast(d.error); }
    else {
      const result = d.extracted || d;
      emit('parsed', result);
      toast("Dateien analysiert!");
    }
  } catch (e) { toast("Fehler: " + e.message); }
  parseLoading.value = false;
}

async function analyzeNewFile(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  parseLoading.value = true;
  try {
    for (const file of files) {
      const fd = new FormData();
      fd.append('file', file);
      const r = await fetch(API.value + '&action=analyze_file', { method: 'POST', body: fd });
      const txt = await r.text();
      if (!txt.startsWith('<')) {
        const d = JSON.parse(txt);
        emit('parsed', d);
        toast(file.name + ' analysiert!');
      }
    }
  } catch (e) { toast('Fehler: ' + e.message); }
  parseLoading.value = false;
}

async function uploadFiles(event) {
  const files = event.target.files;
  if (!files?.length) return;
  uploading.value = true;
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', props.property.id);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        availableFiles.value.push(d.file);
        selectedFileIds.value.push(d.file.id);
      }
    } catch(e) { console.error(e); }
  }
  event.target.value = '';
  uploading.value = false;
  toast(files.length + ' Datei(en) hochgeladen');
}
</script>

<template>
  <div v-if="visible" class="border-t border-border bg-muted/30 px-6 py-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold">Expose auslesen</h3>
      <Button variant="ghost" size="sm" @click="emit('close')">
        <X class="w-4 h-4" />
      </Button>
    </div>

    <!-- For saved properties: file selection -->
    <div v-if="property.id">
      <p class="text-xs text-muted-foreground mb-3">Dateien auswaehlen oder neue hochladen.</p>

      <!-- Upload zone -->
      <label class="flex items-center gap-2 p-3 border border-dashed border-border rounded-lg cursor-pointer hover:bg-muted/50 mb-3">
        <Upload class="w-4 h-4 text-muted-foreground" />
        <span class="text-xs text-muted-foreground">{{ uploading ? 'Wird hochgeladen...' : 'Dateien hochladen' }}</span>
        <input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.xls" class="sr-only" @change="uploadFiles" :disabled="uploading" />
      </label>

      <!-- File checkboxes -->
      <div class="space-y-1.5 mb-3 max-h-40 overflow-y-auto">
        <label v-for="f in availableFiles" :key="f.id" class="flex items-center gap-2 p-2 rounded hover:bg-muted/50 cursor-pointer">
          <input type="checkbox" :value="f.id" v-model="selectedFileIds" class="rounded border-border" />
          <span class="text-xs flex-1 truncate">{{ f.label || f.filename }}</span>
          <span class="text-[10px] text-muted-foreground">{{ f.filename?.split('.').pop()?.toUpperCase() }}</span>
        </label>
      </div>
      <div v-if="!availableFiles.length" class="text-xs text-muted-foreground py-2">Noch keine Dateien. Lade Expose hoch.</div>

      <!-- Parse button -->
      <Button size="sm" :disabled="!selectedFileIds.length || parseLoading" @click="runParse">
        <Sparkles v-if="!parseLoading" class="w-3.5 h-3.5 mr-1.5" />
        <div v-else class="w-3.5 h-3.5 mr-1.5 border-2 border-current border-t-transparent rounded-full animate-spin" />
        {{ parseLoading ? 'Wird analysiert...' : selectedFileIds.length + ' Datei(en) auslesen' }}
      </Button>
    </div>

    <!-- For new properties: direct upload + analyze -->
    <div v-else>
      <p class="text-xs text-muted-foreground mb-3">Expose hochladen -- Felder werden automatisch befuellt.</p>
      <label class="flex items-center gap-2 p-4 border border-dashed border-border rounded-lg cursor-pointer hover:bg-muted/50">
        <Upload class="w-4 h-4 text-muted-foreground" />
        <span class="text-xs text-muted-foreground">{{ parseLoading ? 'Wird analysiert...' : 'PDF, DOC oder Bild hochladen' }}</span>
        <input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.xls" class="sr-only" @change="analyzeNewFile" :disabled="parseLoading" />
      </label>
    </div>
  </div>
</template>
