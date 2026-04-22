<script setup>
import PillRow from '../shared/PillRow.vue';
import MultiPillRow from '../shared/MultiPillRow.vue';

defineProps({ form: Object });

const ENERGY_CLASSES = ['A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];

// Heizungsarten — identisch zu EditTabEnergie.vue (Multi-Select)
const HEATING_TYPES = [
  'Zentralheizung', 'Fernwärme', 'Etagenheizung', 'Kamin',
  'Fußbodenheizung', 'Offener Kamin', 'Heizkörper', 'Heizofen',
  'Kachelofen', 'Wandheizung',
];

// Befeuerung / Primärenergie-Quelle
const FUEL_OPTIONS = [
  'Luftwärmepumpe', 'Sole-Wasser-Wärmepumpe', 'Wasser-Wasser-Wärmepumpe',
  'Erdwärme', 'Brennwerttechnik', 'Gas', 'Öl', 'Holz', 'Pellets',
  'Solar', 'Fernwärme', 'Blockheizkraftwerk', 'Elektro', 'Kohle', 'Alternativ',
];
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Energieausweis -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Energieausweis</div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Vorhanden?</label>
        <PillRow v-model="form.energy_certificate" :options="[
          {value:'vorhanden', label:'Ja'},
          {value:'nein', label:'Nein'},
        ]" />
      </div>
      <div v-if="form.energy_certificate === 'vorhanden'" class="space-y-3 pt-2">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs text-muted-foreground block mb-1">HWB (kWh/m²a)</label>
            <input v-model.number="form.heating_demand_value" type="number" step="0.01" inputmode="decimal"
                   class="w-full h-11 rounded-lg border border-border px-3" />
          </div>
          <div>
            <label class="text-xs text-muted-foreground block mb-1">fGEE</label>
            <input v-model.number="form.energy_efficiency_value" type="number" step="0.01" inputmode="decimal"
                   class="w-full h-11 rounded-lg border border-border px-3" />
          </div>
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Energieklasse</label>
          <PillRow v-model="form.heating_demand_class" :options="ENERGY_CLASSES" />
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Gültig bis</label>
          <input v-model="form.energy_valid_until" type="date"
                 class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
    </div>

    <!-- Heizung — Multi-Select wie im Property-Detail -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Heizung</div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Heizungsart <span class="text-[10px]">(Mehrfachauswahl möglich)</span></label>
        <MultiPillRow v-model="form.heating" :options="HEATING_TYPES" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Primärenergiequelle / Befeuerung</label>
        <PillRow v-model="form.energy_primary_source" :options="FUEL_OPTIONS" />
      </div>
    </div>

    <!-- Extras -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Extras</div>
      <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" v-model="form.has_photovoltaik" class="w-5 h-5 accent-[#EE7600]" />
        <span class="text-sm">Photovoltaik-Anlage</span>
      </label>
      <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" v-model="form.has_wohnraumlueftung" class="w-5 h-5 accent-[#EE7600]" />
        <span class="text-sm">Wohnraumlüftung (kontrollierte Wohnraumlüftung, KWL)</span>
      </label>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">E-Ladestation</label>
        <PillRow v-model="form.charging_station_status" :options="[
          {value:'none', label:'Keine'},
          {value:'prepared', label:'Vorkehrung'},
          {value:'installed', label:'Vorhanden'},
        ]" />
      </div>
    </div>

  </div>
</template>
