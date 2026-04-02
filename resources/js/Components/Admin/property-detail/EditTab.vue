<script setup>
import { ref, reactive, computed, watch, inject, onMounted } from "vue";
import { ChevronRight, Plus, Trash2, Sparkles, Upload, X } from "lucide-vue-next";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

const props = defineProps({
  property: { type: Object, required: true },
  isNew: { type: Boolean, default: false },
});

const emit = defineEmits(["dirty", "saved"]);

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

// ─── Section open/close state ───
const sections = reactive({
  objektAdresse: true,
  preiseKosten: true,
  flaechenRaeume: false,
  ausstattung: false,
  energie: false,
  verfuegbarkeit: false,
  historie: false,
});

// ─── Parse Fields ───
const parseOpen = ref(false);
const parseLoading = ref(false);
const parseFiles = ref([]);
const parseSelectedFiles = ref([]);
const parseUploading = ref(false);

// ─── Option arrays ───
const objectTypes = [
  "Eigentumswohnung", "Haus", "Einfamilienhaus", "Grundstueck",
  "Neubauprojekt", "Gartenwohnung", "Dachgeschosswohnung", "Penthouse",
  "Maisonette", "Reihenhaus", "Doppelhaushaelfte", "Gewerbe",
  "Buero", "Anlage", "Sonstiges", "Neubau",
];

const marketingTypes = [
  { value: "kauf", label: "Kauf" },
  { value: "miete", label: "Miete" },
  { value: "pacht", label: "Pacht" },
];

const categoryOptions = [
  { value: "", label: "-- Keine --" },
  { value: "house", label: "Haus" },
  { value: "apartment", label: "Wohnung" },
  { value: "newbuild", label: "Neubauprojekt" },
  { value: "land", label: "Grundstueck" },
];

const conditionOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "erstbezug", label: "Erstbezug" },
  { value: "neuwertig", label: "Neuwertig" },
  { value: "gepflegt", label: "Gepflegt" },
  { value: "renovierungsbeduerftig", label: "Renovierungsbeduerftig" },
  { value: "saniert", label: "Saniert" },
  { value: "teilsaniert", label: "Teilsaniert" },
  { value: "abbruchreif", label: "Abbruchreif" },
];

const qualityOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "einfach", label: "Einfach" },
  { value: "normal", label: "Normal" },
  { value: "gehoben", label: "Gehoben" },
  { value: "luxurioes", label: "Luxurioes" },
];

const energyTypeOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "Verbrauch", label: "Verbrauchsausweis" },
  { value: "Bedarf", label: "Bedarfsausweis" },
];

const energyClasses = ["A++", "A+", "A", "B", "C", "D", "E", "F", "G", "H"];

// ─── Field visibility map ───
// W = Website, P = Portal, I = Intern (admin only)
const fieldVis = {
  ref_id:         { icons: ['W'], tip: 'Website: Referenz-ID' },
  object_type:    { icons: ['W','P'], tip: 'Website + Portal: Objekttyp' },
  marketing_type: { icons: [], tip: 'Wird nirgends angezeigt' },
  property_category: { icons: ['W'], tip: 'Website: Kategorie-Filter' },
  project_name:   { icons: ['W'], tip: 'Website: Projektname' },
  title:          { icons: [], tip: 'Wird nirgends angezeigt' },
  address:        { icons: ['W','P'], tip: 'Website + Portal: Adresse' },
  zip:            { icons: ['W','P'], tip: 'Website + Portal: PLZ' },
  city:           { icons: ['W','P'], tip: 'Website + Portal: Stadt' },
  latitude:       { icons: [], tip: 'Wird nirgends angezeigt' },
  longitude:      { icons: [], tip: 'Wird nirgends angezeigt' },
  broker_id:      { icons: ['I'], tip: 'Nur intern: Makler-Zuordnung' },
  status:         { icons: ['I'], tip: 'Nur intern: Status-Verwaltung' },
  construction_end: { icons: [], tip: 'Wird nirgends angezeigt' },
  builder_company: { icons: [], tip: 'Wird nirgends angezeigt' },
  purchase_price: { icons: ['W'], tip: 'Website: Kaufpreis' },
  price_to:       { icons: [], tip: 'Wird nirgends angezeigt' },
  price_per_m2:   { icons: [], tip: 'Wird nirgends angezeigt' },
  parking_price:  { icons: [], tip: 'Wird nirgends angezeigt' },
  operating_costs: { icons: [], tip: 'Wird nirgends angezeigt' },
  maintenance_reserves: { icons: [], tip: 'Wird nirgends angezeigt' },
  rental_price:   { icons: [], tip: 'Wird nirgends angezeigt' },
  rent_warm:      { icons: [], tip: 'Wird nirgends angezeigt' },
  rent_deposit:   { icons: [], tip: 'Wird nirgends angezeigt' },
  commission_percent: { icons: ['I'], tip: 'Nur intern: Provision' },
  commission_total: { icons: ['I'], tip: 'Nur intern: Provision EUR' },
  commission_note: { icons: ['I'], tip: 'Nur intern: Provisionsnotiz' },
  buyer_commission_percent: { icons: [], tip: 'Wird nirgends angezeigt' },
  commission_makler: { icons: ['I'], tip: 'Nur intern: Makler-Provision' },
  buyer_commission_text: { icons: [], tip: 'Wird nirgends angezeigt' },
  living_area:    { icons: ['W'], tip: 'Website: Wohnflaeche' },
  realty_area:    { icons: [], tip: 'Wird nirgends angezeigt' },
  free_area:      { icons: ['W'], tip: 'Website: Grundstueck' },
  area_balcony:   { icons: [], tip: 'Wird nirgends angezeigt' },
  area_terrace:   { icons: [], tip: 'Wird nirgends angezeigt' },
  area_garden:    { icons: [], tip: 'Wird nirgends angezeigt' },
  area_loggia:    { icons: [], tip: 'Wird nirgends angezeigt' },
  area_basement:  { icons: [], tip: 'Wird nirgends angezeigt' },
  area_garage:    { icons: [], tip: 'Wird nirgends angezeigt' },
  office_space:   { icons: [], tip: 'Wird nirgends angezeigt' },
  rooms_amount:   { icons: ['W'], tip: 'Website: Zimmeranzahl' },
  bedrooms:       { icons: [], tip: 'Wird nirgends angezeigt' },
  bathrooms:      { icons: ['W'], tip: 'Website: Badezimmer' },
  toilets:        { icons: [], tip: 'Wird nirgends angezeigt' },
  floor_number:   { icons: [], tip: 'Wird nirgends angezeigt' },
  floor_count:    { icons: [], tip: 'Wird nirgends angezeigt' },
  garage_spaces:  { icons: ['W'], tip: 'Website: als Feature' },
  parking_spaces: { icons: ['W'], tip: 'Website: als Feature' },
  parking_type:   { icons: [], tip: 'Wird nirgends angezeigt' },
  realty_condition: { icons: [], tip: 'Wird nirgends angezeigt' },
  quality:        { icons: [], tip: 'Wird nirgends angezeigt' },
  construction_year: { icons: ['W'], tip: 'Website: Baujahr' },
  year_renovated: { icons: ['W'], tip: 'Website: Sanierungsjahr' },
  kitchen_type:   { icons: [], tip: 'Wird nirgends angezeigt' },
  heating:        { icons: [], tip: 'Wird nirgends angezeigt' },
  flooring:       { icons: [], tip: 'Wird nirgends angezeigt' },
  bathroom_equipment: { icons: [], tip: 'Wird nirgends angezeigt' },
  orientation:    { icons: [], tip: 'Wird nirgends angezeigt' },
  furnishing:     { icons: [], tip: 'Wird nirgends angezeigt' },
  // Boolean features
  has_balcony:    { icons: ['W'], tip: 'Website: Feature-Badge' },
  has_terrace:    { icons: ['W'], tip: 'Website: Feature-Badge' },
  has_loggia:     { icons: ['W'], tip: 'Website: Feature-Badge' },
  has_garden:     { icons: ['W'], tip: 'Website: Feature-Badge' },
  has_basement:   { icons: ['W'], tip: 'Website: Feature-Badge' },
  has_cellar:     { icons: [], tip: 'Wird nirgends angezeigt' },
  has_elevator:   { icons: ['W'], tip: 'Website: Feature "Lift"' },
  has_fitted_kitchen: { icons: [], tip: 'Wird nirgends angezeigt' },
  has_air_conditioning: { icons: [], tip: 'Wird nirgends angezeigt' },
  has_pool:       { icons: [], tip: 'Wird nirgends angezeigt' },
  has_sauna:      { icons: [], tip: 'Wird nirgends angezeigt' },
  has_fireplace:  { icons: [], tip: 'Wird nirgends angezeigt' },
  has_alarm:      { icons: [], tip: 'Wird nirgends angezeigt' },
  has_barrier_free: { icons: [], tip: 'Wird nirgends angezeigt' },
  has_guest_wc:   { icons: [], tip: 'Wird nirgends angezeigt' },
  has_storage_room: { icons: [], tip: 'Wird nirgends angezeigt' },
  has_washing_connection: { icons: [], tip: 'Wird nirgends angezeigt' },
  // Energy
  energy_type:    { icons: [], tip: 'Wird nirgends angezeigt' },
  heating_demand_class: { icons: [], tip: 'Wird nirgends angezeigt' },
  heating_demand_value: { icons: ['W'], tip: 'Website: HWB-Wert' },
  energy_efficiency_value: { icons: [], tip: 'Wird nirgends angezeigt' },
  energy_primary_source: { icons: [], tip: 'Wird nirgends angezeigt' },
  energy_valid_until: { icons: [], tip: 'Wird nirgends angezeigt' },
  energy_certificate: { icons: ['W'], tip: 'Website: Energieausweis-Text' },
  // Verfuegbarkeit
  available_from: { icons: [], tip: 'Wird nirgends angezeigt' },
  available_text: { icons: [], tip: 'Wird nirgends angezeigt' },
  construction_start: { icons: [], tip: 'Wird nirgends angezeigt' },
  property_manager: { icons: [], tip: 'Wird nirgends angezeigt' },
  inserat_since:  { icons: ['P'], tip: 'Portal: Tage am Markt' },
  platforms:      { icons: [], tip: 'Wird nirgends angezeigt' },
};

function vis(key) {
  return fieldVis[key] || { icons: [], tip: 'Unbekannt' };
}

const kitchenOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "ohne", label: "Ohne Kueche" },
  { value: "offen", label: "Offene Kueche" },
  { value: "einbau", label: "Einbaukueche" },
  { value: "pantry", label: "Pantrykueche" },
];

// ─── Boolean features ───
const features = [
  { key: "has_balcony", label: "Balkon" },
  { key: "has_terrace", label: "Terrasse" },
  { key: "has_loggia", label: "Loggia" },
  { key: "has_garden", label: "Garten" },
  { key: "has_basement", label: "Keller" },
  { key: "has_cellar", label: "Kellerabteil" },
  { key: "has_elevator", label: "Aufzug" },
  { key: "has_fitted_kitchen", label: "Einbaukueche" },
  { key: "has_air_conditioning", label: "Klimaanlage" },
  { key: "has_pool", label: "Pool" },
  { key: "has_sauna", label: "Sauna" },
  { key: "has_fireplace", label: "Kamin" },
  { key: "has_alarm", label: "Alarmanlage" },
  { key: "has_barrier_free", label: "Barrierefrei" },
  { key: "has_guest_wc", label: "Gaeste-WC" },
  { key: "has_storage_room", label: "Abstellraum" },
  { key: "has_washing_connection", label: "Waschmaschinenanschluss" },
];

// ─── Form reactive with ALL property fields ───
const form = reactive({
  id: null, ref_id: "", openimmo_id: "", title: "", project_name: "",
  address: "", latitude: null, longitude: null, city: "", zip: "",
  object_type: "Eigentumswohnung", type: "Eigentumswohnung",
  property_category: "", sub_type: "", marketing_type: "kauf",
  status: "auftrag",
  purchase_price: null, price_per_m2: null, parking_price: null,
  rental_price: null, rent_warm: null, rent_deposit: null,
  operating_costs: null, maintenance_reserves: null,
  living_area: null, realty_area: null, free_area: null,
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
  has_fitted_kitchen: false, has_air_conditioning: false,
  has_pool: false, has_sauna: false, has_fireplace: false,
  has_alarm: false, has_barrier_free: false, has_guest_wc: false,
  has_storage_room: false, has_washing_connection: false, has_cellar: false,
  garage_spaces: null, parking_spaces: null, parking_type: "", 
  description: "", description_location: "", description_equipment: "",
  description_other: "", highlights: "",
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
  price_to: null,
  rent_cold: null,
  area_land: null, area_usable: null, area_office: null,
  price: null,
  size_m2: null,
  year_built: null,
  object_condition: "",
  energy_hwb: null, energy_fgee: null, energy_class: "",
  rooms: null,
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
  // Ensure object_type is populated from type if not set
  if (!form.object_type && form.type) form.object_type = form.type;
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

const areaRoomsBadge = computed(() => {
  const parts = [];
  if (form.living_area) parts.push(form.living_area + " m\u00B2");
  if (form.rooms_amount) parts.push(form.rooms_amount + " Zimmer");
  return parts.join(" \u00B7 ") || null;
});

const energyBadge = computed(() => {
  const parts = [];
  if (form.heating_demand_value) parts.push("HWB " + form.heating_demand_value);
  if (form.heating_demand_class) parts.push(form.heating_demand_class);
  return parts.join(" \u00B7 ") || null;
});

// ─── History ───
const historyItems = ref([]);
const historyAdding = ref(false);
const historyNew = ref({ year: "", title: "", description: "" });
const historySaving = ref(false);

function loadHistory() {
  let d = form.property_history;
  if (typeof d === "string") { try { d = JSON.parse(d); } catch { d = []; } }
  historyItems.value = Array.isArray(d) ? JSON.parse(JSON.stringify(d)) : [];
}

const historyCount = computed(() => historyItems.value.length);

function historyAddEntry() {
  if (!historyNew.value.year || !historyNew.value.title) return;
  historyItems.value.push({ ...historyNew.value });
  historyItems.value.sort((a, b) => String(a.year).localeCompare(String(b.year)));
  historyNew.value = { year: "", title: "", description: "" };
  historyAdding.value = false;
  saveHistory();
}

function historyDeleteEntry(idx) {
  historyItems.value.splice(idx, 1);
  saveHistory();
}

async function saveHistory() {
  if (!form.id) return;
  historySaving.value = true;
  try {
    const r = await fetch(API.value + "&action=update_property", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: form.id, property_history: JSON.stringify(historyItems.value) }),
    });
    const d = await r.json();
    if (d.success) {
      form.property_history = JSON.stringify(historyItems.value);
      toast("Historie gespeichert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  historySaving.value = false;
}

// ─── Dirty tracking ───
const dirty = ref(false);
watch(form, () => {
  dirty.value = true;
  emit("dirty");
}, { deep: true });

// ─── Init ───
onMounted(() => {
  loadBrokers();
  copyPropertyToForm(props.property);
  loadHistory();
  dirty.value = false;
});

watch(() => props.property, (p) => {
  if (p) {
    copyPropertyToForm(p);
    loadHistory();
    dirty.value = false;
  }
}, { deep: true });

// ─── Energy alias mapping (from AI parsing) ───
function mapEnergyAliases(obj) {
  if (!obj) return;
  const aliases = {
    energy_hwb: "heating_demand_value",
    energy_fgee: "energy_efficiency_value",
    energy_class: "heating_demand_class",
  };
  for (const [from, to] of Object.entries(aliases)) {
    if (obj[from] && !obj[to]) obj[to] = obj[from];
  }
}

// ─── Save ───
const saving = ref(false);

async function save() {
  saving.value = true;
  const wasNew = !form.id;
  // Map energy aliases before save
  mapEnergyAliases(form);
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
  loadHistory();
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
      }
    } catch (e) { console.error(e); }
  }
  event.target.value = '';
  parseUploading.value = false;
  toast(files.length + ' Datei(en) hochgeladen');
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

// ─── Expose methods to parent via template ref ───
defineExpose({ save, discard });
</script>

<template>
  <div class="space-y-3 max-w-5xl">

    <!-- Legend -->
    <div class="flex items-center gap-4 px-1 mb-1">
      <span class="text-[10px] text-muted-foreground">Feld-Sichtbarkeit:</span>
      <span class="inline-flex items-center gap-1 text-[10px]"><span class="text-[8px] font-bold px-1 rounded" style="background:hsl(217 91% 93%);color:hsl(217 91% 50%)">W</span> Website</span>
      <span class="inline-flex items-center gap-1 text-[10px]"><span class="text-[8px] font-bold px-1 rounded" style="background:hsl(280 67% 93%);color:hsl(280 67% 45%)">P</span> Kundenportal</span>
      <span class="inline-flex items-center gap-1 text-[10px]"><span class="text-[8px] font-bold px-1 rounded" style="background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)">I</span> Nur intern</span>
      <span class="inline-flex items-center gap-1 text-[10px]"><span class="w-1.5 h-1.5 rounded-full" style="background:hsl(240 5.9% 85%)"></span> Nicht angezeigt</span>
    </div>

    <!-- Felder auslesen -->
    <div class="flex items-center gap-2 px-1">
      <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="parseOpen = !parseOpen; if (parseOpen && !parseFiles.length) loadParseFiles()">
        <Sparkles class="w-3 h-3" />
        Felder auslesen
      </Button>
    </div>

    <div v-if="parseOpen" class="rounded-lg p-4 space-y-3" style="border:1px solid hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9% / 0.3)">
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

    <!-- 1. Objekt & Adresse -->
    <Collapsible v-model:open="sections.objektAdresse" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.objektAdresse }" />
          <span class="text-[13px] font-semibold">Objekt & Adresse</span>
        </div>
        <span v-if="form.ref_id" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ form.ref_id }}</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Objekt</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Ref-ID <span v-if="vis('ref_id').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('ref_id').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('ref_id').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('ref_id').tip"></span></label>
            <Input v-model="form.ref_id" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Objekttyp <span v-if="vis('object_type').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('object_type').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('object_type').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('object_type').tip"></span></label>
            <Select v-model="form.object_type">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in objectTypes" :key="t" :value="t">{{ t }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Transaktionsart <span v-if="vis('marketing_type').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('marketing_type').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('marketing_type').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('marketing_type').tip"></span></label>
            <Select v-model="form.marketing_type">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in marketingTypes" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Kategorie <span v-if="vis('property_category').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('property_category').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('property_category').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('property_category').tip"></span></label>
            <Select v-model="form.property_category">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="c in categoryOptions" :key="c.value" :value="c.value">{{ c.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Projektname <span v-if="vis('project_name').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('project_name').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('project_name').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('project_name').tip"></span></label>
            <Input v-model="form.project_name" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Inserat-Titel <span v-if="vis('title').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('title').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('title').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('title').tip"></span></label>
            <Input v-model="form.title" class="h-8 text-[13px]" />
          </div>
        </div>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Adresse</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div class="col-span-2">
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Strasse & Hausnummer <span v-if="vis('address').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('address').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('address').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('address').tip"></span></label>
            <Input v-model="form.address" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">PLZ <span v-if="vis('zip').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('zip').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('zip').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('zip').tip"></span></label>
            <Input v-model="form.zip" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Stadt <span v-if="vis('city').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('city').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('city').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('city').tip"></span></label>
            <Input v-model="form.city" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Breitengrad <span v-if="vis('latitude').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('latitude').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('latitude').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('latitude').tip"></span></label>
            <Input v-model="form.latitude" type="number" step="0.0000001" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Laengengrad <span v-if="vis('longitude').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('longitude').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('longitude').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('longitude').tip"></span></label>
            <Input v-model="form.longitude" type="number" step="0.0000001" class="h-8 text-[13px]" />
          </div>
        </div>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Zuordnung</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler <span v-if="vis('broker_id').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('broker_id').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('broker_id').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('broker_id').tip"></span></label>
            <Select v-model="form.broker_id">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Status <span v-if="vis('status').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('status').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('status').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('status').tip"></span></label>
            <Select v-model="form.status">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="auftrag">Auftrag</SelectItem>
                <SelectItem value="aktiv">Aktiv</SelectItem>
                <SelectItem value="verkauft">Verkauft</SelectItem>
                <SelectItem value="reserviert">Reserviert</SelectItem>
                <SelectItem value="inaktiv">Inaktiv</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Fertigstellung <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" title="Wird nirgends angezeigt"></span></label>
            <Input v-model="form.construction_end" type="date" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Bautraeger <span v-if="vis('builder_company').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('builder_company').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('builder_company').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('builder_company').tip"></span></label>
            <Input v-model="form.builder_company" class="h-8 text-[13px]" />
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 2. Preise & Kosten -->
    <Collapsible v-model:open="sections.preiseKosten" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.preiseKosten }" />
          <span class="text-[13px] font-semibold">Preise & Kosten</span>
        </div>
        <span v-if="form.purchase_price" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ Number(form.purchase_price).toLocaleString('de-AT') }} EUR</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 block">{{ isNewbuild ? 'Gesamtvolumen' : 'Kaufpreis / Miete' }} <span class="inline-flex gap-0.5"><span class="text-[8px] font-bold px-1 rounded" style="background:hsl(217 91% 93%);color:hsl(217 91% 50%)" title="Website: Kaufpreis">W</span></span></label>
            <Input v-model="form.purchase_price" type="number" step="0.01" :disabled="isNewbuild" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Preis bis <span v-if="vis('price_to').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('price_to').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('price_to').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('price_to').tip"></span></label>
            <Input v-model="form.price_to" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Preis/m2 <span v-if="vis('price_per_m2').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('price_per_m2').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('price_per_m2').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('price_per_m2').tip"></span></label>
            <Input v-model="form.price_per_m2" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Stellplatz-Preis <span v-if="vis('parking_price').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('parking_price').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('parking_price').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('parking_price').tip"></span></label>
            <Input v-model="form.parking_price" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Betriebskosten <span v-if="vis('operating_costs').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('operating_costs').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('operating_costs').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('operating_costs').tip"></span></label>
            <Input v-model="form.operating_costs" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Ruecklage <span v-if="vis('maintenance_reserves').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('maintenance_reserves').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('maintenance_reserves').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('maintenance_reserves').tip"></span></label>
            <Input v-model="form.maintenance_reserves" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
        </div>

        <template v-if="form.marketing_type === 'miete'">
          <Separator class="my-3" />
          <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Miete</div>
          <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
            <div>
              <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Kaltmiete <span v-if="vis('rental_price').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('rental_price').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('rental_price').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('rental_price').tip"></span></label>
              <Input v-model="form.rental_price" type="number" step="0.01" class="h-8 text-[13px]" />
            </div>
            <div>
              <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Warmmiete <span v-if="vis('rent_warm').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('rent_warm').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('rent_warm').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('rent_warm').tip"></span></label>
              <Input v-model="form.rent_warm" type="number" step="0.01" class="h-8 text-[13px]" />
            </div>
            <div>
              <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Kaution <span v-if="vis('rent_deposit').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('rent_deposit').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('rent_deposit').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('rent_deposit').tip"></span></label>
              <Input v-model="form.rent_deposit" type="number" step="0.01" class="h-8 text-[13px]" />
            </div>
          </div>
        </template>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Provision Intern</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Provision % <span v-if="vis('commission_percent').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('commission_percent').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('commission_percent').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('commission_percent').tip"></span></label>
            <Input v-model="form.commission_percent" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Provision EUR <span v-if="vis('commission_total').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('commission_total').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('commission_total').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('commission_total').tip"></span></label>
            <Input v-model="form.commission_total" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div class="col-span-2">
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Provisionsnotiz <span v-if="vis('commission_note').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('commission_note').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('commission_note').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('commission_note').tip"></span></label>
            <Input v-model="form.commission_note" class="h-8 text-[13px]" />
          </div>
        </div>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Provision Oeffentlich</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler-Provision % <span v-if="vis('buyer_commission_percent').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('buyer_commission_percent').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('buyer_commission_percent').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('buyer_commission_percent').tip"></span></label>
            <Input v-model="form.buyer_commission_percent" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Makler-Provision EUR <span v-if="vis('commission_makler').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('commission_makler').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('commission_makler').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('commission_makler').tip"></span></label>
            <Input v-model="form.commission_makler" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div class="col-span-2">
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Provisionstext (Inserate) <span v-if="vis('buyer_commission_text').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('buyer_commission_text').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('buyer_commission_text').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('buyer_commission_text').tip"></span></label>
            <Input v-model="form.buyer_commission_text" class="h-8 text-[13px]" />
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 3. Flaechen & Raeume -->
    <Collapsible v-model:open="sections.flaechenRaeume" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.flaechenRaeume }" />
          <span class="text-[13px] font-semibold">Flaechen & Raeume</span>
        </div>
        <span v-if="areaRoomsBadge" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ areaRoomsBadge }}</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Flaechen (m2)</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-3 gap-x-3 gap-y-2">
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
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">{{ f.label }} <span v-if="vis(f.key).icons.length" class="inline-flex gap-0.5"><span v-for="i in vis(f.key).icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis(f.key).tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis(f.key).tip"></span></label>
            <div v-if="f.key === 'living_area' && isNewbuild" class="relative">
              <Input :model-value="form[f.key]" type="number" step="0.01" class="h-8 text-[13px] bg-muted/50 cursor-not-allowed" disabled title="Wird automatisch aus Einheiten berechnet" />
              <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] text-muted-foreground">auto</span>
            </div>
            <Input v-else v-model="form[f.key]" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div></div>
        </div>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Raeume & Stockwerk</div>
        <div class="grid grid-cols-6 max-sm:grid-cols-3 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Zimmer <span v-if="vis('rooms_amount').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('rooms_amount').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('rooms_amount').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('rooms_amount').tip"></span></label>
            <Input v-model="form.rooms_amount" type="number" step="0.5" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Schlafzimmer <span v-if="vis('bedrooms').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('bedrooms').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('bedrooms').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('bedrooms').tip"></span></label>
            <Input v-model="form.bedrooms" type="number" step="1" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Badezimmer <span v-if="vis('bathrooms').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('bathrooms').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('bathrooms').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('bathrooms').tip"></span></label>
            <Input v-model="form.bathrooms" type="number" step="1" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">WCs <span v-if="vis('toilets').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('toilets').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('toilets').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('toilets').tip"></span></label>
            <Input v-model="form.toilets" type="number" step="1" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Stockwerk <span v-if="vis('floor_number').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('floor_number').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('floor_number').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('floor_number').tip"></span></label>
            <Input v-model="form.floor_number" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Stockwerke ges. <span v-if="vis('floor_count').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('floor_count').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('floor_count').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('floor_count').tip"></span></label>
            <Input v-model="form.floor_count" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Garagen <span v-if="vis('garage_spaces').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('garage_spaces').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('garage_spaces').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('garage_spaces').tip"></span></label>
            <Input v-model="form.garage_spaces" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Stellplaetze <span v-if="vis('parking_spaces').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('parking_spaces').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('parking_spaces').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('parking_spaces').tip"></span></label>
            <Input v-model="form.parking_spaces" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Parkplatz-Typ <span v-if="vis('parking_type').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('parking_type').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('parking_type').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('parking_type').tip"></span></label>
            <Input v-model="form.parking_type" class="h-8 text-[13px]" />
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 4. Ausstattung -->
    <Collapsible v-if="!isChild" v-model:open="sections.ausstattung" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.ausstattung }" />
          <span class="text-[13px] font-semibold">Ausstattung</span>
        </div>
        <span v-if="activeFeatureCount" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ activeFeatureCount }} Merkmale</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Zustand <span v-if="vis('realty_condition').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('realty_condition').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('realty_condition').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('realty_condition').tip"></span></label>
            <Select v-model="form.realty_condition">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in conditionOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Qualitaet <span v-if="vis('quality').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('quality').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('quality').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('quality').tip"></span></label>
            <Select v-model="form.quality">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in qualityOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Baujahr <span v-if="vis('construction_year').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('construction_year').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('construction_year').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('construction_year').tip"></span></label>
            <Input v-model="form.construction_year" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Sanierungsjahr <span v-if="vis('year_renovated').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('year_renovated').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('year_renovated').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('year_renovated').tip"></span></label>
            <Input v-model="form.year_renovated" type="number" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Kueche <span v-if="vis('kitchen_type').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('kitchen_type').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('kitchen_type').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('kitchen_type').tip"></span></label>
            <Select v-model="form.kitchen_type">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in kitchenOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Heizung <span v-if="vis('heating').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('heating').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('heating').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('heating').tip"></span></label>
            <Input v-model="form.heating" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Bodenbelag <span v-if="vis('flooring').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('flooring').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('flooring').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('flooring').tip"></span></label>
            <Input v-model="form.flooring" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Badausstattung <span v-if="vis('bathroom_equipment').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('bathroom_equipment').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('bathroom_equipment').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('bathroom_equipment').tip"></span></label>
            <Input v-model="form.bathroom_equipment" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Ausrichtung <span v-if="vis('orientation').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('orientation').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('orientation').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('orientation').tip"></span></label>
            <Input v-model="form.orientation" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Moeblierung <span v-if="vis('furnishing').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('furnishing').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('furnishing').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('furnishing').tip"></span></label>
            <Input v-model="form.furnishing" class="h-8 text-[13px]" />
          </div>
        </div>

        <Separator class="my-3" />
        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-2">Merkmale</div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="feat in features" :key="feat.key" type="button"
            @click="form[feat.key] = !form[feat.key]"
            :style="form[feat.key] ? 'background:hsl(240 5.9% 10%);color:white' : 'border:1px solid hsl(240 5.9% 90%)'"
            class="px-2.5 py-1 rounded-md text-[11px] transition-colors">
            {{ feat.label }} <span v-if="vis(feat.key).icons.length" class="text-[7px] font-bold px-0.5 rounded ml-0.5" :style="form[feat.key] ? 'background:hsl(217 91% 80%);color:white' : 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)'">W</span><span v-else class="w-1 h-1 rounded-full ml-0.5 inline-block" style="background:hsl(240 5.9% 80%)"></span>
          </button>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 5. Energie -->
    <Collapsible v-model:open="sections.energie" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.energie }" />
          <span class="text-[13px] font-semibold">Energie</span>
        </div>
        <span v-if="energyBadge" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ energyBadge }}</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Ausweistyp <span v-if="vis('energy_type').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('energy_type').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('energy_type').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('energy_type').tip"></span></label>
            <Select v-model="form.energy_type">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="o in energyTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Energieklasse <span v-if="vis('heating_demand_class').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('heating_demand_class').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('heating_demand_class').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('heating_demand_class').tip"></span></label>
            <Select v-model="form.heating_demand_class">
              <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="">--</SelectItem>
                <SelectItem v-for="c in energyClasses" :key="c" :value="c">{{ c }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">HWB (kWh/m2a) <span v-if="vis('heating_demand_value').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('heating_demand_value').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('heating_demand_value').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('heating_demand_value').tip"></span></label>
            <Input v-model="form.heating_demand_value" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">fGEE <span v-if="vis('energy_efficiency_value').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('energy_efficiency_value').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('energy_efficiency_value').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('energy_efficiency_value').tip"></span></label>
            <Input v-model="form.energy_efficiency_value" type="number" step="0.01" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Energietraeger <span v-if="vis('energy_primary_source').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('energy_primary_source').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('energy_primary_source').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('energy_primary_source').tip"></span></label>
            <Input v-model="form.energy_primary_source" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Gueltig bis <span v-if="vis('energy_valid_until').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('energy_valid_until').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('energy_valid_until').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('energy_valid_until').tip"></span></label>
            <Input v-model="form.energy_valid_until" type="date" class="h-8 text-[13px]" />
          </div>
          <div class="col-span-6 max-sm:col-span-2">
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Energieausweis (Freitext) <span v-if="vis('energy_certificate').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('energy_certificate').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('energy_certificate').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('energy_certificate').tip"></span></label>
            <Textarea v-model="form.energy_certificate" rows="2" class="text-[13px]" />
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 6. Verfuegbarkeit & Bau -->
    <Collapsible v-model:open="sections.verfuegbarkeit" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.verfuegbarkeit }" />
          <span class="text-[13px] font-semibold">Verfuegbarkeit & Bau</span>
        </div>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="grid grid-cols-6 max-sm:grid-cols-2 gap-x-3 gap-y-2">
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Verfuegbar ab <span v-if="vis('available_from').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('available_from').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('available_from').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('available_from').tip"></span></label>
            <Input v-model="form.available_from" type="date" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Verfuegbarkeit <span v-if="vis('available_text').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('available_text').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('available_text').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('available_text').tip"></span></label>
            <Input v-model="form.available_text" class="h-8 text-[13px]" placeholder="sofort, nach Vereinbarung" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Baubeginn <span v-if="vis('construction_start').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('construction_start').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('construction_start').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('construction_start').tip"></span></label>
            <Input v-model="form.construction_start" type="date" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 block">Fertigstellung</label>
            <Input v-model="form.construction_end" type="date" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Bautraeger <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" title="Wird nirgends angezeigt"></span></label>
            <Input v-model="form.builder_company" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Hausverwaltung <span v-if="vis('property_manager').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('property_manager').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('property_manager').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('property_manager').tip"></span></label>
            <Input v-model="form.property_manager" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Inseriert seit <span v-if="vis('inserat_since').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('inserat_since').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('inserat_since').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('inserat_since').tip"></span></label>
            <Input v-model="form.inserat_since" type="date" class="h-8 text-[13px]" />
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground mb-0.5 flex items-center gap-1">Plattformen <span v-if="vis('platforms').icons.length" class="inline-flex gap-0.5"><span v-for="i in vis('platforms').icons" :key="i" class="text-[8px] font-bold px-1 rounded" :style="i==='W' ? 'background:hsl(217 91% 93%);color:hsl(217 91% 50%)' : i==='P' ? 'background:hsl(280 67% 93%);color:hsl(280 67% 45%)' : 'background:hsl(240 4.8% 93%);color:hsl(240 3.8% 46%)'" :title="vis('platforms').tip">{{i}}</span></span><span v-else class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:hsl(240 5.9% 85%)" :title="vis('platforms').tip"></span></label>
            <Input v-model="form.platforms" class="h-8 text-[13px]" placeholder="willhaben, immoscout24" />
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>

    <!-- 7. Objekthistorie -->
    <Collapsible v-if="!isNewbuild && !isChild" v-model:open="sections.historie" class="rounded-lg" style="border:1px solid hsl(240 5.9% 90%)">
      <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-3 hover:bg-muted/30 rounded-lg transition-colors cursor-pointer">
        <div class="flex items-center gap-2">
          <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200 text-muted-foreground" :class="{ 'rotate-90': sections.historie }" />
          <span class="text-[13px] font-semibold">Objekthistorie</span>
        </div>
        <span v-if="historyCount" class="text-[10px] text-muted-foreground px-2 py-0.5 rounded-full" style="background:hsl(240 4.8% 95.9%)">{{ historyCount }} Eintraege</span>
      </CollapsibleTrigger>
      <CollapsibleContent class="px-4 pb-4">
        <div class="space-y-3">
          <Button variant="outline" size="sm" @click="historyAdding = !historyAdding" class="text-xs h-7">
            <Plus class="w-3 h-3 mr-1" />
            {{ historyAdding ? 'Abbrechen' : 'Eintrag hinzufuegen' }}
          </Button>

          <div v-if="historyAdding" class="p-3 rounded-lg space-y-2" style="background:hsl(240 4.8% 95.9% / 0.5)">
            <div class="flex gap-2">
              <Input v-model="historyNew.year" placeholder="Jahr" class="h-8 text-[13px] w-20" />
              <Input v-model="historyNew.title" placeholder="Titel" class="h-8 text-[13px] flex-1" />
            </div>
            <Input v-model="historyNew.description" placeholder="Beschreibung (optional)" class="h-8 text-[13px]" />
            <Button size="sm" @click="historyAddEntry()" :disabled="!historyNew.year || !historyNew.title" class="text-xs h-7">
              Hinzufuegen
            </Button>
          </div>

          <div v-if="historyItems.length" class="relative pl-6 space-y-4">
            <div class="absolute left-[11px] top-0 bottom-0 w-[2px]" style="background:hsl(240 5.9% 90%)"></div>
            <div v-for="(h, i) in historyItems" :key="i" class="group relative flex items-start gap-3">
              <div class="absolute left-[-17px] top-1.5 w-3 h-3 rounded-full border-2 border-background z-10" style="background:hsl(240 5.9% 10%)"></div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-xs font-bold">{{ h.year }}</span>
                  <span class="text-xs font-medium">{{ h.title }}</span>
                </div>
                <p v-if="h.description" class="text-[11px] text-muted-foreground mt-0.5">{{ h.description }}</p>
              </div>
              <button @click="historyDeleteEntry(i)"
                class="opacity-0 group-hover:opacity-100 transition-opacity p-1 text-muted-foreground hover:text-destructive rounded shrink-0">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </div>
          </div>

          <p v-else class="text-xs text-muted-foreground">Keine Historie-Eintraege vorhanden.</p>
        </div>
      </CollapsibleContent>
    </Collapsible>

  </div>
</template>
