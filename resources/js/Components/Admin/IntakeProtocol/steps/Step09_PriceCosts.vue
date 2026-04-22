<script setup>
import { computed } from 'vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const isKauf = computed(() => props.form.marketing_type !== 'miete' && props.form.marketing_type !== 'pacht');

const priceLabel = computed(() => {
  if (props.form.marketing_type === 'miete') return 'Monatsmiete (€)';
  if (props.form.marketing_type === 'pacht') return 'Pacht (€ / Monat)';
  return 'Kaufpreis (€)';
});

const priceField = computed({
  get: () => isKauf.value ? props.form.purchase_price : props.form.rental_price,
  set: (v) => {
    if (isKauf.value) props.form.purchase_price = v;
    else props.form.rental_price = v;
  }
});

const priceSkipped = computed({
  get: () => props.isSkipped(isKauf.value ? 'purchase_price' : 'rental_price'),
  set: (v) => {
    const k = isKauf.value ? 'purchase_price' : 'rental_price';
    v ? props.markSkipped(k) : props.unmarkSkipped(k);
  }
});

// Betriebskosten-Zeilen — 1:1 wie im Property-Editor (EditTabKosten.vue)
const COST_ROWS = [
  { key: 'operating_costs',       label: 'Betriebskosten' },
  { key: 'maintenance_reserves',  label: 'Rücklage' },
  { key: 'heating_costs',         label: 'Heizkosten' },
  { key: 'warm_water_costs',      label: 'Warmwasser' },
  { key: 'cooling_costs',         label: 'Kühlung' },
  { key: 'admin_costs',           label: 'Verwaltung' },
  { key: 'elevator_costs',        label: 'Aufzug' },
  { key: 'parking_costs_monthly', label: 'Parkplatz' },
  { key: 'other_costs',           label: 'Sonstige' },
];

const sumCosts = computed(() => {
  let s = 0;
  for (const r of COST_ROWS) {
    const v = parseFloat(props.form[r.key]);
    if (!isNaN(v)) s += v;
  }
  return s;
});

const monthlyCostsHint = computed(() => {
  const sum = sumCosts.value;
  if (props.form.monthly_costs != null && props.form.monthly_costs !== '') {
    return `Override: ${props.form.monthly_costs} € (Summe Zeilen: ${sum.toFixed(2)} €)`;
  }
  return `Summe aller Zeilen: ${sum.toFixed(2)} €`;
});
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Preis -->
    <Card>
      <CardHeader class="pb-3">
        <div class="flex items-center justify-between">
          <CardTitle class="text-base">{{ priceLabel }}</CardTitle>
          <SkipFieldSwitch v-model="priceSkipped" />
        </div>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input
          v-model.number="priceField"
          type="number"
          inputmode="decimal"
          placeholder="495000"
          class="h-12 text-lg font-semibold"
        />
        <p class="text-[11px] text-muted-foreground">Richtwert — kann später angepasst werden</p>
      </CardContent>
    </Card>

    <!-- Miet-Spezifisch -->
    <Card v-if="!isKauf">
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Miet-Details</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Warmmiete (€)</label>
            <Input v-model.number="form.rent_warm" type="number" inputmode="decimal" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium">Kaution (€)</label>
            <Input v-model.number="form.rent_deposit" type="number" inputmode="decimal" class="h-11" />
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Laufende Kosten -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Laufende Kosten (mtl.)</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <div v-for="r in COST_ROWS" :key="r.key" class="space-y-1.5">
            <label class="text-xs text-muted-foreground">{{ r.label }}</label>
            <Input
              v-model.number="form[r.key]"
              type="number" step="0.01" inputmode="decimal"
              placeholder="€"
              class="h-11"
            />
          </div>
        </div>

        <Separator />

        <div class="space-y-1.5">
          <label class="text-xs text-muted-foreground block">
            Gesamt mtl. <span class="text-[10px]">(optional — wenn leer, wird Summe verwendet)</span>
          </label>
          <Input
            v-model.number="form.monthly_costs"
            type="number" step="0.01" inputmode="decimal"
            placeholder="auto-Summe"
            class="h-11"
          />
          <p class="text-[10px] text-muted-foreground">{{ monthlyCostsHint }}</p>
        </div>
      </CardContent>
    </Card>

    <!-- Verfügbar ab -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Verfügbar ab</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input v-model="form.available_from" type="date" class="h-11" />
        <p class="text-[11px] text-muted-foreground">
          Provisionen werden nicht hier erfasst — die richtet der Makler später im Cockpit vor dem Inserieren ein.
        </p>
      </CardContent>
    </Card>

  </div>
</template>
