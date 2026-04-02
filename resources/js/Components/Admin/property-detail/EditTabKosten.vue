<script setup>
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import AccordionSection from "./AccordionSection.vue";

defineProps({
  form: { type: Object, required: true },
  isNewbuild: { type: Boolean, default: false },
});

const inputCls = "h-9 text-[13px] border border-input rounded-lg bg-background";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Preise -->
      <AccordionSection title="Preise" color="#ea580c" :default-open="true">
        <div>
          <label :class="labelCls">Kaufpreis / Miete</label>
          <Input v-model="form.purchase_price" type="number" :disabled="isNewbuild" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Preis/m²</label>
          <Input v-model="form.price_per_m2" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stellplatz-Preis</label>
          <Input v-model="form.parking_price" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Betriebskosten</label>
          <Input v-model="form.operating_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Rücklage</label>
          <Input v-model="form.maintenance_reserves" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Erweiterte Kosten -->
      <AccordionSection title="Erweiterte Kosten" color="#f97316" :default-open="false">
        <div>
          <label :class="labelCls">Heizkosten</label>
          <Input v-model="form.heating_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Warmwasser</label>
          <Input v-model="form.warm_water_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Kühlung</label>
          <Input v-model="form.cooling_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Verwaltung</label>
          <Input v-model="form.admin_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Aufzug</label>
          <Input v-model="form.elevator_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Parkplatz</label>
          <Input v-model="form.parking_costs_monthly" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Sonstige</label>
          <Input v-model="form.other_costs" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Monatliche Kosten ges.</label>
          <Input v-model="form.monthly_costs" type="number" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Miete -->
      <AccordionSection v-if="form.marketing_type === 'miete'" title="Miete" color="#ec4899" :default-open="true">
        <div>
          <label :class="labelCls">Kaltmiete</label>
          <Input v-model="form.rental_price" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Warmmiete</label>
          <Input v-model="form.rent_warm" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Kaution</label>
          <Input v-model="form.rent_deposit" type="number" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Provision Intern -->
      <AccordionSection title="Provision Intern" color="#8b5cf6" :default-open="true">
        <div>
          <label :class="labelCls">Provision %</label>
          <Input v-model="form.commission_percent" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Provision EUR</label>
          <Input v-model="form.commission_total" type="number" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Provisionsnotiz</label>
          <Input v-model="form.commission_note" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Provision Öffentlich -->
      <AccordionSection title="Provision Öffentlich" color="#3b82f6" :default-open="true">
        <div>
          <label :class="labelCls">Makler-Provision %</label>
          <Input v-model="form.buyer_commission_percent" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Makler-Provision EUR</label>
          <Input v-model="form.commission_makler" type="number" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Provisionstext</label>
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
          <label :class="labelCls">Grundbucheintragung %</label>
          <Input v-model="form.land_register_fee_pct" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Grunderwerbssteuer %</label>
          <Input v-model="form.land_transfer_tax_pct" type="number" placeholder="3.5" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Vertragserstellung %</label>
          <Input v-model="form.contract_fee_pct" type="number" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
