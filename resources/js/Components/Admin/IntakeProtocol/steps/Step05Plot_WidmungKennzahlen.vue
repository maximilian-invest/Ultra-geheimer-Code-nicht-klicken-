<script setup>
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

defineProps({ form: Object });
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader>
        <CardTitle>Widmung & Bebaubarkeit</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="plot-zoning">Widmung</Label>
            <Input id="plot-zoning" v-model="form.plot_zoning" type="text" placeholder="Reines Wohngebiet, Mischgebiet..." />
          </div>
          <div class="space-y-2">
            <Label for="plot-bp">Bebauungsplan-Nr.</Label>
            <Input id="plot-bp" v-model="form.plot_buildplan_id" type="text" placeholder="z.B. 06AE05" />
          </div>
        </div>

        <div class="rounded-md border p-3 flex items-center gap-3">
          <Switch id="plot-buildable" :model-value="form.plot_buildable" @update:model-value="form.plot_buildable = $event" />
          <Label for="plot-buildable" class="flex-1 cursor-pointer">Bebaubar</Label>
        </div>
        <div class="rounded-md border p-3 flex items-center gap-3">
          <Switch id="plot-developed" :model-value="form.plot_developed" @update:model-value="form.plot_developed = $event" />
          <Label for="plot-developed" class="flex-1 cursor-pointer">Aufgeschlossen</Label>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Kennzahlen</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="plot-gfz">GFZ (Geschoss­flaechenzahl)</Label>
            <Input id="plot-gfz" v-model="form.plot_gfz" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-grz">GRZ (Grundflaechenzahl)</Label>
            <Input id="plot-grz" v-model="form.plot_grz" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-bmz">BMZ (Baumassenzahl)</Label>
            <Input id="plot-bmz" v-model="form.plot_bmz" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-mba">Hoechstflaeche Bauplatz (m²)</Label>
            <Input id="plot-mba" v-model="form.plot_max_building_area" type="number" inputmode="decimal" step="0.5" />
          </div>
          <div class="col-span-2 space-y-2">
            <Label for="plot-mwe">Max. Wohneinheiten je Gebaeude</Label>
            <Input id="plot-mwe" v-model="form.plot_max_units_per_building" type="number" inputmode="numeric" />
          </div>
        </div>
      </CardContent>
    </Card>

  </div>
</template>
