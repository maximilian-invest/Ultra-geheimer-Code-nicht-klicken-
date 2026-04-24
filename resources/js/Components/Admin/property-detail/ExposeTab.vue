<script setup>
import { ref, computed, inject, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, RefreshCw, ExternalLink, Save, Plus, Trash2, GripVertical, Image as ImageIcon, Wand2 } from 'lucide-vue-next';

const props = defineProps({
  property: { type: Object, required: true },
});

const toast = inject('toast', (msg) => console.log('[toast]', msg));

// Generation + Capture state
const regenerating = ref(false);
const savingCaptions = ref(false);
const savingConfig = ref(false);
const error = ref('');
const info = ref(null);

// Editable texts
const claim = ref(props.property.expose_claim || '');
const captionsPool = ref(props.property.expose_captions_pool || '');

// Editor state: pages + image pool loaded from API
const loadingConfig = ref(true);
const pages = ref([]); // array of page objects
const images = ref([]); // pool of {id, url, category, is_title_image}

// Preview cache-bust
const previewKey = ref(0);
const previewUrl = computed(() => `/admin/properties/${props.property.id}/expose/preview?ts=${Date.now()}&v=${previewKey.value}`);

// Helpers — nutzt axios (setzt XSRF-Cookie + Header automatisch, vermeidet 419).
const http = window.axios;

// Layout-Katalog mit metadaten für den Picker
const LAYOUTS = [
  { key: 'L1', label: '1 Bild · Full Bleed',     maxImages: 1, editorial: false },
  { key: 'L2', label: '2 Bilder · Halb/Halb',     maxImages: 2, editorial: false },
  { key: 'L3', label: '3 Bilder · Groß+2',        maxImages: 3, editorial: false },
  { key: 'L4', label: '4 Bilder · 2×2 Raster',    maxImages: 4, editorial: false },
  { key: 'LM', label: '4 Bilder · Masonry',       maxImages: 4, editorial: false },
  { key: 'L5', label: '5 Bilder · Mosaik',        maxImages: 5, editorial: false },
  { key: 'M1', label: '3 Bilder + Zitat-Zelle',   maxImages: 3, editorial: true },
  { key: 'M3', label: 'Zitat oben + 3 Bilder',    maxImages: 3, editorial: true },
  { key: 'M4', label: 'Vollbild + Zitat-Overlay', maxImages: 1, editorial: true },
];

const layoutByKey = (k) => LAYOUTS.find(l => l.key === k);

const impressionenPages = computed(() => pages.value
  .map((p, i) => ({ page: p, index: i }))
  .filter(x => x.page.type === 'impressionen'));

// Bilder die aktuell in KEINER Page verwendet werden (für Pool-Filter)
const usedImageIds = computed(() => {
  const ids = new Set();
  for (const p of pages.value) {
    if (p.image_id) ids.add(p.image_id);
    if (Array.isArray(p.image_ids)) p.image_ids.forEach(id => ids.add(id));
  }
  return ids;
});

const unusedImages = computed(() => images.value.filter(img => !usedImageIds.value.has(img.id)));

// Image lookup
function imgById(id) { return images.value.find(i => i.id === id); }

async function loadConfig() {
  loadingConfig.value = true;
  try {
    const { data } = await http.get(`/admin/properties/${props.property.id}/expose/config`);
    pages.value = data.config?.pages || [];
    images.value = data.images || [];
  } catch (e) {
    error.value = 'Fehler beim Laden: ' + (e?.response?.data?.message || e.message);
  } finally {
    loadingConfig.value = false;
  }
}

async function regenerate() {
  if (!confirm('Exposé neu generieren? Aktuelle Anpassungen gehen verloren.')) return;
  regenerating.value = true;
  error.value = '';
  try {
    const { data } = await http.post(`/admin/properties/${props.property.id}/expose`);
    if (!data.success) throw new Error(data.error || 'Fehler beim Generieren');
    info.value = data;
    await loadConfig();
    previewKey.value++;
    toast('Exposé neu generiert · ' + data.page_count + ' Seiten');
  } catch (e) {
    error.value = e?.response?.data?.message || e.message;
  } finally {
    regenerating.value = false;
  }
}

async function saveCaptions() {
  savingCaptions.value = true;
  error.value = '';
  try {
    const { data } = await http.post(`/admin/properties/${props.property.id}/expose/captions`, {
      expose_claim: claim.value.trim() || null,
      expose_captions_pool: captionsPool.value.trim() || null,
    });
    if (!data.success) throw new Error(data.error || 'Fehler beim Speichern');
    toast('Texte gespeichert');
    previewKey.value++;
  } catch (e) {
    error.value = e?.response?.data?.message || e.message;
  } finally {
    savingCaptions.value = false;
  }
}

async function saveConfig() {
  savingConfig.value = true;
  error.value = '';
  try {
    const { data } = await http.put(`/admin/properties/${props.property.id}/expose/config`, {
      config: { pages: pages.value },
    });
    if (!data.success) throw new Error(data.error || 'Fehler beim Speichern');
    info.value = data;
    previewKey.value++;
    toast('Layout gespeichert · ' + data.page_count + ' Seiten');
  } catch (e) {
    error.value = e?.response?.data?.message || e.message;
  } finally {
    savingConfig.value = false;
  }
}

// --- Layout / Bild Operations ---

function changeLayout(pageIndex, newLayout) {
  const page = pages.value[pageIndex];
  const meta = layoutByKey(newLayout);
  if (!meta) return;
  page.layout = newLayout;
  // Auf erlaubte Bildanzahl beschneiden
  if (Array.isArray(page.image_ids) && page.image_ids.length > meta.maxImages) {
    page.image_ids = page.image_ids.slice(0, meta.maxImages);
  }
  if (meta.editorial && !page.caption) {
    page.caption = '';
  }
}

function removeImageFromPage(pageIndex, imgId) {
  const page = pages.value[pageIndex];
  if (!Array.isArray(page.image_ids)) return;
  page.image_ids = page.image_ids.filter(id => id !== imgId);
}

function addImageToPage(pageIndex, imgId) {
  const page = pages.value[pageIndex];
  const meta = layoutByKey(page.layout);
  if (!meta) return;
  if (!Array.isArray(page.image_ids)) page.image_ids = [];
  if (page.image_ids.length >= meta.maxImages) return;
  if (page.image_ids.includes(imgId)) return;
  page.image_ids.push(imgId);
}

function movePageImage(pageIndex, fromIdx, toIdx) {
  const page = pages.value[pageIndex];
  if (!Array.isArray(page.image_ids)) return;
  const [moved] = page.image_ids.splice(fromIdx, 1);
  page.image_ids.splice(toIdx, 0, moved);
}

function addImpressionenPage() {
  // Neue Seite nach der letzten Impressionen-Seite einfügen, oder vor Kontakt
  const kontaktIdx = pages.value.findIndex(p => p.type === 'kontakt');
  const newPage = { type: 'impressionen', layout: 'L4', image_ids: [], caption: null };
  if (kontaktIdx >= 0) {
    pages.value.splice(kontaktIdx, 0, newPage);
  } else {
    pages.value.push(newPage);
  }
}

function removePage(pageIndex) {
  if (pages.value[pageIndex]?.type !== 'impressionen') return;
  if (!confirm('Seite wirklich entfernen?')) return;
  pages.value.splice(pageIndex, 1);
}

function movePage(pageIndex, direction) {
  const targetIdx = pageIndex + direction;
  if (targetIdx < 0 || targetIdx >= pages.value.length) return;
  // Nur zwischen Impressionen-Seiten tauschbar
  if (pages.value[pageIndex].type !== 'impressionen') return;
  if (pages.value[targetIdx].type !== 'impressionen') return;
  const [moved] = pages.value.splice(pageIndex, 1);
  pages.value.splice(targetIdx, 0, moved);
}

// --- Drag & Drop (HTML5 native) ---
const dragging = ref(null); // { imgId, source: 'pool' | { pageIndex, imgIdx } }

function onDragStartFromPool(imgId) {
  dragging.value = { imgId, source: 'pool' };
}
function onDragStartFromPage(pageIndex, imgIdx, imgId) {
  dragging.value = { imgId, source: { pageIndex, imgIdx } };
}
function onDropOnPage(pageIndex, e) {
  e.preventDefault();
  const d = dragging.value;
  if (!d) return;
  if (d.source === 'pool') {
    addImageToPage(pageIndex, d.imgId);
  } else if (typeof d.source === 'object' && d.source.pageIndex !== pageIndex) {
    removeImageFromPage(d.source.pageIndex, d.imgId);
    addImageToPage(pageIndex, d.imgId);
  }
  dragging.value = null;
}
function onDropOnPool(e) {
  e.preventDefault();
  const d = dragging.value;
  if (!d || d.source === 'pool') { dragging.value = null; return; }
  removeImageFromPage(d.source.pageIndex, d.imgId);
  dragging.value = null;
}
function onDragOver(e) { e.preventDefault(); }

onMounted(loadConfig);
</script>

<template>
  <div class="p-6 space-y-5">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold">Exposé</h2>
        <p class="text-sm text-muted-foreground mt-1 max-w-xl">
          Adaptive Exposé-Vorlage — Layout, Bilder und Zitate pro Seite editierbar.
          Änderungen an Property-Daten (Preis, Beschreibung, Lage) werden automatisch übernommen.
        </p>
      </div>
      <div class="flex gap-2 flex-shrink-0">
        <Button @click="regenerate" :disabled="regenerating" variant="outline" size="sm" title="Verwirft Anpassungen und baut aus Property-Daten neu auf">
          <Loader2 v-if="regenerating" class="w-4 h-4 mr-2 animate-spin" />
          <Wand2 v-else class="w-4 h-4 mr-2" />
          Neu generieren
        </Button>
        <Button @click="saveConfig" :disabled="savingConfig" size="sm"
                class="bg-orange-500 hover:bg-orange-600 text-white shadow-sm">
          <Loader2 v-if="savingConfig" class="w-4 h-4 mr-2 animate-spin" />
          <Save v-else class="w-4 h-4 mr-2" />
          Layout speichern
        </Button>
        <a :href="previewUrl" target="_blank" rel="noopener">
          <Button variant="outline" size="sm">
            <ExternalLink class="w-4 h-4 mr-2" />
            Vollbild
          </Button>
        </a>
      </div>
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <!-- Makler-Texte -->
    <div class="rounded-lg border border-zinc-200 p-4 bg-zinc-100/70 shadow-md space-y-3">
      <div class="flex items-center justify-between gap-4">
        <div>
          <h3 class="text-sm font-semibold">Makler-Texte</h3>
          <p class="text-xs text-muted-foreground mt-0.5">Claim fürs Cover und poetische Sätze für Editorial-Seiten.</p>
        </div>
        <Button @click="saveCaptions" :disabled="savingCaptions" variant="outline" size="sm">
          <Loader2 v-if="savingCaptions" class="w-4 h-4 mr-2 animate-spin" />
          <Save v-else class="w-4 h-4 mr-2" />
          Texte speichern
        </Button>
      </div>

      <div>
        <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Claim (Cover, optional)</label>
        <input v-model="claim" type="text" maxlength="200"
          placeholder="z.B. Wohnen, wo andere Urlaub machen."
          class="w-full h-9 px-3 text-[13px] bg-white rounded-md border border-zinc-200 shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
      </div>

      <div>
        <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Impressionen-Zitate (Fallback-Pool, eine Zeile = ein Satz)</label>
        <textarea v-model="captionsPool" rows="3"
          placeholder="Wo Tageslicht den Raum formt.&#10;Ein Ort, an dem Tage länger bleiben."
          class="w-full px-3 py-2 text-[13px] bg-white rounded-md border border-zinc-200 resize-none shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
        <p class="text-[10.5px] text-muted-foreground mt-1">
          Wird verwendet wenn bei einer Editorial-Seite kein eigenes Zitat gesetzt ist. Zeile 1 → erste Editorial, Zeile 2 → zweite, usw.
        </p>
      </div>
    </div>

    <!-- Editor: Impressionen-Seiten -->
    <div v-if="loadingConfig" class="text-center text-sm text-muted-foreground py-6">
      <Loader2 class="w-5 h-5 mx-auto animate-spin text-orange-500 mb-2" />
      Lade Exposé-Konfiguration…
    </div>

    <div v-else class="grid lg:grid-cols-[1fr_280px] gap-5">
      <!-- Linke Spalte: Seiten-Liste -->
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold">Impressionen-Seiten</h3>
          <Button @click="addImpressionenPage" variant="outline" size="sm">
            <Plus class="w-4 h-4 mr-1.5" />
            Seite hinzufügen
          </Button>
        </div>

        <div v-if="impressionenPages.length === 0" class="text-center text-sm text-muted-foreground py-6 rounded-lg border border-zinc-200 bg-zinc-100/70 shadow-md">
          Keine Impressionen-Seiten. Klick „Seite hinzufügen" oder „Neu generieren".
        </div>

        <div v-for="{ page, index } in impressionenPages" :key="index"
             class="rounded-lg border border-zinc-200 p-3 bg-white shadow-md space-y-3"
             @dragover="onDragOver"
             @drop="onDropOnPage(index, $event)">
          <div class="flex items-center gap-2">
            <GripVertical class="w-4 h-4 text-muted-foreground flex-shrink-0" />
            <span class="text-[10px] font-bold text-orange-600 bg-orange-50 px-2 py-0.5 rounded">Seite {{ index + 1 }}</span>
            <select :value="page.layout" @change="changeLayout(index, $event.target.value)"
                    class="h-8 text-[12px] px-2 bg-white rounded-md border border-zinc-200 shadow flex-1 focus:outline-none focus:ring-1 focus:ring-orange-400">
              <optgroup label="Klassisch">
                <option v-for="l in LAYOUTS.filter(x => !x.editorial)" :key="l.key" :value="l.key">{{ l.label }}</option>
              </optgroup>
              <optgroup label="Editorial (mit Zitat)">
                <option v-for="l in LAYOUTS.filter(x => x.editorial)" :key="l.key" :value="l.key">{{ l.label }}</option>
              </optgroup>
            </select>
            <button @click="movePage(index, -1)" class="p-1 hover:bg-zinc-100 rounded text-xs" title="Nach oben">↑</button>
            <button @click="movePage(index, 1)" class="p-1 hover:bg-zinc-100 rounded text-xs" title="Nach unten">↓</button>
            <button @click="removePage(index)" class="p-1 hover:bg-red-50 text-red-500 rounded" title="Entfernen">
              <Trash2 class="w-4 h-4" />
            </button>
          </div>

          <!-- Caption-Field für Editorial -->
          <div v-if="layoutByKey(page.layout)?.editorial">
            <label class="text-[10px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Zitat für diese Seite</label>
            <input v-model="page.caption" type="text" maxlength="300"
                   placeholder="Leer lassen → aus Pool verwenden"
                   class="w-full h-8 px-2.5 text-[12px] bg-zinc-50 rounded-md border border-zinc-200 shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
          </div>

          <!-- Bild-Thumbnails auf dieser Seite -->
          <div class="flex gap-2 flex-wrap">
            <div v-for="(imgId, imgIdx) in (page.image_ids || [])" :key="imgId"
                 draggable="true"
                 @dragstart="onDragStartFromPage(index, imgIdx, imgId)"
                 class="relative w-16 h-16 rounded-md overflow-hidden bg-zinc-100 border border-zinc-200 shadow group cursor-move">
              <img v-if="imgById(imgId)" :src="imgById(imgId).url" class="w-full h-full object-cover" alt="" />
              <div v-else class="w-full h-full flex items-center justify-center text-xs text-muted-foreground">?</div>
              <button @click="removeImageFromPage(index, imgId)"
                      class="absolute top-0 right-0 w-5 h-5 bg-black/60 text-white rounded-bl flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
                <Trash2 class="w-3 h-3" />
              </button>
            </div>
            <div v-if="(page.image_ids?.length || 0) < (layoutByKey(page.layout)?.maxImages || 0)"
                 class="w-16 h-16 rounded-md border border-dashed border-zinc-300 bg-zinc-50/60 flex items-center justify-center text-muted-foreground">
              <Plus class="w-5 h-5" />
            </div>
          </div>
          <p class="text-[10px] text-muted-foreground">
            {{ (page.image_ids?.length || 0) }} / {{ layoutByKey(page.layout)?.maxImages || 0 }} Bilder · Drag &amp; Drop aus dem Pool rechts
          </p>
        </div>
      </div>

      <!-- Rechte Spalte: Bild-Pool -->
      <div class="space-y-2">
        <div class="flex items-center gap-1.5 text-sm font-semibold">
          <ImageIcon class="w-4 h-4" />
          Medien-Pool
          <span class="text-xs font-normal text-muted-foreground ml-1">({{ unusedImages.length }})</span>
        </div>
        <div class="rounded-lg border border-zinc-200 p-2 bg-zinc-100/70 shadow-md max-h-[500px] overflow-y-auto"
             @dragover="onDragOver"
             @drop="onDropOnPool">
          <div v-if="unusedImages.length === 0" class="text-center text-xs text-muted-foreground py-4">
            Alle Bilder sind auf Seiten verteilt.
          </div>
          <div v-else class="grid grid-cols-2 gap-2">
            <div v-for="img in unusedImages" :key="img.id"
                 draggable="true"
                 @dragstart="onDragStartFromPool(img.id)"
                 class="relative aspect-square rounded-md overflow-hidden cursor-move bg-white border border-zinc-200 shadow hover:shadow-lg hover:ring-2 hover:ring-orange-400 transition">
              <img :src="img.url" class="w-full h-full object-cover" alt="" />
              <span v-if="img.is_title_image" class="absolute top-1 left-1 text-[9px] bg-orange-500 text-white px-1.5 py-0.5 rounded">Cover</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <div class="space-y-2 pt-2">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold">Live-Vorschau</h3>
        <span v-if="info" class="text-xs text-muted-foreground">{{ info.page_count }} Seiten · aktive Version</span>
      </div>
      <div class="rounded-lg overflow-hidden border border-zinc-200 bg-zinc-100 shadow-md" style="aspect-ratio: 297/210;">
        <iframe :src="previewUrl" class="w-full h-full" style="border:0" />
      </div>
    </div>
  </div>
</template>
