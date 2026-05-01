<script setup>
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PillRow from '../shared/PillRow.vue';

defineProps({ form: Object });

const CONSTRUCTION_OPTIONS = [
  { value: 'offen', label: 'Offen' },
  { value: 'geschlossen', label: 'Geschlossen' },
  { value: 'freistehend', label: 'Freistehend' },
  { value: 'gekuppelt', label: 'Gekuppelt' },
  { value: 'offen_freistehend_gekuppelt', label: 'Offen / freist. od. gekuppelt' },
  { value: 'reihenhaus', label: 'Reihenhaus' },
];

const ROOF_OPTIONS = [
  { value: 'satteldach', label: 'Satteldach' },
  { value: 'walmdach', label: 'Walmdach' },
  { value: 'krueppelwalmdach', label: 'Kruepp.walm' },
  { value: 'pultdach', label: 'Pultdach' },
  { value: 'flachdach', label: 'Flachdach' },
  { value: 'zeltdach', label: 'Zeltdach' },
  { value: 'mansarddach', label: 'Mansarddach' },
  { value: 'frei', label: 'Freie Wahl' },
];
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader>
        <CardTitle>Hoehen & Bautiefen</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="plot-fh">Firsthoehe max. (m)</Label>
            <Input id="plot-fh" v-model="form.plot_max_height_first" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-th">Traufhoehe max. (m)</Label>
            <Input id="plot-th" v-model="form.plot_max_height_eaves" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-ml">Hoechstlaenge (m)</Label>
            <Input id="plot-ml" v-model="form.plot_max_length" type="number" inputmode="decimal" step="0.01" />
          </div>
          <div class="space-y-2">
            <Label for="plot-mw">Hoechstbreite (m)</Label>
            <Input id="plot-mw" v-model="form.plot_max_width" type="number" inputmode="decimal" step="0.01" />
          </div>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Bauweise</CardTitle>
      </CardHeader>
      <CardContent>
        <PillRow v-model="form.plot_construction_type" :options="CONSTRUCTION_OPTIONS" />
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Dach</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="space-y-2">
          <Label>Dachform</Label>
          <PillRow v-model="form.plot_roof_form" :options="ROOF_OPTIONS" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="plot-rmin">Dachneigung min. (Grad)</Label>
            <Input id="plot-rmin" v-model="form.plot_min_roof_pitch" type="number" inputmode="decimal" step="0.5" />
          </div>
          <div class="space-y-2">
            <Label for="plot-rmax">Dachneigung max. (Grad)</Label>
            <Input id="plot-rmax" v-model="form.plot_max_roof_pitch" type="number" inputmode="decimal" step="0.5" />
          </div>
        </div>
      </CardContent>
    </Card>

  </div>
</template>
