<script setup>
import { computed, ref, onMounted, watch, inject } from 'vue';
import SignaturePad from '../shared/SignaturePad.vue';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
  disclaimerText: { type: String, default: '' },
});

const API = inject('API');

const ownerName = computed(() => props.form.owner?.name?.trim() || '—');
const addressLine = computed(() => {
  const parts = [props.form.address, props.form.house_number].filter(Boolean).join(' ');
  return [parts, props.form.zip, props.form.city].filter(Boolean).join(', ') || '—';
});
const priceValue = computed(() => {
  if (props.form.marketing_type === 'miete' || props.form.marketing_type === 'pacht') {
    return props.form.rental_price;
  }
  return props.form.purchase_price;
});
const priceLine = computed(() => {
  const v = priceValue.value;
  if (!v) return '—';
  return new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v);
});
const objectLine = computed(() => [props.form.object_type, props.form.object_subtype].filter(Boolean).join(' · ') || '—');
const photoCount = computed(() => (props.form.photos || []).length);
const openFieldsCount = computed(() => (props.form.open_fields || []).length);

// E-Mail Preview + Edit
const mailLoading = ref(false);
const mailError = ref('');
const mailEdited = ref(false);  // einmal editiert → nicht mehr auto-refreshen
const missingDocs = ref([]);

// Form.mail_subject / form.mail_body werden im Wizard-Root an submit() durchgereicht.
// Erstes Laden: vom Backend-Default holen.
async function loadMailPreview(forceRefresh = false) {
  if (!forceRefresh && mailEdited.value) return;  // User hat editiert, nichts überschreiben
  if (!props.form.owner?.email) {
    props.form.mail_subject = '';
    props.form.mail_body = '';
    missingDocs.value = [];
    return;
  }
  mailLoading.value = true;
  mailError.value = '';
  try {
    const r = await fetch(API.value + '&action=intake_protocol_preview_mail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        form_data: { ...props.form },
      }),
    });
    const d = await r.json();
    if (d.success) {
      props.form.mail_subject = d.subject || '';
      props.form.mail_body = d.body || '';
      missingDocs.value = d.missing_docs || [];
    } else {
      mailError.value = d.error || 'Preview fehlgeschlagen';
    }
  } catch (e) {
    mailError.value = 'Netzwerk-Fehler: ' + e.message;
  }
  mailLoading.value = false;
}

onMounted(() => {
  loadMailPreview();
});

// Refresh wenn Owner-E-Mail sich nachträglich ändert
watch(() => props.form.owner?.email, (newEmail, oldEmail) => {
  if (newEmail !== oldEmail) loadMailPreview();
});

// User-Edits tracken (kein auto-Reload mehr nach erster Bearbeitung)
function onMailEdit() {
  mailEdited.value = true;
}

function resetMailToDefault() {
  mailEdited.value = false;
  loadMailPreview(true);
}
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Summary Card -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3 text-sm">
      <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
        <span class="text-muted-foreground">Eigentümer:</span> <span class="font-medium">{{ ownerName }}</span>
        <span class="text-muted-foreground">Objekt:</span>     <span>{{ objectLine }}</span>
        <span class="text-muted-foreground">Adresse:</span>    <span>{{ addressLine }}</span>
        <span class="text-muted-foreground">Richtpreis:</span> <span class="font-semibold">{{ priceLine }}</span>
        <span class="text-muted-foreground">Fotos:</span>      <span>{{ photoCount }}</span>
        <span class="text-muted-foreground">Offene Felder:</span>
        <span :class="openFieldsCount > 0 ? 'text-amber-700 font-medium' : 'text-green-700 font-medium'">
          {{ openFieldsCount }} {{ openFieldsCount === 1 ? 'Feld' : 'Felder' }}
        </span>
      </div>
    </div>

    <!-- Offene Felder Warnung -->
    <div v-if="openFieldsCount > 0" class="bg-amber-50 border border-amber-300 rounded-xl p-3">
      <div class="text-sm text-amber-900">
        ⚠️ <strong>{{ openFieldsCount }} Feld(er) wurden übersprungen.</strong>
        Diese werden im PDF als „offen" markiert und der Eigentümer erhält eine Erinnerungs-Mail zum Nachreichen.
      </div>
    </div>

    <!-- Haftungsausschluss -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Haftungsausschluss</div>
      <div class="text-xs leading-relaxed text-zinc-700 whitespace-pre-line bg-zinc-50 rounded p-3 border border-zinc-200">{{ disclaimerText }}</div>
    </div>

    <!-- E-Mail an Eigentümer — Preview + Bearbeitung -->
    <div v-if="form.owner?.email" class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="flex items-center justify-between">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">E-Mail an Eigentümer</div>
        <button v-if="mailEdited" type="button" @click="resetMailToDefault"
                class="text-[11px] text-[#EE7600] underline">Auf Standard zurücksetzen</button>
      </div>
      <div class="text-xs text-muted-foreground">
        An: <strong>{{ form.owner.email }}</strong>
        <span v-if="missingDocs.length > 0" class="ml-2 text-amber-700">· {{ missingDocs.length }} fehlende Dokument(e)</span>
      </div>

      <div v-if="mailLoading" class="text-xs text-muted-foreground italic">Vorschau wird geladen…</div>
      <div v-else-if="mailError" class="text-xs text-red-600 bg-red-50 rounded p-2">{{ mailError }}</div>

      <div class="space-y-2">
        <div>
          <label class="text-[11px] text-muted-foreground block mb-1">Betreff</label>
          <input v-model="form.mail_subject" @input="onMailEdit"
                 class="w-full h-11 rounded-lg border border-border px-3 text-sm font-medium" />
        </div>
        <div>
          <label class="text-[11px] text-muted-foreground block mb-1">Nachricht</label>
          <textarea v-model="form.mail_body" @input="onMailEdit" rows="12"
                    class="w-full rounded-lg border border-border px-3 py-2 text-xs leading-relaxed font-[ui-monospace,monospace]"
                    style="white-space:pre-wrap"></textarea>
        </div>
      </div>
      <p class="text-[10px] text-muted-foreground">
        💡 Die Unterschriebene PDF wird automatisch angehängt — hier nur der Begleittext.
      </p>
    </div>

    <div v-else class="bg-amber-50 border border-amber-300 rounded-xl p-3 text-xs text-amber-900">
      ⚠️ Keine E-Mail für Eigentümer hinterlegt — es wird keine Mail versendet. Geh zurück zu Step 3 um eine E-Mail zu ergänzen.
    </div>

    <!-- Unterschriftsname + Pad -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="flex items-center justify-between">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Unterschrift Eigentümer</div>
        <div v-if="form.signature_data_url" class="text-[11px] text-green-700">✓ unterschrieben</div>
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Name (wie er unterschreibt) *</label>
        <input v-model="form.signed_by_name" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <SignaturePad v-model="form.signature_data_url" />
      <p class="text-[11px] text-muted-foreground">
        Mit der Unterschrift bestätigt der Eigentümer die Angaben und den Haftungsausschluss.
      </p>
    </div>

  </div>
</template>
