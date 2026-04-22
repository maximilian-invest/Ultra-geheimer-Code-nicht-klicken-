<script setup>
import { computed } from 'vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
      <CardHeader>
        <div class="flex items-center justify-between">
          <CardTitle>{{ priceLabel }}</CardTitle>
          <SkipFieldSwitch v-model="priceSkipped" />
        </div>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input
          id="price-main"
          v-model.number="priceField"
          type="number"
          inputmode="decimal"
          placeholder="495000"
          class="text-lg font-semibold"
        />
        <p class="text-xs text-muted-foreground">Richtwert — kann später angepasst werden</p>
      </CardContent>
    </Card>

    <!-- Miet-Spezifisch -->
    <Card v-if="!isKauf">
      <CardHeader>
        <CardTitle>Miet-Details</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="rent-warm">Warmmiete (€)</Label>
            <Input id="rent-warm" v-model.number="form.rent_warm" type="number" inputmode="decimal" />
          </div>
          <div class="space-y-2">
            <Label for="rent-deposit">Kaution (€)</Label>
            <Input id="rent-deposit" v-model.number="form.rent_deposit" type="number" inputmode="decimal" />
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Laufende Kosten -->
    <Card>
      <CardHeader>
        <CardTitle>Laufende Kosten (mtl.)</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div v-for="r in COST_ROWS" :key="r.key" class="space-y-2">
            <Label :for="`cost-${r.key}`" class="text-xs text-muted-foreground">{{ r.label }}</Label>
            <Input
              :id="`cost-${r.key}`"
              v-model.number="form[r.key]"
              type="number" step="0.01" inputmode="decimal"
              placeholder="€"
            />
          </div>
        </div>

        <Separator />

        <div class="space-y-2">
          <Label for="cost-total" class="text-xs text-muted-foreground">
            Gesamt mtl. <span class="text-xs">(optional — wenn leer, wird Summe verwendet)</span>
          </Label>
          <Input
            id="cost-total"
            v-model.number="form.monthly_costs"
            type="number" step="0.01" inputmode="decimal"
            placeholder="auto-Summe"
          />
          <p class="text-xs text-muted-foreground">{{ monthlyCostsHint }}</p>
        </div>
      </CardContent>
    </Card>

    <!-- Verfügbar ab -->
    <Card>
      <CardHeader>
        <CardTitle>Verfügbar ab</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input v-model="form.available_from" type="date" />
        <p class="text-xs text-muted-foreground">
          Provisionen werden nicht hier erfasst — die richtet der Makler später im Cockpit vor dem Inserieren ein.
        </p>
      </CardContent>
    </Card>

  </div>
</template>
