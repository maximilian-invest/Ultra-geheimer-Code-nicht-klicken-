<script setup>
import { reactive, computed, watch } from 'vue';
import PillRow from '../shared/PillRow.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Plus, X } from 'lucide-vue-next';

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
  <div class="p-4 space-y-4">

    <!-- Zustand -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Zustand &amp; Qualität</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Zustand <span class="text-red-500">*</span></label>
          <PillRow v-model="form.realty_condition" :options="CONDITIONS.map(c => ({value: c, label: c.charAt(0).toUpperCase() + c.substring(1)}))" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Bauart</label>
          <PillRow v-model="form.construction_type" :options="CONSTRUCTION_TYPES" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Qualität</label>
          <PillRow v-model="form.quality" :options="[
            {value:'einfach', label:'Einfach'},
            {value:'normal', label:'Normal'},
            {value:'gehoben', label:'Gehoben'},
            {value:'luxurioes', label:'Luxuriös'},
          ]" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Eigentumsform</label>
          <PillRow v-model="form.ownership_type" :options="[
            {value:'wohnungseigentum', label:'Wohnungseigentum'},
            {value:'baurecht', label:'Baurecht'},
            {value:'pacht', label:'Pacht'},
          ]" />
        </div>
        <div v-if="form.marketing_type === 'miete'" class="space-y-1.5">
          <label class="text-sm font-medium">Möblierung</label>
          <PillRow v-model="form.furnishing" :options="[
            {value:'unfurnished', label:'Unmöbliert'},
            {value:'partially', label:'Teilmöbliert'},
            {value:'fully', label:'Vollmöbliert'},
          ]" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-medium">Anmerkung zum Zustand</label>
          <Textarea v-model="form.condition_note" rows="2" />
        </div>
      </CardContent>
    </Card>

    <!-- Sanierungen -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Sanierungen</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div v-if="addedCategories.length === 0" class="text-xs text-muted-foreground bg-zinc-50 rounded-lg p-3">
          Tippe auf eine Kategorie unten um eine Sanierung anzulegen.
        </div>

        <div v-for="c in addedCategories" :key="c.key" class="bg-zinc-50 rounded-lg p-3 space-y-1.5">
          <div class="flex gap-2 items-center">
            <div class="flex-1 text-sm font-medium">{{ c.label }}</div>
            <Input
              v-if="c.hasYear"
              v-model="inputs[c.key].year" type="number" placeholder="Jahr"
              inputmode="numeric"
              class="w-20 h-9 text-right"
            />
            <Button
              variant="outline"
              size="icon-sm"
              class="text-red-500 hover:text-red-600 hover:bg-red-50"
              @click="removeCategory(c.key)"
              aria-label="Entfernen"
            >
              <X class="h-4 w-4" />
            </Button>
          </div>
          <Input
            v-model="inputs[c.key].note"
            placeholder="Notiz (z.B. 3-fach verglast)"
            class="h-9 text-xs"
          />
        </div>

        <div v-if="availableCategories.length" class="space-y-1.5">
          <div class="text-xs text-muted-foreground">Kategorien hinzufügen:</div>
          <div class="flex flex-wrap gap-1.5">
            <Button
              v-for="c in availableCategories" :key="c.key"
              variant="outline"
              size="sm"
              class="rounded-full border-dashed border-primary/40 text-primary hover:bg-primary/5 h-7 px-2.5 text-[11px]"
              @click="addCategory(c.key)"
            >
              <Plus class="h-3 w-3" />
              {{ c.label }}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>

  </div>
</template>
