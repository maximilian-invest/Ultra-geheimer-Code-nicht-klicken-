<script setup>
import { computed, inject, ref } from 'vue';
import DocumentChecklistItem from '../shared/DocumentChecklistItem.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
      <CardHeader>
        <CardTitle>Hausverwaltung</CardTitle>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input
          v-model="hvSearch"
          @update:model-value="searchHv(hvSearch)"
          placeholder="Hausverwaltung suchen..."
        />
        <Card v-if="hvResults.length" class="p-0">
          <button
            v-for="h in hvResults" :key="h.id"
            type="button"
            @click="pickHv(h)"
            class="w-full text-left px-3 py-2 hover:bg-accent hover:text-accent-foreground transition-colors border-b last:border-b-0"
          >
            <div class="text-sm font-medium">{{ h.company_name }}</div>
            <div class="text-xs text-muted-foreground">{{ h.contact_person }} · {{ h.email }}</div>
          </button>
        </Card>
        <Button
          v-if="!hvShowNewForm"
          variant="ghost"
          size="sm"
          @click="hvShowNewForm = true"
        >
          <Plus class="h-4 w-4" />
          Neue Hausverwaltung
        </Button>
        <Card v-if="hvShowNewForm">
          <CardContent class="p-3 space-y-2">
            <Input v-model="hvNewForm.company_name" placeholder="Firma *" />
            <Input v-model="hvNewForm.contact_person" placeholder="Ansprechpartner" />
            <Input v-model="hvNewForm.email" type="email" placeholder="E-Mail" />
            <Input v-model="hvNewForm.phone" placeholder="Telefon" />
            <Button class="w-full" @click="createHv">Anlegen</Button>
          </CardContent>
        </Card>
      </CardContent>
    </Card>

    <!-- Belastungen / Rechte -->
    <Card>
      <CardHeader>
        <CardTitle>Belastungen / Rechte</CardTitle>
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
    <Card>
      <CardHeader>
        <CardTitle>Bewilligungen</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <p class="text-sm">Sind alle Baumaßnahmen bewilligt?</p>
        <div class="grid grid-cols-3 gap-2">
          <button type="button"
                  @click="form.approvals_status = 'complete'; form.approvals_notes = ''"
                  :class="[
                    'rounded-md p-3 text-center border-2 transition-all',
                    form.approvals_status === 'complete'
                      ? 'bg-primary text-primary-foreground border-primary shadow-md'
                      : 'bg-background border-input hover:bg-accent'
                  ]">
            <div class="text-xl">✓</div>
            <div class="text-xs font-medium mt-0.5">Alles bewilligt</div>
          </button>
          <button type="button"
                  @click="form.approvals_status = 'partial'"
                  :class="[
                    'rounded-md p-3 text-center border-2 transition-all',
                    form.approvals_status === 'partial'
                      ? 'bg-primary text-primary-foreground border-primary shadow-md'
                      : 'bg-background border-input hover:bg-accent'
                  ]">
            <div class="text-xl">⚠️</div>
            <div class="text-xs font-medium mt-0.5">Teilweise</div>
          </button>
          <button type="button"
                  @click="form.approvals_status = 'unknown'"
                  :class="[
                    'rounded-md p-3 text-center border-2 transition-all',
                    form.approvals_status === 'unknown'
                      ? 'bg-primary text-primary-foreground border-primary shadow-md'
                      : 'bg-background border-input hover:bg-accent'
                  ]">
            <div class="text-xl">❓</div>
            <div class="text-xs font-medium mt-0.5">Unbekannt</div>
          </button>
        </div>

        <Alert
          v-if="['partial','unknown'].includes(form.approvals_status)"
          :variant="form.approvals_status === 'partial' ? 'warning' : 'default'"
        >
          <AlertDescription>
            <div class="space-y-2">
              <div class="flex items-center justify-between">
                <Label>
                  <span v-if="form.approvals_status === 'partial'">Welche Bewilligung fehlt wofür? *</span>
                  <span v-else>Was ist unklar und muss geprüft werden? *</span>
                </Label>
                <SkipFieldSwitch v-model="approvalsNotesSkipped" />
              </div>
              <Textarea
                v-model="form.approvals_notes"
                rows="3"
                :placeholder="form.approvals_status === 'partial' ? 'Terrasse: nicht bewilligt\nDachbodenausbau: nicht im Grundbuch eingetragen' : 'Eigentümer weiß nicht ob Anbau bewilligt'"
              />
            </div>
          </AlertDescription>
        </Alert>
      </CardContent>
    </Card>

    <!-- Dokumenten-Checkliste -->
    <Card>
      <CardHeader>
        <div class="flex items-center justify-between">
          <CardTitle>Dokumenten-Checkliste</CardTitle>
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
