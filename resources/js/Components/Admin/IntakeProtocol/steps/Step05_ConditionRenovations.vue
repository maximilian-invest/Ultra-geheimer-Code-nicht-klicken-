<script setup>
import { reactive, computed, watch } from 'vue';
import PillRow from '../shared/PillRow.vue';

const props = defineProps({ form: Object });

const CONDITIONS = ['neuwertig', 'gebraucht', 'saniert', 'kernsaniert', 'renoviert', 'erstbezug', 'abbruchreif'];
const CONSTRUCTION_TYPES = ['Massiv', 'Holz', 'Fertigteil', 'Mischbauweise'];

const SAN_CATEGORIES = [
  { key: 'general',     label: 'Generalsanierung',        hasYear: true },
  { key: 'windows',     label: 'Fenster',                 hasYear: true },
  { key: 'doors',       label: 'Türen',                   hasYear: true },
  { key: 'floors',      label: 'Fußböden',                hasYear: true },
  { key: 'heating',     label: 'Heizung',                 hasYear: true },
  { key: 'pipes',       label: 'Leitungssystem',          hasYear: true },
  { key: 'connections', label: 'Anschlüsse',              hasYear: true },
  { key: 'facade',      label: 'Fassade',                 hasYear: true },
  { key: 'bathrooms',   label: 'Bäder',                   hasYear: true },
  { key: 'kitchen',     label: 'Küche',                   hasYear: true },
  { key: 'other',       label: 'Sonstige Sanierungen',    hasYear: true },
  { key: 'required',    label: 'Erforderliche Maßnahmen', hasYear: false },
];

// Per-Kategorie reaktive Eingaben. `added`-Flag = User hat die Kategorie explizit
// eingeblendet (kein Leerstring-Trick mehr, der beim watch/trim verloren ging).
const inputs = reactive({});
for (const c of SAN_CATEGORIES) inputs[c.key] = { year: '', note: '', added: false };

// Initialisiere aus form.property_history falls vorhanden
if (Array.isArray(props.form.property_history)) {
  for (const entry of props.form.property_history) {
    const key = entry.category;
    if (inputs[key]) {
      inputs[key] = {
        year: entry.year != null ? String(entry.year) : '',
        note: String(entry.description ?? ''),
        added: true,
      };
    }
  }
}

// Sync → form.property_history: nur Einträge, die added=true sind.
watch(inputs, () => {
  const out = [];
  for (const c of SAN_CATEGORIES) {
    const v = inputs[c.key];
    if (!v.added) continue;
    const year = String(v.year || '').trim();
    const note = String(v.note || '').trim();
    out.push({
      category: c.key,
      title: c.label,
      year: year ? parseInt(year) : null,
      description: note,
    });
  }
  props.form.property_history = out;
}, { deep: true });

const addedCategories = computed(() => SAN_CATEGORIES.filter(c => inputs[c.key].added));
const availableCategories = computed(() => SAN_CATEGORIES.filter(c => !inputs[c.key].added));

function addCategory(key) {
  inputs[key].added = true;
}

function removeCategory(key) {
  inputs[key].added = false;
  inputs[key].year = '';
  inputs[key].note = '';
}
</script>

<template>
  <div class="p-4 space-y-5">

    <!-- Zustand -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Zustand & Qualität</div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Zustand *</div>
        <PillRow v-model="form.realty_condition" :options="CONDITIONS.map(c => ({value: c, label: c.charAt(0).toUpperCase() + c.substring(1)}))" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Bauart</div>
        <PillRow v-model="form.construction_type" :options="CONSTRUCTION_TYPES" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Qualität</div>
        <PillRow v-model="form.quality" :options="[
          {value:'einfach', label:'Einfach'},
          {value:'normal', label:'Normal'},
          {value:'gehoben', label:'Gehoben'},
          {value:'luxurioes', label:'Luxuriös'},
        ]" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Eigentumsform</div>
        <PillRow v-model="form.ownership_type" :options="[
          {value:'wohnungseigentum', label:'Wohnungseigentum'},
          {value:'baurecht', label:'Baurecht'},
          {value:'pacht', label:'Pacht'},
        ]" />
      </div>
      <div v-if="form.marketing_type === 'miete'">
        <div class="text-xs text-muted-foreground mb-1">Möblierung</div>
        <PillRow v-model="form.furnishing" :options="[
          {value:'unfurnished', label:'Unmöbliert'},
          {value:'partially', label:'Teilmöbliert'},
          {value:'fully', label:'Vollmöbliert'},
        ]" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Anmerkung zum Zustand</label>
        <textarea v-model="form.condition_note" rows="2" class="w-full rounded-lg border border-border p-2 text-sm"></textarea>
      </div>
    </div>

    <!-- Sanierungen -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Sanierungen</div>

      <div v-if="addedCategories.length === 0" class="text-[12px] text-muted-foreground bg-zinc-50 rounded-lg p-3">
        💡 Tippe auf eine Kategorie unten um eine Sanierung anzulegen.
      </div>

      <div v-for="c in addedCategories" :key="c.key" class="bg-zinc-50 rounded-lg p-3">
        <div class="flex gap-2 items-center mb-1.5">
          <div class="flex-1 text-sm font-medium">{{ c.label }}</div>
          <input
            v-if="c.hasYear"
            v-model="inputs[c.key].year" type="number" placeholder="Jahr"
            inputmode="numeric"
            class="w-20 h-9 rounded-md border border-border px-2 text-sm text-right"
          />
          <button type="button" @click="removeCategory(c.key)"
                  class="w-8 h-8 rounded-md bg-white border border-border text-red-500 text-sm hover:bg-red-50"
                  title="Entfernen">×</button>
        </div>
        <input
          v-model="inputs[c.key].note"
          placeholder="Notiz (z.B. 3-fach verglast)"
          class="w-full h-9 rounded-md border border-border px-2 text-xs"
        />
      </div>

      <div v-if="availableCategories.length">
        <div class="text-[11px] text-muted-foreground mb-1.5">Kategorien hinzufügen:</div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="c in availableCategories" :key="c.key" type="button" @click="addCategory(c.key)"
                  class="bg-white border border-dashed border-[#EE7600]/40 text-[#EE7600] text-[11px] rounded-full px-2.5 py-1 hover:bg-[#EE7600]/5 active:scale-95 transition">
            + {{ c.label }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
