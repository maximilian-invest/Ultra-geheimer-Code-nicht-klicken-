<script setup>
import { reactive, computed, watch } from 'vue';
import PillRow from '../shared/PillRow.vue';

const props = defineProps({ form: Object });

const CONDITIONS = ['neuwertig', 'gebraucht', 'saniert', 'kernsaniert', 'renoviert', 'erstbezug', 'abbruchreif'];
const CONSTRUCTION_TYPES = ['Massiv', 'Holz', 'Fertigteil', 'Mischbauweise'];

const SAN_CATEGORIES = [
  { key: 'general',   label: 'Generalsanierung',   hasYear: true },
  { key: 'windows',   label: 'Fenster',            hasYear: true },
  { key: 'doors',     label: 'Türen',              hasYear: true },
  { key: 'floors',    label: 'Fußböden',           hasYear: true },
  { key: 'heating',   label: 'Heizung',            hasYear: true },
  { key: 'pipes',     label: 'Leitungssystem',     hasYear: true },
  { key: 'connections', label: 'Anschlüsse',        hasYear: true },
  { key: 'facade',    label: 'Fassade',            hasYear: true },
  { key: 'bathrooms', label: 'Bäder',              hasYear: true },
  { key: 'kitchen',   label: 'Küche',              hasYear: true },
  { key: 'other',     label: 'Sonstige Sanierungen', hasYear: true },
  { key: 'required',  label: 'Erforderliche Maßnahmen', hasYear: false },
];

const inputs = reactive({});
for (const c of SAN_CATEGORIES) inputs[c.key] = { year: '', note: '' };

if (Array.isArray(props.form.property_history)) {
  for (const entry of props.form.property_history) {
    const key = entry.category;
    if (inputs[key]) inputs[key] = { year: String(entry.year ?? ''), note: String(entry.description ?? '') };
  }
}

watch(inputs, () => {
  const out = [];
  for (const c of SAN_CATEGORIES) {
    const v = inputs[c.key];
    const year = String(v.year || '').trim();
    const note = String(v.note || '').trim();
    if (year === '' && note === '') continue;
    out.push({ category: c.key, title: c.label, year: year ? parseInt(year) : null, description: note });
  }
  props.form.property_history = out;
}, { deep: true });

const addedCategories = computed(() =>
  SAN_CATEGORIES.filter(c => inputs[c.key].year.trim() !== '' || inputs[c.key].note.trim() !== '')
);
const availableCategories = computed(() =>
  SAN_CATEGORIES.filter(c => !addedCategories.value.includes(c))
);

function addCategory(key) {
  inputs[key].year = '';
  inputs[key].note = ' ';
}
</script>

<template>
  <div class="p-4 space-y-5">

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

    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Sanierungen</div>

      <div v-for="c in addedCategories" :key="c.key" class="bg-zinc-50 rounded-lg p-3">
        <div class="flex gap-2 items-center mb-1.5">
          <div class="flex-1 text-sm font-medium">{{ c.label }}</div>
          <input
            v-if="c.hasYear"
            v-model="inputs[c.key].year" type="number" placeholder="Jahr"
            inputmode="numeric"
            class="w-20 h-9 rounded-md border border-border px-2 text-sm text-right"
          />
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
                  class="bg-white border border-dashed border-border text-muted-foreground text-[11px] rounded-full px-2.5 py-1">
            + {{ c.label }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
