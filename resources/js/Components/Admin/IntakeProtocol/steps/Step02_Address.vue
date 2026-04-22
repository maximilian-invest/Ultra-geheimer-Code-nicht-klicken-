<script setup>
import { ref, inject, computed } from 'vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const suggestions = ref([]);
const showSuggestions = ref(false);
const loadingSuggestions = ref(false);
let debounceTimer = null;

function splitStreetNumber(value) {
  const str = String(value || '');
  const m = str.match(/^(.+?)[,\s]+(\d+[a-zA-Z]?(?:[-\/]\d+[a-zA-Z]?)?)\s*$/);
  if (m && m[1].trim().length >= 2) {
    return { street: m[1].trim(), houseNumber: m[2] };
  }
  return { street: str, houseNumber: null };
}

function onAddressInput(v) {
  const value = String(v || '');
  props.form.address = value;
  if (debounceTimer) clearTimeout(debounceTimer);
  if (value.trim().length < 3) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }
  debounceTimer = setTimeout(async () => {
    loadingSuggestions.value = true;
    try {
      const q = [value, props.form.zip, props.form.city].filter(Boolean).join(' ');
      const r = await fetch(API.value + '&action=geocode_autocomplete&q=' + encodeURIComponent(q));
      const d = await r.json();
      suggestions.value = Array.isArray(d.results) ? d.results : [];
      showSuggestions.value = suggestions.value.length > 0;
    } catch (e) {
      suggestions.value = [];
    }
    loadingSuggestions.value = false;
  }, 400);
}

function onAddressBlur() {
  setTimeout(() => { showSuggestions.value = false; }, 200);
  const { street, houseNumber } = splitStreetNumber(props.form.address);
  if (houseNumber) {
    props.form.address = street;
    if (!props.form.house_number) props.form.house_number = houseNumber;
  }
}

function pickSuggestion(s) {
  if (s.street) props.form.address = s.street;
  if (s.house_number) props.form.house_number = s.house_number;
  if (s.zip) props.form.zip = s.zip;
  if (s.city) props.form.city = s.city;
  if (s.lat != null) props.form.latitude = s.lat;
  if (s.lng != null) props.form.longitude = s.lng;
  suggestions.value = [];
  showSuggestions.value = false;
}

const isWohnung = computed(() => props.form.object_type === 'Wohnung');
const hasCoords = computed(() => props.form.latitude && props.form.longitude);
</script>

<template>
  <div class="p-4 space-y-4">

    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Adresse</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="relative space-y-1.5">
          <label class="text-sm font-medium block">
            Straße <span class="text-red-500">*</span>
          </label>
          <Input
            :model-value="form.address"
            @update:model-value="onAddressInput"
            @focus="showSuggestions = suggestions.length > 0"
            @blur="onAddressBlur"
            class="h-11"
            placeholder="Beim Tippen erscheinen Vorschläge"
            autocomplete="off"
          />
          <Card
            v-if="showSuggestions"
            class="absolute left-0 right-0 top-full mt-1 shadow-lg z-20 max-h-64 overflow-y-auto p-0"
          >
            <button
              v-for="(s, i) in suggestions" :key="i"
              type="button"
              @mousedown.prevent="pickSuggestion(s)"
              class="w-full text-left px-3 py-2 hover:bg-zinc-50 border-b border-border/40 last:border-b-0 text-xs"
            >
              <div class="font-medium">{{ s.street || s.display_name.split(',')[0] }} {{ s.house_number }}</div>
              <div class="text-muted-foreground">{{ [s.zip, s.city].filter(Boolean).join(' ') }}</div>
            </button>
          </Card>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium block">Hausnr. <span class="text-red-500">*</span></label>
            <Input v-model="form.house_number" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium block">PLZ <span class="text-red-500">*</span></label>
            <Input v-model="form.zip" inputmode="numeric" class="h-11" />
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="text-sm font-medium block">Stadt <span class="text-red-500">*</span></label>
          <Input v-model="form.city" class="h-11" />
        </div>

        <div v-if="isWohnung" class="grid grid-cols-3 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium block">Stiege</label>
            <Input v-model="form.staircase" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium block">Tür</label>
            <Input v-model="form.door" class="h-11" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium block">Etage</label>
            <Input v-model="form.address_floor" inputmode="numeric" class="h-11" />
          </div>
        </div>
      </CardContent>
    </Card>

    <Card v-if="hasCoords" class="overflow-hidden">
      <div style="height:240px">
        <iframe
          :src="`https://www.openstreetmap.org/export/embed.html?bbox=${Number(form.longitude)-0.008}%2C${Number(form.latitude)-0.006}%2C${Number(form.longitude)+0.008}%2C${Number(form.latitude)+0.006}&layer=mapnik&marker=${form.latitude}%2C${form.longitude}`"
          width="100%" height="240" frameborder="0" style="border:0" loading="lazy"
        ></iframe>
      </div>
    </Card>

  </div>
</template>
