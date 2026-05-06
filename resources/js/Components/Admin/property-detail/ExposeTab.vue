<script setup>
import { ref, computed, inject, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Loader2, RefreshCw, ExternalLink, Save, Plus, Trash2, GripVertical, Image as ImageIcon, Wand2, FileDown, Eye, EyeOff } from 'lucide-vue-next';

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
const coverKicker = ref(props.property.expose_cover_kicker || '');
const coverTitle = ref(props.property.expose_cover_title || '');
const coverSubtitle = ref(props.property.expose_cover_subtitle || '');

// Editor state: pages + image pool loaded from API
const loadingConfig = ref(true);
const pages = ref([]); // array of page objects
const images = ref([]); // pool of {id, url, category, is_title_image}
const floorplans = ref([]); // separate Liste fuer Grundriss-Bilder
const floorplanUploading = ref(false);
const floorplanInput = ref(null);

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

// Seiten, deren Sichtbarkeit via Toggle gesteuert werden kann.
// Cover + Kontakt sind fix (Cover = Einstieg, Kontakt = Haftungsausschluss).
const TOGGLEABLE_TYPES = ['details', 'haus', 'sanierungen', 'lage', 'impressionen_intro'];
const TYPE_LABELS = {
  details: 'Details',
  haus: 'Das Haus',
  sanierungen: 'Sanierungen',
  lage: 'Lage',
  impressionen_intro: 'Impressionen-Intro',
};

const fixedPages = computed(() => pages.value
  .map((p, i) => ({ page: p, index: i, label: TYPE_LABELS[p.type] }))
  .filter(x => TOGGLEABLE_TYPES.includes(x.page.type)));

function toggleHidden(pageIndex) {
  const page = pages.value[pageIndex];
  page.hidden = !page.hidden;
}

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
    floorplans.value = data.floorplans || [];
  } catch (e) {
    error.value = 'Fehler beim Laden: ' + (e?.response?.data?.message || e.message);
  } finally {
    loadingConfig.value = false;
  }
}

// --- Grundriss Upload / Loeschen ---
//
// Grundriss-Bilder leben getrennt vom regulaeren Bilder-Pool: is_floorplan=1.
// Damit sie nicht ueber Plattform-Exports/Website verteilt werden. Im
// ExposeConfigBuilder werden sie als 'grundriss'-Pages vor 'kontakt'
// eingefuegt. Nach Add/Delete: einmal regenerieren, damit die config_json
// die Pages aktualisiert.

const API_BASE = inject('API'); // gleiches Pattern wie MediaTab

async function uploadFloorplans(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  floorplanUploading.value = true;
  try {
    const fd = new FormData();
    fd.append('property_id', String(props.property.id));
    fd.append('is_floorplan', '1');
    fd.append('category', 'grundriss');
    for (const f of files) fd.append('images[]', f);
    const apiUrl = (API_BASE?.value || API_BASE || '/admin/api');
    await fetch(apiUrl + '&action=upload_property_image', { method: 'POST', body: fd });
    toast('Grundriss(e) hochgeladen');
    await regenerateAfterFloorplanChange();
  } catch (e) {
    error.value = 'Upload fehlgeschlagen: ' + (e?.message || e);
  } finally {
    floorplanUploading.value = false;
    if (floorplanInput.value) floorplanInput.value.value = '';
  }
}

async function deleteFloorplan(id) {
  if (!confirm('Grundriss-Bild loeschen? (Wird aus dem Exposé entfernt.)')) return;
  try {
    const apiUrl = (API_BASE?.value || API_BASE || '/admin/api');
    await fetch(apiUrl + '&action=delete_property_image', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    toast('Grundriss geloescht');
    await regenerateAfterFloorplanChange();
  } catch (e) {
    error.value = 'Loeschen fehlgeschlagen: ' + (e?.message || e);
  }
}

// Speichert das Stockwerk-Label (property_images.title) — debounced damit
// nicht jeder Tastendruck einen Request triggert.
const floorLabelTimers = {};
function setFloorLabel(id, label) {
  const fp = floorplans.value.find(f => f.id === id);
  if (!fp) return;
  fp.title = label; // optimistic update fuer UI
  if (floorLabelTimers[id]) clearTimeout(floorLabelTimers[id]);
  floorLabelTimers[id] = setTimeout(async () => {
    try {
      const apiUrl = (API_BASE?.value || API_BASE || '/admin/api');
      await fetch(apiUrl + '&action=update_property_image', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, title: (label || '').trim() }),
      });
      // Stille Regenerate damit das Label sofort im Preview-PDF auftaucht.
      await regenerateAfterFloorplanChange();
    } catch (e) {
      error.value = 'Stockwerk speichern fehlgeschlagen: ' + (e?.message || e);
    }
  }, 600);
}

async function moveFloorplan(id, direction) {
  const idx = floorplans.value.findIndex(f => f.id === id);
  const targetIdx = idx + direction;
  if (idx < 0 || targetIdx < 0 || targetIdx >= floorplans.value.length) return;
  // Lokal vertauschen fuer sofortige UI-Reaktion
  const arr = [...floorplans.value];
  [arr[idx], arr[targetIdx]] = [arr[targetIdx], arr[idx]];
  floorplans.value = arr;
  // Persistieren: sort_order fuer beide tauschen
  try {
    const apiUrl = (API_BASE?.value || API_BASE || '/admin/api');
    await Promise.all(arr.map((fp, i) => fetch(apiUrl + '&action=update_property_image', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: fp.id, sort_order: i }),
    })));
    await regenerateAfterFloorplanChange();
  } catch (e) {
    error.value = 'Sortieren fehlgeschlagen: ' + (e?.message || e);
  }
}

// Stille Regenerate nach Grundriss-Aenderungen — kein Confirm-Dialog,
// weil Grundriss-Pages eh nicht manuell editierbar sind.
async function regenerateAfterFloorplanChange() {
  try {
    await http.post(`/admin/properties/${props.property.id}/expose`);
    await loadConfig();
    previewKey.value++;
  } catch (e) {
    error.value = 'Regenerate fehlgeschlagen: ' + (e?.response?.data?.message || e.message);
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
      expose_cover_kicker: coverKicker.value.trim() || null,
      expose_cover_title: coverTitle.value.trim() || null,
      expose_cover_subtitle: coverSubtitle.value.trim() || null,
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
        <a :href="`/admin/properties/${property.id}/expose/pdf`">
          <Button variant="outline" size="sm">
            <FileDown class="w-4 h-4 mr-2" />
            PDF
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

      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Cover-Kicker</label>
          <input v-model="coverKicker" type="text" maxlength="120"
            :placeholder="(property.object_type || 'TOP ZWEIFAMILIENHAUS')"
            class="w-full h-9 px-3 text-[13px] bg-white rounded-md border border-zinc-200 shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
          <p class="text-[10px] text-muted-foreground mt-0.5">Kleine Überzeile (wird uppercase)</p>
        </div>
        <div>
          <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Cover-Haupttitel</label>
          <input v-model="coverTitle" type="text" maxlength="120"
            :placeholder="(property.city || 'Grödig')"
            class="w-full h-9 px-3 text-[13px] bg-white rounded-md border border-zinc-200 shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
          <p class="text-[10px] text-muted-foreground mt-0.5">Große Serif-Zeile</p>
        </div>
        <div>
          <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Cover-Untertitel</label>
          <input v-model="coverSubtitle" type="text" maxlength="200"
            placeholder="Weiherweg 12 · 5083 Grödig"
            class="w-full h-9 px-3 text-[13px] bg-white rounded-md border border-zinc-200 shadow focus:outline-none focus:ring-1 focus:ring-orange-400" />
          <p class="text-[10px] text-muted-foreground mt-0.5">Zeile unter dem Titel</p>
        </div>
      </div>

      <div>
        <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">Claim (optional)</label>
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
        <!-- Sichtbarkeit fixer Seiten -->
        <div v-if="fixedPages.length" class="rounded-lg p-3 bg-zinc-100/70 border border-zinc-200 shadow-md">
          <div class="text-[11px] uppercase tracking-wider text-muted-foreground font-medium mb-2">Seiten ein/ausblenden</div>
          <div class="flex flex-wrap gap-2">
            <button v-for="fp in fixedPages" :key="fp.index" @click="toggleHidden(fp.index)"
                    :class="[
                      'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[12px] font-medium transition-all',
                      fp.page.hidden
                        ? 'bg-zinc-200 text-muted-foreground line-through opacity-60'
                        : 'bg-white text-zinc-900 shadow-sm border border-zinc-200'
                    ]">
              <EyeOff v-if="fp.page.hidden" class="w-3.5 h-3.5" />
              <Eye v-else class="w-3.5 h-3.5 text-orange-500" />
              {{ fp.label }}
            </button>
          </div>
          <p class="text-[10px] text-muted-foreground mt-2">Cover &amp; Kontakt sind immer sichtbar. Änderungen mit „Layout speichern" übernehmen.</p>
        </div>

        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold">Impressionen-Seiten</h3>
          <Button @click="addImpressionenPage" variant="outline" size="sm">
            <Plus class="w-4 h-4 mr-1.5" />
            Seite hinzufügen
          </Button>
        </div>

        <!-- Grundriss-Sektion: separate Bilder, NUR fuers Exposé. Tauchen vor
             der Kontakt-Seite als eigene Pages auf. Nicht in Plattform/Website. -->
        <div class="rounded-lg p-3 bg-zinc-100/70 border border-zinc-200 shadow-md space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-sm font-semibold">Grundrisse</h3>
              <p class="text-[11px] text-muted-foreground">Erscheinen vor der Kontakt-Seite. Nicht auf Plattformen, nicht auf der Website.</p>
            </div>
            <div class="flex items-center gap-2">
              <input ref="floorplanInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="uploadFloorplans" />
              <Button @click="floorplanInput?.click()" variant="outline" size="sm" :disabled="floorplanUploading">
                <Loader2 v-if="floorplanUploading" class="w-4 h-4 mr-1.5 animate-spin" />
                <Plus v-else class="w-4 h-4 mr-1.5" />
                Hochladen
              </Button>
            </div>
          </div>

          <div v-if="!floorplans.length" class="text-center text-sm text-muted-foreground py-6 rounded-lg border border-dashed border-zinc-300 bg-white/50">
            Noch kein Grundriss hochgeladen.
          </div>

          <datalist id="floorplan-stockwerke">
            <option value="Keller" />
            <option value="Erdgeschoss" />
            <option value="1. Obergeschoss" />
            <option value="2. Obergeschoss" />
            <option value="3. Obergeschoss" />
            <option value="Dachgeschoss" />
            <option value="Aussenanlage" />
          </datalist>

          <div v-else class="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div v-for="(fp, fpIdx) in floorplans" :key="fp.id" class="relative group rounded-md overflow-hidden bg-white border border-zinc-200">
              <img :src="fp.url" :alt="fp.original_name || 'Grundriss'" class="w-full h-32 object-contain bg-zinc-50" loading="lazy" />
              <div class="px-2 py-2 space-y-1.5">
                <input
                  type="text"
                  list="floorplan-stockwerke"
                  :value="fp.title || ''"
                  @input="setFloorLabel(fp.id, $event.target.value)"
                  placeholder="Stockwerk (z.B. Erdgeschoss, 1. OG …)"
                  class="w-full px-2 py-1 text-[11px] rounded border border-zinc-200 bg-white focus:outline-none focus:border-orange-400 focus:ring-1 focus:ring-orange-200"
                />
                <div class="text-[9px] text-muted-foreground truncate" :title="fp.original_name">{{ fp.original_name || ('#' + fp.id) }}</div>
              </div>
              <div class="absolute inset-x-1 top-1 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition">
                <button v-if="fpIdx > 0" type="button" class="px-1.5 h-6 rounded bg-white/90 border border-zinc-200 text-[10px]" @click="moveFloorplan(fp.id, -1)" title="Nach oben">↑</button>
                <button v-if="fpIdx < floorplans.length - 1" type="button" class="px-1.5 h-6 rounded bg-white/90 border border-zinc-200 text-[10px]" @click="moveFloorplan(fp.id, 1)" title="Nach unten">↓</button>
                <div class="flex-1"></div>
                <button type="button" class="px-1.5 h-6 rounded bg-red-50 border border-red-200 text-red-700 text-[10px]" @click="deleteFloorplan(fp.id)" title="Löschen">
                  <Trash2 class="w-3 h-3" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-if="impressionenPages.length === 0" class="text-center text-sm text-muted-foreground py-6 rounded-lg border border-zinc-200 bg-zinc-100/70 shadow-md">
          Keine Impressionen-Seiten. Klick „Seite hinzufügen" oder „Neu generieren".
        </div>

        <div v-for="{ page, index } in impressionenPages" :key="index"
             :class="[
               'rounded-lg border border-zinc-200 p-3 shadow-md space-y-3',
               page.hidden ? 'bg-zinc-50 opacity-60' : 'bg-white'
             ]"
             @dragover="onDragOver"
             @drop="onDropOnPage(index, $event)">
          <div class="flex items-center gap-2">
            <GripVertical class="w-4 h-4 text-muted-foreground flex-shrink-0" />
            <span class="text-[10px] font-bold text-orange-600 bg-orange-50 px-2 py-0.5 rounded">Seite {{ index + 1 }}</span>
            <span v-if="page.hidden" class="text-[10px] text-muted-foreground italic">ausgeblendet</span>
            <div class="flex-1"></div>
            <button @click="toggleHidden(index)" class="p-1 hover:bg-zinc-100 rounded" :title="page.hidden ? 'Wieder anzeigen' : 'Ausblenden'">
              <EyeOff v-if="page.hidden" class="w-4 h-4 text-muted-foreground" />
              <Eye v-else class="w-4 h-4 text-orange-500" />
            </button>
            <button @click="movePage(index, -1)" class="p-1 hover:bg-zinc-100 rounded text-xs" title="Nach oben">↑</button>
            <button @click="movePage(index, 1)" class="p-1 hover:bg-zinc-100 rounded text-xs" title="Nach unten">↓</button>
            <button @click="removePage(index)" class="p-1 hover:bg-red-50 text-red-500 rounded" title="Entfernen">
              <Trash2 class="w-4 h-4" />
            </button>
          </div>

          <Select :model-value="page.layout" @update:model-value="(v) => v && changeLayout(index, v)">
            <SelectTrigger class="w-full h-9 text-[13px] bg-white border-zinc-200 shadow">
              <SelectValue placeholder="Layout wählen…" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel class="text-[10px] uppercase tracking-wider text-muted-foreground">Klassisch</SelectLabel>
                <SelectItem v-for="l in LAYOUTS.filter(x => !x.editorial)" :key="l.key" :value="l.key">
                  {{ l.label }}
                </SelectItem>
              </SelectGroup>
              <SelectGroup>
                <SelectLabel class="text-[10px] uppercase tracking-wider text-muted-foreground">Editorial (mit Zitat)</SelectLabel>
                <SelectItem v-for="l in LAYOUTS.filter(x => x.editorial)" :key="l.key" :value="l.key">
                  {{ l.label }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>

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
