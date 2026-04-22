<script setup>
import { inject, onBeforeUnmount, computed } from 'vue';
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

const emit = defineEmits(['close', 'submitted']);

const API = inject('API');
const toast = inject('toast', () => {});

const { form, currentStep, draftKey, TOTAL_STEPS, markSkipped, unmarkSkipped, isSkipped } = useIntakeForm();
const { saving, lastSaved, offline, stopRetry, clearLocal } = useAutoSave({
  form, currentStep, draftKey, apiUrl: API,
});

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

async function submit() {
  const r = await fetch(API.value + '&action=intake_protocol_submit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({
      form_data: { ...form, draft_key: draftKey.value },
      signature_data_url: form.signature_data_url,
      signed_by_name: form.signed_by_name,
      disclaimer_text: DISCLAIMER_TEXT,
      mail_subject: form.mail_subject || null,
      mail_body: form.mail_body || null,
    }),
  });
  const d = await r.json();
  if (d.success) {
    toast('Aufnahmeprotokoll erfolgreich angelegt!');
    clearLocal();
    stopRetry();
    emit('submitted', { property_id: d.property_id, protocol_id: d.protocol_id });
  } else {
    toast('Fehler: ' + (d.error || 'Unbekannt'));
  }
}

onBeforeUnmount(() => stopRetry());
</script>

<template>
  <div class="fixed inset-0 z-50 bg-zinc-50 flex flex-col" style="overflow-y:auto">

    <StepHeader
      :current-step="currentStep"
      :total-steps="TOTAL_STEPS"
      :title="STEP_TITLES[currentStep - 1]"
      @cancel="emit('close')"
    />

    <div v-if="offline" class="bg-orange-50 text-orange-700 text-xs px-4 py-2 text-center">
      📡 Offline — Änderungen werden später gespeichert
    </div>

    <div class="flex-1 mx-auto w-full" style="max-width:640px">
      <component
        :is="currentStepComponent"
        :form="form"
        :is-skipped="isSkipped"
        :mark-skipped="markSkipped"
        :unmark-skipped="unmarkSkipped"
        :disclaimer-text="DISCLAIMER_TEXT"
      />
    </div>

    <StepNavigation
      :current-step="currentStep"
      :total-steps="TOTAL_STEPS"
      :next-disabled="nextDisabled"
      @prev="goPrev"
      @next="goNext"
    />

  </div>
</template>
