<script setup>
import { inject, onBeforeUnmount, onMounted, computed, ref } from 'vue';
import StepHeader from './shared/StepHeader.vue';
import StepNavigation from './shared/StepNavigation.vue';
import Step01_ObjectType from './steps/Step01_ObjectType.vue';
import Step02_Address from './steps/Step02_Address.vue';
import Step03_Owner from './steps/Step03_Owner.vue';
import Step04_CoreData from './steps/Step04_CoreData.vue';
import Step05_ConditionRenovations from './steps/Step05_ConditionRenovations.vue';
import Step06_FeaturesParking from './steps/Step06_FeaturesParking.vue';
import Step07_Energy from './steps/Step07_Energy.vue';
import Step08_LegalDocuments from './steps/Step08_LegalDocuments.vue';
import Step09_PriceCosts from './steps/Step09_PriceCosts.vue';
import Step10_Photos from './steps/Step10_Photos.vue';
import Step11_SignatureSummary from './steps/Step11_SignatureSummary.vue';
import { useIntakeForm } from './composables/useIntakeForm';
import { useAutoSave } from './composables/useAutoSave';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { WifiOff, AlertCircle } from 'lucide-vue-next';

const props = defineProps({
  // Wenn gesetzt, wird dieser Draft vom Server geladen (z.B. aus Draft-Liste).
  // Sonst wird ein evtl. in localStorage liegender Key wiederverwendet.
  initialDraftKey: { type: String, default: '' },
});
const emit = defineEmits(['close', 'submitted']);

const API = inject('API');
const toast = inject('toast', () => {});

const { form, currentStep, draftKey, TOTAL_STEPS, markSkipped, unmarkSkipped, isSkipped, reset, finishAndCleanup } = useIntakeForm({
  draftKey: props.initialDraftKey || undefined,
});
const { saving, lastSaved, offline, stopRetry, clearLocal, loadLocal, saveRemote } = useAutoSave({
  form, currentStep, draftKey, apiUrl: API,
});

// Draft-Resume-Prompt: wenn beim Mount localStorage-Daten fuer die aktuelle
// draftKey existieren → zeige Banner mit „Weiter wo du aufgehört hast?"
const resumePrompt = ref(null);  // { step, updatedAt } oder null

async function loadDraftFromServer(draftKeyToLoad) {
  try {
    const r = await fetch(API.value + '&action=intake_protocol_draft_load&draft_key=' + encodeURIComponent(draftKeyToLoad));
    const d = await r.json();
    if (d.success && d.form_data) {
      Object.assign(form, d.form_data);
      currentStep.value = Math.max(1, Math.min(TOTAL_STEPS, d.current_step || 1));
      toast('Entwurf geladen');
    }
  } catch (e) {
    toast('Fehler beim Laden: ' + e.message);
  }
}

onMounted(() => {
  // Wenn ein konkreter Draft-Key von aussen vorgegeben wurde (z.B. aus der
  // Drafts-Liste), laden wir diesen vom Server und ueberspringen den
  // Resume-Prompt komplett.
  if (props.initialDraftKey) {
    loadDraftFromServer(props.initialDraftKey);
    return;
  }

  const saved = loadLocal();
  if (saved && saved.form && saved.currentStep) {
    // Nur anbieten wenn das Formular nicht komplett leer ist (also mehr als die Defaults)
    const hasRealData = !!(
      saved.form.object_type ||
      saved.form.owner?.name ||
      saved.form.address ||
      saved.form.living_area
    );
    if (hasRealData) {
      resumePrompt.value = {
        step: saved.currentStep,
        updatedAt: saved.updatedAt,
      };
    } else {
      // Leerer Entwurf → still gelöscht
      try { localStorage.removeItem('intake_protocol_draft_' + draftKey.value); } catch {}
    }
  }
});

function resumeDraft() {
  const saved = loadLocal();
  if (!saved) { resumePrompt.value = null; return; }
  Object.assign(form, saved.form);
  currentStep.value = Math.max(1, Math.min(TOTAL_STEPS, saved.currentStep || 1));
  resumePrompt.value = null;
}

function discardDraft() {
  reset();
  resumePrompt.value = null;
}

function resumeAgeHuman(ts) {
  if (!ts) return '';
  const diffSec = Math.round((Date.now() - ts) / 1000);
  if (diffSec < 60) return 'vor wenigen Sekunden';
  if (diffSec < 3600) return `vor ${Math.round(diffSec / 60)} Minuten`;
  if (diffSec < 86400) return `vor ${Math.round(diffSec / 3600)} Stunden`;
  return `vor ${Math.round(diffSec / 86400)} Tagen`;
}

const STEP_TITLES = [
  'Objekttyp & Vermarktung', 'Adresse', 'Eigentümer',
  'Kerndaten', 'Zustand & Sanierungen',
  'Ausstattung & Stellplätze', 'Energie',
  'Rechtliches & Dokumente', 'Preis & Kosten',
  'Fotos', 'Unterschrift',
];

const currentStepComponent = computed(() => [
  Step01_ObjectType, Step02_Address, Step03_Owner,
  Step04_CoreData, Step05_ConditionRenovations,
  Step06_FeaturesParking, Step07_Energy,
  Step08_LegalDocuments, Step09_PriceCosts,
  Step10_Photos, Step11_SignatureSummary,
][currentStep.value - 1]);

const DISCLAIMER_TEXT = 'Die im Aufnahmeprotokoll angegebenen Informationen stammen vom Eigentümer. Der Eigentümer bestätigt durch seine Unterschrift, dass diese Infos von ihm weitergegeben wurden.';

const nextDisabled = computed(() => {
  if (currentStep.value === 8
      && ['partial', 'unknown'].includes(form.approvals_status)
      && !form.approvals_notes.trim()
      && !isSkipped('approvals_notes')) {
    return true;
  }
  if (currentStep.value === TOTAL_STEPS
      && (!form.signature_data_url || !form.signed_by_name.trim())) {
    return true;
  }
  return false;
});

function goNext() {
  if (currentStep.value >= TOTAL_STEPS) {
    submit();
    return;
  }
  currentStep.value += 1;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goPrev() {
  if (currentStep.value > 1) {
    currentStep.value -= 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

function handleCancel() {
  // Cancel loescht den Draft NICHT — User kommt spaeter zurueck mit Resume-Prompt
  emit('close');
}

// Explizites "Speichern & schliessen": erzwingt einen Server-Save (falls noch
// nicht passiert ist) + schliesst den Wizard. Draft bleibt in der Liste.
async function saveAndClose() {
  await saveRemote();
  toast('Entwurf gespeichert — du findest ihn unter „Offene Entwürfe"');
  emit('close');
}

const submitting = ref(false);
const submitError = ref('');

async function submit() {
  if (submitting.value) return;
  submitting.value = true;
  submitError.value = '';

  try {
    const r = await fetch(API.value + '&action=intake_protocol_submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        form_data: { ...form, draft_key: draftKey.value },
        signature_data_url: form.signature_data_url,
        signed_by_name: form.signed_by_name,
        disclaimer_text: DISCLAIMER_TEXT,
      }),
    });

    const txt = await r.text();
    let d;
    try {
      d = JSON.parse(txt);
    } catch {
      submitError.value = `Server antwortete nicht mit JSON (${r.status}). Antwort: ${txt.slice(0, 200)}`;
      return;
    }

    if (d.success) {
      const warnings = d.mail_warnings || [];
      if (warnings.length > 0) {
        toast('Protokoll angelegt — aber: ' + warnings.join('; '));
      } else {
        toast('Aufnahmeprotokoll erfolgreich angelegt!');
      }
      clearLocal();
      finishAndCleanup();
      stopRetry();
      emit('submitted', { property_id: d.property_id, protocol_id: d.protocol_id });
    } else {
      submitError.value = d.error || `Unbekannter Fehler (Status ${r.status})`;
    }
  } catch (e) {
    submitError.value = 'Netzwerk-Fehler: ' + (e?.message || String(e));
  } finally {
    submitting.value = false;
  }
}

onBeforeUnmount(() => stopRetry());

// Resume-Prompt als Dialog-Modell
const resumeDialogOpen = computed({
  get: () => resumePrompt.value !== null,
  set: (v) => { if (!v) resumePrompt.value = null; },
});
</script>

<template>
  <!-- Teleport zu <body> damit der Wizard aus verschachtelten Scroll-Containern /
       transformierten Parents ausbricht. Position:fixed ist sonst nicht zuverlaessig,
       weil jeder Parent mit transform/filter/perspective eine neue containing-block
       Kontext aufmacht und den Overlay in den Parent-Scope einsperrt. -->
  <Teleport to="body">
  <!-- Canonical Layout: Header (fix oben) / Body (scrollt) / Nav (fix unten).
       Outer container hat KEIN overflow — nur der mittlere Body scrollt.
       Damit bleiben Header und Nav zuverlaessig an Position, egal wie lang
       der Step-Content wird.
       WICHTIG: `bg-white dark:bg-zinc-950` EXPLIZIT, nicht `bg-background`-CSS-Var.
       In diesem Admin-Kontext wird die Variable manchmal nicht korrekt inheritet
       und der Wizard wird transparent sichtbar. Explizite Farbe ist robust. -->
  <div
    class="fixed inset-0 z-[100] bg-white dark:bg-zinc-950 flex flex-col intake-wizard"
    style="touch-action: manipulation;"
  >

    <!-- Resume-Prompt als shadcn-Dialog -->
    <Dialog v-model:open="resumeDialogOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Entwurf gefunden</DialogTitle>
          <DialogDescription>
            Es gibt einen nicht abgeschlossenen Aufnahmeprotokoll-Entwurf aus Schritt
            <strong>{{ resumePrompt?.step }}</strong> von {{ TOTAL_STEPS }},
            gespeichert {{ resumeAgeHuman(resumePrompt?.updatedAt) }}.
          </DialogDescription>
        </DialogHeader>
        <p class="text-xs text-muted-foreground">
          „Weitermachen" lädt die Daten zurück. „Neu starten" verwirft den Entwurf.
        </p>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button variant="outline" class="flex-1" @click="discardDraft">
            Neu starten
          </Button>
          <Button class="flex-[2]" @click="resumeDraft">
            Weitermachen
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- TOP: Header + optionale Offline-Anzeige (kein Scroll) -->
    <div class="shrink-0">
      <StepHeader
        :current-step="currentStep"
        :total-steps="TOTAL_STEPS"
        :title="STEP_TITLES[currentStep - 1]"
        @cancel="handleCancel"
        @save-close="saveAndClose"
      />
      <Alert v-if="offline" variant="warning" class="rounded-none border-x-0 border-t-0">
        <WifiOff class="size-4" />
        <AlertDescription>
          Offline — Änderungen werden später gespeichert
        </AlertDescription>
      </Alert>
    </div>

    <!-- MIDDLE: Body scrollt unabhaengig (mit subtilem Muted-Hintergrund) -->
    <div class="flex-1 overflow-y-auto bg-muted/30">
      <div class="mx-auto w-full max-w-2xl">
        <component
          :is="currentStepComponent"
          :form="form"
          :is-skipped="isSkipped"
          :mark-skipped="markSkipped"
          :unmark-skipped="unmarkSkipped"
          :disclaimer-text="DISCLAIMER_TEXT"
        />
      </div>
    </div>

    <!-- BOTTOM: Navigation (kein Scroll, auf iOS safe-area Respekt) -->
    <div class="shrink-0">
      <!-- Submit-Error: Server-Antwort sichtbar bevor der StepNav-Button kommt -->
      <Alert v-if="submitError" variant="destructive" class="rounded-none border-x-0">
        <AlertCircle class="size-4" />
        <AlertTitle>Absenden fehlgeschlagen</AlertTitle>
        <AlertDescription class="text-xs">
          {{ submitError }}
          <div class="mt-2 text-[11px] opacity-80">
            Deine Eingaben sind weiterhin gespeichert. Tippe erneut auf „Absenden" oder „Speichern & später" um den Entwurf zu sichern.
          </div>
        </AlertDescription>
      </Alert>
      <StepNavigation
        :current-step="currentStep"
        :total-steps="TOTAL_STEPS"
        :next-disabled="nextDisabled || submitting"
        :submitting="submitting"
        @prev="goPrev"
        @next="goNext"
      />
    </div>

  </div>
  </Teleport>
</template>

<style>
/* Mobile-Zoom unterdruecken:
   - iOS Safari zoomt bei Input-Focus wenn font-size < 16px. Wir forcen 16px.
   - Double-tap-Zoom wird durch touch-action:manipulation auf dem Root
     verhindert (siehe inline style oben). */
.intake-wizard input,
.intake-wizard textarea,
.intake-wizard select {
  font-size: 16px !important;
}

/* Buttons im Wizard haben auch touch-action:manipulation (verhindert
   Double-Tap-Zoom bei schnellem Hintereinander-Tippen von Pills/Tiles). */
.intake-wizard button {
  touch-action: manipulation;
}
</style>
