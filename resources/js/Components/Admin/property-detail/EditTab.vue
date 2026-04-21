<script setup>
import { ref, reactive, computed, watch, inject, onMounted } from "vue";
import { Plus, Trash2, Sparkles, Upload, X, Eye, Wand2 } from "lucide-vue-next";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import MediaTab from "@/Components/Admin/property-detail/MediaTab.vue";
import EditTabAllgemeines from "@/Components/Admin/property-detail/EditTabAllgemeines.vue";
import EditTabKosten from "@/Components/Admin/property-detail/EditTabKosten.vue";
import EditTabFlaechen from "@/Components/Admin/property-detail/EditTabFlaechen.vue";
import EditTabEnergie from "@/Components/Admin/property-detail/EditTabEnergie.vue";
import FieldExportBadges from "@/Components/Admin/property-detail/FieldExportBadges.vue";

const props = defineProps({
  property: { type: Object, required: true },
  isNew: { type: Boolean, default: false },
});

const emit = defineEmits(["dirty", "clean", "saved", "propertyCreated"]);

const API = inject("API");
const toast = inject("toast");
const userId = inject("userId", ref(null));

// ─── Broker list ───
const brokerList = ref([]);
async function loadBrokers() {
  try {
    const r = await fetch(API.value + "&action=list_brokers");
    const d = await r.json();
    brokerList.value = (d.brokers || []).filter(b => ["admin", "makler"].includes(b.user_type));
  } catch (e) { console.error("loadBrokers", e); }
}

// ─── Active sub-tab ───
const activeSubTab = ref("allgemeines");

// ─── Parse Fields ───
const parseOpen = ref(false);
const parseLoading = ref(false);
const parseFiles = ref([]);
const parseSelectedFiles = ref([]);
const parseUploading = ref(false);

// ─── Field visibility map — ersetzt durch FieldExportBadges.vue +
//    resources/js/utils/propertyFieldExports.js (zentrale Mapping-Datei) ───

// iconMap + vis() entfernt — Ersatz: <FieldExportBadges field="..." />

// ─── Boolean features ───
const features = [
  { key: "has_elevator", label: "Aufzug" },
  { key: "has_fitted_kitchen", label: "Einbaukueche" },
  { key: "has_air_conditioning", label: "Klimaanlage" },
  { key: "has_alarm", label: "Alarmanlage" },
  { key: "has_barrier_free", label: "Barrierefrei" },
  { key: "has_fireplace", label: "Kamin" },
  { key: "has_storage_room", label: "Abstellraum" },
  { key: "has_washing_connection", label: "Waschmaschinenanschluss" },
];

// ─── Form reactive with ALL property fields ───
const form = reactive({
  id: null, ref_id: "", openimmo_id: "", title: "", project_name: "",
  address: "", latitude: null, longitude: null, city: "", zip: "",
  object_type: "Eigentumswohnung",
  property_category: "", object_subtype: "", marketing_type: "kauf",
  status: "aktiv", // mapped to realty_status server-side
  purchase_price: null, price_per_m2: null, parking_price: null,
  rental_price: null, rent_warm: null, rent_deposit: null,
  operating_costs: null, maintenance_reserves: null,
  living_area: null, realty_area: null, free_area: null, total_area: null,
  area_balcony: null, area_terrace: null, area_garden: null, area_basement: null,
  area_loggia: null, area_garage: null, office_space: null,
  rooms_amount: null, bedrooms: null, bathrooms: null, toilets: null,
  floor_count: null, floor_number: null,
  energy_certificate: "", heating_demand_value: null, energy_type: "",
  heating_demand_class: "", energy_efficiency_value: null,
  energy_primary_source: "", energy_valid_until: null,
  construction_year: null, year_renovated: null, heating: "",
  condition_note: "", realty_condition: "", quality: "",
  flooring: "", bathroom_equipment: "", kitchen_type: "",
  furnishing: "", orientation: "", noise_level: "",
  has_basement: false, has_garden: false, has_elevator: false,
  has_balcony: false, has_terrace: false, has_loggia: false,
  balcony_count: null, terrace_count: null, loggia_count: null, garden_count: null, basement_count: null,
  has_fitted_kitchen: false, has_air_conditioning: false,
  has_pool: false, has_sauna: false, has_fireplace: false,
  has_alarm: false, has_barrier_free: false, has_guest_wc: false,
  has_storage_room: false, has_washing_connection: false, has_cellar: false,
  garage_spaces: null, parking_spaces: null, parking_type: "",
  highlights: "",
  realty_description: "", location_description: "", equipment_description: "", other_description: "",
  broker_id: null,
  commission_percent: null, commission_note: "", commission_total: null,
  commission_makler: null, buyer_commission_percent: null,
  buyer_commission_text: "", commission_incl_vat: true,
  builder_company: "", property_manager: "",
  construction_start: null, construction_end: null,
  move_in_date: null, available_from: null, available_text: "",
  total_units: null, plot_dedication: "", plot_buildable: false, plot_developed: false,
  platforms: "", inserat_since: null, is_published: false,
  expose_path: "", nebenkosten_path: "",
  on_hold: false,
  property_history: null,
  parent_id: null,
  construction_type: "", ownership_type: "", subtitle: "", ad_tag: "",
  closing_date: null, internal_rating: null,
  house_number: "", staircase: "", door: "", entrance: "", address_floor: "",
  heating_costs: null, warm_water_costs: null, cooling_costs: null,
  admin_costs: null, elevator_costs: null, parking_costs_monthly: null,
  other_costs: null, monthly_costs: null,
  land_register_fee_pct: null, land_transfer_tax_pct: null, contract_fee_pct: null,
  buyer_commission_free: false,
  building_details: {},
});

// Auto-derive category from object_type (replaces manual Kategorie dropdown)
watch(() => form.object_type, (newType) => {
  if (!newType) return;
  const t = newType.toLowerCase();
  if (['eigentumswohnung', 'gartenwohnung', 'dachgeschosswohnung', 'penthouse', 'maisonette'].includes(t)) {
    form.property_category = 'apartment';
  } else if (['haus', 'einfamilienhaus', 'reihenhaus', 'doppelhaushaelfte'].includes(t)) {
    form.property_category = 'house';
  } else if (['grundstueck'].includes(t)) {
    form.property_category = 'land';
  } else if (['neubauprojekt', 'neubau'].includes(t)) {
    form.property_category = 'newbuild';
  } else if (['gewerbe', 'buero', 'anlage', 'sonstiges'].includes(t)) {
    form.property_category = 'gewerbe';
  }
});

// ─── Snapshot for discard ───
let snapshot = {};

function copyPropertyToForm(prop) {
  if (!prop) return;
  for (const key of Object.keys(form)) {
    if (prop[key] !== undefined) {
      form[key] = prop[key];
    }
  }
  // Legacy field mapping: copy old API names into correct form keys
  if (prop.sub_type && !prop.object_subtype) form.object_subtype = prop.sub_type;
  if (prop.type && !form.object_type) form.object_type = prop.type;
  // Set broker_id default + ensure String for Select compatibility
  if (!form.broker_id && userId?.value) form.broker_id = String(userId.value);
  else if (form.broker_id) form.broker_id = String(form.broker_id);
  snapshot = JSON.parse(JSON.stringify(form));
}

// ─── Computed helpers ───
const isNewbuild = computed(() => form.property_category === "newbuild");
const isChild = computed(() => !!form.parent_id);

const activeFeatureCount = computed(() =>
  features.filter(f => form[f.key]).length
);

// ─── Sanierungen (structured, 1:1 zu Immoji refurbishments) ───
// Storage format stays array of {category, year, title, description} so the
// existing timeline displays on OverviewTab and website-v2 keep working
// (they read year/title/description).
const SANIERUNGEN_CATEGORIES = [
  { key: 'general',   label: 'Generalsanierung',      hasYear: true },
  { key: 'windows',   label: 'Fenster',               hasYear: true },
  { key: 'doors',     label: 'Türen',                 hasYear: true },
  { key: 'floors',    label: 'Fußböden',              hasYear: true },
  { key: 'heating',   label: 'Heizung',               hasYear: true },
  { key: 'pipes',     label: 'Leitungssystem',        hasYear: true },
  { key: 'connections', label: 'Anschlüsse',          hasYear: true },
  { key: 'facade',    label: 'Fassade',               hasYear: true },
  { key: 'bathrooms', label: 'Bäder',                 hasYear: true },
  { key: 'kitchen',   label: 'Küche',                 hasYear: true },
  { key: 'other',     label: 'Sonstige Sanierungen',  hasYear: true },
  { key: 'required',  label: 'Erforderliche Maßnahmen', hasYear: false },
];

// Per-category form state: { [categoryKey]: { year, note } }
const sanierungenInputs = reactive({});
for (const c of SANIERUNGEN_CATEGORIES) {
  sanierungenInputs[c.key] = { year: '', note: '' };
}

const historySaving = ref(false);
let sanierungenSaveTimer = null;

function loadSanierungen() {
  let d = form.property_history;
  if (typeof d === "string") { try { d = JSON.parse(d); } catch { d = []; } }
  const arr = Array.isArray(d) ? d : [];

  for (const c of SANIERUNGEN_CATEGORIES) {
    sanierungenInputs[c.key] = { year: '', note: '' };
  }
  for (const entry of arr) {
    // New-shape entries carry an explicit category key.
    let catKey = entry.category;
    // Legacy entries only have title/description — match title to a known
    // category label, else file under "other".
    if (!catKey) {
      const match = SANIERUNGEN_CATEGORIES.find(c => c.label === entry.title);
      catKey = match ? match.key : 'other';
    }
    if (!sanierungenInputs[catKey]) continue;
    // If multiple legacy rows map to the same category we just keep the
    // most recent one — data loss is acceptable on the migration path
    // (legacy was a free-text timeline, new storage has one row/category).
    sanierungenInputs[catKey] = {
      year: String(entry.year ?? ''),
      note: String(entry.description ?? ''),
    };
  }
}

const sanierungenCount = computed(() => {
  let n = 0;
  for (const c of SANIERUNGEN_CATEGORIES) {
    const v = sanierungenInputs[c.key];
    if ((c.hasYear && String(v.year).trim() !== '') || String(v.note).trim() !== '') n++;
  }
  return n;
});

function buildSanierungenArray() {
  const out = [];
  for (const c of SANIERUNGEN_CATEGORIES) {
    const v = sanierungenInputs[c.key];
    const year = String(v.year || '').trim();
    const note = String(v.note || '').trim();
    if (year === '' && note === '') continue;
    out.push({
      category: c.key,
      year: c.hasYear ? year : '',
      title: c.label,
      description: note,
    });
  }
  // Sort by year ascending (empty years last) so the timeline reads in order.
  out.sort((a, b) => {
    const ay = parseInt(a.year, 10) || 99999;
    const by = parseInt(b.year, 10) || 99999;
    return ay - by;
  });
  return out;
}

function scheduleSanierungenSave() {
  clearTimeout(sanierungenSaveTimer);
  sanierungenSaveTimer = setTimeout(() => saveSanierungen(), 800);
}

async function saveSanierungen() {
  if (!form.id) return;
  const items = buildSanierungenArray();
  historySaving.value = true;
  try {
    const r = await fetch(API.value + "&action=update_property", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: form.id, property_history: JSON.stringify(items) }),
    });
    const d = await r.json();
    if (d.success) {
      form.property_history = JSON.stringify(items);
      toast("Sanierungen gespeichert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  historySaving.value = false;
}

// ─── Dirty tracking ───
// Compare form vs snapshot so programmatic updates (copyPropertyToForm during
// init, props.property changes, parse-fields refresh) don't produce false
// dirty signals that would trigger the unsaved-changes popup.
const dirty = ref(false);
watch(form, () => {
  const isNowDirty = JSON.stringify(form) !== JSON.stringify(snapshot);
  if (isNowDirty === dirty.value) return;
  dirty.value = isNowDirty;
  emit(isNowDirty ? "dirty" : "clean");
}, { deep: true });

// ─── Live Preview ───
const previewOpen = ref(false);
const previewFrame = ref(null);

const previewUrl = computed(() => {
  if (!form.id) return '';
  return '/website-preview/objekt.html?id=' + form.id + '&preview=1';
});

// Debounced preview update
let previewTimer = null;
watch(form, () => {
  if (!previewOpen.value || !previewFrame.value?.contentWindow) return;
  clearTimeout(previewTimer);
  previewTimer = setTimeout(() => {
    const typeMap = { newbuild: 'Neubauprojekt', house: 'Haus', apartment: 'Wohnung', land: 'Grundstück' };
    const features = [];
    if (form.has_balcony) features.push('Balkon');
    if (form.has_terrace) features.push('Terrasse');
    if (form.has_garden) features.push('Garten');
    if (form.has_elevator) features.push('Aufzug');
    if (form.has_basement) features.push('Keller');
    if (form.has_loggia) features.push('Loggia');
    if (form.has_pool) features.push('Pool');
    if (form.has_sauna) features.push('Sauna');
    if (form.has_fireplace) features.push('Kamin');
    if (form.has_barrier_free) features.push('Barrierefrei');
    if (form.has_fitted_kitchen) features.push('Einbauküche');
    if (form.has_air_conditioning) features.push('Klimaanlage');

    previewFrame.value.contentWindow.postMessage({
      type: 'sr-preview-update',
      fields: {
        title: form.project_name || form.title || '',
        address: [form.address, form.zip, form.city].filter(Boolean).join(', '),
        price: form.purchase_price,
        type: typeMap[form.property_category] || form.object_type || '',
        ref: form.ref_id || '',
        subtitle: (form.realty_description || form.location_description || '').substring(0, 80),
        area: form.living_area,
        rooms: form.rooms_amount,
        bathrooms: form.bathrooms,
        description: form.realty_description || '',
        features: features,
        year: form.construction_year,
        heating: form.heating,
        energyClass: form.heating_demand_class,
        energyHwb: form.heating_demand_value,
      }
    }, '*');
  }, 300);
}, { deep: true });

// ─── Init ───
onMounted(async () => {
  loadBrokers();
  // First copy what we have from the parent (may be incomplete)
  copyPropertyToForm(props.property);
  // Then fetch full property data (includes boolean features etc.)
  if (props.property?.id) {
    try {
      const r = await fetch(API.value + '&action=get_property&property_id=' + props.property.id);
      const d = await r.json();
      if (d.property) {
        copyPropertyToForm(d.property);
      }
    } catch (e) { console.error('Failed to load full property', e); }
  }
  loadSanierungen();
  dirty.value = false;
});

watch(() => props.property, (p) => {
  if (p) {
    copyPropertyToForm(p);
    loadSanierungen();
    dirty.value = false;
  }
}, { deep: true });

// ─── Save ───
const saving = ref(false);

async function save() {
  saving.value = true;
  const wasNew = !form.id;
  try {
    const payload = { ...form };
    const r = await fetch(API.value + "&action=save_full_property", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
      toast("Gespeichert");
      if (!form.id && d.property?.id) {
        form.id = d.property.id;
      }
      snapshot = JSON.parse(JSON.stringify(form));
      dirty.value = false;
      emit("saved", d.property || payload);
      if (wasNew && d.property?.id) {
        emit("propertyCreated", d.property);
      }

      // Immoji sync happens explicitly via Portale tab — no auto-sync on save
      // This prevents duplicate creation when entities are deleted on immoji
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  saving.value = false;
}

// ─── Discard ───
function discard() {
  Object.assign(form, JSON.parse(JSON.stringify(snapshot)));
  loadSanierungen();
  dirty.value = false;
}

// ─── Parse Fields Functions ───
async function loadParseFiles() {
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    parseFiles.value = d.files || [];
    parseSelectedFiles.value = parseFiles.value
      .filter(f => /expos/i.test(f.filename) || /expos/i.test(f.label || ''))
      .map(f => f.id);
  } catch (e) { parseFiles.value = []; }
}

async function uploadParseFiles(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  parseUploading.value = true;
  const total = files.length;
  let uploaded = 0;
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', props.property.id);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        parseFiles.value.push(d.file);
        parseSelectedFiles.value.push(d.file.id);
        uploaded++;
      }
    } catch (e) { console.error(e); }
  }
  event.target.value = '';
  parseUploading.value = false;
  toast(uploaded === total
    ? uploaded + ' Datei(en) hochgeladen'
    : uploaded + ' von ' + total + ' Datei(en) hochgeladen');
}

async function runParseFields() {
  parseLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=parse_property_fields", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, file_ids: parseSelectedFiles.value }),
    });
    const txt = await r.text();
    if (txt.startsWith("<!") || txt.startsWith("<html")) { toast("Session abgelaufen"); parseLoading.value = false; return; }
    const d = JSON.parse(txt);
    if (d.error) { toast(d.error, "error"); }
    else {
      const filled = d.filled_list || [];
      const msg = d.fields_filled + " Felder ausgefuellt" + (filled.length ? ": " + filled.slice(0, 8).join(", ") + (filled.length > 8 ? " ..." : "") : "") + (d.fields_skipped ? " | " + d.fields_skipped + " uebersprungen (bereits befuellt)" : "");
      toast(msg, "success");
      parseOpen.value = false;
      // Refresh property data in-place (no page reload)
      try {
        const pr = await fetch(API.value + "&action=get_property&property_id=" + props.property.id);
        const pd = await pr.json();
        if (pd.property) { copyPropertyToForm(pd.property); dirty.value = false; }
      } catch (e2) { console.error("Property refresh failed", e2); }
    }
  } catch (e) { toast("Fehler: " + e.message, "error"); }
  parseLoading.value = false;
}

// ─── AI description generation ───
const aiGenerating = ref(null); // 'objekt' | 'lage' | null
const aiOverwriteDialog = ref(false);
const aiPendingType = ref(null); // 'objekt' | 'lage'
const aiPendingPreview = ref(''); // existing text shown in the dialog

function requestGenerateDescription(type) {
  if (aiGenerating.value) return;
  if (!form.id) { toast("Bitte zuerst Objekt speichern."); return; }

  const targetKey = type === 'lage' ? 'location_description' : 'realty_description';
  const currentText = (form[targetKey] || '').trim();
  if (currentText !== '') {
    aiPendingType.value = type;
    aiPendingPreview.value = currentText;
    aiOverwriteDialog.value = true;
    return;
  }
  runGenerateDescription(type);
}

function cancelGenerateDescription() {
  aiOverwriteDialog.value = false;
  aiPendingType.value = null;
  aiPendingPreview.value = '';
}

async function confirmGenerateDescription() {
  const type = aiPendingType.value;
  aiOverwriteDialog.value = false;
  aiPendingType.value = null;
  aiPendingPreview.value = '';
  if (type) await runGenerateDescription(type);
}

async function runGenerateDescription(type) {
  const targetKey = type === 'lage' ? 'location_description' : 'realty_description';
  aiGenerating.value = type;
  try {
    const body = { property_id: form.id, type };
    if (type === 'objekt') {
      // Use all property_files for context — the backend trims if needed.
      body.file_ids = (parseFiles.value || []).map(f => f.id).filter(Boolean);
    }
    const r = await fetch(API.value + "&action=generate_property_description", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
    });
    const d = await r.json().catch(() => ({ success: false, error: 'Ungültige Serverantwort (HTTP ' + r.status + ')' }));
    if (d.success && d.text) {
      form[targetKey] = d.text;
      toast(type === 'lage' ? 'Lagebeschreibung generiert' : 'Objektbeschreibung generiert');
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch (e) {
    toast('Generierung fehlgeschlagen: ' + e.message);
  } finally {
    aiGenerating.value = null;
  }
}

// ─── Wording polish ───
const aiPolishing = ref(null); // 'objekt' | 'lage' | null

async function polishDescription(type) {
  if (aiPolishing.value || aiGenerating.value) return;
  const targetKey = type === 'lage' ? 'location_description' : 'realty_description';
  const current = (form[targetKey] || '').trim();
  if (current === '') {
    toast('Kein Text zum Verbessern vorhanden.');
    return;
  }
  aiPolishing.value = type;
  try {
    const r = await fetch(API.value + "&action=polish_property_description", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ type, text: current }),
    });
    const d = await r.json().catch(() => ({ success: false, error: 'Ungültige Serverantwort (HTTP ' + r.status + ')' }));
    if (d.success && d.text) {
      form[targetKey] = d.text;
      toast('Wording verbessert');
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch (e) {
    toast('Verbesserung fehlgeschlagen: ' + e.message);
  } finally {
    aiPolishing.value = null;
  }
}

// ─── Expose methods to parent via template ref ───
defineExpose({ save, discard });
</script>

<template>
  <Tabs v-model="activeSubTab" default-value="allgemeines">
    <!-- Subtab bar — flush under main tabs -->
    <div class="-mx-6 mb-6 sticky top-0 z-10 bg-white">
      <TabsList class="flex w-full justify-start bg-gradient-to-b from-zinc-50 to-zinc-100/50 border-b border-zinc-200 rounded-none h-auto p-0 px-6 gap-0">
        <TabsTrigger value="allgemeines" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Allgemeines</TabsTrigger>
        <TabsTrigger value="kosten" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Kosten</TabsTrigger>
        <TabsTrigger value="flaechen" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Flaechen</TabsTrigger>
        <TabsTrigger value="energie" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Energie</TabsTrigger>
        <TabsTrigger value="beschreibung" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Beschreibung</TabsTrigger>
        <TabsTrigger value="medien" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Medien</TabsTrigger>
        <TabsTrigger v-if="!isNewbuild && !isChild" value="historie" class="flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 border-transparent text-muted-foreground data-[state=active]:border-zinc-800 data-[state=active]:text-zinc-900 data-[state=active]:font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none">Sanierungen</TabsTrigger>
        <div class="flex-1"></div>
        <button
          class="flex items-center gap-1.5 px-3 py-2 text-[11px] font-medium rounded-md bg-zinc-900 text-white hover:bg-zinc-800 shadow-sm transition-colors"
          @click="parseOpen = !parseOpen; if (parseOpen && !parseFiles.length) loadParseFiles()"
        >
          <Sparkles class="w-3.5 h-3.5" />
          Mit KI auslesen
        </button>
      </TabsList>
    </div>

    <div v-if="parseOpen" class="rounded-lg p-4 space-y-3 mb-3" style="border:1px solid hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9% / 0.3)">
      <div class="flex items-center justify-between">
        <h3 class="text-[13px] font-semibold">Felder aus Dokumenten auslesen</h3>
        <button @click="parseOpen = false" class="text-muted-foreground hover:text-foreground">
          <X class="w-4 h-4" />
        </button>
      </div>
      <p class="text-[11px] text-muted-foreground">Nur leere Felder werden befuellt. Bestehende Werte bleiben erhalten.</p>

      <label class="flex items-center gap-2 p-3 rounded-lg cursor-pointer hover:bg-muted/50" style="border:1px dashed hsl(240 5.9% 85%)">
        <Upload class="w-4 h-4 text-muted-foreground" />
        <span class="text-[11px] text-muted-foreground">{{ parseUploading ? 'Wird hochgeladen...' : 'Neue Dateien hochladen' }}</span>
        <input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.xls" class="sr-only" @change="uploadParseFiles" :disabled="parseUploading" />
      </label>

      <div class="space-y-1 max-h-40 overflow-y-auto">
        <label v-for="f in parseFiles" :key="f.id" class="flex items-center gap-2 p-2 rounded hover:bg-muted/50 cursor-pointer">
          <input type="checkbox" :value="f.id" v-model="parseSelectedFiles" class="rounded border-border" />
          <span class="text-[11px] flex-1 truncate">{{ f.label || f.filename }}</span>
          <span class="text-[9px] text-muted-foreground uppercase">{{ f.filename?.split('.').pop() }}</span>
        </label>
      </div>
      <div v-if="!parseFiles.length" class="text-[11px] text-muted-foreground py-2">Noch keine Dateien. Bitte oben hochladen.</div>

      <Button size="sm" :disabled="!parseSelectedFiles.length || parseLoading" @click="runParseFields">
        <Sparkles v-if="!parseLoading" class="w-3.5 h-3.5 mr-1.5" />
        <div v-else class="w-3.5 h-3.5 mr-1.5 border-2 border-current border-t-transparent rounded-full animate-spin" />
        {{ parseLoading ? 'Wird analysiert...' : parseSelectedFiles.length + ' Datei(en) auslesen' }}
      </Button>
    </div>

      <TabsContent value="allgemeines" class="mt-0 px-1">
        <EditTabAllgemeines :form="form" :broker-list="brokerList" :is-newbuild="isNewbuild" :is-child="isChild" :features="features" />
      </TabsContent>

      <TabsContent value="kosten" class="mt-0 px-1">
        <EditTabKosten :form="form" :is-newbuild="isNewbuild" />
      </TabsContent>

      <TabsContent value="flaechen" class="mt-0 px-1">
        <EditTabFlaechen :form="form" :is-newbuild="isNewbuild" />
      </TabsContent>

      <TabsContent value="energie" class="mt-0 px-1">
        <EditTabEnergie :form="form" />
      </TabsContent>

      <!-- REMOVED: old objekt inline content below — kept for reference until sub-components verified -->
      <template v-if="false">
      <TabsContent value="_objekt_old" class="mt-0">
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Objekt</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ref-ID <FieldExportBadges field="ref_id" /></label>
            <Input v-model="form.ref_id" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Objekttyp <FieldExportBadges field="object_type" /></label>
            <Select v-model="form.object_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in objectTypes" :key="t" :value="t">{{ t }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Transaktionsart <FieldExportBadges field="marketing_type" /></label>
            <Select v-model="form.marketing_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in marketingTypes" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Kategorie <FieldExportBadges field="property_category" /></label>
            <Select v-model="form.property_category">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="c in categoryOptions" :key="c.value" :value="c.value">{{ c.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Projektname <FieldExportBadges field="project_name" /></label>
            <Input v-model="form.project_name" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Inserat-Titel <FieldExportBadges field="title" /></label>
            <Input v-model="form.title" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Unterobjektart</label>
            <Select v-model="form.construction_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in subtypeOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Bauart</label>
            <Select v-model="form.ownership_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in constructionTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Eigentumsform</label>
            <Select v-model="form.subtitle">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in ownershipOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Wohneinheiten <FieldExportBadges field="unit_count" /></label>
            <Input v-model="form.unit_count" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div class="col-span-2">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Untertitel <FieldExportBadges field="ad_tag" /></label>
            <Input v-model="form.ad_tag" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Werbetag <FieldExportBadges field="closing_date" /></label>
            <Input v-model="form.closing_date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Adresse</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div class="col-span-2">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Strasse <FieldExportBadges field="address" /></label>
            <Input v-model="form.address" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Hausnummer</label>
            <Input v-model="form.house_number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">PLZ <FieldExportBadges field="zip" /></label>
            <Input v-model="form.zip" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Stadt <FieldExportBadges field="city" /></label>
            <Input v-model="form.city" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Stiege</label>
            <Input v-model="form.staircase" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Tuer</label>
            <Input v-model="form.door" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Etage</label>
            <Input v-model="form.address_floor" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Breitengrad <FieldExportBadges field="latitude" /></label>
            <Input v-model="form.latitude" type="number" step="0.0000001" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Laengengrad <FieldExportBadges field="longitude" /></label>
            <Input v-model="form.longitude" type="number" step="0.0000001" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Zuordnung</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler <FieldExportBadges field="broker_id" /></label>
            <Select v-model="form.broker_id">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Status <FieldExportBadges field="status" /></label>
            <Select v-model="form.status">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="aktiv">Aktiv</SelectItem>
                <SelectItem value="inaktiv">Inaktiv</SelectItem>
                <SelectItem value="verkauft">Verkauft</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Fertigstellung</label>
            <Input v-model="form.construction_end" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Bautraeger <FieldExportBadges field="builder_company" /></label>
            <Input v-model="form.builder_company" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>
      </TabsContent>

      <TabsContent value="preise" class="mt-0">
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 block">{{ isNewbuild ? 'Gesamtvolumen' : 'Kaufpreis / Miete' }} <span class="inline-flex gap-0.5"><component :is="iconMap['globe']" class="w-3 h-3 text-orange-400 flex-shrink-0 cursor-help" title="Sichtbar auf der Website" /></span></label>
            <Input v-model="form.purchase_price" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Preis/m2 <FieldExportBadges field="price_per_m2" /></label>
            <Input v-model="form.price_per_m2" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Stellplatz-Preis <FieldExportBadges field="parking_price" /></label>
            <Input v-model="form.parking_price" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Betriebskosten <FieldExportBadges field="operating_costs" /></label>
            <Input v-model="form.operating_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ruecklage <FieldExportBadges field="maintenance_reserves" /></label>
            <Input v-model="form.maintenance_reserves" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <template v-if="form.marketing_type === 'miete'">
          <Separator class="my-2" />
          <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Miete</div>
          <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
            <div>
              <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Kaltmiete <FieldExportBadges field="rental_price" /></label>
              <Input v-model="form.rental_price" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
            </div>
            <div>
              <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Warmmiete <FieldExportBadges field="rent_warm" /></label>
              <Input v-model="form.rent_warm" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
            </div>
            <div>
              <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Kaution <FieldExportBadges field="rent_deposit" /></label>
              <Input v-model="form.rent_deposit" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
            </div>
          </div>
        </template>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Provision Intern</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Provision % <FieldExportBadges field="commission_percent" /></label>
            <Input v-model="form.commission_percent" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Provision EUR <FieldExportBadges field="commission_total" /></label>
            <Input v-model="form.commission_total" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div class="col-span-2">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Provisionsnotiz <FieldExportBadges field="commission_note" /></label>
            <Input v-model="form.commission_note" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Provision Oeffentlich</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler-Provision % <FieldExportBadges field="buyer_commission_percent" /></label>
            <Input v-model="form.buyer_commission_percent" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler-Provision EUR <FieldExportBadges field="commission_makler" /></label>
            <Input v-model="form.commission_makler" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div class="col-span-2">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Provisionstext (Inserate) <FieldExportBadges field="buyer_commission_text" /></label>
            <Input v-model="form.buyer_commission_text" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Erweiterte Kosten</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Heizkosten</label>
            <Input v-model="form.heating_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Warmwasser</label>
            <Input v-model="form.warm_water_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Kuehlung</label>
            <Input v-model="form.cooling_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Verwaltung</label>
            <Input v-model="form.admin_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Aufzug</label>
            <Input v-model="form.elevator_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Parkplatz</label>
            <Input v-model="form.parking_costs_monthly" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Sonstige</label>
            <Input v-model="form.other_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Monatliche Kosten</label>
            <Input v-model="form.monthly_costs" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Nebenkosten</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Grundbucheintragung %</label>
            <Input v-model="form.land_register_fee_pct" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Grunderwerbssteuer %</label>
            <Input v-model="form.land_transfer_tax_pct" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5">Vertragserstellung %</label>
            <Input v-model="form.contract_fee_pct" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="flex flex-wrap gap-1.5">
          <button type="button"
            @click="form.buyer_commission_free = !form.buyer_commission_free"
            :style="form.buyer_commission_free ? 'background:hsl(240 5.9% 10%);color:white' : 'border:1px solid hsl(240 5.9% 90%)'"
            class="px-2.5 py-1 rounded-md text-[11px] transition-colors">
            Provisionsfrei
          </button>
        </div>
      </TabsContent>

      <TabsContent value="flaechen" class="mt-0">
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Flaechen (m2)</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { key: 'living_area', label: 'Wohnflaeche' },
            { key: 'realty_area', label: 'Nutzflaeche' },
            { key: 'free_area', label: 'Grundstueck' },
            { key: 'area_balcony', label: 'Balkon' },
            { key: 'area_terrace', label: 'Terrasse' },
            { key: 'area_garden', label: 'Garten' },
            { key: 'area_loggia', label: 'Loggia' },
            { key: 'area_basement', label: 'Keller' },
            { key: 'area_garage', label: 'Garage' },
            { key: 'office_space', label: 'Buero' },
          ]" :key="f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">{{ f.label }} <FieldExportBadges :field="f.key" /></label>
            <div v-if="f.key === 'living_area' && isNewbuild" class="relative">
              <Input :model-value="form[f.key]" type="number" step="0.01" class="h-8 text-[13px] bg-muted/50 cursor-not-allowed" disabled title="Wird automatisch aus Einheiten berechnet" />
              <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] text-muted-foreground">auto</span>
            </div>
            <Input v-else v-model="form[f.key]" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div></div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Raeume & Stockwerk</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Zimmer <FieldExportBadges field="rooms_amount" /></label>
            <Input v-model="form.rooms_amount" type="number" step="0.5" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Schlafzimmer <FieldExportBadges field="bedrooms" /></label>
            <Input v-model="form.bedrooms" type="number" step="1" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Badezimmer <FieldExportBadges field="bathrooms" /></label>
            <Input v-model="form.bathrooms" type="number" step="1" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">WCs <FieldExportBadges field="toilets" /></label>
            <Input v-model="form.toilets" type="number" step="1" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Stockwerk <FieldExportBadges field="floor_number" /></label>
            <Input v-model="form.floor_number" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Stockwerke ges. <FieldExportBadges field="floor_count" /></label>
            <Input v-model="form.floor_count" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Stellplaetze <FieldExportBadges field="parking_spaces" /></label>
            <Input v-model="form.parking_spaces" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Parkplatz-Typ <FieldExportBadges field="parking_type" /></label>
            <Input v-model="form.parking_type" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>
      </TabsContent>

      <TabsContent v-if="!isChild" value="ausstattung" class="mt-0">
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Zustand <FieldExportBadges field="realty_condition" /></label>
            <Select v-model="form.realty_condition">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in conditionOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Qualitaet <FieldExportBadges field="quality" /></label>
            <Select v-model="form.quality">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in qualityOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Baujahr <FieldExportBadges field="construction_year" /></label>
            <Input v-model="form.construction_year" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Sanierungsjahr <FieldExportBadges field="year_renovated" /></label>
            <Input v-model="form.year_renovated" type="number" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Kueche <FieldExportBadges field="kitchen_type" /></label>
            <Select v-model="form.kitchen_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in kitchenOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Heizung <FieldExportBadges field="heating" /></label>
            <Input v-model="form.heating" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Bodenbelag <FieldExportBadges field="flooring" /></label>
            <Input v-model="form.flooring" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Badausstattung <FieldExportBadges field="bathroom_equipment" /></label>
            <Input v-model="form.bathroom_equipment" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ausrichtung <FieldExportBadges field="orientation" /></label>
            <Input v-model="form.orientation" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Moeblierung <FieldExportBadges field="furnishing" /></label>
            <Input v-model="form.furnishing" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Merkmale</div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="feat in features" :key="feat.key" type="button"
            @click="form[feat.key] = !form[feat.key]"
            :style="form[feat.key] ? 'background:hsl(240 5.9% 10%);color:white' : 'border:1px solid hsl(240 5.9% 90%)'"
            class="px-2.5 py-1 rounded-md text-[11px] transition-colors">
            {{ feat.label }} <FieldExportBadges :field="feat.key" />
          </button>
        </div>
      </TabsContent>

      <TabsContent value="energie" class="mt-0">
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ausweistyp <FieldExportBadges field="energy_type" /></label>
            <Select v-model="form.energy_type">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in energyTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Energieklasse <FieldExportBadges field="heating_demand_class" /></label>
            <Select v-model="form.heating_demand_class">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="">--</SelectItem>
                <SelectItem v-for="c in energyClasses" :key="c" :value="c">{{ c }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">HWB (kWh/m2a) <FieldExportBadges field="heating_demand_value" /></label>
            <Input v-model="form.heating_demand_value" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">fGEE <FieldExportBadges field="energy_efficiency_value" /></label>
            <Input v-model="form.energy_efficiency_value" type="number" step="0.01" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Energietraeger <FieldExportBadges field="energy_primary_source" /></label>
            <Input v-model="form.energy_primary_source" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Gueltig bis <FieldExportBadges field="energy_valid_until" /></label>
            <Input v-model="form.energy_valid_until" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div class="col-span-3 max-sm:col-span-2">
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Energieausweis (Freitext) <FieldExportBadges field="energy_certificate" /></label>
            <Textarea v-model="form.energy_certificate" rows="2" class="text-[13px]" />
          </div>
        </div>
      </TabsContent>

      <TabsContent value="bau" class="mt-0">
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Verfuegbar ab <FieldExportBadges field="available_from" /></label>
            <Input v-model="form.available_from" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Verfuegbarkeit <FieldExportBadges field="available_text" /></label>
            <Input v-model="form.available_text" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" placeholder="sofort, nach Vereinbarung" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Baubeginn <FieldExportBadges field="construction_start" /></label>
            <Input v-model="form.construction_start" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 block">Fertigstellung</label>
            <Input v-model="form.construction_end" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Bautraeger</label>
            <Input v-model="form.builder_company" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Hausverwaltung <FieldExportBadges field="property_manager" /></label>
            <Input v-model="form.property_manager" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
          <div>
            <label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Inseriert seit <FieldExportBadges field="inserat_since" /></label>
            <Input v-model="form.inserat_since" type="date" class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border" />
          </div>
        </div>
      </TabsContent>

      <TabsContent value="gebaeude" class="mt-0">
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Bau</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'construction', key: 'method', label: 'Bauweise', opts: buildingOptions.constructionMethod },
            { section: 'construction', key: 'condition', label: 'Bauzustand', opts: buildingOptions.constructionCondition },
            { section: 'construction', key: 'expansion', label: 'Ausbaustufe', opts: buildingOptions.expansionStage },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Fassade</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'facade', key: 'type', label: 'Fassadentyp', opts: buildingOptions.facadeType },
            { section: 'facade', key: 'exterior_condition', label: 'Aussenputz Zustand', opts: buildingOptions.conditionGrade },
            { section: 'facade', key: 'masonry_condition', label: 'Mauerwerk Zustand', opts: buildingOptions.conditionGrade },
            { section: 'facade', key: 'basement_masonry', label: 'Kellermauerwerk', opts: buildingOptions.conditionGrade },
            { section: 'facade', key: 'insulation', label: 'Daemmung', opts: buildingOptions.conditionGrade },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Heizung &amp; Warmwasser</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'heating', key: 'type', label: 'Heizungsart', opts: buildingOptions.heatingType },
            { section: 'heating', key: 'fuel', label: 'Brennstoff', opts: buildingOptions.fuelType },
            { section: 'heating', key: 'hot_water', label: 'Warmwasser', opts: buildingOptions.hotWaterType },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Elektrik &amp; Belueftung</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'electrical', key: 'type', label: 'Elektrik', opts: buildingOptions.electricalType },
            { section: 'electrical', key: 'condition', label: 'Elektrik Zustand', opts: buildingOptions.conditionGrade },
            { section: 'electrical', key: 'ventilation_type', label: 'Belueftung', opts: buildingOptions.ventilationType },
            { section: 'electrical', key: 'ventilation_condition', label: 'Belueftung Zustand', opts: buildingOptions.conditionGrade },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Telekommunikation</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'telecom', key: 'tv', label: 'TV-Anschluss', opts: buildingOptions.tvConnection },
            { section: 'telecom', key: 'phone', label: 'Telefonanschluss', opts: buildingOptions.phoneConnection },
            { section: 'telecom', key: 'internet', label: 'Internet', opts: buildingOptions.internetConnection },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Dach</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'roof', key: 'shape', label: 'Dachform', opts: buildingOptions.roofShape },
            { section: 'roof', key: 'covering', label: 'Dachbedeckung', opts: buildingOptions.roofCovering },
            { section: 'roof', key: 'insulation', label: 'Dachdaemmung', opts: buildingOptions.conditionGrade },
            { section: 'roof', key: 'dormers', label: 'Dachgauben', opts: buildingOptions.conditionGrade },
            { section: 'roof', key: 'skylights', label: 'Dachfenster', opts: buildingOptions.conditionGrade },
            { section: 'roof', key: 'gutters', label: 'Dachrinnen', opts: buildingOptions.conditionGrade },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Fenster</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'windows', key: 'material', label: 'Fenstermaterial', opts: buildingOptions.windowMaterial },
            { section: 'windows', key: 'glazing', label: 'Verglasung', opts: buildingOptions.glazingType },
            { section: 'windows', key: 'sun_protection', label: 'Sonnenschutz', opts: buildingOptions.sunProtection },
            { section: 'windows', key: 'condition', label: 'Zustand', opts: buildingOptions.conditionGrade },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <Separator class="my-2" />
        <div class="text-[10px] font-medium text-muted-foreground/70 mb-1">Etagen</div>
        <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-x-2 gap-y-1.5">
          <div v-for="f in [
            { section: 'floors', key: 'stairs', label: 'Treppe', opts: buildingOptions.stairsType },
            { section: 'floors', key: 'elevator', label: 'Aufzug', opts: buildingOptions.elevatorType },
            { section: 'floors', key: 'common_area', label: 'Allgemeinflaechen', opts: buildingOptions.commonAreaCondition },
          ]" :key="f.section+f.key">
            <label class="text-[10px] text-muted-foreground mb-0.5">{{ f.label }}</label>
            <Select :model-value="bd(f.section, f.key)" @update:model-value="v => setBd(f.section, f.key, v)">
              <SelectTrigger class="h-8 text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in f.opts" :key="o" :value="o">{{ o || '-- Bitte waehlen --' }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </TabsContent>
      </template><!-- end v-if="false" old inline blocks -->

      <TabsContent value="beschreibung" class="mt-0">
        <div class="space-y-6">
          <div v-for="f in [
            { key: 'realty_description', label: 'Objektbeschreibung', placeholder: 'Allgemeine Beschreibung des Objekts...', aiType: 'objekt', aiHint: 'Aus Property-Daten + hochgeladenen Dokumenten' },
            { key: 'location_description', label: 'Lagebeschreibung', placeholder: 'Beschreibung der Lage und Umgebung...', aiType: 'lage', aiHint: 'Web-Recherche zu Adresse, Infrastruktur & Umgebung' },
          ]" :key="f.key" class="space-y-1.5">
            <div class="flex items-center justify-between gap-2">
              <label class="block text-[12px] font-medium text-muted-foreground">{{ f.label }}</label>
              <div v-if="f.aiType" class="flex items-center gap-1.5">
                <Button
                  variant="outline"
                  size="xs"
                  class="h-6 text-[11px] gap-1"
                  :disabled="aiGenerating !== null || aiPolishing !== null || !(form[f.key] || '').trim()"
                  :title="(form[f.key] || '').trim() === '' ? 'Erst einen Text schreiben oder generieren' : 'Wording & Formatierung verbessern (keine neuen Fakten)'"
                  @click="polishDescription(f.aiType)"
                >
                  <Wand2 class="w-3 h-3" />
                  <span v-if="aiPolishing === f.aiType">Verbessere…</span>
                  <span v-else>Wording</span>
                </Button>
                <Button
                  variant="outline"
                  size="xs"
                  class="h-6 text-[11px] gap-1"
                  :disabled="aiGenerating !== null || aiPolishing !== null || !form.id"
                  :title="!form.id ? 'Objekt zuerst speichern' : f.aiHint"
                  @click="requestGenerateDescription(f.aiType)"
                >
                  <Sparkles class="w-3 h-3" />
                  <span v-if="aiGenerating === f.aiType">Generiere…</span>
                  <span v-else>KI generieren</span>
                </Button>
              </div>
            </div>
            <Textarea
              v-model="form[f.key]"
              :placeholder="f.placeholder"
              rows="14"
              class="w-full resize-y text-[13px] bg-zinc-100/80 border-transparent hover:border-border focus:border-border transition-colors leading-relaxed"
            />
          </div>
        </div>
      </TabsContent>

      <TabsContent value="medien" class="mt-0">
        <MediaTab :property="property" @dirty="emit('dirty')" />
      </TabsContent>

      <TabsContent v-if="!isNewbuild && !isChild" value="historie" class="mt-0">
        <div class="space-y-2">
          <p class="text-[11px] text-muted-foreground mb-2">
            Nur ausgefüllte Kategorien werden gespeichert und synchronisiert. Eingaben werden automatisch gesichert.
          </p>
          <div
            v-for="cat in SANIERUNGEN_CATEGORIES"
            :key="cat.key"
            class="grid grid-cols-[120px_90px_1fr] max-sm:grid-cols-1 gap-2 items-center py-1.5 border-b border-border/40 last:border-0"
          >
            <label class="text-[12px] font-medium text-zinc-900">{{ cat.label }}</label>
            <Input
              v-if="cat.hasYear"
              v-model="sanierungenInputs[cat.key].year"
              @input="scheduleSanierungenSave"
              placeholder="Jahr"
              class="h-8 text-[13px]"
              inputmode="numeric"
              maxlength="4"
            />
            <div v-else class="text-[11px] text-muted-foreground italic max-sm:hidden">ohne Jahr</div>
            <Input
              v-model="sanierungenInputs[cat.key].note"
              @input="scheduleSanierungenSave"
              placeholder="Anmerkung (optional)"
              class="h-8 text-[13px]"
            />
          </div>
          <div class="flex items-center justify-between pt-2">
            <span class="text-[11px] text-muted-foreground">{{ sanierungenCount }} Kategorie(n) ausgefüllt</span>
            <Button variant="outline" size="sm" :disabled="historySaving" @click="saveSanierungen" class="text-xs h-7">
              {{ historySaving ? 'Speichere…' : 'Jetzt speichern' }}
            </Button>
          </div>
        </div>
      </TabsContent>

    <!-- Preview Overlay Sidebar -->
    <template v-if="form.id && !isNew">
      <!-- Collapsed tab -->
      <button
        v-if="!previewOpen"
        @click="previewOpen = true"
        class="fixed right-0 top-1/2 -translate-y-1/2 z-30 bg-zinc-100 hover:bg-zinc-200 border border-r-0 border-zinc-200 rounded-l-lg px-1.5 py-6 transition-colors hidden xl:flex flex-col items-center gap-1"
        title="Vorschau oeffnen"
      >
        <Eye class="w-3.5 h-3.5 text-zinc-500" />
        <span class="text-[9px] text-zinc-500 font-medium" style="writing-mode:vertical-rl">Vorschau</span>
      </button>

      <!-- Overlay panel -->
      <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-200 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
      >
        <div v-if="previewOpen" class="fixed right-0 top-0 bottom-0 z-40 bg-background shadow-2xl border-l border-border hidden xl:flex flex-col" style="width:620px">
          <div class="flex items-center justify-between px-3 py-2 border-b border-border bg-zinc-50">
            <span class="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Website-Vorschau</span>
            <button @click="previewOpen = false" class="w-6 h-6 rounded-md flex items-center justify-center hover:bg-zinc-200 text-muted-foreground hover:text-foreground transition-colors">
              <X class="w-3.5 h-3.5" />
            </button>
          </div>
          <div class="flex-1 overflow-hidden">
            <iframe
              ref="previewFrame"
              :src="previewUrl"
              class="w-full h-full border-0"
              style="transform-origin:top left;transform:scale(0.5);width:200%;height:200%"
            />
          </div>
        </div>
      </Transition>
    </template>
  </Tabs>

  <!-- AI description overwrite confirmation -->
  <Dialog :open="aiOverwriteDialog" @update:open="(v) => { if (!v) cancelGenerateDescription() }">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Sparkles class="w-4 h-4" style="color:#8b5cf6" />
          <template v-if="aiPendingType === 'lage'">Lagebeschreibung überschreiben?</template>
          <template v-else>Objektbeschreibung überschreiben?</template>
        </DialogTitle>
        <DialogDescription>
          Der bestehende Text wird durch die KI-generierte Version ersetzt. Diese Aktion kann nicht rückgängig gemacht werden.
        </DialogDescription>
      </DialogHeader>

      <div class="max-h-40 overflow-y-auto rounded-md border border-border/50 bg-zinc-50 p-3 text-xs text-zinc-700 whitespace-pre-wrap">
        {{ aiPendingPreview }}
      </div>

      <DialogFooter class="gap-2">
        <Button variant="outline" size="sm" @click="cancelGenerateDescription">Abbrechen</Button>
        <Button size="sm" @click="confirmGenerateDescription" style="background:#8b5cf6;color:white">
          <Sparkles class="w-3.5 h-3.5 mr-1" />
          Neu generieren
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
