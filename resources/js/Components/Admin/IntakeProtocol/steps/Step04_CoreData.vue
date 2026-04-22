<script setup>
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

defineProps({ form: Object });
</script>

<template>
  <div class="p-4 space-y-4">
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Kerndaten</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Wohnfläche m² <span class="text-red-500">*</span></label>
            <Input v-model="form.living_area" type="number" inputmode="decimal" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Zimmer <span class="text-red-500">*</span></label>
            <Input v-model="form.rooms_amount" type="number" step="0.5" inputmode="decimal" class="h-11" />
          </div>
          <div v-if="form.object_type === 'Haus' || form.object_type === 'Grundstück'" class="space-y-1.5">
            <label class="text-sm font-medium">Grundstücksfläche m²</label>
            <Input v-model="form.free_area" type="number" inputmode="decimal" class="h-11" />
          </div>
          <div v-if="form.object_type === 'Gewerbe'" class="space-y-1.5">
            <label class="text-sm font-medium">Nutzfläche m²</label>
            <Input v-model="form.realty_area" type="number" inputmode="decimal" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Schlafzimmer</label>
            <Input v-model="form.bedrooms" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Badezimmer</label>
            <Input v-model="form.bathrooms" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">WC</label>
            <Input v-model="form.toilets" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div v-if="form.object_type === 'Haus'" class="space-y-1.5">
            <label class="text-sm font-medium">Stockwerke</label>
            <Input v-model="form.floor_count" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div v-if="form.object_type === 'Wohnung'" class="space-y-1.5">
            <label class="text-sm font-medium">Etage</label>
            <Input v-model="form.floor_number" type="number" inputmode="numeric" class="h-11" />
          </div>
          <div class="col-span-2 space-y-1.5">
            <label class="text-sm font-medium">Baujahr <span class="text-red-500">*</span></label>
            <Input v-model="form.construction_year" type="number" inputmode="numeric" placeholder="YYYY" class="h-11" />
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
