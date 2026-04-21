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
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Preise -->
      <AccordionSection title="Preise" color="#ea580c" :default-open="true">
        <div>
          <label :class="labelCls">{{ priceLabel }} <FieldExportBadges field="purchase_price" /></label>
          <Input v-model="form.purchase_price" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Preis/m²</label>
          <Input v-model="form.price_per_m2" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stellplatz-Preis <FieldExportBadges field="parking_price" /></label>
          <Input v-model="form.parking_price" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Betriebskosten <FieldExportBadges field="operating_costs" /></label>
          <Input v-model="form.operating_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Rücklage <FieldExportBadges field="maintenance_reserves" /></label>
          <Input v-model="form.maintenance_reserves" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Erweiterte Kosten -->
      <AccordionSection title="Erweiterte Kosten" color="#f97316" :default-open="false">
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
      <AccordionSection title="Provision Intern" color="#8b5cf6" :default-open="true">
        <div>
          <label :class="labelCls">Provision % <FieldExportBadges field="commission_percent" /></label>
          <Input v-model="form.commission_percent" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Provision EUR <FieldExportBadges field="commission_total" /></label>
          <Input v-model="form.commission_total" type="number" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Provisionsnotiz <FieldExportBadges field="commission_note" /></label>
          <Input v-model="form.commission_note" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Provision Öffentlich -->
      <AccordionSection title="Provision Öffentlich" color="#3b82f6" :default-open="true">
        <div>
          <label :class="labelCls">Makler-Provision % <FieldExportBadges field="buyer_commission_percent" /></label>
          <Input v-model="form.buyer_commission_percent" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Makler-Provision EUR <FieldExportBadges field="commission_makler" /></label>
          <Input v-model="form.commission_makler" type="number" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Provisionstext <FieldExportBadges field="buyer_commission_text" /></label>
          <Input v-model="form.buyer_commission_text" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <button
            type="button"
            @click="form.buyer_commission_free = !form.buyer_commission_free"
            class="px-3 py-1.5 rounded-lg text-[13px] font-medium transition-colors"
            :class="form.buyer_commission_free ? 'bg-zinc-900 text-white' : 'border border-border text-foreground hover:bg-zinc-50'"
          >
            Provisionsfrei
          </button>
        </div>
      </AccordionSection>

      <!-- Nebenkosten -->
      <AccordionSection title="Nebenkosten" color="#22c55e" :default-open="false">
        <div>
          <label :class="labelCls">Grundbucheintragung % <FieldExportBadges field="land_register_fee_pct" /></label>
          <Input v-model="form.land_register_fee_pct" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Grunderwerbssteuer % <FieldExportBadges field="land_transfer_tax_pct" /></label>
          <Input v-model="form.land_transfer_tax_pct" type="number" placeholder="3.5" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Vertragserstellung % <FieldExportBadges field="contract_fee_pct" /></label>
          <Input v-model="form.contract_fee_pct" type="number" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
