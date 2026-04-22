<script setup>
import OwnerPicker from '../shared/OwnerPicker.vue';

defineProps({
  form: { type: Object, required: true },
});
</script>

<template>
  <div class="p-4 space-y-5">

    <OwnerPicker :form="form" />

    <div class="bg-orange-50 border border-[#EE7600]/30 rounded-xl p-4">
      <label class="flex items-start gap-3 cursor-pointer">
        <input
          type="checkbox"
          v-model="form.portal_access_granted"
          class="mt-0.5 w-5 h-5 accent-[#EE7600]"
        />
        <div>
          <div class="text-sm font-semibold">Eigentümer bekommt Portalzugang</div>
          <div class="text-xs text-muted-foreground mt-1">
            Er erhält eine separate E-Mail mit Login-Daten zum Kundenportal
            (kundenportal.sr-homes.at). Portal zeigt Aktivitäten, Dokumente,
            Interessenten-Anfragen zu seinem Objekt.
          </div>
        </div>
      </label>
    </div>

    <div v-if="form.portal_access_granted && !form.owner.email" class="text-xs text-red-600 bg-red-50 p-3 rounded-lg">
      ⚠ Ohne E-Mail kann kein Portalzugang angelegt werden. Bitte E-Mail eintragen.
    </div>

  </div>
</template>
