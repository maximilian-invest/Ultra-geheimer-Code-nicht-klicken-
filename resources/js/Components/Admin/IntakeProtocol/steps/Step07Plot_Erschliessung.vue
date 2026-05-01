<script setup>
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

defineProps({ form: Object });

const UTILITY_TOGGLES = [
  { key: 'plot_utility_water',       label: 'Wasser' },
  { key: 'plot_utility_sewage',      label: 'Kanal' },
  { key: 'plot_utility_electricity', label: 'Strom' },
  { key: 'plot_utility_gas',         label: 'Gas' },
  { key: 'plot_utility_fiber',       label: 'Glasfaser' },
];

const RESTRICTION_TOGGLES = [
  { key: 'plot_wlv_reserve',          label: 'WLV-Vorbehaltsbereich' },
  { key: 'plot_wlv_hint',             label: 'WLV-Hinweisbereich' },
  { key: 'plot_flood_risk',           label: 'Hochwassergefaehrdung' },
  { key: 'plot_landscape_protection', label: 'Landschaftsschutzgebiet' },
  { key: 'plot_planting_obligation',  label: 'Pflanzbindung' },
];
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader>
        <CardTitle>Erschliessung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <p class="text-xs text-muted-foreground mb-2">Was liegt am Grundstueck an?</p>
        <div
          v-for="u in UTILITY_TOGGLES"
          :key="u.key"
          class="rounded-md border p-3 flex items-center gap-3"
        >
          <Switch
            :id="`utility-${u.key}`"
            :model-value="form[u.key]"
            @update:model-value="form[u.key] = $event"
          />
          <Label :for="`utility-${u.key}`" class="flex-1 cursor-pointer">{{ u.label }}</Label>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Gefahrenzonen & Auflagen</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="space-y-2">
          <Label for="plot-hazard">Wildbachgefahrenzone</Label>
          <select
            id="plot-hazard"
            v-model="form.plot_hazard_zone"
            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="">Keine</option>
            <option value="gelb">Gelbe Zone</option>
            <option value="rot">Rote Zone</option>
          </select>
        </div>
        <div class="space-y-2">
          <div
            v-for="r in RESTRICTION_TOGGLES"
            :key="r.key"
            class="rounded-md border p-3 flex items-center gap-3"
          >
            <Switch
              :id="`restr-${r.key}`"
              :model-value="form[r.key]"
              @update:model-value="form[r.key] = $event"
            />
            <Label :for="`restr-${r.key}`" class="flex-1 cursor-pointer">{{ r.label }}</Label>
          </div>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Notizen zum Bebauungsplan</CardTitle>
      </CardHeader>
      <CardContent>
        <Textarea
          v-model="form.plot_notes"
          rows="4"
          placeholder="z.B. Sondervorschriften, Sichtachsen, Zufahrt, Servitute, Altlasten..."
        />
      </CardContent>
    </Card>

  </div>
</template>
