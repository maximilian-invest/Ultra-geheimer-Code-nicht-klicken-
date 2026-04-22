<script setup>
defineProps({ form: Object });
</script>

<template>
  <div class="p-4 space-y-4">
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Wohnfläche m² *</label>
        <input v-model="form.living_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Zimmer *</label>
        <input v-model="form.rooms_amount" type="number" step="0.5" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Haus' || form.object_type === 'Grundstück'">
        <label class="text-[11px] text-muted-foreground block mb-1">Grundstücksfläche m²</label>
        <input v-model="form.free_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Gewerbe'">
        <label class="text-[11px] text-muted-foreground block mb-1">Nutzfläche m²</label>
        <input v-model="form.realty_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Schlafzimmer</label>
        <input v-model="form.bedrooms" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Badezimmer</label>
        <input v-model="form.bathrooms" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">WC</label>
        <input v-model="form.toilets" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Haus'">
        <label class="text-[11px] text-muted-foreground block mb-1">Stockwerke</label>
        <input v-model="form.floor_count" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Wohnung'">
        <label class="text-[11px] text-muted-foreground block mb-1">Etage</label>
        <input v-model="form.floor_number" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div class="col-span-2">
        <label class="text-[11px] text-muted-foreground block mb-1">Baujahr *</label>
        <input v-model="form.construction_year" type="number" inputmode="numeric" placeholder="YYYY" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
    </div>
  </div>
</template>
