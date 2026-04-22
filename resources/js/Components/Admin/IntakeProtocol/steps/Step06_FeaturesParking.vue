<script setup>
import PillRow from '../shared/PillRow.vue';

defineProps({ form: Object });

const FEATURE_TOGGLES = [
  { key: 'has_elevator', label: 'Aufzug' },
  { key: 'has_fitted_kitchen', label: 'Einbauküche' },
  { key: 'has_air_conditioning', label: 'Klimaanlage' },
  { key: 'has_pool', label: 'Pool' },
  { key: 'has_sauna', label: 'Sauna' },
  { key: 'has_fireplace', label: 'Kamin' },
  { key: 'has_alarm', label: 'Alarmanlage' },
  { key: 'has_barrier_free', label: 'Barrierefrei' },
  { key: 'has_guest_wc', label: 'Gäste-WC' },
  { key: 'has_storage_room', label: 'Abstellraum' },
];

const AREA_TOGGLES = [
  { key: 'has_balcony', label: 'Balkon', areaKey: 'area_balcony', countKey: 'balcony_count' },
  { key: 'has_terrace', label: 'Terrasse', areaKey: 'area_terrace', countKey: 'terrace_count' },
  { key: 'has_loggia', label: 'Loggia', areaKey: 'area_loggia', countKey: 'loggia_count' },
  { key: 'has_garden', label: 'Garten', areaKey: 'area_garden', countKey: null },
  { key: 'has_basement', label: 'Keller', areaKey: 'area_basement', countKey: 'basement_count' },
];

const COMMON_AREA_OPTIONS = [
  { key: 'fahrradraum', label: 'Fahrradraum' },
  { key: 'muellraum', label: 'Müllraum' },
  { key: 'trockenraum', label: 'Trockenraum' },
  { key: 'waschkueche', label: 'Waschküche' },
  { key: 'kinderwagenraum', label: 'Kinderwagenraum' },
  { key: 'hobbyraum', label: 'Hobbyraum' },
  { key: 'partyraum', label: 'Partyraum' },
  { key: 'fitnessraum', label: 'Fitnessraum' },
  { key: 'gemeinschaftssauna', label: 'Gemeinschafts-Sauna' },
  { key: 'spielplatz', label: 'Kinderspielplatz' },
  { key: 'dachterrasse', label: 'Gemeinschafts-Dachterrasse' },
  { key: 'gemeinschaftsgarten', label: 'Gemeinschaftsgarten' },
  { key: 'heizraum', label: 'Heizraum' },
  { key: 'lagerraum', label: 'Lagerraum' },
];

function toggleCommonArea(form, key) {
  if (!Array.isArray(form.common_areas)) form.common_areas = [];
  const idx = form.common_areas.indexOf(key);
  if (idx >= 0) form.common_areas.splice(idx, 1);
  else form.common_areas.push(key);
}
</script>

<template>
  <div class="p-4 space-y-5">

    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Außenflächen & Keller</div>
      <div class="space-y-2">
        <div v-for="a in AREA_TOGGLES" :key="a.key" class="bg-white border border-border rounded-lg p-3">
          <label class="flex items-center gap-3 cursor-pointer">
            <input type="checkbox" v-model="form[a.key]" class="w-5 h-5 accent-[#EE7600]" />
            <span class="flex-1 text-sm font-medium">{{ a.label }}</span>
          </label>
          <div v-if="form[a.key]" class="mt-2 grid grid-cols-2 gap-2">
            <input v-model="form[a.areaKey]" type="number" inputmode="decimal" placeholder="m²"
                   class="h-9 rounded-md border border-border px-2 text-sm" />
            <input v-if="a.countKey" v-model="form[a.countKey]" type="number" inputmode="numeric" placeholder="Anzahl"
                   class="h-9 rounded-md border border-border px-2 text-sm" />
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Merkmale</div>
      <div class="grid grid-cols-2 gap-2">
        <button v-for="f in FEATURE_TOGGLES" :key="f.key" type="button"
                @click="form[f.key] = !form[f.key]"
                :class="[
                  'px-3 py-2 rounded-lg text-sm font-medium text-left',
                  form[f.key] ? 'bg-zinc-900 text-white' : 'bg-white border border-border text-foreground'
                ]">
          {{ f.label }}
        </button>
      </div>
    </div>

    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Allgemeinräume</div>
      <div class="flex flex-wrap gap-1.5">
        <button v-for="o in COMMON_AREA_OPTIONS" :key="o.key" type="button"
                @click="toggleCommonArea(form, o.key)"
                :class="[
                  'px-2.5 py-1.5 rounded-full text-[12px] font-medium',
                  (form.common_areas || []).includes(o.key)
                    ? 'bg-zinc-900 text-white'
                    : 'bg-white border border-border text-foreground'
                ]">
          {{ o.label }}
        </button>
      </div>
    </div>

    <div class="space-y-3">
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Ausrichtung</label>
        <PillRow v-model="form.orientation" :options="['N','NO','O','SO','S','SW','W','NW']" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Bodenbelag</label>
        <input v-model="form.flooring" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Badausstattung</label>
        <input v-model="form.bathroom_equipment" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
    </div>

    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Stellplätze</div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Garagen</label>
          <input v-model="form.garage_spaces" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Außenplätze</label>
          <input v-model="form.parking_spaces" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Parking-Typ</label>
        <PillRow v-model="form.parking_type" :options="['Garage', 'Tiefgarage', 'Carport', 'Stellplatz']" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Zuordnung</label>
        <PillRow v-model="form.parking_assignment" :options="[
          {value:'assigned', label:'Dem Objekt zugeordnet'},
          {value:'shared', label:'Allgemein / gemeinsam'},
        ]" />
      </div>
    </div>

  </div>
</template>
