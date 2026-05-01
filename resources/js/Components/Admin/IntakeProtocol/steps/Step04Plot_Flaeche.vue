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
        <CardTitle>Grundstuecksdaten</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2 space-y-2">
            <Label for="plot-free-area">Grundstuecksflaeche m² <span class="text-destructive">*</span></Label>
            <Input id="plot-free-area" v-model="form.free_area" type="number" inputmode="decimal" step="0.5" />
          </div>
          <div class="col-span-2 space-y-2">
            <Label for="plot-topo">Topografie / Hangneigung</Label>
            <Input id="plot-topo" v-model="form.plot_topography" type="text" placeholder="eben, leichte Suedhanglage, terrassiert..." />
          </div>
        </div>
        <p class="mt-3 text-xs text-muted-foreground">
          Wohnflaeche, Raeume und Innenausstattung gibt es bei einem Grundstueck nicht — die werden hier ausgeblendet.
          Bebauungsplan-Kennzahlen kommen in den naechsten Schritten.
        </p>
      </CardContent>
    </Card>
  </div>
</template>
