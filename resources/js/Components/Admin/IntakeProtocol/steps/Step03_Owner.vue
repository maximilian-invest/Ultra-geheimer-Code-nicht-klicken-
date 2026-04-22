<script setup>
import OwnerPicker from '../shared/OwnerPicker.vue';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle } from 'lucide-vue-next';

defineProps({
  form: { type: Object, required: true },
});
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader>
        <CardTitle>Eigentümer</CardTitle>
        <CardDescription>
          Kontaktdaten. Bestehende Kontakte werden bei Namenseingabe vorgeschlagen.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <OwnerPicker :form="form" />
      </CardContent>
    </Card>

    <Card>
      <CardContent class="pt-6">
        <div class="flex items-start gap-3">
          <Switch
            id="portal-access"
            :model-value="form.portal_access_granted"
            @update:model-value="form.portal_access_granted = $event"
            class="mt-0.5"
          />
          <div class="flex-1 space-y-1">
            <Label for="portal-access" class="cursor-pointer">Eigentümer bekommt Portalzugang</Label>
            <p class="text-xs text-muted-foreground">
              Er erhält eine separate E-Mail mit Login-Daten zum Kundenportal
              (kundenportal.sr-homes.at). Portal zeigt Aktivitäten, Dokumente,
              Interessenten-Anfragen zu seinem Objekt.
            </p>
          </div>
        </div>
      </CardContent>
    </Card>

    <Alert v-if="form.portal_access_granted && !form.owner.email" variant="destructive">
      <AlertCircle class="size-4" />
      <AlertDescription>
        Ohne E-Mail kann kein Portalzugang angelegt werden. Bitte E-Mail eintragen.
      </AlertDescription>
    </Alert>

  </div>
</template>
