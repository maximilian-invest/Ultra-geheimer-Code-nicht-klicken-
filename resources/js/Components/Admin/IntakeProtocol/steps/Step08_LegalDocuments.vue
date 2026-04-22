<script setup>
import { computed, inject, ref } from 'vue';
import DocumentChecklistItem from '../shared/DocumentChecklistItem.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';

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

    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Hausverwaltung</div>
      <input
        v-model="hvSearch"
        @input="searchHv(hvSearch)"
        placeholder="Hausverwaltung suchen..."
        class="w-full h-11 rounded-lg border border-border px-3"
      />
      <div v-if="hvResults.length" class="bg-white border border-border rounded-lg divide-y divide-border/40">
        <button v-for="h in hvResults" :key="h.id" type="button" @click="pickHv(h)"
                class="w-full text-left px-3 py-2 hover:bg-zinc-50">
          <div class="text-sm font-medium">{{ h.company_name }}</div>
          <div class="text-xs text-muted-foreground">{{ h.contact_person }} · {{ h.email }}</div>
        </button>
      </div>
      <button v-if="!hvShowNewForm" type="button" @click="hvShowNewForm = true" class="text-sm text-[#EE7600] font-medium">
        + Neue Hausverwaltung
      </button>
      <div v-if="hvShowNewForm" class="bg-zinc-50 rounded-lg p-3 space-y-2">
        <input v-model="hvNewForm.company_name" placeholder="Firma *" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.contact_person" placeholder="Ansprechpartner" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.email" type="email" placeholder="E-Mail" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.phone" placeholder="Telefon" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <button type="button" @click="createHv" class="w-full h-10 rounded-md bg-[#EE7600] text-white text-sm font-medium">Anlegen</button>
      </div>
    </div>

    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Belastungen / Rechte</div>
      <textarea v-model="form.encumbrances" rows="3"
                placeholder="Pfandrechte, Wohnrechte, Dienstbarkeiten ..."
                class="w-full rounded-lg border border-border p-2 text-sm"></textarea>
    </div>

    <div class="bg-white border-l-4 border-l-[#EE7600] border-t border-r border-b border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Bewilligungen</div>
      <div class="text-sm">Sind alle Baumaßnahmen bewilligt?</div>
      <div class="grid grid-cols-3 gap-2">
        <button type="button" @click="form.approvals_status = 'complete'; form.approvals_notes = ''"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'complete' ? 'bg-green-50 border-2 border-green-600' : 'bg-white border border-border'
                ]">
          <div class="text-xl">✓</div>
          <div class="text-[11px] font-medium mt-0.5">Alles bewilligt</div>
        </button>
        <button type="button" @click="form.approvals_status = 'partial'"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'partial' ? 'bg-amber-50 border-2 border-amber-500' : 'bg-white border border-border'
                ]">
          <div class="text-xl">⚠️</div>
          <div class="text-[11px] font-medium mt-0.5">Teilweise</div>
        </button>
        <button type="button" @click="form.approvals_status = 'unknown'"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'unknown' ? 'bg-zinc-100 border-2 border-zinc-600' : 'bg-white border border-border'
                ]">
          <div class="text-xl">❓</div>
          <div class="text-[11px] font-medium mt-0.5">Unbekannt</div>
        </button>
      </div>

      <div v-if="['partial','unknown'].includes(form.approvals_status)"
           :class="[
             'rounded-lg p-3 space-y-2',
             form.approvals_status === 'partial' ? 'bg-amber-50 border border-amber-200' : 'bg-zinc-100 border border-zinc-300'
           ]">
        <div class="flex items-center justify-between">
          <label class="text-xs font-semibold">
            <span v-if="form.approvals_status === 'partial'">Welche Bewilligung fehlt wofür? *</span>
            <span v-else>Was ist unklar und muss geprüft werden? *</span>
          </label>
          <SkipFieldSwitch v-model="approvalsNotesSkipped" />
        </div>
        <textarea v-model="form.approvals_notes" rows="3"
                  :placeholder="form.approvals_status === 'partial' ? 'Terrasse: nicht bewilligt\nDachbodenausbau: nicht im Grundbuch eingetragen' : 'Eigentümer weiß nicht ob Anbau bewilligt'"
                  class="w-full rounded-md border border-border p-2 text-sm"></textarea>
      </div>
    </div>

    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between mb-1">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Dokumenten-Checkliste</div>
        <div class="text-[11px] text-[#EE7600] font-medium">{{ availableCount }} / {{ DOCS.length }} vorhanden</div>
      </div>
      <div class="space-y-1">
        <DocumentChecklistItem
          v-for="d in DOCS" :key="d.key"
          :doc-key="d.key"
          :label="d.label"
          :model-value="getDocStatus(d.key)"
          @update:model-value="setDocStatus(d.key, $event)"
        />
      </div>
    </div>

  </div>
</template>
