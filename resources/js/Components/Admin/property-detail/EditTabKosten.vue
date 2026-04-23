<script setup>
import { computed } from "vue";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import AccordionSection from "./AccordionSection.vue";
import FieldExportBadges from "./FieldExportBadges.vue";

const props = defineProps({
  form: { type: Object, required: true },
  isNewbuild: { type: Boolean, default: false },
});

const inputCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";

// Vermarktungsart -> Label fuer das Haupt-Preis-Feld.
// Miete nutzt dasselbe Feld (form.purchase_price) wie Kauf — wir
// haben bewusst keine eigene Mietpreis-Sektion mehr. Bei Miete zeigen
// wir 'Mietpreis (mtl.)' als Label und pushen den Wert Richtung Immoji
// in costs.rentalPrice statt costs.purchasePrice.
const isRental = computed(() => (props.form?.marketing_type || '') === 'miete');
const priceLabel = computed(() => isRental.value ? 'Mietpreis (mtl.)' : 'Kaufpreis');

// Live-Vorschau der berechneten Nebenkosten-Betraege (gleiche Logik wie Website).
// Leere Felder -> wird auf der Website gar nicht ausgespielt; hier als "—" angezeigt.
const priceNum = computed(() => Number(props.form?.purchase_price) || 0);
const fmtEuro = (v) => v > 0
  ? new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v)
  : '—';

const pctRaw = (key) => {
  const v = props.form?.[key];
  const raw = Number(v);
  return isFinite(raw) && raw > 0 ? raw : 0;
};

const nkGrunderwerb = computed(() => priceNum.value * pctRaw('land_transfer_tax_pct') / 100);
const nkGrundbuch    = computed(() => priceNum.value * pctRaw('land_register_fee_pct') / 100);
const nkPfandrecht   = computed(() => priceNum.value * pctRaw('mortgage_register_fee_pct') / 100);
const nkVertrag      = computed(() => priceNum.value * pctRaw('contract_fee_pct') / 100);
const nkProvision    = computed(() => {
  if (props.form?.buyer_commission_free) return 0;
  return priceNum.value * pctRaw('buyer_commission_percent') / 100;
});
const nkGesamt = computed(() =>
  nkGrunderwerb.value + nkGrundbuch.value + nkPfandrecht.value + nkVertrag.value + nkProvision.value
);

// Feld-Zaehler pro Sektion
function countFilled(keys) {
  let filled = 0;
  for (const k of keys) {
    const v = props.form?.[k];
    if (v === null || v === undefined) continue;
    if (typeof v === 'string' && v.trim() === '') continue;
    if (typeof v === 'boolean' && v === false) continue;
    if (typeof v === 'number' && v === 0) continue;
    filled++;
  }
  return filled;
}
const SECTION_FIELDS = {
  preise:    ['purchase_price', 'price_per_m2'],
  bk:        ['operating_costs', 'maintenance_reserves', 'heating_costs', 'warm_water_costs', 'cooling_costs', 'admin_costs', 'elevator_costs', 'parking_costs_monthly', 'other_costs', 'monthly_costs'],
  provIntern:['commission_percent', 'commission_total', 'commission_note'],
  nebenkosten:['land_transfer_tax_pct', 'land_register_fee_pct', 'mortgage_register_fee_pct', 'contract_fee_pct', 'buyer_commission_percent', 'buyer_commission_free', 'nebenkosten_note'],
};
const sectionCounts = computed(() => {
  const out = {};
  for (const [key, fields] of Object.entries(SECTION_FIELDS)) {
    out[key] = { filled: countFilled(fields), total: fields.length };
  }
  return out;
});
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Preise -->
      <AccordionSection title="Preise" color="#ea580c" :default-open="true" :filled="sectionCounts.preise.filled" :total="sectionCounts.preise.total">
        <div>
          <label :class="labelCls">{{ priceLabel }} <FieldExportBadges field="purchase_price" /></label>
          <Input v-model="form.purchase_price" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Preis/m²</label>
          <Input v-model="form.price_per_m2" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Betriebskosten (monatlich) -->
      <AccordionSection title="Betriebskosten" color="#f97316" :default-open="true" :filled="sectionCounts.bk.filled" :total="sectionCounts.bk.total">
        <div>
          <label :class="labelCls">Betriebskosten <FieldExportBadges field="operating_costs" /></label>
          <Input v-model="form.operating_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Rücklage <FieldExportBadges field="maintenance_reserves" /></label>
          <Input v-model="form.maintenance_reserves" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Heizkosten <FieldExportBadges field="heating_costs" /></label>
          <Input v-model="form.heating_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Warmwasser <FieldExportBadges field="warm_water_costs" /></label>
          <Input v-model="form.warm_water_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Kühlung <FieldExportBadges field="cooling_costs" /></label>
          <Input v-model="form.cooling_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Verwaltung <FieldExportBadges field="admin_costs" /></label>
          <Input v-model="form.admin_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Aufzug <FieldExportBadges field="elevator_costs" /></label>
          <Input v-model="form.elevator_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Parkplatz <FieldExportBadges field="parking_costs_monthly" /></label>
          <Input v-model="form.parking_costs_monthly" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Sonstige <FieldExportBadges field="other_costs" /></label>
          <Input v-model="form.other_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Monatliche Kosten ges. <FieldExportBadges field="monthly_costs" /></label>
          <Input v-model="form.monthly_costs" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Provision Intern -->
      <AccordionSection title="Provision Intern" color="#8b5cf6" :default-open="true" :filled="sectionCounts.provIntern.filled" :total="sectionCounts.provIntern.total">
        <div>
          <label :class="labelCls">Provision % <FieldExportBadges field="commission_percent" /></label>
          <Input v-model="form.commission_percent" type="number" step="0.01" inputmode="decimal" placeholder="z.B. 3,25" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Provision EUR <FieldExportBadges field="commission_total" /></label>
          <Input v-model="form.commission_total" type="number" step="0.01" inputmode="decimal" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Provisionsnotiz <FieldExportBadges field="commission_note" /></label>
          <Input v-model="form.commission_note" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Nebenkosten beim Kauf (inkl. Makler-Provision oeffentlich) -->
      <AccordionSection title="Nebenkosten beim Kauf" color="#22c55e" :default-open="true" :filled="sectionCounts.nebenkosten.filled" :total="sectionCounts.nebenkosten.total">
        <div class="col-span-2 text-[10.5px] text-muted-foreground mb-1 leading-relaxed">
          Werden auf der Objekt-Website rechts neben dem Kaufpreis angezeigt.
          Neue Projekte werden mit den <strong>österreichischen Standardsätzen</strong> (3,5% / 1,1% / 1,2% / 1,5% / 3,0%) vorausgefüllt —
          Felder bei Bedarf überschreiben oder leeren, um die Position nicht anzuzeigen.
        </div>
        <div>
          <label :class="labelCls">Grunderwerbsteuer % <FieldExportBadges field="land_transfer_tax_pct" /></label>
          <Input v-model="form.land_transfer_tax_pct" type="number" step="0.01" placeholder="3,5" :class="inputCls" />
          <p class="text-[10px] text-muted-foreground mt-0.5 text-right tabular-nums">≈ {{ fmtEuro(nkGrunderwerb) }}</p>
        </div>
        <div>
          <label :class="labelCls">Grundbuch-Eintragung % <FieldExportBadges field="land_register_fee_pct" /></label>
          <Input v-model="form.land_register_fee_pct" type="number" step="0.01" placeholder="1,1" :class="inputCls" />
          <p class="text-[10px] text-muted-foreground mt-0.5 text-right tabular-nums">≈ {{ fmtEuro(nkGrundbuch) }}</p>
        </div>
        <div>
          <label :class="labelCls">Pfandrecht-Eintragung % <FieldExportBadges field="mortgage_register_fee_pct" /></label>
          <Input v-model="form.mortgage_register_fee_pct" type="number" step="0.01" placeholder="1,2" :class="inputCls" />
          <p class="text-[10px] text-muted-foreground mt-0.5 text-right tabular-nums">≈ {{ fmtEuro(nkPfandrecht) }}</p>
        </div>
        <div>
          <label :class="labelCls">Vertragserrichtung % <FieldExportBadges field="contract_fee_pct" /></label>
          <Input v-model="form.contract_fee_pct" type="number" step="0.01" placeholder="1,5" :class="inputCls" />
          <p class="text-[10px] text-muted-foreground mt-0.5 text-right tabular-nums">≈ {{ fmtEuro(nkVertrag) }}</p>
        </div>
        <div>
          <label :class="labelCls">Maklerprovision % <FieldExportBadges field="buyer_commission_percent" /></label>
          <Input v-model="form.buyer_commission_percent" type="number" step="0.01" placeholder="3,0" :class="inputCls" />
          <p class="text-[10px] text-muted-foreground mt-0.5 text-right tabular-nums">≈ {{ fmtEuro(nkProvision) }}</p>
        </div>
        <div class="flex items-end">
          <button
            type="button"
            @click="form.buyer_commission_free = !form.buyer_commission_free"
            class="w-full h-9 px-3 rounded-lg text-[12px] font-medium transition-colors"
            :class="form.buyer_commission_free ? 'bg-zinc-900 text-white' : 'border border-border text-foreground hover:bg-zinc-50'"
          >
            {{ form.buyer_commission_free ? '✓ Provisionsfrei für Käufer' : 'Provisionsfrei markieren' }}
          </button>
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Anmerkung zu den Nebenkosten <FieldExportBadges field="nebenkosten_note" /></label>
          <Input v-model="form.nebenkosten_note" :class="inputCls" placeholder="z.B. inkl. Notarbeglaubigung, ohne Steuerberatung" />
        </div>
        <div class="col-span-2 flex items-center justify-end pt-2 border-t border-zinc-200">
          <div class="text-right">
            <div class="text-[10px] text-muted-foreground">Summe zusätzlich zum Kaufpreis</div>
            <div class="text-[14px] font-semibold tabular-nums">{{ fmtEuro(nkGesamt) }}</div>
          </div>
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
