<script setup>
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

const selectCls = "h-9 text-[13px] border border-input rounded-lg bg-background";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

const gradeOptions = ["", "Sehr gut", "Gut", "Befriedigend", "Mangelhaft", "Unzureichend"];

// bd() reads a field from form.building_details JSON
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

// Data-driven section definitions
// Each field: [section_key, field_key, label, options_array]
const leftSections = [
  {
    title: "Bau",
    color: "#ea580c",
    sectionKey: "construction",
    fields: [
      ["method", "Bauweise", ["", "Massivbau", "Holzbau", "Stahlbau", "Fertigteilbau", "Mischbauweise"]],
      ["condition", "Zustand", ["", "Neubau", "Bestand", "Abbruchreif", "Rohbau", "Ausbauhaus"]],
      ["expansion", "Ausbaustufe", ["", "Schlüsselfertig", "Belagsfertig", "Rohbau", "Ausbauhaus"]],
    ],
  },
  {
    title: "Fassade",
    color: "#f97316",
    sectionKey: "facade",
    fields: [
      ["type", "Fassadentyp", ["", "Putz", "Klinker", "Holz", "Glas", "Metall", "Naturstein", "WDVS"]],
      ["exterior_condition", "Außenzustand", gradeOptions],
      ["masonry_condition", "Mauerwerk", gradeOptions],
      ["basement_masonry", "Kellermauerwerk", gradeOptions],
      ["insulation", "Dämmung", gradeOptions],
    ],
  },
  {
    title: "Heizung & Warmwasser",
    color: "#ef4444",
    sectionKey: "heating",
    fields: [
      ["type", "Heizungstyp", ["", "Zentralheizung", "Etagenheizung", "Ofenheizung", "Fußbodenheizung", "Fernwärme", "Wärmepumpe"]],
      ["fuel", "Brennstoff", ["", "Gas", "Öl", "Holz", "Pellets", "Strom", "Solar", "Erdwärme", "Fernwärme"]],
      ["hot_water", "Warmwasser", ["", "Zentral", "Boiler", "Durchlauferhitzer", "Solar"]],
    ],
  },
  {
    title: "Elektrik & Belüftung",
    color: "#8b5cf6",
    sectionKey: "electrical",
    fields: [
      ["type", "Elektriktyp", ["", "Standard", "Aufputz", "Unterputz"]],
      ["condition", "Zustand", gradeOptions],
      ["ventilation_type", "Belüftung", ["", "Natürlich", "Mechanisch", "Kontrolliert"]],
      ["ventilation_condition", "Belüftungszustand", gradeOptions],
    ],
  },
];

const rightSections = [
  {
    title: "Telekommunikation",
    color: "#3b82f6",
    sectionKey: "telecom",
    fields: [
      ["tv", "TV", ["", "Kabel", "SAT", "DVB-T", "IPTV"]],
      ["phone", "Telefon", ["", "Analog", "ISDN", "VoIP"]],
      ["internet", "Internet", ["", "DSL", "Glasfaser", "Kabel", "LTE", "5G"]],
    ],
  },
  {
    title: "Dach",
    color: "#22c55e",
    sectionKey: "roof",
    fields: [
      ["shape", "Dachform", ["", "Satteldach", "Flachdach", "Walmdach", "Pultdach", "Mansarddach", "Zeltdach"]],
      ["covering", "Eindeckung", ["", "Ziegel", "Betondachstein", "Schiefer", "Blech", "Bitumen", "Grün"]],
      ["insulation", "Dämmung", gradeOptions],
      ["dormers", "Dachgauben", gradeOptions],
      ["skylights", "Dachfenster", gradeOptions],
      ["gutters", "Dachrinnen", gradeOptions],
    ],
  },
  {
    title: "Fenster",
    color: "#06b6d4",
    sectionKey: "windows",
    fields: [
      ["material", "Material", ["", "Kunststoff", "Holz", "Aluminium", "Holz-Alu"]],
      ["glazing", "Verglasung", ["", "Einfach", "Doppelt", "Dreifach", "Schallschutz"]],
      ["sun_protection", "Sonnenschutz", ["", "Rollladen", "Jalousien", "Markise", "Außenliegend", "Innenliegend"]],
      ["condition", "Zustand", gradeOptions],
    ],
  },
  {
    title: "Etagen",
    color: "#f59e0b",
    sectionKey: "floors",
    fields: [
      ["stairs", "Treppen", ["", "Innen", "Außen", "Aufzug"]],
      ["elevator", "Aufzug", ["", "Vorhanden", "Nicht vorhanden"]],
      ["common_area", "Allgemeinbereich", ["", "Sehr gut", "Gut", "Befriedigend", "Mangelhaft"]],
    ],
  },
];
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <AccordionSection
        v-for="section in leftSections"
        :key="section.sectionKey"
        :title="section.title"
        :color="section.color"
        :default-open="false"
      >
        <div v-for="[fieldKey, label, options] in section.fields" :key="fieldKey">
          <label :class="labelCls">{{ label }}</label>
          <Select
            :model-value="bd(section.sectionKey, fieldKey)"
            @update:model-value="setBd(section.sectionKey, fieldKey, $event)"
          >
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="opt in options" :key="opt" :value="opt">
                {{ opt === "" ? "—" : opt }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
      </AccordionSection>
    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <AccordionSection
        v-for="section in rightSections"
        :key="section.sectionKey"
        :title="section.title"
        :color="section.color"
        :default-open="false"
      >
        <div v-for="[fieldKey, label, options] in section.fields" :key="fieldKey">
          <label :class="labelCls">{{ label }}</label>
          <Select
            :model-value="bd(section.sectionKey, fieldKey)"
            @update:model-value="setBd(section.sectionKey, fieldKey, $event)"
          >
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="opt in options" :key="opt" :value="opt">
                {{ opt === "" ? "—" : opt }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
