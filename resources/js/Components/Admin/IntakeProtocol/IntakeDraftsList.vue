<script setup>
import { ref, inject, onMounted, computed } from 'vue';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { FileText, Trash2, Play, Clock, Loader2 } from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'resume', 'new']);

const API = inject('API');
const loading = ref(false);
const drafts = ref([]);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const r = await fetch(API.value + '&action=intake_protocol_draft_list');
    const d = await r.json();
    if (d.success) {
      drafts.value = d.drafts || [];
    } else {
      error.value = d.error || 'Laden fehlgeschlagen';
    }
  } catch (e) {
    error.value = 'Netzwerk-Fehler: ' + e.message;
  }
  loading.value = false;
}

async function deleteDraft(draft) {
  if (!confirm(`Entwurf „${draft.title}" wirklich löschen?`)) return;
  try {
    const r = await fetch(API.value + '&action=intake_protocol_draft_delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ draft_key: draft.draft_key }),
    });
    const d = await r.json();
    if (d.success) {
      drafts.value = drafts.value.filter(x => x.id !== draft.id);
      // Auch local-storage entry entfernen falls dieser Key aktiv war
      try { localStorage.removeItem('intake_protocol_draft_' + draft.draft_key); } catch {}
      try {
        if (localStorage.getItem('intake_protocol_current_draft_key') === draft.draft_key) {
          localStorage.removeItem('intake_protocol_current_draft_key');
        }
      } catch {}
    }
  } catch {}
}

function resumeDraft(draft) {
  emit('resume', draft.draft_key);
  emit('update:open', false);
}

function startNew() {
  emit('new');
  emit('update:open', false);
}

function relativeTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const diff = Math.round((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return 'vor wenigen Sekunden';
  if (diff < 3600) return `vor ${Math.round(diff / 60)} Min.`;
  if (diff < 86400) return `vor ${Math.round(diff / 3600)} Std.`;
  if (diff < 86400 * 7) return `vor ${Math.round(diff / 86400)} Tagen`;
  return d.toLocaleDateString('de-AT');
}

const handleOpenChange = (v) => emit('update:open', v);

onMounted(() => { if (props.open) load(); });

// Reload bei Sichtbarkeit
import { watch } from 'vue';
watch(() => props.open, (v) => { if (v) load(); });
</script>

<template>
  <Dialog :open="open" @update:open="handleOpenChange">
    <DialogContent class="sm:max-w-lg max-h-[85vh] flex flex-col">
      <DialogHeader>
        <DialogTitle>Offene Aufnahmeprotokolle</DialogTitle>
        <DialogDescription>
          Entwürfe werden automatisch gespeichert. Hier kannst du einen früheren Entwurf fortsetzen oder löschen.
        </DialogDescription>
      </DialogHeader>

      <div class="flex-1 overflow-y-auto -mx-6 px-6 space-y-2">
        <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground py-8 justify-center">
          <Loader2 class="size-4 animate-spin" />
          Lade Entwürfe…
        </div>

        <div v-else-if="error" class="text-sm text-destructive py-4">{{ error }}</div>

        <div v-else-if="drafts.length === 0" class="text-sm text-muted-foreground py-8 text-center">
          <FileText class="size-8 mx-auto mb-2 opacity-40" />
          Keine offenen Entwürfe vorhanden.
        </div>

        <Card v-for="d in drafts" :key="d.id" class="hover:shadow-md transition-shadow">
          <CardContent class="p-3">
            <div class="flex items-start gap-3">
              <div class="flex-1 min-w-0 space-y-1">
                <div class="font-medium text-sm truncate">{{ d.title }}</div>
                <div v-if="d.owner_name || d.object_type" class="text-xs text-muted-foreground truncate">
                  {{ [d.owner_name, d.object_subtype || d.object_type].filter(Boolean).join(' · ') }}
                </div>
                <div class="flex items-center gap-2 text-[11px] text-muted-foreground pt-1">
                  <Badge variant="secondary" class="text-[10px]">Schritt {{ d.current_step }}/11</Badge>
                  <span class="inline-flex items-center gap-1">
                    <Clock class="size-3" />
                    {{ relativeTime(d.last_saved_at) }}
                  </span>
                </div>
              </div>
              <div class="flex flex-col gap-1 shrink-0">
                <Button size="sm" @click="resumeDraft(d)" class="bg-orange-500 hover:bg-orange-600 text-white">
                  <Play class="size-3.5 mr-1" />
                  Fortsetzen
                </Button>
                <Button size="sm" variant="ghost" class="text-muted-foreground hover:text-destructive" @click="deleteDraft(d)">
                  <Trash2 class="size-3.5" />
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <div class="flex gap-2 pt-3 border-t">
        <Button variant="outline" class="flex-1" @click="handleOpenChange(false)">Schließen</Button>
        <Button class="flex-1 bg-orange-500 hover:bg-orange-600 text-white" @click="startNew">
          Neues Protokoll starten
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
