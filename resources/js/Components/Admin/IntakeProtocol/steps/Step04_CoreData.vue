<script setup>
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps({ form: Object });
</script>

<template>
  <div class="p-4 space-y-4">
    <Card>
      <CardHeader>
        <CardTitle>Kerndaten</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="core-living">Wohnfläche m² <span class="text-destructive">*</span></Label>
            <Input id="core-living" v-model="form.living_area" type="number" inputmode="decimal" />
          </div>
          <div class="space-y-2">
            <Label for="core-rooms">Zimmer <span class="text-destructive">*</span></Label>
            <Input id="core-rooms" v-model="form.rooms_amount" type="number" step="0.5" inputmode="decimal" />
          </div>
          <div v-if="form.object_type === 'Haus' || form.object_type === 'Grundstück'" class="space-y-2">
            <Label for="core-free">Grundstücksfläche m²</Label>
            <Input id="core-free" v-model="form.free_area" type="number" inputmode="decimal" />
          </div>
          <div v-if="form.object_type === 'Gewerbe'" class="space-y-2">
            <Label for="core-realty">Nutzfläche m²</Label>
            <Input id="core-realty" v-model="form.realty_area" type="number" inputmode="decimal" />
          </div>
          <div class="space-y-2">
            <Label for="core-bedrooms">Schlafzimmer</Label>
            <Input id="core-bedrooms" v-model="form.bedrooms" type="number" inputmode="numeric" />
          </div>
          <div class="space-y-2">
            <Label for="core-bathrooms">Badezimmer</Label>
            <Input id="core-bathrooms" v-model="form.bathrooms" type="number" inputmode="numeric" />
          </div>
          <div class="space-y-2">
            <Label for="core-toilets">WC</Label>
            <Input id="core-toilets" v-model="form.toilets" type="number" inputmode="numeric" />
          </div>
          <div v-if="form.object_type === 'Haus'" class="space-y-2">
            <Label for="core-floorcount">Stockwerke</Label>
            <Input id="core-floorcount" v-model="form.floor_count" type="number" inputmode="numeric" />
          </div>
          <div v-if="form.object_type === 'Wohnung'" class="space-y-2">
            <Label for="core-floornumber">Etage</Label>
            <Input id="core-floornumber" v-model="form.floor_number" type="number" inputmode="numeric" />
          </div>
          <div class="col-span-2 space-y-2">
            <Label for="core-year">Baujahr <span class="text-destructive">*</span></Label>
            <Input id="core-year" v-model="form.construction_year" type="number" inputmode="numeric" placeholder="YYYY" />
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
