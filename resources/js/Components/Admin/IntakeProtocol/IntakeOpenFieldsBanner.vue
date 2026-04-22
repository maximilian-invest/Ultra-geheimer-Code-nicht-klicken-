<script setup>
import { computed } from 'vue';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { AlertCircle } from 'lucide-vue-next';

const props = defineProps({
  protocol: { type: Object, default: null },
});

// open_fields wird vom Wizard als Array von Feld-Keys gespeichert.
// Fallback fuer legacy object-form { field: true } behalten.
const openFieldKeys = computed(() => {
  const raw = props.protocol?.open_fields;
  if (Array.isArray(raw)) return raw;
  if (raw && typeof raw === 'object') {
    return Object.keys(raw).filter(k => raw[k]);
  }
  return [];
});

const FIELD_LABELS = {
  living_area: 'Wohnfläche',
  free_area: 'Grundstücksfläche',
  realty_area: 'Nutzfläche',
  rooms_amount: 'Zimmer',
  construction_year: 'Baujahr',
  purchase_price: 'Kaufpreis',
  rental_price: 'Miete',
  commission_percent: 'Provision',
  buyer_commission_percent: 'Käuferprovision',
  energy_certificate: 'Energieausweis',
  heating_demand_value: 'HWB-Wert',
  heating_demand_class: 'Energieklasse',
  approvals_notes: 'Bewilligungs-Notizen',
  ref_id: 'Ref-ID',
};

function label(key) {
  return FIELD_LABELS[key] || key;
}

const fmtDate = computed(() => {
  if (!props.protocol?.created_at) return '';
  try {
    return new Date(props.protocol.created_at).toLocaleDateString('de-AT');
  } catch {
    return '';
  }
});
</script>

<template>
  <Alert
    v-if="protocol && openFieldKeys.length > 0"
    variant="warning"
    class="mb-4 mx-3 sm:mx-5 mt-3"
  >
    <AlertCircle class="size-4" />
    <AlertTitle>
      {{ openFieldKeys.length }}
      {{ openFieldKeys.length === 1 ? 'Feld wurde' : 'Felder wurden' }}
      im Aufnahmeprotokoll übersprungen
    </AlertTitle>
    <AlertDescription>
      <div>
        <span v-for="(k, i) in openFieldKeys" :key="k">
          {{ label(k) }}<span v-if="i < openFieldKeys.length - 1">, </span>
        </span>
      </div>
      <div v-if="fmtDate" class="mt-2 text-xs opacity-80">
        Aufgenommen am {{ fmtDate }}
      </div>
    </AlertDescription>
  </Alert>
</template>
