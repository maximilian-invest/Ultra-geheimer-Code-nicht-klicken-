<script setup>
import { computed } from 'vue';
import SignaturePad from '../shared/SignaturePad.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
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
      <CardHeader>
        <CardTitle>Zusammenfassung</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <span class="text-muted-foreground">Eigentümer:</span> <span class="font-medium">{{ ownerName }}</span>
          <span class="text-muted-foreground">Objekt:</span>     <span>{{ objectLine }}</span>
          <span class="text-muted-foreground">Adresse:</span>    <span>{{ addressLine }}</span>
          <span class="text-muted-foreground">Richtpreis:</span> <span class="font-semibold">{{ priceLine }}</span>
          <span class="text-muted-foreground">Fotos:</span>      <span>{{ photoCount }}</span>
          <span class="text-muted-foreground">Offene Felder:</span>
          <span class="font-medium">
            {{ openFieldsCount }} {{ openFieldsCount === 1 ? 'Feld' : 'Felder' }}
          </span>
        </div>
      </CardContent>
    </Card>

    <!-- Offene Felder Warnung -->
    <Alert v-if="openFieldsCount > 0" variant="warning">
      <AlertCircle class="size-4" />
      <AlertTitle>{{ openFieldsCount }} Feld(er) wurden übersprungen</AlertTitle>
      <AlertDescription>
        Diese werden im PDF als „offen" markiert. Sie können den Eigentümer später per E-Mail zum Nachreichen auffordern.
      </AlertDescription>
    </Alert>

    <!-- Haftungsausschluss -->
    <Card>
      <CardHeader>
        <CardTitle>Haftungsausschluss</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="text-xs leading-relaxed whitespace-pre-line bg-muted rounded-md p-3">{{ disclaimerText }}</div>
      </CardContent>
    </Card>

    <!-- Unterschriftsname + Pad -->
    <Card>
      <CardHeader>
        <div class="flex items-center justify-between">
          <CardTitle>Unterschrift Eigentümer</CardTitle>
          <Badge v-if="form.signature_data_url" variant="secondary">
            <Check class="h-3 w-3" /> unterschrieben
          </Badge>
        </div>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-2">
          <Label for="signed-name">Name (wie er unterschreibt) <span class="text-destructive">*</span></Label>
          <Input id="signed-name" v-model="form.signed_by_name" />
        </div>
        <SignaturePad v-model="form.signature_data_url" />
        <p class="text-xs text-muted-foreground">
          Mit der Unterschrift bestätigt der Eigentümer die Angaben und den Haftungsausschluss.
        </p>
      </CardContent>
    </Card>

    <!-- Info: Mail kommt später -->
    <Alert v-if="form.owner?.email" variant="info">
      <Mail class="size-4" />
      <AlertTitle>Die E-Mail an den Eigentümer wird nicht sofort versendet</AlertTitle>
      <AlertDescription>
        Nach dem Absenden finden Sie in der Objekt-Übersicht einen Button „E-Mail an Eigentümer senden",
        wo Sie die Nachricht in Ruhe anpassen und versenden können.
      </AlertDescription>
    </Alert>
    <Alert v-else variant="warning">
      <AlertCircle class="size-4" />
      <AlertDescription>
        Keine E-Mail für Eigentümer hinterlegt — Sie können später im Property-Detail nachtragen und versenden.
      </AlertDescription>
    </Alert>

  </div>
</template>
