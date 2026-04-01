<script setup>
import { ref, reactive, computed, watch, inject, onMounted } from "vue";
import { ChevronRight, Plus, Trash2 } from "lucide-vue-next";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
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
  total_area: null, living_area: null, realty_area: null, free_area: null,
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
  // Set broker_id default
  if (!form.broker_id && userId?.value) form.broker_id = userId.value;
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
  if (form.total_area) parts.push(form.total_area + " m\u00B2");
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

// ─── Expose methods to parent via template ref ───
defineExpose({ save, discard });
</script>

<template>
  <div class="space-y-3 max-w-5xl">

    <!-- ═══ 1. Objekt & Adresse ═══ -->
    <div class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.objektAdresse">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.objektAdresse }" />
            <span class="text-sm font-semibold">Objekt & Adresse</span>
          </div>
          <Badge v-if="form.ref_id" variant="outline" class="text-[10px]">{{ form.ref_id }}</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-1 mb-0.5">Objekt</div>

            <div>
              <label class="text-xs text-muted-foreground">Ref-ID</label>
              <Input v-model="form.ref_id" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Objekttyp</label>
              <Select v-model="form.object_type">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="t in objectTypes" :key="t" :value="t">{{ t }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Transaktionsart</label>
              <Select v-model="form.marketing_type">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="t in marketingTypes" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Kategorie</label>
              <Select v-model="form.property_category">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="c in categoryOptions" :key="c.value" :value="c.value">{{ c.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Projektname</label>
              <Input v-model="form.project_name" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Inserat-Titel</label>
              <Input v-model="form.title" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Adresse</div>

            <div class="col-span-2 max-sm:col-span-2">
              <label class="text-xs text-muted-foreground">Strasse & Hausnummer</label>
              <Input v-model="form.address" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">PLZ</label>
              <Input v-model="form.zip" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Stadt</label>
              <Input v-model="form.city" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Breitengrad</label>
              <Input v-model="form.latitude" type="number" step="0.0000001" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Laengengrad</label>
              <Input v-model="form.longitude" type="number" step="0.0000001" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Zuordnung</div>

            <div>
              <label class="text-xs text-muted-foreground">Zustaendiger Makler</label>
              <Select v-model="form.broker_id">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Status</label>
              <Select v-model="form.status">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
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
              <label class="text-xs text-muted-foreground">Fertigstellung</label>
              <Input v-model="form.construction_end" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Bautraeger</label>
              <Input v-model="form.builder_company" class="h-[30px] text-[13px]" />
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 2. Preise & Kosten ═══ -->
    <div class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.preiseKosten">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.preiseKosten }" />
            <span class="text-sm font-semibold">Preise & Kosten</span>
          </div>
          <Badge v-if="form.purchase_price" variant="outline" class="text-[10px]">{{ Number(form.purchase_price).toLocaleString('de-AT') }} EUR</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">

            <div>
              <label class="text-xs text-muted-foreground">{{ isNewbuild ? 'Gesamtvolumen (berechnet)' : 'Kaufpreis / Miete' }}</label>
              <Input v-model="form.purchase_price" type="number" step="0.01" :disabled="isNewbuild" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Preis bis</label>
              <Input v-model="form.price_to" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Preis/m2</label>
              <Input v-model="form.price_per_m2" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Stellplatz-Preis</label>
              <Input v-model="form.parking_price" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Provision Intern</div>

            <div>
              <label class="text-xs text-muted-foreground">Provision %</label>
              <Input v-model="form.commission_percent" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Provision Gesamt EUR</label>
              <Input v-model="form.commission_total" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div class="col-span-2 max-sm:col-span-2">
              <label class="text-xs text-muted-foreground">Provisionsnotiz (intern)</label>
              <Input v-model="form.commission_note" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Provision Oeffentlich</div>

            <div>
              <label class="text-xs text-muted-foreground">Makler-Provision %</label>
              <Input v-model="form.buyer_commission_percent" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Makler-Provision EUR</label>
              <Input v-model="form.commission_makler" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div class="col-span-2 max-sm:col-span-2">
              <label class="text-xs text-muted-foreground">Provisionstext (Inserate)</label>
              <Input v-model="form.buyer_commission_text" class="h-[30px] text-[13px]" />
            </div>

            <!-- Miete fields -->
            <template v-if="form.marketing_type === 'miete'">
              <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Miete</div>
              <div>
                <label class="text-xs text-muted-foreground">Kaltmiete</label>
                <Input v-model="form.rental_price" type="number" step="0.01" class="h-[30px] text-[13px]" />
              </div>
              <div>
                <label class="text-xs text-muted-foreground">Warmmiete</label>
                <Input v-model="form.rent_warm" type="number" step="0.01" class="h-[30px] text-[13px]" />
              </div>
              <div>
                <label class="text-xs text-muted-foreground">Kaution</label>
                <Input v-model="form.rent_deposit" type="number" step="0.01" class="h-[30px] text-[13px]" />
              </div>
              <div></div>
            </template>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Betriebskosten</div>

            <div>
              <label class="text-xs text-muted-foreground">Betriebskosten</label>
              <Input v-model="form.operating_costs" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Ruecklage</label>
              <Input v-model="form.maintenance_reserves" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 3. Flaechen & Raeume ═══ -->
    <div class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.flaechenRaeume">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.flaechenRaeume }" />
            <span class="text-sm font-semibold">Flaechen & Raeume</span>
          </div>
          <Badge v-if="areaRoomsBadge" variant="outline" class="text-[10px]">{{ areaRoomsBadge }}</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-1 mb-0.5">Flaechen (m2)</div>

            <div v-for="f in [
              { key: 'total_area', label: 'Gesamtflaeche' },
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
              <label class="text-xs text-muted-foreground">{{ f.label }}</label>
              <Input v-model="form[f.key]" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Raeume</div>

            <div>
              <label class="text-xs text-muted-foreground">Zimmer</label>
              <Input v-model="form.rooms_amount" type="number" step="0.5" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Schlafzimmer</label>
              <Input v-model="form.bedrooms" type="number" step="1" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Badezimmer</label>
              <Input v-model="form.bathrooms" type="number" step="1" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Separate WCs</label>
              <Input v-model="form.toilets" type="number" step="1" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Stockwerk & Parking</div>

            <div>
              <label class="text-xs text-muted-foreground">Stockwerk</label>
              <Input v-model="form.floor_number" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Stockwerke gesamt</label>
              <Input v-model="form.floor_count" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Garagen</label>
              <Input v-model="form.garage_spaces" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Stellplaetze</label>
              <Input v-model="form.parking_spaces" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Parkplatz-Typ</label>
              <Input v-model="form.parking_type" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Stellplatz-Preis</label>
              <Input v-model="form.parking_price" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 4. Ausstattung ═══ -->
    <div v-if="!isChild" class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.ausstattung">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.ausstattung }" />
            <span class="text-sm font-semibold">Ausstattung</span>
          </div>
          <Badge v-if="activeFeatureCount" variant="outline" class="text-[10px]">{{ activeFeatureCount }} Merkmale</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">

            <div>
              <label class="text-xs text-muted-foreground">Zustand</label>
              <Select v-model="form.realty_condition">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="o in conditionOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Qualitaet</label>
              <Select v-model="form.quality">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="o in qualityOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Baujahr</label>
              <Input v-model="form.construction_year" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Sanierungsjahr</label>
              <Input v-model="form.year_renovated" type="number" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Kueche</label>
              <Select v-model="form.kitchen_type">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="o in kitchenOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Heizung</label>
              <Input v-model="form.heating" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Bodenbelag</label>
              <Input v-model="form.flooring" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Badausstattung</label>
              <Input v-model="form.bathroom_equipment" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Ausrichtung</label>
              <Input v-model="form.orientation" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Moeblierung</label>
              <Input v-model="form.furnishing" class="h-[30px] text-[13px]" />
            </div>

            <div class="col-span-4 max-sm:col-span-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-0.5">Merkmale</div>

            <div class="col-span-4 max-sm:col-span-2 grid grid-cols-3 max-sm:grid-cols-2 gap-1.5 mt-2">
              <button v-for="feat in features" :key="feat.key" type="button"
                @click="form[feat.key] = !form[feat.key]"
                :class="form[feat.key] ? 'bg-foreground text-background' : 'border border-border text-muted-foreground hover:bg-muted/50'"
                class="px-3 py-1.5 rounded-md text-xs transition-colors">
                {{ feat.label }}
              </button>
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 5. Energie ═══ -->
    <div class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.energie">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.energie }" />
            <span class="text-sm font-semibold">Energie</span>
          </div>
          <Badge v-if="energyBadge" variant="outline" class="text-[10px]">{{ energyBadge }}</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">

            <div>
              <label class="text-xs text-muted-foreground">Ausweistyp</label>
              <Select v-model="form.energy_type">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="o in energyTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Energieklasse</label>
              <Select v-model="form.heating_demand_class">
                <SelectTrigger class="h-[30px] text-[13px]"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="">--</SelectItem>
                  <SelectItem v-for="c in energyClasses" :key="c" :value="c">{{ c }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">HWB (kWh/m2a)</label>
              <Input v-model="form.heating_demand_value" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">fGEE</label>
              <Input v-model="form.energy_efficiency_value" type="number" step="0.01" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Energietraeger</label>
              <Input v-model="form.energy_primary_source" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Gueltig bis</label>
              <Input v-model="form.energy_valid_until" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div class="col-span-4 max-sm:col-span-2">
              <label class="text-xs text-muted-foreground">Energieausweis (Freitext)</label>
              <Textarea v-model="form.energy_certificate" rows="2" class="text-[13px]" />
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 6. Verfuegbarkeit & Bau ═══ -->
    <div class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.verfuegbarkeit">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.verfuegbarkeit }" />
            <span class="text-sm font-semibold">Verfuegbarkeit & Bau</span>
          </div>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="grid grid-cols-4 max-sm:grid-cols-2 gap-x-3 gap-y-1.5">
            <div>
              <label class="text-xs text-muted-foreground">Verfuegbar ab</label>
              <Input v-model="form.available_from" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Verfuegbarkeit (Text)</label>
              <Input v-model="form.available_text" class="h-[30px] text-[13px]" placeholder="sofort, nach Vereinbarung" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Baubeginn</label>
              <Input v-model="form.construction_start" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Fertigstellung</label>
              <Input v-model="form.construction_end" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Bautraeger</label>
              <Input v-model="form.builder_company" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Hausverwaltung</label>
              <Input v-model="form.property_manager" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Inseriert seit</label>
              <Input v-model="form.inserat_since" type="date" class="h-[30px] text-[13px]" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Plattformen</label>
              <Input v-model="form.platforms" class="h-[30px] text-[13px]" placeholder="willhaben, immoscout24" />
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══ 7. Objekthistorie ═══ -->
    <div v-if="!isNewbuild && !isChild" class="border border-border/50 rounded-lg mb-3">
      <Collapsible v-model:open="sections.historie">
        <CollapsibleTrigger class="flex items-center justify-between w-full p-3 hover:bg-muted/50 rounded-t-lg cursor-pointer">
          <div class="flex items-center gap-2">
            <ChevronRight class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': sections.historie }" />
            <span class="text-sm font-semibold">Objekthistorie</span>
          </div>
          <Badge v-if="historyCount" variant="outline" class="text-[10px]">{{ historyCount }} Eintraege</Badge>
        </CollapsibleTrigger>
        <CollapsibleContent class="px-4 pb-4">
          <div class="space-y-3">

            <!-- Add entry -->
            <div class="flex items-center gap-2">
              <Button variant="outline" size="sm" @click="historyAdding = !historyAdding" class="text-xs">
                <Plus class="w-3 h-3 mr-1" />
                {{ historyAdding ? 'Abbrechen' : 'Eintrag hinzufuegen' }}
              </Button>
            </div>

            <div v-if="historyAdding" class="p-3 bg-muted/30 rounded-lg border border-border space-y-2">
              <div class="flex gap-2">
                <Input v-model="historyNew.year" placeholder="Jahr" class="h-[30px] text-[13px] w-20" />
                <Input v-model="historyNew.title" placeholder="Titel" class="h-[30px] text-[13px] flex-1" />
              </div>
              <Input v-model="historyNew.description" placeholder="Beschreibung (optional)" class="h-[30px] text-[13px]" />
              <Button size="sm" @click="historyAddEntry()" :disabled="!historyNew.year || !historyNew.title" class="text-xs">
                Hinzufuegen
              </Button>
            </div>

            <!-- Timeline -->
            <div v-if="historyItems.length" class="relative pl-6 space-y-4">
              <div class="absolute left-[11px] top-0 bottom-0 w-[2px] bg-border"></div>
              <div v-for="(h, i) in historyItems" :key="i" class="group relative flex items-start gap-3">
                <div class="absolute left-[-17px] top-1.5 w-3 h-3 rounded-full bg-foreground border-2 border-background z-10"></div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-bold">{{ h.year }}</span>
                    <span class="text-xs font-medium text-foreground">{{ h.title }}</span>
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

  </div>
</template>
