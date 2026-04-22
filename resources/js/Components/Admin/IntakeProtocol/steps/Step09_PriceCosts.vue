<script setup>
import { computed } from 'vue';
import PillRow from '../shared/PillRow.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const hasBk = computed({
  get: () => props.form.operating_costs !== null && props.form.operating_costs !== '',
  set: (v) => { props.form.operating_costs = v ? 0 : null; }
});

const hasReserve = computed({
  get: () => props.form.maintenance_reserves !== null && props.form.maintenance_reserves !== '',
  set: (v) => { props.form.maintenance_reserves = v ? 0 : null; }
});

const priceSkipped = computed({
  get: () => props.isSkipped('purchase_price'),
  set: (v) => v ? props.markSkipped('purchase_price') : props.unmarkSkipped('purchase_price'),
});

const commissionSkipped = computed({
  get: () => props.isSkipped('commission_percent'),
  set: (v) => v ? props.markSkipped('commission_percent') : props.unmarkSkipped('commission_percent'),
});

// Commission preset: maps display pill to numeric value
const COMMISSION_OPTIONS = [
  { value: 3.0, label: '3 %' },
  { value: 3.5, label: '3,5 %' },
  { value: 4.0, label: '4 %' },
  { value: null, label: 'Anders' },  // null = custom input visible
];

const commissionPresetValue = computed({
  get: () => {
    const v = props.form.commission_percent;
    if (v === 3.0 || v === 3.5 || v === 4.0) return v;
    // If the value doesn't match a preset, it's "custom" (but null sentinel means "custom mode" only when no value set)
    return v ?? null;
  },
  set: (v) => {
    // v is the clicked preset value (or null for "Anders")
    if (v === null) {
      // "Anders" keeps current value but forces custom input visibility
      if (props.form.commission_percent === 3.0 || props.form.commission_percent === 3.5 || props.form.commission_percent === 4.0) {
        props.form.commission_percent = null;
      }
    } else {
      props.form.commission_percent = v;
    }
  }
});

const showCustomCommission = computed(() =>
  !(props.form.commission_percent === 3.0 || props.form.commission_percent === 3.5 || props.form.commission_percent === 4.0)
);

// Label for "Preis" changes depending on rental vs sale
const priceLabel = computed(() => {
  if (props.form.marketing_type === 'miete') return 'Monatsmiete (€)';
  if (props.form.marketing_type === 'pacht') return 'Pacht (€ / Monat)';
  return 'Kaufpreis (€)';
});

const priceField = computed({
  get: () => props.form.marketing_type === 'miete' || props.form.marketing_type === 'pacht'
    ? props.form.rental_price
    : props.form.purchase_price,
  set: (v) => {
    if (props.form.marketing_type === 'miete' || props.form.marketing_type === 'pacht') {
      props.form.rental_price = v;
    } else {
      props.form.purchase_price = v;
    }
  }
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

    <!-- Laufende Kosten -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Laufende Kosten</div>

      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="hasBk" id="has-bk" class="h-4 w-4 accent-[#EE7600]" />
          <label for="has-bk" class="text-sm">Monatliche Betriebskosten</label>
        </div>
        <input v-if="hasBk" v-model.number="form.operating_costs" type="number" inputmode="decimal"
               class="w-full h-11 rounded-md border border-border px-3" placeholder="€ / Monat" />
      </div>

      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="hasReserve" id="has-res" class="h-4 w-4 accent-[#EE7600]" />
          <label for="has-res" class="text-sm">Rücklage (mtl.)</label>
        </div>
        <input v-if="hasReserve" v-model.number="form.maintenance_reserves" type="number" inputmode="decimal"
               class="w-full h-11 rounded-md border border-border px-3" placeholder="€ / Monat" />
      </div>
    </div>

    <!-- Provision -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between">
        <label class="text-sm font-medium">Provision (% vom Kaufpreis)</label>
        <SkipFieldSwitch v-model="commissionSkipped" />
      </div>
      <PillRow
        :model-value="commissionPresetValue"
        :options="COMMISSION_OPTIONS"
        @update:model-value="commissionPresetValue = $event"
      />
      <input v-if="showCustomCommission"
             v-model.number="form.commission_percent" type="number" step="0.1" inputmode="decimal"
             class="w-full h-11 rounded-md border border-border px-3 mt-2" placeholder="z.B. 3.25" />
      <p class="text-[11px] text-muted-foreground">+ 20 % USt. — wird im Alleinvermittlungsauftrag angedruckt</p>
    </div>

    <!-- Käuferprovision (nur Kauf) -->
    <div v-if="form.marketing_type === 'kauf'" class="bg-white border border-border rounded-xl p-4 space-y-2">
      <label class="text-sm font-medium">Käuferprovision (%)</label>
      <input v-model.number="form.buyer_commission_percent" type="number" step="0.1" inputmode="decimal"
             class="w-full h-11 rounded-md border border-border px-3" placeholder="z.B. 3.0" />
      <p class="text-[11px] text-muted-foreground">Was der Käufer zahlt — wird auf Website/Portalen angezeigt</p>
    </div>

  </div>
</template>
