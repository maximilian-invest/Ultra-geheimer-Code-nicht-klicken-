<script setup>
// Mail-Composer-Dialog: wird aus der Property-Detail-Seite geoeffnet,
// laedt den Default-Text fuer das Aufnahmeprotokoll per protocol_id und
// laesst den Makler in Ruhe Betreff + Body editieren bevor er auf „Senden" klickt.
import { ref, inject, computed, onMounted } from 'vue';

const props = defineProps({
  protocolId: { type: Number, required: true },
});
const emit = defineEmits(['close', 'sent']);

const API = inject('API');

const loading = ref(true);
const sending = ref(false);
const error = ref('');
const subject = ref('');
const body = ref('');
const ownerEmail = ref('');
const missingDocs = ref([]);
const alreadySentAt = ref(null);

const isResend = computed(() => !!alreadySentAt.value);

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const r = await fetch(API.value + '&action=intake_protocol_preview_mail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ protocol_id: props.protocolId }),
    });
    const d = await r.json();
    if (d.success) {
      subject.value = d.subject || '';
      body.value = d.body || '';
      ownerEmail.value = d.owner_email || '';
      missingDocs.value = d.missing_docs || [];
      alreadySentAt.value = d.already_sent_at || null;
    } else {
      error.value = d.error || 'Laden fehlgeschlagen';
    }
  } catch (e) {
    error.value = 'Netzwerk-Fehler: ' + e.message;
  }
  loading.value = false;
}

async function send() {
  if (sending.value) return;
  if (!ownerEmail.value) { error.value = 'Keine E-Mail-Adresse hinterlegt.'; return; }
  if (!subject.value.trim() || !body.value.trim()) { error.value = 'Betreff und Nachricht erforderlich.'; return; }

  sending.value = true;
  error.value = '';
  try {
    const r = await fetch(API.value + '&action=intake_protocol_resend_email', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        protocol_id: props.protocolId,
        type: 'protocol',
        subject: subject.value,
        body: body.value,
      }),
    });
    const d = await r.json();
    if (d.success) {
      emit('sent', { sent_at: d.sent_at });
    } else {
      error.value = d.error || 'Versand fehlgeschlagen';
    }
  } catch (e) {
    error.value = 'Netzwerk-Fehler: ' + e.message;
  }
  sending.value = false;
}

function resetToDefault() {
  load();
}

onMounted(() => load());
</script>

<template>
  <div class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-3 border-b border-border">
        <div>
          <div class="flex items-center gap-2">
            <span class="text-lg">📧</span>
            <h3 class="font-semibold text-base">
              {{ isResend ? 'E-Mail erneut senden' : 'E-Mail an Eigentümer' }}
            </h3>
          </div>
          <div v-if="isResend" class="text-[11px] text-muted-foreground mt-0.5">
            Bereits einmal versendet am {{ new Date(alreadySentAt).toLocaleString('de-AT') }}
          </div>
        </div>
        <button type="button" @click="$emit('close')"
                class="w-8 h-8 rounded-md hover:bg-zinc-100 flex items-center justify-center text-zinc-500">✕</button>
      </div>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto p-5 space-y-3">
        <div v-if="loading" class="text-sm text-muted-foreground italic">Vorschau wird geladen…</div>
        <div v-else>
          <div class="text-xs text-muted-foreground mb-3">
            An: <strong>{{ ownerEmail || '(keine E-Mail)' }}</strong>
            <span v-if="missingDocs.length > 0" class="ml-2 text-amber-700">
              · {{ missingDocs.length }} fehlende Dokument(e)
            </span>
          </div>

          <div class="space-y-3">
            <div>
              <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">Betreff</label>
              <input v-model="subject"
                     class="w-full h-11 rounded-lg border border-border px-3 text-sm font-medium" />
            </div>
            <div>
              <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">Nachricht</label>
              <textarea v-model="body" rows="14"
                        class="w-full rounded-lg border border-border px-3 py-2 text-[13px] leading-relaxed font-[ui-monospace,monospace]"
                        style="white-space:pre-wrap"></textarea>
            </div>
            <button type="button" @click="resetToDefault"
                    class="text-xs text-[#EE7600] underline">
              Auf Standard-Text zurücksetzen
            </button>
          </div>

          <p class="text-[11px] text-muted-foreground mt-3">
            💡 Das unterschriebene Aufnahmeprotokoll-PDF wird automatisch angehängt.
            <span v-if="missingDocs.length > 0">Ebenso der Alleinvermittlungsauftrag.</span>
          </p>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-5 py-3 border-t border-border">
        <div v-if="error" class="bg-red-50 border border-red-300 text-red-800 text-xs rounded p-2 mb-2">
          {{ error }}
        </div>
        <div class="flex gap-2">
          <button type="button" @click="$emit('close')"
                  :disabled="sending"
                  class="flex-1 h-11 rounded-lg border border-border text-sm font-medium hover:bg-zinc-50 disabled:opacity-50">
            Abbrechen
          </button>
          <button type="button" @click="send"
                  :disabled="sending || loading || !ownerEmail"
                  class="flex-[2] h-11 rounded-lg bg-[#EE7600] text-white text-sm font-semibold disabled:bg-zinc-300 disabled:cursor-not-allowed">
            {{ sending ? 'Wird gesendet…' : (isResend ? '↻ Erneut senden' : '📧 Senden') }}
          </button>
        </div>
      </div>

    </div>
  </div>
</template>
