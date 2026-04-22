<script setup>
// Mail-Composer-Dialog: wird aus der Property-Detail-Seite geoeffnet,
// laedt den Default-Text fuer das Aufnahmeprotokoll per protocol_id und
// laesst den Makler in Ruhe Betreff + Body editieren bevor er auf „Senden" klickt.
import { ref, inject, computed, onMounted } from 'vue';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { RotateCcw, Send, AlertCircle } from 'lucide-vue-next';

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
const open = ref(true);

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

function handleOpenChange(v) {
  open.value = v;
  if (!v) emit('close');
}

onMounted(() => load());
</script>

<template>
  <Dialog :open="open" @update:open="handleOpenChange">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          {{ isResend ? 'E-Mail erneut senden' : 'E-Mail an Eigentümer' }}
        </DialogTitle>
        <DialogDescription v-if="isResend">
          Bereits einmal versendet am {{ new Date(alreadySentAt).toLocaleString('de-AT') }}
        </DialogDescription>
      </DialogHeader>

      <div v-if="loading" class="text-sm text-muted-foreground italic">Vorschau wird geladen…</div>
      <div v-else class="space-y-4">
        <div class="text-xs text-muted-foreground flex items-center gap-2 flex-wrap">
          <span>An: <strong>{{ ownerEmail || '(keine E-Mail)' }}</strong></span>
          <Badge v-if="missingDocs.length > 0" variant="outline">
            {{ missingDocs.length }} fehlende Dokument(e)
          </Badge>
        </div>

        <div class="space-y-2">
          <Label for="mail-subject">Betreff</Label>
          <Input id="mail-subject" v-model="subject" />
        </div>
        <div class="space-y-2">
          <Label for="mail-body">Nachricht</Label>
          <Textarea
            id="mail-body"
            v-model="body"
            rows="14"
            class="text-xs leading-relaxed font-mono"
            style="white-space:pre-wrap"
          />
        </div>
        <Button variant="link" size="sm" class="px-0 h-auto" @click="resetToDefault">
          <RotateCcw class="h-3 w-3" />
          Auf Standard-Text zurücksetzen
        </Button>

        <p class="text-xs text-muted-foreground">
          Das unterschriebene Aufnahmeprotokoll-PDF wird automatisch angehängt.
          <span v-if="missingDocs.length > 0">Ebenso der Alleinvermittlungsauftrag.</span>
        </p>
      </div>

      <Alert v-if="error" variant="destructive">
        <AlertCircle class="size-4" />
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <DialogFooter class="gap-2 sm:gap-2">
        <Button
          variant="outline"
          class="flex-1"
          :disabled="sending"
          @click="handleOpenChange(false)"
        >
          Abbrechen
        </Button>
        <Button
          class="flex-[2]"
          :disabled="sending || loading || !ownerEmail"
          @click="send"
        >
          <Send class="h-4 w-4" />
          {{ sending ? 'Wird gesendet…' : (isResend ? 'Erneut senden' : 'Senden') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
