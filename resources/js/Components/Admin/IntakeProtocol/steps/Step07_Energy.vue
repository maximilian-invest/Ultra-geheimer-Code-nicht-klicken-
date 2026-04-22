<script setup>
import PillRow from '../shared/PillRow.vue';
import MultiPillRow from '../shared/MultiPillRow.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

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
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Energieausweis</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Vorhanden?</label>
          <PillRow v-model="form.energy_certificate" :options="[
            {value:'vorhanden', label:'Ja'},
            {value:'nein', label:'Nein'},
          ]" />
        </div>
        <div v-if="form.energy_certificate === 'vorhanden'" class="space-y-3 pt-2">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <label class="text-sm font-medium">HWB (kWh/m²a)</label>
              <Input v-model.number="form.heating_demand_value" type="number" step="0.01"
                     inputmode="decimal" class="h-11" />
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium">fGEE</label>
              <Input v-model.number="form.energy_efficiency_value" type="number" step="0.01"
                     inputmode="decimal" class="h-11" />
            </div>
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Energieklasse</label>
            <PillRow v-model="form.heating_demand_class" :options="ENERGY_CLASSES" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Gültig bis</label>
            <Input v-model="form.energy_valid_until" type="date" class="h-11" />
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Heizung — Multi-Select wie im Property-Detail -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Heizung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium">
            Heizungsart <span class="text-xs font-normal text-muted-foreground">(Mehrfachauswahl)</span>
          </label>
          <MultiPillRow v-model="form.heating" :options="HEATING_TYPES" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Primärenergiequelle / Befeuerung</label>
          <PillRow v-model="form.energy_primary_source" :options="FUEL_OPTIONS" />
        </div>
      </CardContent>
    </Card>

    <!-- Extras -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Extras</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="flex items-center gap-3">
          <Switch
            :model-value="form.has_photovoltaik"
            @update:model-value="form.has_photovoltaik = $event"
          />
          <span class="text-sm">Photovoltaik-Anlage</span>
        </div>
        <div class="flex items-center gap-3">
          <Switch
            :model-value="form.has_wohnraumlueftung"
            @update:model-value="form.has_wohnraumlueftung = $event"
          />
          <span class="text-sm">Wohnraumlüftung (kontrollierte Wohnraumlüftung, KWL)</span>
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">E-Ladestation</label>
          <PillRow v-model="form.charging_station_status" :options="[
            {value:'none', label:'Keine'},
            {value:'prepared', label:'Vorkehrung'},
            {value:'installed', label:'Vorhanden'},
          ]" />
        </div>
      </CardContent>
    </Card>

  </div>
</template>
