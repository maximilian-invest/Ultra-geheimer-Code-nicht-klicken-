<script setup>
import PillRow from '../shared/PillRow.vue';
import MultiPillRow from '../shared/MultiPillRow.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';

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
      <CardHeader>
        <CardTitle>Energieausweis</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="space-y-2">
          <Label>Vorhanden?</Label>
          <PillRow v-model="form.energy_certificate" :options="[
            {value:'vorhanden', label:'Ja'},
            {value:'nein', label:'Nein'},
          ]" />
        </div>
        <div v-if="form.energy_certificate === 'vorhanden'" class="space-y-4 pt-2">
          <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
              <Label for="energy-hwb">HWB (kWh/m²a)</Label>
              <Input id="energy-hwb" v-model.number="form.heating_demand_value" type="number" step="0.01"
                     inputmode="decimal" />
            </div>
            <div class="space-y-2">
              <Label for="energy-fgee">fGEE</Label>
              <Input id="energy-fgee" v-model.number="form.energy_efficiency_value" type="number" step="0.01"
                     inputmode="decimal" />
            </div>
          </div>
          <div class="space-y-2">
            <Label>Energieklasse</Label>
            <PillRow v-model="form.heating_demand_class" :options="ENERGY_CLASSES" />
          </div>
          <div class="space-y-2">
            <Label for="energy-valid">Gültig bis</Label>
            <Input id="energy-valid" v-model="form.energy_valid_until" type="date" />
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Heizung — Multi-Select wie im Property-Detail -->
    <Card>
      <CardHeader>
        <CardTitle>Heizung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="space-y-2">
          <Label>
            Heizungsart <span class="text-xs font-normal text-muted-foreground">(Mehrfachauswahl)</span>
          </Label>
          <MultiPillRow v-model="form.heating" :options="HEATING_TYPES" />
        </div>
        <div class="space-y-2">
          <Label>Primärenergiequelle / Befeuerung</Label>
          <PillRow v-model="form.energy_primary_source" :options="FUEL_OPTIONS" />
        </div>
      </CardContent>
    </Card>

    <!-- Extras -->
    <Card>
      <CardHeader>
        <CardTitle>Extras</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="flex items-center gap-3">
          <Switch
            id="extra-pv"
            :model-value="form.has_photovoltaik"
            @update:model-value="form.has_photovoltaik = $event"
          />
          <Label for="extra-pv" class="cursor-pointer">Photovoltaik-Anlage</Label>
        </div>
        <div class="flex items-center gap-3">
          <Switch
            id="extra-kwl"
            :model-value="form.has_wohnraumlueftung"
            @update:model-value="form.has_wohnraumlueftung = $event"
          />
          <Label for="extra-kwl" class="cursor-pointer">Wohnraumlüftung (kontrollierte Wohnraumlüftung, KWL)</Label>
        </div>
        <div class="space-y-2">
          <Label>E-Ladestation</Label>
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
