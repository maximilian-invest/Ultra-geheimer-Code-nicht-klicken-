<script setup>
import { ref, computed, onMounted, inject, watch } from "vue";
import { BookOpen, Sparkles, FileText, Loader2, Check } from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");
const kbCounts = inject("kbCounts", ref({}));

const kbEntries = ref([]);
const loading = ref(false);

// Expose parser state
const showExposeParser = ref(false);
const exposeFiles = ref([]);
const exposeSelectedFiles = ref([]);
const exposeLoading = ref(false);
const exposeResult = ref(null);

const entryCount = computed(() => kbEntries.value.length);

async function loadKBEntries() {
  loading.value = true;
  try {
    const r = await fetch(API.value + "&action=list_knowledge&property_id=" + props.property.id);
    const d = await r.json();
    kbEntries.value = d.entries || d.knowledge || [];
  } catch (e) {
    kbEntries.value = [];
  }
  loading.value = false;
}

async function loadExposeFiles() {
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    exposeFiles.value = d.files || [];
    exposeSelectedFiles.value = exposeFiles.value
      .filter(f => /expos/i.test(f.filename) || /expos/i.test(f.label || ""))
      .map(f => f.id);
  } catch (e) {
    exposeFiles.value = [];
  }
}

function toggleFileSelection(fileId) {
  const idx = exposeSelectedFiles.value.indexOf(fileId);
  if (idx >= 0) {
    exposeSelectedFiles.value.splice(idx, 1);
  } else {
    exposeSelectedFiles.value.push(fileId);
  }
}

async function handleKIAuslesen() {
  if (!showExposeParser.value) {
    showExposeParser.value = true;
    await loadExposeFiles();
    return;
  }
  await runExpose("kb");
}

async function runExpose(mode) {
  exposeLoading.value = true;
  exposeResult.value = null;
  try {
    const body = { property_id: props.property.id };
    if (exposeSelectedFiles.value.length > 0) {
      body.file_ids = exposeSelectedFiles.value;
    }
    const r = await fetch(API.value + "&action=parse_expose&property_id=" + props.property.id, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const txt = await r.text();
    if (txt.startsWith("<!") || txt.startsWith("<html")) {
      toast("Session abgelaufen, bitte Seite neu laden");
      exposeLoading.value = false;
      return;
    }
    const d = JSON.parse(txt);
    if (d.error) {
      toast(d.error);
    } else {
      exposeResult.value = d.extracted || d;
      if (mode === "kb") {
        await applyExposeToKB();
      }
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  exposeLoading.value = false;
}

async function applyExposeToKB() {
  if (!exposeResult.value) return;
  const result = exposeResult.value;
  const entries = [];
  if (result.fields) {
    for (const [k, v] of Object.entries(result.fields)) {
      if (v !== null && v !== undefined && v !== "") {
        entries.push({
          title: k,
          content: String(v),
          category: "dokument_extrakt",
          source_type: "ai_extract",
        });
      }
    }
  }
  if (entries.length === 0) {
    toast('Keine Felder zum Speichern gefunden');
    exposeResult.value = null;
    showExposeParser.value = false;
    return;
  }
  try {
    // add_knowledge akzeptiert ein Array oder einzelnes Item — ein Batch-Call ist schneller
    const r = await fetch(API.value + "&action=add_knowledge", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(entries.map(e => ({ property_id: props.property.id, ...e }))),
    });
    const d = await r.json();
    if (r.ok && d.success) {
      const count = d.count ?? (d.inserted_ids?.length ?? 0);
      if (count > 0) {
        toast(count + ' Einträge in Wissens-DB gespeichert');
        await loadKBEntries();
      } else {
        toast('Keine Einträge gespeichert — Backend hat alle Felder verworfen (ungültige Kategorie?)');
      }
    } else {
      toast('Fehler: ' + (d.error || 'Backend hat keine Einträge bestätigt'));
    }
  } catch (e) {
    toast('Fehler: ' + e.message);
  }
  exposeResult.value = null;
  showExposeParser.value = false;
}

function categoryLabel(cat) {
  const map = {
    dokument_extrakt: "Dokument-Extrakt",
    manual: "Manuell",
    ai_generated: "KI-generiert",
  };
  return map[cat] || cat || "Allgemein";
}

onMounted(() => {
  loadKBEntries();
});

watch(() => props.property?.id, () => {
  loadKBEntries();
});
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <h2 class="text-lg font-semibold">Wissens-DB</h2>
        <Badge variant="outline" class="bg-emerald-50 text-emerald-700 border-emerald-200 text-xs">
          {{ entryCount }} {{ entryCount === 1 ? 'Eintrag' : 'Einträge' }}
        </Badge>
      </div>
      <Button size="sm" class="bg-purple-600 hover:bg-purple-700 text-white" @click="handleKIAuslesen" :disabled="exposeLoading">
        <Sparkles class="w-3.5 h-3.5 mr-1.5" />
        KI auslesen
      </Button>
    </div>

    <!-- Expose Parser Inline -->
    <div v-if="showExposeParser" class="border border-border/50 rounded-lg p-4 bg-muted/30 space-y-3">
      <div class="text-sm font-medium">Dateien für KI-Analyse auswählen</div>
      <div v-if="exposeFiles.length === 0" class="text-sm text-muted-foreground">
        Keine Dateien vorhanden.
      </div>
      <div v-else class="space-y-2">
        <label
          v-for="file in exposeFiles" :key="file.id"
          class="flex items-center gap-2.5 text-sm cursor-pointer hover:bg-muted/50 rounded px-2 py-1.5 -mx-2"
        >
          <input type="checkbox" :checked="exposeSelectedFiles.includes(file.id)" @change="toggleFileSelection(file.id)" class="rounded border-border" />
          <FileText class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
          <span class="truncate">{{ file.filename || file.label || 'Datei ' + file.id }}</span>
        </label>
      </div>
      <div class="flex items-center gap-2 pt-1">
        <Button size="sm" @click="runExpose('kb')" :disabled="exposeLoading || exposeSelectedFiles.length === 0">
          <Loader2 v-if="exposeLoading" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
          <Check v-else class="w-3.5 h-3.5 mr-1.5" />
          Auslesen
        </Button>
        <Button size="sm" variant="outline" @click="showExposeParser = false" :disabled="exposeLoading">
          Abbrechen
        </Button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
    </div>

    <!-- KB Entries Grid -->
    <div v-else-if="kbEntries.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div
        v-for="entry in kbEntries" :key="entry.id || entry.title"
        class="border border-border/50 rounded-lg p-3 space-y-1.5"
      >
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <BookOpen class="w-4 h-4 text-emerald-600 shrink-0" />
            <span class="font-medium text-sm truncate">{{ entry.title }}</span>
          </div>
          <Badge variant="outline" class="bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px] shrink-0">
            {{ categoryLabel(entry.category) }}
          </Badge>
        </div>
        <p class="text-xs text-muted-foreground line-clamp-2">{{ entry.content }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-12 space-y-2">
      <BookOpen class="w-8 h-8 text-muted-foreground/40 mx-auto" />
      <p class="text-sm text-muted-foreground">Keine Wissens-Einträge vorhanden.</p>
      <p class="text-xs text-muted-foreground">
        Nutze <span class="font-medium text-purple-600">KI auslesen</span>, um Exposé-Daten automatisch zu extrahieren.
      </p>
    </div>
  </div>
</template>
