<script setup>
import { Input } from "@/components/ui/input";
import AccordionSection from "./AccordionSection.vue";

defineProps({
  form: { type: Object, required: true },
  isNewbuild: { type: Boolean, default: false },
});

const inputCls = "h-9 text-[13px] border border-input rounded-lg bg-background";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

const areaFields = [
  { key: "living_area", label: "Wohnfläche" },
  { key: "realty_area", label: "Nutzfläche" },
  { key: "free_area", label: "Grundstück" },
  { key: "area_balcony", label: "Balkon" },
  { key: "area_terrace", label: "Terrasse" },
  { key: "area_garden", label: "Garten" },
  { key: "area_loggia", label: "Loggia" },
  { key: "area_basement", label: "Keller" },
  { key: "area_garage", label: "Garage" },
  { key: "office_space", label: "Büro" },
];
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Flächen -->
      <AccordionSection title="Flächen (m²)" color="#ea580c" :default-open="true">
        <div v-for="field in areaFields" :key="field.key">
          <label :class="labelCls">{{ field.label }}</label>
          <div v-if="field.key === 'living_area' && isNewbuild" class="relative">
            <Input
              v-model="form[field.key]"
              type="number"
              disabled
              :class="inputCls + ' pr-12 opacity-60'"
            />
            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] text-muted-foreground font-medium bg-zinc-100 px-1.5 py-0.5 rounded">auto</span>
          </div>
          <Input
            v-else
            v-model="form[field.key]"
            type="number"
            :class="inputCls"
          />
        </div>
      </AccordionSection>
    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Räume & Stockwerk -->
      <AccordionSection title="Räume & Stockwerk" color="#8b5cf6" :default-open="true">
        <div>
          <label :class="labelCls">Zimmer</label>
          <Input v-model="form.rooms_amount" type="number" step="0.5" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Schlafzimmer</label>
          <Input v-model="form.bedrooms" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Badezimmer</label>
          <Input v-model="form.bathrooms" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">WCs</label>
          <Input v-model="form.toilets" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stockwerk</label>
          <Input v-model="form.floor_number" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stockwerke ges. <span class="text-[10px] text-muted-foreground font-normal">(auto aus Einheiten)</span></label>
          <Input v-model="form.floor_count" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Garagen <span class="text-[10px] text-muted-foreground font-normal">(auto aus Einheiten)</span></label>
          <Input v-model="form.garage_spaces" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stellplätze</label>
          <Input v-model="form.parking_spaces" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Parkplatz-Typ</label>
          <Input v-model="form.parking_type" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
