<script setup>
import { computed } from 'vue';
import SignaturePad from '../shared/SignaturePad.vue';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
  disclaimerText: { type: String, default: '' },
});

const ownerName = computed(() => props.form.owner?.name?.trim() || '—');
const addressLine = computed(() => {
  const parts = [props.form.address, props.form.house_number].filter(Boolean).join(' ');
  return [parts, props.form.zip, props.form.city].filter(Boolean).join(', ') || '—';
});
const priceValue = computed(() => {
  if (props.form.marketing_type === 'miete' || props.form.marketing_type === 'pacht') {
    return props.form.rental_price;
  }
  return props.form.purchase_price;
});
const priceLine = computed(() => {
  const v = priceValue.value;
  if (!v) return '—';
  return new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v);
});
const objectLine = computed(() => [props.form.object_type, props.form.object_subtype].filter(Boolean).join(' · ') || '—');
const commissionLine = computed(() => {
  const v = props.form.commission_percent;
  return v != null && v !== '' ? `${v} %` : '—';
});
const photoCount = computed(() => (props.form.photos || []).length);
const openFieldsCount = computed(() => (props.form.open_fields || []).length);
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Summary Card -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3 text-sm">
      <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
        <span class="text-muted-foreground">Eigentümer:</span> <span class="font-medium">{{ ownerName }}</span>
        <span class="text-muted-foreground">Objekt:</span>     <span>{{ objectLine }}</span>
        <span class="text-muted-foreground">Adresse:</span>    <span>{{ addressLine }}</span>
        <span class="text-muted-foreground">Richtpreis:</span> <span class="font-semibold">{{ priceLine }}</span>
        <span class="text-muted-foreground">Provision:</span>  <span>{{ commissionLine }} + 20 % USt.</span>
        <span class="text-muted-foreground">Fotos:</span>      <span>{{ photoCount }}</span>
        <span class="text-muted-foreground">Offene Felder:</span>
        <span :class="openFieldsCount > 0 ? 'text-amber-700 font-medium' : 'text-green-700 font-medium'">
          {{ openFieldsCount }} {{ openFieldsCount === 1 ? 'Feld' : 'Felder' }}
        </span>
      </div>
    </div>

    <!-- Offene Felder — Warnung -->
    <div v-if="openFieldsCount > 0" class="bg-amber-50 border border-amber-300 rounded-xl p-3">
      <div class="text-sm text-amber-900">
        ⚠️ <strong>{{ openFieldsCount }} Feld(er) wurden übersprungen.</strong>
        Diese werden im PDF als „offen" markiert und der Eigentümer erhält eine Erinnerungs-Mail zum Nachreichen.
      </div>
    </div>

    <!-- Haftungsausschluss -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Haftungsausschluss</div>
      <div class="text-xs leading-relaxed text-zinc-700 whitespace-pre-line bg-zinc-50 rounded p-3 border border-zinc-200">{{ disclaimerText }}</div>
    </div>

    <!-- Unterschriftsname + Pad -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="flex items-center justify-between">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Unterschrift Eigentümer</div>
        <div v-if="form.signature_data_url" class="text-[11px] text-green-700">✓ unterschrieben</div>
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Name (wie er unterschreibt) *</label>
        <input v-model="form.signed_by_name" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <SignaturePad v-model="form.signature_data_url" />
      <p class="text-[11px] text-muted-foreground">
        Mit der Unterschrift bestätigt der Eigentümer die Angaben und den Haftungsausschluss.
        Ein PDF wird erzeugt und per E-Mail zugesandt.
      </p>
    </div>

    <p class="text-[11px] text-center text-muted-foreground px-4">
      Nach „Absenden" wird ein Aufnahmeprotokoll-PDF generiert und per E-Mail an
      <strong>{{ form.owner?.email || '(keine E-Mail)' }}</strong> versendet.
    </p>

  </div>
</template>
