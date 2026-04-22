<script setup>
import { computed } from 'vue';
import SignaturePad from '../shared/SignaturePad.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { AlertCircle, Check, Mail } from 'lucide-vue-next';

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
const photoCount = computed(() => (props.form.photos || []).length);
const openFieldsCount = computed(() => (props.form.open_fields || []).length);
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Summary Card -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Zusammenfassung</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <span class="text-muted-foreground">Eigentümer:</span> <span class="font-medium">{{ ownerName }}</span>
          <span class="text-muted-foreground">Objekt:</span>     <span>{{ objectLine }}</span>
          <span class="text-muted-foreground">Adresse:</span>    <span>{{ addressLine }}</span>
          <span class="text-muted-foreground">Richtpreis:</span> <span class="font-semibold">{{ priceLine }}</span>
          <span class="text-muted-foreground">Fotos:</span>      <span>{{ photoCount }}</span>
          <span class="text-muted-foreground">Offene Felder:</span>
          <span :class="openFieldsCount > 0 ? 'text-amber-700 font-medium' : 'text-green-700 font-medium'">
            {{ openFieldsCount }} {{ openFieldsCount === 1 ? 'Feld' : 'Felder' }}
          </span>
        </div>
      </CardContent>
    </Card>

    <!-- Offene Felder Warnung -->
    <Card v-if="openFieldsCount > 0" class="border-amber-300 bg-amber-50">
      <CardContent class="p-3 flex items-start gap-2">
        <AlertCircle class="h-4 w-4 text-amber-700 shrink-0 mt-0.5" />
        <div class="text-sm text-amber-900">
          <strong>{{ openFieldsCount }} Feld(er) wurden übersprungen.</strong>
          Diese werden im PDF als „offen" markiert. Sie können den Eigentümer später per E-Mail zum Nachreichen auffordern.
        </div>
      </CardContent>
    </Card>

    <!-- Haftungsausschluss -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Haftungsausschluss</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="text-xs leading-relaxed text-zinc-700 whitespace-pre-line bg-zinc-50 rounded p-3 border border-zinc-200">{{ disclaimerText }}</div>
      </CardContent>
    </Card>

    <!-- Unterschriftsname + Pad -->
    <Card>
      <CardHeader class="pb-3">
        <div class="flex items-center justify-between">
          <CardTitle class="text-base">Unterschrift Eigentümer</CardTitle>
          <Badge v-if="form.signature_data_url" variant="secondary" class="bg-green-100 text-green-800 border-green-200">
            <Check class="h-3 w-3" /> unterschrieben
          </Badge>
        </div>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Name (wie er unterschreibt) <span class="text-red-500">*</span></label>
          <Input v-model="form.signed_by_name" class="h-11" />
        </div>
        <SignaturePad v-model="form.signature_data_url" />
        <p class="text-[11px] text-muted-foreground">
          Mit der Unterschrift bestätigt der Eigentümer die Angaben und den Haftungsausschluss.
        </p>
      </CardContent>
    </Card>

    <!-- Info: Mail kommt später -->
    <Card v-if="form.owner?.email" class="border-blue-200 bg-blue-50">
      <CardContent class="p-3 flex items-start gap-2">
        <Mail class="h-4 w-4 text-blue-700 shrink-0 mt-0.5" />
        <div class="text-xs text-blue-900">
          <strong>Die E-Mail an den Eigentümer wird nicht sofort versendet.</strong>
          Nach dem Absenden finden Sie in der Objekt-Übersicht einen Button „E-Mail an Eigentümer senden",
          wo Sie die Nachricht in Ruhe anpassen und versenden können.
        </div>
      </CardContent>
    </Card>
    <Card v-else class="border-amber-300 bg-amber-50">
      <CardContent class="p-3 flex items-start gap-2">
        <AlertCircle class="h-4 w-4 text-amber-700 shrink-0 mt-0.5" />
        <div class="text-xs text-amber-900">
          Keine E-Mail für Eigentümer hinterlegt — Sie können später im Property-Detail nachtragen und versenden.
        </div>
      </CardContent>
    </Card>

  </div>
</template>
