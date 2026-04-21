<script setup>
import { computed, watch, onMounted, ref } from "vue";
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
});

const inputCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

// Flächen-Zeilen ohne Garage — die Garage ist jetzt Teil der Stellplatz-Entries.
const areaFields = [
  { key: "living_area", label: "Wohnfläche" },
  { key: "realty_area", label: "Nutzfläche" },
  { key: "free_area", label: "Grundstück" },
  { key: "area_balcony", label: "Balkon", countKey: "balcony_count" },
  { key: "area_terrace", label: "Terrasse", countKey: "terrace_count" },
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
      <AccordionSection title="Flächen (m²)" color="#ea580c" :default-open="true">
        <div v-for="field in areaFields" :key="field.key">
          <label :class="labelCls">{{ field.label }} <span v-if="field.countKey" class="text-[10px] text-muted-foreground font-normal">(m² | Anzahl)</span></label>
          <div v-if="field.key === 'living_area' && isNewbuild" class="relative">
            <Input
              v-model="form[field.key]"
              type="number"
              disabled
              :class="inputCls + ' pr-12 opacity-60'"
            />
            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] text-muted-foreground font-medium bg-zinc-100 px-1.5 py-0.5 rounded">auto</span>
          </div>
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
      </AccordionSection>

      <!-- Stellplätze -->
      <AccordionSection title="Stellplätze" color="#0891b2" :default-open="true">
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
      <!-- Räume & Stockwerk -->
      <AccordionSection title="Räume & Stockwerk" color="#8b5cf6" :default-open="true">
        <div>
          <label :class="labelCls">Zimmer <FieldExportBadges field="rooms_amount" /></label>
          <Input v-model="form.rooms_amount" type="number" step="0.5" :class="inputCls" />
        </div>
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
          <label :class="labelCls">Stockwerk <FieldExportBadges field="floor_number" /></label>
          <Input v-model="form.floor_number" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stockwerke ges. <FieldExportBadges field="floor_count" /></label>
          <Input v-model="form.floor_count" type="number" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
