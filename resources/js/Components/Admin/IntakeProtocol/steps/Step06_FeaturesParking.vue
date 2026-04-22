<script setup>
import PillRow from '../shared/PillRow.vue';
import MultiPillRow from '../shared/MultiPillRow.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

defineProps({ form: Object });

const FEATURE_TOGGLES = [
  { key: 'has_elevator',         label: 'Aufzug' },
  { key: 'has_fitted_kitchen',   label: 'Einbauküche' },
  { key: 'has_air_conditioning', label: 'Klimaanlage' },
  { key: 'has_pool',             label: 'Pool' },
  { key: 'has_sauna',            label: 'Sauna' },
  { key: 'has_fireplace',        label: 'Kamin' },
  { key: 'has_alarm',            label: 'Alarmanlage' },
  { key: 'has_barrier_free',     label: 'Barrierefrei' },
  { key: 'has_guest_wc',         label: 'Gäste-WC' },
  { key: 'has_storage_room',     label: 'Abstellraum' },
];

// Aussenflaechen + Keller — jetzt mit eigener Terrasse vs. Dachterrasse
const AREA_TOGGLES = [
  { key: 'has_balcony',      label: 'Balkon',       areaKey: 'area_balcony',      countKey: 'balcony_count' },
  { key: 'has_terrace',      label: 'Terrasse',     areaKey: 'area_terrace',      countKey: 'terrace_count' },
  { key: 'has_dachterrasse', label: 'Dachterrasse', areaKey: 'area_dachterrasse', countKey: 'dachterrasse_count' },
  { key: 'has_loggia',       label: 'Loggia',       areaKey: 'area_loggia',       countKey: 'loggia_count' },
  { key: 'has_garden',       label: 'Garten',       areaKey: 'area_garden',       countKey: null },
  { key: 'has_basement',     label: 'Keller',       areaKey: 'area_basement',     countKey: null },
];

const COMMON_AREA_OPTIONS = [
  { key: 'fahrradraum',         label: 'Fahrradraum' },
  { key: 'muellraum',           label: 'Müllraum' },
  { key: 'trockenraum',         label: 'Trockenraum' },
  { key: 'waschkueche',         label: 'Waschküche' },
  { key: 'kinderwagenraum',     label: 'Kinderwagenraum' },
  { key: 'hobbyraum',           label: 'Hobbyraum' },
  { key: 'partyraum',           label: 'Partyraum' },
  { key: 'fitnessraum',         label: 'Fitnessraum' },
  { key: 'gemeinschaftssauna',  label: 'Gemeinschafts-Sauna' },
  { key: 'spielplatz',          label: 'Kinderspielplatz' },
  { key: 'dachterrasse',        label: 'Gemeinschafts-Dachterrasse' },
  { key: 'gemeinschaftsgarten', label: 'Gemeinschaftsgarten' },
  { key: 'heizraum',            label: 'Heizraum' },
  { key: 'lagerraum',           label: 'Lagerraum' },
];

// Bodenbelag-Optionen (Multi-Select, gespeichert als JSON-String)
const FLOORING_OPTIONS = [
  'Parkett', 'Laminat', 'Fliesen', 'Vinyl', 'Teppich',
  'Natursteinboden', 'Holzdielen', 'PVC', 'Kork', 'Beton',
  'Feinsteinzeug', 'Terrazzo', 'Sonstiges',
];

// Badausstattung (Multi-Select)
const BATHROOM_OPTIONS = [
  'Badewanne', 'Dusche', 'Bodenebene Dusche', 'Doppelwaschtisch',
  'Bidet', 'Gäste-WC', 'Fenster im Bad', 'Regendusche', 'Whirlpool',
  'Handtuchheizkörper', 'Dampfdusche', 'Sauna im Bad',
];
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Außenflaechen + Keller -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Außenflächen &amp; Keller</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <div
          v-for="a in AREA_TOGGLES"
          :key="a.key"
          class="rounded-lg border border-border/60 p-3 space-y-2"
        >
          <div class="flex items-center gap-3">
            <Switch
              :model-value="form[a.key]"
              @update:model-value="form[a.key] = $event"
            />
            <span class="flex-1 text-sm font-medium">{{ a.label }}</span>
          </div>
          <div v-if="form[a.key]" class="grid grid-cols-2 gap-2 pt-1">
            <Input v-model.number="form[a.areaKey]" type="number" inputmode="decimal" step="0.5"
                   placeholder="m² gesamt" class="h-9 text-sm" />
            <Input v-if="a.countKey" v-model.number="form[a.countKey]" type="number" inputmode="numeric"
                   placeholder="Anzahl" class="h-9 text-sm" />
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Merkmale -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Merkmale</CardTitle>
      </CardHeader>
      <CardContent>
        <ToggleGroup
          type="multiple"
          variant="outline"
          size="sm"
          :model-value="FEATURE_TOGGLES.filter(f => form[f.key]).map(f => f.key)"
          @update:model-value="(keys) => FEATURE_TOGGLES.forEach(f => form[f.key] = (keys || []).includes(f.key))"
          class="grid grid-cols-2 gap-2 justify-start"
        >
          <ToggleGroupItem
            v-for="f in FEATURE_TOGGLES" :key="f.key"
            :value="f.key"
            class="h-10 justify-start data-[state=on]:bg-zinc-900 data-[state=on]:text-white data-[state=on]:border-zinc-900"
          >
            {{ f.label }}
          </ToggleGroupItem>
        </ToggleGroup>
      </CardContent>
    </Card>

    <!-- Allgemeinräume -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Allgemeinräume</CardTitle>
      </CardHeader>
      <CardContent>
        <ToggleGroup
          type="multiple"
          variant="outline"
          size="sm"
          :model-value="Array.isArray(form.common_areas) ? form.common_areas : []"
          @update:model-value="(v) => form.common_areas = Array.isArray(v) ? v : []"
          class="flex-wrap justify-start"
        >
          <ToggleGroupItem
            v-for="o in COMMON_AREA_OPTIONS" :key="o.key"
            :value="o.key"
            class="rounded-full px-3 h-8 text-xs data-[state=on]:bg-zinc-900 data-[state=on]:text-white data-[state=on]:border-zinc-900"
          >
            {{ o.label }}
          </ToggleGroupItem>
        </ToggleGroup>
      </CardContent>
    </Card>

    <!-- Ausrichtung + Bodenbelag + Bad -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Ausrichtung &amp; Ausstattung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Ausrichtung</label>
          <PillRow v-model="form.orientation" :options="['N','NO','O','SO','S','SW','W','NW']" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">
            Bodenbelag <span class="text-xs font-normal text-muted-foreground">(Mehrfachauswahl)</span>
          </label>
          <MultiPillRow v-model="form.flooring" :options="FLOORING_OPTIONS" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">
            Badausstattung <span class="text-xs font-normal text-muted-foreground">(Mehrfachauswahl)</span>
          </label>
          <MultiPillRow v-model="form.bathroom_equipment" :options="BATHROOM_OPTIONS" />
        </div>
      </CardContent>
    </Card>

    <!-- Stellplätze -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Stellplätze</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Garagen</label>
            <Input v-model.number="form.garage_spaces" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Außenplätze</label>
            <Input v-model.number="form.parking_spaces" type="number" inputmode="numeric" class="h-11" />
          </div>
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Parking-Typ</label>
          <PillRow v-model="form.parking_type" :options="['Garage', 'Tiefgarage', 'Carport', 'Stellplatz']" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Zuordnung</label>
          <PillRow v-model="form.parking_assignment" :options="[
            {value:'assigned', label:'Dem Objekt zugeordnet'},
            {value:'shared', label:'Allgemein / gemeinsam'},
          ]" />
        </div>
      </CardContent>
    </Card>

  </div>
</template>
