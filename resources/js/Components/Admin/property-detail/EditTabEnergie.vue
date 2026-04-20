<script setup>
import { computed } from "vue";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import AccordionSection from "./AccordionSection.vue";

const props = defineProps({
  form: { type: Object, required: true },
});

const inputCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const selectCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

// building_details JSON helpers (shared pattern across EditTab subsections).
function bd(sectionKey, fieldKey) {
  try {
    const parsed = typeof props.form.building_details === "string"
      ? JSON.parse(props.form.building_details)
      : (props.form.building_details || {});
    return parsed?.[sectionKey]?.[fieldKey];
  } catch {
    return undefined;
  }
}

function setBd(sectionKey, fieldKey, value) {
  try {
    let parsed = typeof props.form.building_details === "string"
      ? JSON.parse(props.form.building_details)
      : (props.form.building_details || {});
    if (!parsed[sectionKey]) parsed[sectionKey] = {};
    parsed[sectionKey][fieldKey] = value;
    props.form.building_details = parsed;
  } catch {
    props.form.building_details = { [sectionKey]: { [fieldKey]: value } };
  }
}

// ─── Heizungsart (multi-select) ────────────────────────────────────────
const heatingTypes = [
  "Zentralheizung",
  "Fernwärme",
  "Etagenheizung",
  "Kamin",
  "Fußbodenheizung",
  "Offener Kamin",
  "Heizkörper",
  "Heizofen",
  "Kachelofen",
  "Wandheizung",
];

const selectedHeatingTypes = computed(() => {
  const v = bd("heating", "types");
  if (Array.isArray(v)) return v;
  if (typeof v === "string" && v) {
    try { const p = JSON.parse(v); return Array.isArray(p) ? p : []; } catch { return []; }
  }
  return [];
});

function toggleHeatingType(t) {
  const current = [...selectedHeatingTypes.value];
  const idx = current.indexOf(t);
  if (idx >= 0) current.splice(idx, 1);
  else current.push(t);
  setBd("heating", "types", current);
}

function isHeatingTypeSelected(t) {
  return selectedHeatingTypes.value.includes(t);
}

// ─── Befeuerung ────────────────────────────────────────────────────────
const fuelOptions = [
  "Luftwärmepumpe",
  "Sole-Wasser-Wärmepumpe",
  "Wasser-Wasser-Wärmepumpe",
  "Erdwärme",
  "Brennwerttechnik",
  "Gas",
  "Öl",
  "Holz",
  "Pellets",
  "Solar",
  "Fernwärme",
  "Blockheizkraftwerk",
  "Elektro",
  "Kohle",
  "Alternativ",
];

// ─── Warmwasser ────────────────────────────────────────────────────────
const hotWaterOptions = [
  "Boiler",
  "Brauchwasserwärmepumpe",
  "Fernwärme",
  "Zentral",
  "Durchlauferhitzer Strom",
  "Durchlauferhitzer Gas",
  "Frischwasserstation",
  "Gaskessel",
  "Ölkessel",
  "Solar",
  "Wärmepumpe",
];
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Heizung & Warmwasserversorgung -->
    <AccordionSection title="Heizung & Warmwasserversorgung" color="#ef4444" :default-open="true">
      <!-- Heizungsart (Multi-Select) -->
      <div class="col-span-2">
        <label :class="labelCls">Heizungsart</label>
        <div class="rounded-lg bg-zinc-100/80 p-2 space-y-1 max-h-64 overflow-y-auto">
          <label
            v-for="t in heatingTypes"
            :key="t"
            class="flex items-center gap-2.5 px-2 py-1.5 rounded-md hover:bg-white/70 cursor-pointer transition-colors"
          >
            <input
              type="checkbox"
              :checked="isHeatingTypeSelected(t)"
              class="w-4 h-4 accent-zinc-900 cursor-pointer"
              @change="toggleHeatingType(t)"
            />
            <span class="text-[13px] text-zinc-900">{{ t }}</span>
          </label>
        </div>
        <div v-if="selectedHeatingTypes.length" class="mt-1.5 flex flex-wrap gap-1">
          <span
            v-for="t in selectedHeatingTypes"
            :key="t"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-zinc-900 text-white text-[11px] font-medium"
          >
            {{ t }}
            <button type="button" class="hover:text-zinc-300" @click="toggleHeatingType(t)">×</button>
          </span>
        </div>
      </div>

      <!-- Befeuerung -->
      <div>
        <label :class="labelCls">Befeuerung</label>
        <Select
          :model-value="bd('heating', 'fuel') || ''"
          @update:model-value="setBd('heating', 'fuel', $event)"
        >
          <SelectTrigger :class="selectCls"><SelectValue placeholder="Wählen..." /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="opt in fuelOptions" :key="opt" :value="opt">{{ opt }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- Warmwasser -->
      <div>
        <label :class="labelCls">Warmwasser</label>
        <Select
          :model-value="bd('heating', 'hot_water') || ''"
          @update:model-value="setBd('heating', 'hot_water', $event)"
        >
          <SelectTrigger :class="selectCls"><SelectValue placeholder="Wählen..." /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="opt in hotWaterOptions" :key="opt" :value="opt">{{ opt }}</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </AccordionSection>

    <!-- Energieausweis (nur Energiewerte + Gültigkeit) -->
    <AccordionSection title="Energieausweis" color="#22c55e" :default-open="true">
      <div>
        <label :class="labelCls">Energieklasse</label>
        <Select clearable v-model="form.heating_demand_class">
          <SelectTrigger :class="selectCls"><SelectValue placeholder="Wählen..." /></SelectTrigger>
          <SelectContent>
            <SelectItem value="A++">A++</SelectItem>
            <SelectItem value="A+">A+</SelectItem>
            <SelectItem value="A">A</SelectItem>
            <SelectItem value="B">B</SelectItem>
            <SelectItem value="C">C</SelectItem>
            <SelectItem value="D">D</SelectItem>
            <SelectItem value="E">E</SelectItem>
            <SelectItem value="F">F</SelectItem>
            <SelectItem value="G">G</SelectItem>
            <SelectItem value="H">H</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div>
        <label :class="labelCls">HWB kWh/m²a</label>
        <Input v-model="form.heating_demand_value" type="number" :class="inputCls" />
      </div>
      <div>
        <label :class="labelCls">fGEE</label>
        <Input v-model="form.energy_efficiency_value" type="number" :class="inputCls" />
      </div>
      <div>
        <label :class="labelCls">Gültig bis</label>
        <Input v-model="form.energy_valid_until" type="date" :class="inputCls" />
      </div>
    </AccordionSection>
  </div>
</template>
