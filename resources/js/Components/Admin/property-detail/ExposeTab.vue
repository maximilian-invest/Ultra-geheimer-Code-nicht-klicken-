<script setup>
import { ref, computed, inject, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, RefreshCw, ExternalLink, Save } from 'lucide-vue-next';

const props = defineProps({
  property: { type: Object, required: true },
});

const toast = inject('toast', (msg) => console.log('[toast]', msg));
const generating = ref(false);
const savingCaptions = ref(false);
const error = ref('');
const info = ref(null); // { page_count, version_id }

// Makler-kuratierte Texte — initial aus Property, editierbar.
const claim = ref(props.property.expose_claim || '');
const captionsPool = ref(props.property.expose_captions_pool || '');

const previewUrl = computed(() => `/admin/properties/${props.property.id}/expose/preview?ts=${Date.now()}`);
const previewKey = ref(0);
const srcWithBust = computed(() => `${previewUrl.value}&v=${previewKey.value}`);

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function generate() {
  generating.value = true;
  error.value = '';
  try {
    const res = await fetch(`/admin/properties/${props.property.id}/expose`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || `HTTP ${res.status}`);
    info.value = data;
    previewKey.value++;
    toast('Exposé gespeichert · ' + data.page_count + ' Seiten');
  } catch (e) {
    error.value = e.message;
  } finally {
    generating.value = false;
  }
}

async function saveCaptions() {
  savingCaptions.value = true;
  error.value = '';
  try {
    const res = await fetch(`/admin/properties/${props.property.id}/expose/captions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
      body: JSON.stringify({
        expose_claim: claim.value.trim() || null,
        expose_captions_pool: captionsPool.value.trim() || null,
      }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || `HTTP ${res.status}`);
    toast('Texte gespeichert');
    // Nach Text-Speichern die Preview aktualisieren.
    previewKey.value++;
  } catch (e) {
    error.value = e.message;
  } finally {
    savingCaptions.value = false;
  }
}
</script>

<template>
  <div class="p-6 space-y-5">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold">Exposé</h2>
        <p class="text-sm text-muted-foreground mt-1 max-w-xl">
          Das adaptive Exposé wird automatisch aus den Objektdaten und hochgeladenen Bildern erzeugt.
          Änderungen an der Property sind sofort in der Vorschau sichtbar.
        </p>
      </div>
      <div class="flex gap-2 flex-shrink-0">
        <Button @click="generate" :disabled="generating" variant="default" size="sm">
          <Loader2 v-if="generating" class="w-4 h-4 mr-2 animate-spin" />
          <RefreshCw v-else class="w-4 h-4 mr-2" />
          {{ info ? 'Neu speichern' : 'Exposé speichern' }}
        </Button>
        <a :href="previewUrl" target="_blank" rel="noopener">
          <Button variant="outline" size="sm">
            <ExternalLink class="w-4 h-4 mr-2" />
            Vollbild
          </Button>
        </a>
      </div>
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <!-- Makler-Texte für Titel-Claim + Editorial-Captions -->
    <div class="border border-border rounded-md p-4 bg-zinc-50/60 space-y-3">
      <div class="flex items-center justify-between gap-4">
        <div>
          <h3 class="text-sm font-semibold">Makler-Texte</h3>
          <p class="text-xs text-muted-foreground mt-0.5">
            Kurzer Claim fürs Cover und poetische Sätze für die Editorial-Impressionen.
          </p>
        </div>
        <Button @click="saveCaptions" :disabled="savingCaptions" variant="outline" size="sm">
          <Loader2 v-if="savingCaptions" class="w-4 h-4 mr-2 animate-spin" />
          <Save v-else class="w-4 h-4 mr-2" />
          Texte speichern
        </Button>
      </div>

      <div>
        <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">
          Claim (optional, für Cover)
        </label>
        <input v-model="claim" type="text" maxlength="200"
          placeholder="z.B. Wohnen, wo andere Urlaub machen."
          class="w-full h-9 px-3 text-[13px] bg-white border border-border rounded-md focus:outline-none focus:ring-1 focus:ring-orange-400" />
      </div>

      <div>
        <label class="text-[11px] text-muted-foreground mb-1 block uppercase tracking-wider font-medium">
          Impressionen-Zitate (eine Zeile = ein Satz)
        </label>
        <textarea v-model="captionsPool" rows="4"
          placeholder="Wo Tageslicht den Raum formt.&#10;Ein Ort, an dem Tage länger bleiben.&#10;Mehr als vier Wände."
          class="w-full px-3 py-2 text-[13px] bg-white border border-border rounded-md resize-none focus:outline-none focus:ring-1 focus:ring-orange-400" />
        <p class="text-[10.5px] text-muted-foreground mt-1">
          Der Generator verteilt diese Sätze rotierend auf die Editorial-Impressionen-Seiten. Leer lassen → Default-Vorschläge werden verwendet.
        </p>
      </div>
    </div>

    <div v-if="info" class="text-xs text-muted-foreground">
      Aktive Version: {{ info.page_count }} Seiten · gespeichert soeben
    </div>

    <div class="border border-border rounded-md overflow-hidden bg-zinc-50" style="aspect-ratio: 297/210;">
      <iframe :src="srcWithBust" class="w-full h-full" style="border:0" />
    </div>
  </div>
</template>
