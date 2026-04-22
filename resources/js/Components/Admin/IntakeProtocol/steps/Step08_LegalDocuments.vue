<script setup>
import { computed, inject, ref } from 'vue';
import DocumentChecklistItem from '../shared/DocumentChecklistItem.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus } from 'lucide-vue-next';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const API = inject('API');

const DOCS = [
  { key: 'grundbuchauszug', label: 'Grundbuchauszug' },
  { key: 'energieausweis', label: 'Energieausweis' },
  { key: 'plaene', label: 'Grundrisse / Pläne' },
  { key: 'nutzwertgutachten', label: 'Nutzwertgutachten' },
  { key: 'ruecklagenstand', label: 'Rücklagenstand' },
  { key: 'wohnungseigentumsvertrag', label: 'Wohnungseigentumsvertrag' },
  { key: 'hausordnung', label: 'Hausordnung' },
  { key: 'letzte_jahresabrechnung', label: 'Letzte Jahresabrechnung' },
  { key: 'betriebskostenabrechnung', label: 'Betriebskostenabrechnung' },
  { key: 'schaetzwert_gutachten', label: 'Schätzwert-Gutachten' },
  { key: 'baubewilligung', label: 'Baubewilligung' },
  { key: 'mietvertrag', label: 'Mietvertrag' },
  { key: 'hypothekenvertrag', label: 'Hypothekenvertrag' },
];

function setDocStatus(key, status) {
  if (!props.form.documents_available) props.form.documents_available = {};
  props.form.documents_available[key] = status;
}

function getDocStatus(key) {
  return props.form.documents_available?.[key] || '';
}

const availableCount = computed(() =>
  Object.values(props.form.documents_available || {}).filter(v => v === 'available').length
);

const hvSearch = ref('');
const hvResults = ref([]);
const hvShowNewForm = ref(false);
const hvNewForm = ref({ company_name: '', contact_person: '', email: '', phone: '' });
let hvDebounce = null;

async function searchHv(q) {
  if (q.length < 2) { hvResults.value = []; return; }
  if (hvDebounce) clearTimeout(hvDebounce);
  hvDebounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=list_property_managers&search=' + encodeURIComponent(q));
      const d = await r.json();
      hvResults.value = (d.managers || []).slice(0, 5);
    } catch (e) { hvResults.value = []; }
  }, 300);
}

function pickHv(h) {
  props.form.property_manager_id = h.id;
  hvSearch.value = h.company_name;
  hvResults.value = [];
}

async function createHv() {
  const r = await fetch(API.value + '&action=create_property_manager', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(hvNewForm.value),
  });
  const d = await r.json();
  if (d.success && d.id) {
    props.form.property_manager_id = d.id;
    hvSearch.value = hvNewForm.value.company_name;
    hvShowNewForm.value = false;
  }
}

const approvalsNotesSkipped = computed({
  get: () => props.isSkipped('approvals_notes'),
  set: (v) => v ? props.markSkipped('approvals_notes') : props.unmarkSkipped('approvals_notes'),
});
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Hausverwaltung -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Hausverwaltung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input
          v-model="hvSearch"
          @update:model-value="searchHv(hvSearch)"
          placeholder="Hausverwaltung suchen..."
          class="h-11"
        />
        <Card v-if="hvResults.length" class="divide-y divide-border/40 p-0">
          <button
            v-for="h in hvResults" :key="h.id"
            type="button"
            @click="pickHv(h)"
            class="w-full text-left px-3 py-2 hover:bg-zinc-50"
          >
            <div class="text-sm font-medium">{{ h.company_name }}</div>
            <div class="text-xs text-muted-foreground">{{ h.contact_person }} · {{ h.email }}</div>
          </button>
        </Card>
        <Button
          v-if="!hvShowNewForm"
          variant="ghost"
          size="sm"
          class="text-primary"
          @click="hvShowNewForm = true"
        >
          <Plus class="h-4 w-4" />
          Neue Hausverwaltung
        </Button>
        <Card v-if="hvShowNewForm" class="bg-zinc-50">
          <CardContent class="p-3 space-y-2">
            <Input v-model="hvNewForm.company_name" placeholder="Firma *" class="h-10" />
            <Input v-model="hvNewForm.contact_person" placeholder="Ansprechpartner" class="h-10" />
            <Input v-model="hvNewForm.email" type="email" placeholder="E-Mail" class="h-10" />
            <Input v-model="hvNewForm.phone" placeholder="Telefon" class="h-10" />
            <Button class="w-full h-10" @click="createHv">Anlegen</Button>
          </CardContent>
        </Card>
      </CardContent>
    </Card>

    <!-- Belastungen / Rechte -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Belastungen / Rechte</CardTitle>
      </CardHeader>
      <CardContent>
        <Textarea
          v-model="form.encumbrances"
          rows="3"
          placeholder="Pfandrechte, Wohnrechte, Dienstbarkeiten ..."
        />
      </CardContent>
    </Card>

    <!-- Bewilligungen -->
    <Card class="border-l-4 border-l-primary">
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Bewilligungen</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="text-sm">Sind alle Baumaßnahmen bewilligt?</div>
        <div class="grid grid-cols-3 gap-2">
          <button type="button"
                  @click="form.approvals_status = 'complete'; form.approvals_notes = ''"
                  :class="[
                    'rounded-xl p-3 text-center border transition-colors',
                    form.approvals_status === 'complete'
                      ? 'bg-green-50 border-2 border-green-600'
                      : 'bg-white border-border hover:border-green-300'
                  ]">
            <div class="text-xl">✓</div>
            <div class="text-[11px] font-medium mt-0.5">Alles bewilligt</div>
          </button>
          <button type="button"
                  @click="form.approvals_status = 'partial'"
                  :class="[
                    'rounded-xl p-3 text-center border transition-colors',
                    form.approvals_status === 'partial'
                      ? 'bg-amber-50 border-2 border-amber-500'
                      : 'bg-white border-border hover:border-amber-300'
                  ]">
            <div class="text-xl">⚠️</div>
            <div class="text-[11px] font-medium mt-0.5">Teilweise</div>
          </button>
          <button type="button"
                  @click="form.approvals_status = 'unknown'"
                  :class="[
                    'rounded-xl p-3 text-center border transition-colors',
                    form.approvals_status === 'unknown'
                      ? 'bg-zinc-100 border-2 border-zinc-600'
                      : 'bg-white border-border hover:border-zinc-300'
                  ]">
            <div class="text-xl">❓</div>
            <div class="text-[11px] font-medium mt-0.5">Unbekannt</div>
          </button>
        </div>

        <Card
          v-if="['partial','unknown'].includes(form.approvals_status)"
          :class="form.approvals_status === 'partial' ? 'bg-amber-50 border-amber-200' : 'bg-zinc-100 border-zinc-300'"
        >
          <CardContent class="p-3 space-y-2">
            <div class="flex items-center justify-between">
              <label class="text-xs font-semibold">
                <span v-if="form.approvals_status === 'partial'">Welche Bewilligung fehlt wofür? *</span>
                <span v-else>Was ist unklar und muss geprüft werden? *</span>
              </label>
              <SkipFieldSwitch v-model="approvalsNotesSkipped" />
            </div>
            <Textarea
              v-model="form.approvals_notes"
              rows="3"
              :placeholder="form.approvals_status === 'partial' ? 'Terrasse: nicht bewilligt\nDachbodenausbau: nicht im Grundbuch eingetragen' : 'Eigentümer weiß nicht ob Anbau bewilligt'"
            />
          </CardContent>
        </Card>
      </CardContent>
    </Card>

    <!-- Dokumenten-Checkliste -->
    <Card>
      <CardHeader class="pb-3">
        <div class="flex items-center justify-between">
          <CardTitle class="text-base">Dokumenten-Checkliste</CardTitle>
          <Badge variant="secondary">{{ availableCount }} / {{ DOCS.length }} vorhanden</Badge>
        </div>
      </CardHeader>
      <CardContent class="space-y-1.5">
        <DocumentChecklistItem
          v-for="d in DOCS" :key="d.key"
          :doc-key="d.key"
          :label="d.label"
          :model-value="getDocStatus(d.key)"
          @update:model-value="setDocStatus(d.key, $event)"
        />
      </CardContent>
    </Card>

  </div>
</template>
