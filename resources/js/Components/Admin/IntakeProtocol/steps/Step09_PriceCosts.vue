<script setup>
import { computed } from 'vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';

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
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between">
        <label class="text-sm font-medium">{{ priceLabel }}</label>
        <SkipFieldSwitch v-model="priceSkipped" />
      </div>
      <input v-model.number="priceField" type="number" inputmode="decimal"
             class="w-full h-12 rounded-md border border-border px-3 text-lg font-semibold"
             placeholder="495000" />
      <p class="text-[11px] text-muted-foreground">Richtwert — kann später angepasst werden</p>
    </div>

    <!-- Miet-Spezifisch -->
    <div v-if="!isKauf" class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Warmmiete (€)</label>
          <input v-model.number="form.rent_warm" type="number" inputmode="decimal"
                 class="w-full h-11 rounded-md border border-border px-3" />
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Kaution (€)</label>
          <input v-model.number="form.rent_deposit" type="number" inputmode="decimal"
                 class="w-full h-11 rounded-md border border-border px-3" />
        </div>
      </div>
    </div>

    <!-- Laufende Kosten — alle Felder aus dem Property-Editor 1:1 -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Laufende Kosten (mtl.)</div>
      <div class="grid grid-cols-2 gap-3">
        <div v-for="r in COST_ROWS" :key="r.key">
          <label class="text-[11px] text-muted-foreground block mb-1">{{ r.label }}</label>
          <input v-model.number="form[r.key]" type="number" step="0.01" inputmode="decimal"
                 placeholder="€"
                 class="w-full h-11 rounded-md border border-border px-3" />
        </div>
      </div>

      <div class="pt-2 border-t border-border/40">
        <label class="text-[11px] text-muted-foreground block mb-1">
          Gesamt mtl. <span class="text-[10px]">(optional — wenn leer, wird Summe verwendet)</span>
        </label>
        <input v-model.number="form.monthly_costs" type="number" step="0.01" inputmode="decimal"
               placeholder="auto-Summe"
               class="w-full h-11 rounded-md border border-border px-3" />
        <p class="text-[10px] text-muted-foreground mt-1">💡 {{ monthlyCostsHint }}</p>
      </div>
    </div>

    <!-- Verfügbar ab -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <label class="text-xs text-muted-foreground block mb-1">Verfügbar ab</label>
      <input v-model="form.available_from" type="date"
             class="w-full h-11 rounded-md border border-border px-3" />
      <p class="text-[11px] text-muted-foreground">
        💡 Provisionen werden nicht hier erfasst — die richtet der Makler später im Cockpit vor dem Inserieren ein.
      </p>
    </div>

  </div>
</template>
