import { ref, watch } from 'vue';

/**
 * Debounced Auto-Save für den Wizard-Draft.
 * Speichert nach jeder Änderung in localStorage (sofort) und synced
 * alle 2 Sekunden nach stopping mit dem Server. Bei Netzfehler bleibt
 * localStorage-Wert bestehen und wird später nochmal probiert.
 */
export function useAutoSave({ form, currentStep, draftKey, apiUrl }) {
  const saving = ref(false);
  const lastSaved = ref(null);
  const offline = ref(false);
  let debounceTimer = null;

  function localStorageKey() {
    return 'intake_protocol_draft_' + draftKey.value;
  }

  function saveLocal() {
    try {
      localStorage.setItem(localStorageKey(), JSON.stringify({
        form: JSON.parse(JSON.stringify(form)),
        currentStep: currentStep.value,
        updatedAt: Date.now(),
      }));
    } catch (e) {
      console.warn('localStorage save failed', e);
    }
  }

  function loadLocal() {
    try {
      const raw = localStorage.getItem(localStorageKey());
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function clearLocal() {
    try { localStorage.removeItem(localStorageKey()); } catch (e) {}
  }

  async function saveRemote() {
    saving.value = true;
    try {
      const r = await fetch(apiUrl.value + '&action=intake_protocol_draft_save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          draft_key: draftKey.value,
          form_data: form,
          current_step: currentStep.value,
        }),
      });
      const d = await r.json();
      if (d.success) {
        lastSaved.value = new Date();
        offline.value = false;
      } else {
        offline.value = true;
      }
    } catch (e) {
      offline.value = true;
    }
    saving.value = false;
  }

  function scheduleSave() {
    saveLocal();
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => saveRemote(), 2000);
  }

  watch([() => JSON.parse(JSON.stringify(form)), currentStep], () => scheduleSave(), { deep: true });

  const retryInterval = setInterval(() => {
    if (offline.value) saveRemote();
  }, 30000);

  window.addEventListener('beforeunload', () => {
    saveLocal();
    try {
      navigator.sendBeacon(
        apiUrl.value + '&action=intake_protocol_draft_save',
        new Blob([JSON.stringify({
          draft_key: draftKey.value,
          form_data: form,
          current_step: currentStep.value,
        })], { type: 'application/json' })
      );
    } catch (e) {}
  });

  return {
    saving, lastSaved, offline,
    saveLocal, loadLocal, clearLocal, saveRemote,
    stopRetry: () => clearInterval(retryInterval),
  };
}
