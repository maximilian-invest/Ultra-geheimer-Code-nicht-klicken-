<script setup>
import OwnerPicker from '../shared/OwnerPicker.vue';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { AlertCircle } from 'lucide-vue-next';

defineProps({
  form: { type: Object, required: true },
});
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Eigentümer</CardTitle>
        <CardDescription>
          Kontaktdaten. Bestehende Kontakte werden bei Namenseingabe vorgeschlagen.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <OwnerPicker :form="form" />
      </CardContent>
    </Card>

    <Card class="border-primary/30 bg-primary/5">
      <CardContent class="p-4">
        <div class="flex items-start gap-3">
          <Switch
            :model-value="form.portal_access_granted"
            @update:model-value="form.portal_access_granted = $event"
            class="mt-0.5"
          />
          <div class="flex-1">
            <div class="text-sm font-semibold">Eigentümer bekommt Portalzugang</div>
            <div class="text-xs text-muted-foreground mt-1">
              Er erhält eine separate E-Mail mit Login-Daten zum Kundenportal
              (kundenportal.sr-homes.at). Portal zeigt Aktivitäten, Dokumente,
              Interessenten-Anfragen zu seinem Objekt.
            </div>
          </div>
        </div>
      </CardContent>
    </Card>

    <Card v-if="form.portal_access_granted && !form.owner.email" class="border-red-300 bg-red-50">
      <CardContent class="p-3 flex items-start gap-2 text-xs text-red-700">
        <AlertCircle class="h-4 w-4 shrink-0 mt-0.5" />
        <span>Ohne E-Mail kann kein Portalzugang angelegt werden. Bitte E-Mail eintragen.</span>
      </CardContent>
    </Card>

  </div>
</template>
