<script setup>
import { computed, watch, onMounted, ref, inject } from "vue";
import { Trash2, Plus } from "lucide-vue-next";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import AccordionSection from "./AccordionSection.vue";
import FieldExportBadges from "./FieldExportBadges.vue";

const props = defineProps({
  form: { type: Object, required: true },
  isNewbuild: { type: Boolean, default: false },
  isChild: { type: Boolean, default: false },
  features: { type: Array, default: () => [] },
});

// Fuer Neubauprojekte: Ranges aus property_units berechnen
const API = inject("API");
const unitData = ref([]);

async function loadUnits() {
  if (!props.form.id || !props.isNewbuild) return;
  try {
    const r = await fetch(API.value + "&action=get_units&property_id=" + props.form.id);
    const d = await r.json();
    unitData.value = Array.isArray(d.units) ? d.units.filter(u => !u.is_parking) : [];
  } catch (e) {
    console.warn("get_units failed:", e);
  }
}

onMounted(loadUnits);
watch(() => [props.form.id, props.isNewbuild], loadUnits);

function minMaxRange(key, unit = "m²") {
  const values = unitData.value.map(u => parseFloat(u[key])).filter(v => !isNaN(v) && v > 0);
  if (!values.length) return null;
  const min = Math.min(...values);
  const max = Math.max(...values);
  if (min === max) return `${min.toLocaleString("de-AT")} ${unit}`.trim();
  return `${min.toLocaleString("de-AT")} – ${max.toLocaleString("de-AT")} ${unit}`.trim();
}

// Allgemeinraeume — fixe Liste fuer Checkbox-Auswahl.
// Wird als JSON-Array im DB-Feld common_areas gespeichert (Strings als Keys).
const COMMON_AREA_OPTIONS = [
  { key: "fahrradraum",            label: "Fahrradraum" },
  { key: "muellraum",              label: "Müllraum" },
  { key: "trockenraum",            label: "Trockenraum" },
  { key: "waschkueche",            label: "Waschküche" },
  { key: "kinderwagenraum",        label: "Kinderwagenraum" },
  { key: "hobbyraum",              label: "Hobbyraum" },
  { key: "partyraum",              label: "Partyraum" },
  { key: "fitnessraum",            label: "Fitnessraum" },
  { key: "gemeinschaftssauna",     label: "Gemeinschafts-Sauna" },
  { key: "spielplatz",             label: "Kinderspielplatz" },
  { key: "dachterrasse",           label: "Gemeinschafts-Dachterrasse" },
  { key: "gemeinschaftsgarten",    label: "Gemeinschaftsgarten" },
  { key: "heizraum",               label: "Heizraum" },
  { key: "lagerraum",              label: "Lagerraum" },
];

// Parser: common_areas kann JSON-Array sein (neu), Freitext (alt) oder leer.
const commonAreaSet = computed(() => {
  const raw = props.form.common_areas;
  if (!raw) return new Set();
  if (Array.isArray(raw)) return new Set(raw);
  if (typeof raw === "string") {
    const t = raw.trim();
    if (t.startsWith("[")) {
      try { return new Set(JSON.parse(t) || []); }
      catch { return new Set(); }
    }
    // Fallback: alte Freitext-Werte per Komma trennen, klein machen, gegen bekannte Keys matchen.
    const knownKeys = COMMON_AREA_OPTIONS.map(o => o.key);
    const found = new Set();
    t.toLowerCase().split(/[,;\n]/).map(s => s.trim()).forEach(token => {
      const key = knownKeys.find(k => token.includes(k) || k.includes(token.replace(/[üöäß]/g, c => ({ü:'u',ö:'o',ä:'a',ß:'ss'})[c] || c)));
      if (key) found.add(key);
    });
    return found;
  }
  return new Set();
});

function toggleCommonArea(key) {
  const current = new Set(commonAreaSet.value);
  if (current.has(key)) current.delete(key);
  else current.add(key);
  // Als JSON-Array speichern fuer saubere Weiterverarbeitung.
  props.form.common_areas = JSON.stringify(Array.from(current));
}

// Feld-Zaehler pro Sektion
function countFilled(keys) {
  let filled = 0;
  for (const k of keys) {
    const v = props.form?.[k];
    if (v === null || v === undefined) continue;
    if (typeof v === 'string' && v.trim() === '') continue;
    if (typeof v === 'boolean' && v === false) continue;
    if (typeof v === 'number' && v === 0) continue;
    if (Array.isArray(v) && v.length === 0) continue;
    filled++;
  }
  return filled;
}
// Merkmale-Counter: zaehlt die aktiven Booleans aus der features-Liste
const featuresFilled = computed(() => {
  if (!Array.isArray(props.features)) return 0;
  return props.features.filter(f => !!props.form?.[f.key]).length;
});
const commonAreasFilled = computed(() => commonAreaSet.value.size);

const SECTION_FIELDS = {
  flaechen:   ['living_area', 'realty_area', 'free_area', 'total_area', 'area_balcony', 'area_terrace', 'area_garden', 'area_loggia', 'area_basement', 'rooms_amount', 'floor_count'],
  detailzimmer: ['bedrooms', 'bathrooms', 'toilets', 'floor_number'],
  stellplatz: ['garage_spaces', 'parking_spaces'],
  ausstattung:['quality', 'year_renovated', 'flooring', 'bathroom_equipment', 'orientation'],
};
const sectionCounts = computed(() => {
  const out = {};
  for (const [key, fields] of Object.entries(SECTION_FIELDS)) {
    out[key] = { filled: countFilled(fields), total: fields.length };
  }
  // Merkmale = Anzahl Features + Booleans (nur ausgewaehlte, ansonsten 0 von N)
  out.ausstattung.filled += featuresFilled.value;
  out.ausstattung.total += Array.isArray(props.features) ? props.features.length : 0;
  // Allgemeinraeume: Anzahl angekreuzter aus fester Liste
  out.allgemein = { filled: commonAreasFilled.value, total: COMMON_AREA_OPTIONS.length };
  return out;
});

// Range-Labels per Feld fuer Neubau
const neubauRanges = computed(() => ({
  living_area:   minMaxRange("area_m2", "m²"),
  rooms_amount:  minMaxRange("rooms", ""),
  area_balcony:  minMaxRange("balcony_terrace_m2", "m²"),
  area_terrace:  minMaxRange("balcony_terrace_m2", "m²"), // selbes Feld in Units
  area_garden:   minMaxRange("garden_m2", "m²"),
}));

const inputCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const selectCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

// Flächen-Zeilen ohne Garage — die Garage ist jetzt Teil der Stellplatz-Entries.
const areaFields = [
  { key: "living_area", label: "Wohnfläche" },
  { key: "realty_area", label: "Nutzfläche" },
  { key: "free_area", label: "Grundstück" },
  { key: "area_balcony", label: "Balkon", countKey: "balcony_count" },
  { key: "area_terrace", label: "Terrasse", countKey: "terrace_count" },
  { key: "area_dachterrasse", label: "Dachterrasse", countKey: "dachterrasse_count" },
  { key: "area_garden", label: "Garten", countKey: "garden_count" },
  { key: "area_loggia", label: "Loggia", countKey: "loggia_count" },
  { key: "area_basement", label: "Keller", countKey: "basement_count" },
  { key: "office_space", label: "Büro" },
];

// Immoji-kompatible Stellplatzarten.
const parkingTypeOptions = [
  { value: "barn", label: "Scheune" },
  { value: "outdoor", label: "PKW-Außenstellplatz" },
  { value: "carport", label: "Carport" },
  { value: "duplex_garage", label: "Duplexgarage" },
  { value: "garage", label: "Garage" },
  { value: "general", label: "Allgemeiner Stellplatz" },
  { value: "hall", label: "Halle" },
  { value: "underground_garage", label: "Tiefgarage" },
  { value: "car_park", label: "Parkhaus" },
  { value: "other", label: "Sonstiges" },
];

// "geeignet für" Optionen.
const suitableForOptions = [
  { value: "car", label: "KFZ" },
  { value: "truck", label: "LKW" },
  { value: "motorcycle", label: "Motorrad" },
  { value: "bike", label: "Fahrrad" },
  { value: "motorhome", label: "Wohnmobil" },
  { value: "boat", label: "Boot" },
];

// ─── Stellplätze als Array unter building_details.parking_spaces ───────────
function readBD() {
  try {
    return typeof props.form.building_details === "string"
      ? (JSON.parse(props.form.building_details) || {})
      : (props.form.building_details || {});
  } catch { return {}; }
}
function writeBD(bd) {
  props.form.building_details = bd;
}

function newParkingEntry() {
  return {
    type: "outdoor",
    count: 1,
    max_vehicle_width: "",
    area: "",
    suitable_for: "",
    description: "",
  };
}

const parkingSpaces = ref([]);

function loadParking() {
  const bd = readBD();
  const existing = Array.isArray(bd.parking_spaces) ? bd.parking_spaces : [];
  if (existing.length) {
    parkingSpaces.value = existing.map(e => ({ ...newParkingEntry(), ...e }));
    return;
  }

  // Kein strukturiertes Parking vorhanden — aus den Legacy-Flat-Fields seeden.
  const seeded = [];
  const outdoor = Number(props.form.parking_spaces) || 0;
  const garage = Number(props.form.garage_spaces) || 0;
  if (outdoor > 0) {
    seeded.push({
      ...newParkingEntry(),
      type: props.form.parking_type || "outdoor",
      count: outdoor,
    });
  }
  if (garage > 0) {
    seeded.push({
      ...newParkingEntry(),
      type: "garage",
      count: garage,
      area: Number(props.form.area_garage) || "",
    });
  }
  parkingSpaces.value = seeded;
}

function addParkingEntry() {
  parkingSpaces.value.push(newParkingEntry());
  persistParking();
}
function removeParkingEntry(idx) {
  parkingSpaces.value.splice(idx, 1);
  persistParking();
}

function persistParking() {
  const clean = parkingSpaces.value
    .filter(e => e && e.type)
    .map(e => ({
      type: e.type,
      count: e.count ? Number(e.count) : null,
      max_vehicle_width: e.max_vehicle_width ? Number(e.max_vehicle_width) : null,
      area: e.area ? Number(e.area) : null,
      suitable_for: e.suitable_for || null,
      description: (e.description || "").trim() || null,
    }));

  const bd = readBD();
  bd.parking_spaces = clean;
  writeBD(bd);

  // Flat-Felder synchronisieren — OverviewTab + Immoji-Export lesen die flachen
  // parking_spaces / garage_spaces / area_garage aus der properties-Tabelle.
  // Ohne diese Sync wuerde die Uebersicht 1 Stellplatz zeigen obwohl 2 in der
  // strukturierten Liste stehen.
  let outdoorTotal = 0;
  let garageTotal = 0;
  let garageArea = 0;
  for (const e of clean) {
    const cnt = Math.max(0, Number(e.count) || 0);
    // garage + tiefgarage + carport zaehlen als „Garage"
    if (['garage', 'tiefgarage', 'carport'].includes((e.type || '').toLowerCase())) {
      garageTotal += cnt;
      garageArea += (Number(e.area) || 0) * cnt;
    } else {
      // outdoor / stellplatz / sonstiges
      outdoorTotal += cnt;
    }
  }
  props.form.parking_spaces = outdoorTotal || null;
  props.form.garage_spaces = garageTotal || null;
  if (garageArea > 0) props.form.area_garage = garageArea;
}

// Jede Änderung sofort in das JSON schreiben (Save läuft normal über
// den bestehenden Speichern-Button oben im Tab).
watch(parkingSpaces, persistParking, { deep: true });

onMounted(loadParking);
watch(() => props.form?.id, loadParking);
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Flächen -->
      <AccordionSection title="Flächen (m²)" color="#ea580c" :default-open="true" :filled="sectionCounts.flaechen.filled" :total="sectionCounts.flaechen.total">
        <div v-for="field in areaFields" :key="field.key">
          <label :class="labelCls + ' flex items-center gap-1.5 flex-wrap'">
            <span>{{ field.label }}</span>
            <span v-if="field.countKey && !isNewbuild" class="text-[10px] text-muted-foreground font-normal">(m² | Anzahl)</span>
            <FieldExportBadges :field="field.key" />
            <span v-if="isNewbuild && neubauRanges[field.key]"
                  class="text-[9px] text-orange-600 font-normal tabular-nums ml-auto"
                  :title="'Bereich aus Einheiten — wird verwendet wenn Feld leer ist'">
              ∅ {{ neubauRanges[field.key] }}
            </span>
          </label>
          <!-- Neubau: editierbares Input, Range nur als Placeholder + Label-Hint -->
          <template v-if="isNewbuild">
            <Input
              v-model="form[field.key]"
              type="number"
              :placeholder="neubauRanges[field.key] ? neubauRanges[field.key] : 'z.B. 85'"
              :class="inputCls"
            />
          </template>
          <!-- Bestand: normale Eingaben -->
          <div v-else-if="field.countKey" class="flex gap-2">
            <div class="flex-1">
              <Input v-model="form[field.key]" type="number" placeholder="m²" :class="inputCls" />
            </div>
            <div class="w-20">
              <Input v-model="form[field.countKey]" type="number" placeholder="Anz." :class="inputCls" />
            </div>
          </div>
          <Input
            v-else
            v-model="form[field.key]"
            type="number"
            :class="inputCls"
          />
        </div>

        <!-- Zimmer + Stockwerke als regulaere Flaechen-Felder (auch fuer Neubau) -->
        <div>
          <label :class="labelCls + ' flex items-center gap-1.5 flex-wrap'">
            <span>Zimmer</span>
            <FieldExportBadges field="rooms_amount" />
            <span v-if="isNewbuild && neubauRanges.rooms_amount"
                  class="text-[9px] text-orange-600 font-normal tabular-nums ml-auto"
                  :title="'Bereich aus Einheiten — wird verwendet wenn Feld leer ist'">
              ∅ {{ neubauRanges.rooms_amount }}
            </span>
          </label>
          <Input
            v-if="isNewbuild"
            v-model="form.rooms_amount"
            type="number"
            step="0.5"
            :placeholder="neubauRanges.rooms_amount ? neubauRanges.rooms_amount : 'z.B. 3'"
            :class="inputCls"
          />
          <Input v-else v-model="form.rooms_amount" type="number" step="0.5" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stockwerke gesamt <FieldExportBadges field="floor_count" /></label>
          <Input v-model="form.floor_count" type="number" :class="inputCls" placeholder="z.B. 3" />
        </div>
      </AccordionSection>

      <!-- Stellplätze -->
      <AccordionSection title="Stellplätze" color="#0891b2" :default-open="true" :filled="sectionCounts.stellplatz.filled" :total="sectionCounts.stellplatz.total">
        <div v-if="!parkingSpaces.length" class="text-[12px] text-muted-foreground py-2">
          Keine Stellplätze angelegt.
        </div>

        <div
          v-for="(entry, idx) in parkingSpaces"
          :key="idx"
          class="space-y-3 py-3 border-t border-border/40 first:border-0 first:pt-0 last:pb-0"
        >
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label :class="labelCls">Art <span class="text-red-500">*</span></label>
              <Select v-model="entry.type">
                <SelectTrigger :class="inputCls">
                  <SelectValue placeholder="Wählen..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="opt in parkingTypeOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label :class="labelCls">Anzahl</label>
              <Input v-model="entry.count" type="number" min="1" :class="inputCls" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label :class="labelCls">max. Fahrzeugbreite (m)</label>
              <Input v-model="entry.max_vehicle_width" type="number" step="0.1" placeholder="z.B. 2.1" :class="inputCls" />
            </div>
            <div>
              <label :class="labelCls">Fläche (m²)</label>
              <Input v-model="entry.area" type="number" step="0.1" :class="inputCls" />
            </div>
          </div>

          <div>
            <label :class="labelCls">geeignet für</label>
            <Select v-model="entry.suitable_for">
              <SelectTrigger :class="inputCls">
                <SelectValue placeholder="Wählen..." />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="opt in suitableForOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <label :class="labelCls">Beschreibung</label>
            <Textarea v-model="entry.description" rows="2" class="text-[13px] bg-zinc-100/80 border-0" />
          </div>

          <div class="flex justify-end">
            <Button
              variant="ghost"
              size="sm"
              class="text-[11px] h-7 gap-1 text-red-600 hover:text-red-700 hover:bg-red-50"
              @click="removeParkingEntry(idx)"
            >
              <Trash2 class="w-3 h-3" />
              Stellplatz entfernen
            </Button>
          </div>
        </div>

        <div class="pt-2">
          <Button variant="outline" size="sm" class="h-8 text-[12px] gap-1" @click="addParkingEntry">
            <Plus class="w-3 h-3" />
            Stellplatz hinzufügen
          </Button>
        </div>
      </AccordionSection>
    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Detailzimmer (Schlafzimmer/Badezimmer/WCs/Stockwerk) nur bei Bestand.
           Zimmer-Anzahl + Stockwerke gesamt sind jetzt unter Flächen integriert. -->
      <AccordionSection v-if="!isNewbuild" title="Detailzimmer" color="#8b5cf6" :default-open="true" :filled="sectionCounts.detailzimmer.filled" :total="sectionCounts.detailzimmer.total">
        <div>
          <label :class="labelCls">Schlafzimmer <FieldExportBadges field="bedrooms" /></label>
          <Input v-model="form.bedrooms" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Badezimmer <FieldExportBadges field="bathrooms" /></label>
          <Input v-model="form.bathrooms" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">WCs <FieldExportBadges field="toilets" /></label>
          <Input v-model="form.toilets" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stockwerk (Etage) <FieldExportBadges field="floor_number" /></label>
          <Input v-model="form.floor_number" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Allgemeinräume (Gemeinschaftsräume im Gebäude) -->
      <AccordionSection v-if="!isChild" title="Allgemeinräume" color="#8b5cf6" :default-open="false" :filled="sectionCounts.allgemein.filled" :total="sectionCounts.allgemein.total">
        <div class="col-span-2 text-[10.5px] text-muted-foreground mb-1 leading-relaxed">
          Gemeinschafts- und Nebenräume im Gebäude. Werden auf der Website als Liste angezeigt.
        </div>
        <div class="col-span-2 flex flex-wrap gap-1.5">
          <button
            v-for="opt in COMMON_AREA_OPTIONS"
            :key="opt.key"
            type="button"
            @click="toggleCommonArea(opt.key)"
            class="px-2.5 py-1 rounded-md text-[12px] font-medium transition-colors"
            :class="commonAreaSet.has(opt.key)
              ? 'bg-zinc-900 text-white'
              : 'border border-border text-foreground hover:bg-zinc-50'"
          >
            {{ opt.label }}
          </button>
        </div>
        <div class="col-span-2 mt-1">
          <FieldExportBadges field="common_areas" />
        </div>
      </AccordionSection>

      <!-- Ausstattung & Merkmale -->
      <AccordionSection v-if="!isChild" title="Ausstattung & Merkmale" color="#06b6d4" :default-open="false" :filled="sectionCounts.ausstattung.filled" :total="sectionCounts.ausstattung.total">
        <div>
          <label :class="labelCls">Qualität <FieldExportBadges field="quality" /></label>
          <Select v-model="form.quality">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="einfach">Einfach</SelectItem>
              <SelectItem value="normal">Normal</SelectItem>
              <SelectItem value="gehoben">Gehoben</SelectItem>
              <SelectItem value="luxurioes">Luxuriös</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Sanierungsjahr <FieldExportBadges field="year_renovated" /></label>
          <Input v-model="form.year_renovated" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Bodenbelag <FieldExportBadges field="flooring" /></label>
          <Input v-model="form.flooring" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Badausstattung <FieldExportBadges field="bathroom_equipment" /></label>
          <Input v-model="form.bathroom_equipment" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Ausrichtung <FieldExportBadges field="orientation" /></label>
          <Input v-model="form.orientation" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Merkmale</label>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="feat in features"
              :key="feat.key"
              type="button"
              @click="form[feat.key] = !form[feat.key]"
              class="px-2.5 py-1 rounded-md text-[12px] font-medium transition-colors"
              :class="form[feat.key] ? 'bg-zinc-900 text-white' : 'border border-border text-foreground hover:bg-zinc-50'"
            >
              {{ feat.label }}
            </button>
          </div>
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
