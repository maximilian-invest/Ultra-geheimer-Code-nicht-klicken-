<script setup>
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

// bd/setBd mirror the JSON-building_details pattern used in EditTabGebaeude.
function bd(sectionKey, fieldKey) {
  try {
    const parsed = typeof props.form.building_details === "string"
      ? JSON.parse(props.form.building_details)
      : (props.form.building_details || {});
    return parsed?.[sectionKey]?.[fieldKey] ?? "";
  } catch {
    return "";
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
    const fresh = { [sectionKey]: { [fieldKey]: value } };
    props.form.building_details = fresh;
  }
}

const heatingFields = [
  ["type", "Heizungstyp", ["", "Zentralheizung", "Etagenheizung", "Ofenheizung", "Fußbodenheizung", "Fernwärme", "Wärmepumpe"]],
  ["fuel", "Brennstoff", ["", "Gas", "Öl", "Holz", "Pellets", "Strom", "Solar", "Erdwärme", "Fernwärme"]],
  ["hot_water", "Warmwasser", ["", "Zentral", "Boiler", "Durchlauferhitzer", "Solar"]],
];
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Heizung & Warmwasser -->
    <AccordionSection title="Heizung & Warmwasser" color="#ef4444" :default-open="true">
      <div v-for="[fieldKey, label, options] in heatingFields" :key="fieldKey">
        <label :class="labelCls">{{ label }}</label>
        <Select
          :model-value="bd('heating', fieldKey)"
          @update:model-value="setBd('heating', fieldKey, $event)"
        >
          <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="opt in options" :key="opt" :value="opt">
              {{ opt === "" ? "—" : opt }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div>
        <label :class="labelCls">Heizung (Freitext)</label>
        <Input v-model="form.heating" :class="inputCls" placeholder="z.B. Fernwärme + Solar" />
      </div>
    </AccordionSection>

    <!-- Energieausweis -->
    <AccordionSection title="Energieausweis" color="#22c55e" :default-open="true">
      <div>
        <label :class="labelCls">Ausweistyp</label>
        <Select v-model="form.energy_certificate">
          <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="Verbrauch">Verbrauch</SelectItem>
            <SelectItem value="Bedarf">Bedarf</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div>
        <label :class="labelCls">Energieklasse</label>
        <Select v-model="form.heating_demand_class">
          <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
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
        <label :class="labelCls">Energieträger</label>
        <Input v-model="form.energy_primary_source" :class="inputCls" />
      </div>
      <div>
        <label :class="labelCls">Gültig bis</label>
        <Input v-model="form.energy_valid_until" type="date" :class="inputCls" />
      </div>
    </AccordionSection>
  </div>
</template>
