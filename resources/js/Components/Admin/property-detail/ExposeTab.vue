<script setup>
import { ref, computed, inject } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, RefreshCw, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
  property: { type: Object, required: true },
});

const toast = inject('toast', (msg) => console.log('[toast]', msg));
const generating = ref(false);
const error = ref('');
const info = ref(null); // { page_count, version_id }

const previewUrl = computed(() => `/admin/properties/${props.property.id}/expose/preview?ts=${Date.now()}`);

async function generate() {
  generating.value = true;
  error.value = '';
  try {
    const res = await fetch(`/admin/properties/${props.property.id}/expose`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept': 'application/json',
      },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    info.value = data;
    toast('Exposé gespeichert · ' + data.page_count + ' Seiten');
  } catch (e) {
    error.value = e.message;
  } finally {
    generating.value = false;
  }
}
</script>

<template>
  <div class="p-6 space-y-4">
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

    <div v-if="info" class="text-xs text-muted-foreground">
      Aktive Version: {{ info.page_count }} Seiten · gespeichert soeben
    </div>

    <div class="border border-border rounded-md overflow-hidden bg-zinc-50" style="aspect-ratio: 297/210;">
      <iframe :src="previewUrl" class="w-full h-full" style="border:0" />
    </div>
  </div>
</template>
