<script setup>
import { ref, inject, watch } from 'vue';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const suggestions = ref([]);
const showSuggestions = ref(false);
let debounce = null;

// Wenn der Nutzer im Namen-Feld tippt: Vorschlaege holen, aber den Namen
// SOFORT in form.owner.name uebernehmen. Kein „Neuer-Eigentuemer"-Zwischenschritt mehr.
async function onNameInput() {
  const q = (props.form.owner.name || '').trim();

  // Wenn der User den Namen manuell aendert, verlieren wir die customer-id.
  // (Sonst wuerde der Submit denken, wir haben einen bestehenden Kontakt.)
  if (props.form.owner_customer_id) {
    props.form.owner_customer_id = null;
  }

  if (q.length < 2) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }

  if (debounce) clearTimeout(debounce);
  debounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=contacts&search=' + encodeURIComponent(q));
      const d = await r.json();
      suggestions.value = (d.contacts || []).slice(0, 5);
      showSuggestions.value = suggestions.value.length > 0;
    } catch (e) {
      suggestions.value = [];
      showSuggestions.value = false;
    }
  }, 300);
}

function pickContact(c) {
  // Uebernimmt name/email/phone aus dem bestehenden Kontakt.
  // Adresse wird nicht ueberschrieben falls schon eingetragen.
  props.form.owner.name  = c.full_name || '';
  props.form.owner.email = c.email || props.form.owner.email || '';
  props.form.owner.phone = c.phone || props.form.owner.phone || '';
  props.form.owner_customer_id = c.id || null;
  suggestions.value = [];
  showSuggestions.value = false;
}

// Close suggestions on blur (mit kleiner Verzoegerung damit click auf Vorschlag durchgeht)
function onNameBlur() {
  setTimeout(() => { showSuggestions.value = false; }, 200);
}

// Wenn der User ueber die URL / Auto-Save einen existierenden owner_customer_id
// geladen hat, sollte das Namensfeld nicht mehr Vorschlaege triggern.
watch(() => props.form.owner_customer_id, (v) => {
  if (v) showSuggestions.value = false;
});
</script>

<template>
  <div class="space-y-3">

    <!-- Badge oben: wenn bestehender Kontakt verknuepft ist -->
    <div v-if="form.owner_customer_id"
         class="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-xs text-green-900">
      <span class="text-base">✓</span>
      <span>Bestehender Kontakt ausgewählt — Änderungen lösen die Verknüpfung auf.</span>
    </div>

    <!-- Name (immer sichtbar, treibt Autocomplete) -->
    <div class="relative">
      <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
        Name des Eigentümers <span class="text-red-500">*</span>
      </label>
      <input
        v-model="form.owner.name"
        @input="onNameInput"
        @focus="onNameInput"
        @blur="onNameBlur"
        placeholder="Vor- und Nachname"
        autocomplete="off"
        class="w-full h-11 rounded-lg border border-border px-3 bg-white"
      />
      <!-- Autocomplete-Panel (nur wenn es Vorschlaege gibt) -->
      <div
        v-if="showSuggestions && suggestions.length"
        class="absolute left-0 right-0 top-full mt-1 bg-white border border-border rounded-lg shadow-lg z-20 max-h-64 overflow-y-auto"
      >
        <button
          v-for="c in suggestions" :key="c.id"
          type="button"
          @mousedown.prevent="pickContact(c)"
          class="w-full text-left px-3 py-2 hover:bg-zinc-50 border-b border-zinc-100 last:border-b-0"
        >
          <div class="text-sm font-medium">{{ c.full_name }}</div>
          <div class="text-[11px] text-muted-foreground">
            {{ [c.email, c.phone].filter(Boolean).join(' · ') }}
          </div>
        </button>
        <div class="px-3 py-2 text-[11px] text-muted-foreground border-t border-zinc-100 bg-zinc-50">
          💡 Auswählen übernimmt E-Mail/Telefon. Oder einfach weiter tippen für neuen Eigentümer.
        </div>
      </div>
    </div>

    <!-- Email + Phone (immer sichtbar, optional, aber E-Mail empfohlen fuers PDF) -->
    <div class="grid grid-cols-1 gap-3">
      <div>
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
          E-Mail <span class="text-[10px] normal-case text-muted-foreground">(für PDF-Versand empfohlen)</span>
        </label>
        <input
          v-model="form.owner.email"
          type="email"
          placeholder="name@example.com"
          autocomplete="off"
          class="w-full h-11 rounded-lg border border-border px-3 bg-white"
        />
      </div>
      <div>
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
          Telefon
        </label>
        <input
          v-model="form.owner.phone"
          type="tel"
          inputmode="tel"
          placeholder="+43 …"
          autocomplete="off"
          class="w-full h-11 rounded-lg border border-border px-3 bg-white"
        />
      </div>
    </div>

    <!-- Wohnsitz (optional, zusammenklappbar) -->
    <details class="bg-white border border-border rounded-xl p-3">
      <summary class="text-xs font-medium text-muted-foreground cursor-pointer select-none">
        Wohnsitz-Adresse <span class="text-[10px]">(optional — nur falls abweichend vom Objekt)</span>
      </summary>
      <div class="mt-3 space-y-2">
        <input
          v-model="form.owner.address"
          placeholder="Straße + Hausnr."
          class="w-full h-10 rounded-lg border border-border px-3"
        />
        <div class="grid grid-cols-[1fr_2fr] gap-2">
          <input v-model="form.owner.zip" placeholder="PLZ" inputmode="numeric"
                 class="h-10 rounded-lg border border-border px-3" />
          <input v-model="form.owner.city" placeholder="Stadt"
                 class="h-10 rounded-lg border border-border px-3" />
        </div>
      </div>
    </details>

  </div>
</template>
