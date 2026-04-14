<template>
  <div class="min-h-screen bg-background">
    <div class="mx-auto max-w-5xl px-6 py-10">
      <!-- Back link -->
      <a
        :href="`/admin/properties/${property.id}`"
        class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft class="h-4 w-4" />
        Zurueck zu {{ property.address }}
      </a>

      <!-- Header -->
      <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 class="font-display text-3xl font-semibold tracking-tight">{{ link.name }}</h1>
          <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <Badge :variant="statusVariant(link.status)" class="gap-1">
              <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(link.status)"></span>
              {{ statusLabel(link.status) }}
            </Badge>
            <span class="flex items-center gap-1">
              <Calendar class="h-3.5 w-3.5" />
              Laeuft am {{ formatDate(link.expires_at) }}
            </span>
            <span>·</span>
            <span>Erstellt am {{ formatDate(link.created_at) }}</span>
          </div>
        </div>
      </div>

      <!-- URL box -->
      <Card class="mt-6">
        <CardContent class="flex items-center gap-3 p-4">
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
            <LinkIcon class="h-4 w-4 text-muted-foreground" />
          </div>
          <code class="flex-1 truncate font-mono text-sm text-foreground">{{ link.url }}</code>
          <Button variant="outline" size="sm" @click="copyUrl">
            <component :is="copied ? Check : Copy" class="h-4 w-4" />
            {{ copied ? 'Kopiert' : 'Kopieren' }}
          </Button>
        </CardContent>
      </Card>

      <!-- Metrics -->
      <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <Card>
          <CardContent class="p-5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Aufrufe</span>
              <Eye class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="mt-2 text-3xl font-semibold tracking-tight">{{ totalOpens }}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Personen</span>
              <Users class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="mt-2 text-3xl font-semibold tracking-tight">{{ sessions.length }}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ansichten</span>
              <FileText class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="mt-2 text-3xl font-semibold tracking-tight">{{ totalViews }}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Downloads</span>
              <Download class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="mt-2 text-3xl font-semibold tracking-tight">{{ totalDownloads }}</div>
          </CardContent>
        </Card>
      </div>

      <!-- Documents -->
      <Card class="mt-6">
        <CardHeader class="flex flex-row items-start justify-between space-y-0">
          <div>
            <CardTitle class="flex items-center gap-2 text-lg">
              <FileText class="h-5 w-5 text-muted-foreground" />
              Dokumente
            </CardTitle>
            <p class="mt-1 text-sm text-muted-foreground">
              Waehle aus, welche Files im Link sichtbar sind.
            </p>
          </div>
          <span class="shrink-0 text-xs font-medium text-muted-foreground">
            {{ selectedIds.length }} / {{ allFiles.length }}
          </span>
        </CardHeader>
        <CardContent class="pt-0">
          <div v-if="allFiles.length === 0" class="rounded-lg border border-dashed border-border bg-muted/30 p-8 text-center text-sm text-muted-foreground">
            Diese Property hat noch keine Dokumente.
          </div>
          <div v-else class="divide-y divide-border rounded-lg border border-border">
            <label
              v-for="file in allFiles"
              :key="file.id"
              class="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
              :class="selectedIds.includes(file.id) && 'bg-muted/30'"
            >
              <input
                type="checkbox"
                :value="file.id"
                v-model="selectedIds"
                class="h-4 w-4 shrink-0 rounded border-border text-primary accent-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
              />
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
                <FileText class="h-4 w-4 text-muted-foreground" />
              </div>
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium text-foreground">{{ file.label }}</div>
                <div class="truncate font-mono text-xs text-muted-foreground">
                  {{ file.filename }}<span v-if="file.file_size"> · {{ formatSize(file.file_size) }}</span>
                </div>
              </div>
            </label>
          </div>
        </CardContent>
        <CardFooter v-if="allFiles.length > 0" class="flex items-center gap-3 border-t border-border bg-muted/20 px-6 py-4">
          <Button :disabled="!isDirty || saving" @click="saveDocs">
            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
            <Save v-else class="h-4 w-4" />
            {{ saving ? 'Speichere...' : 'Speichern' }}
          </Button>
          <Button variant="outline" :disabled="!isDirty || saving" @click="resetSelection">
            <RotateCcw class="h-4 w-4" />
            Zuruecksetzen
          </Button>
          <div v-if="saveMessage" class="ml-auto flex items-center gap-1.5 text-sm">
            <CheckCircle2 v-if="saveMessageType === 'success'" class="h-4 w-4 text-emerald-600" />
            <AlertCircle v-else class="h-4 w-4 text-destructive" />
            <span :class="saveMessageType === 'success' ? 'text-emerald-600' : 'text-destructive'">
              {{ saveMessage }}
            </span>
          </div>
        </CardFooter>
      </Card>

      <!-- Activity -->
      <Card class="mt-6">
        <CardHeader>
          <CardTitle class="flex items-center gap-2 text-lg">
            <Activity class="h-5 w-5 text-muted-foreground" />
            Aktivitaet
          </CardTitle>
          <p class="text-sm text-muted-foreground">Wer hat den Link geoeffnet und was angesehen.</p>
        </CardHeader>
        <CardContent>
          <div v-if="sessions.length === 0" class="rounded-lg border border-dashed border-border bg-muted/30 p-8 text-center text-sm text-muted-foreground">
            Noch keine Zugriffe.
          </div>
          <ul v-else class="space-y-5">
            <li v-for="session in sessions" :key="session.id" class="rounded-lg border border-border p-4">
              <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-semibold">
                    {{ initials(session.email) }}
                  </div>
                  <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-foreground">{{ session.email }}</div>
                    <div class="text-xs text-muted-foreground">
                      Zuerst gesehen {{ formatDateTime(session.first_seen_at) }}
                    </div>
                  </div>
                </div>
              </div>
              <Separator class="my-3" />
              <ul class="space-y-1.5">
                <li
                  v-for="event in session.events"
                  :key="event.id"
                  class="flex items-center justify-between text-xs"
                >
                  <span class="flex items-center gap-2">
                    <component :is="eventIcon(event.event_type)" class="h-3.5 w-3.5" :class="eventColor(event.event_type)" />
                    <span class="font-medium text-foreground">{{ eventLabel(event.event_type) }}</span>
                  </span>
                  <span class="text-muted-foreground">{{ formatDateTime(event.created_at) }}</span>
                </li>
              </ul>
            </li>
          </ul>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
  ArrowLeft,
  Link as LinkIcon,
  Copy,
  Check,
  Calendar,
  Eye,
  Users,
  FileText,
  Download,
  Save,
  RotateCcw,
  CheckCircle2,
  AlertCircle,
  Loader2,
  Activity,
  MousePointerClick,
} from 'lucide-vue-next';
import { Card, CardContent, CardHeader, CardTitle, CardFooter } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';

const props = defineProps({
  link: Object,
  sessions: Array,
  property: Object,
  allFiles: { type: Array, default: () => [] },
});

const initialIds = () => [...(props.link.document_ids || [])].map(Number).sort((a, b) => a - b);

const selectedIds = ref(initialIds());
const saving = ref(false);
const saveMessage = ref('');
const saveMessageType = ref('success');
const copied = ref(false);

watch(() => props.link.document_ids, () => {
  selectedIds.value = initialIds();
});

const isDirty = computed(() => {
  const current = [...selectedIds.value].sort((a, b) => a - b).join(',');
  const original = initialIds().join(',');
  return current !== original;
});

const totalOpens = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'link_opened').length, 0)
);
const totalViews = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_viewed').length, 0)
);
const totalDownloads = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_downloaded').length, 0)
);

function statusLabel(s) {
  return { active: 'Aktiv', expired: 'Abgelaufen', revoked: 'Gesperrt' }[s] || s;
}
function statusVariant(s) {
  return { active: 'secondary', expired: 'outline', revoked: 'destructive' }[s] || 'outline';
}
function statusDot(s) {
  return { active: 'bg-emerald-500', expired: 'bg-amber-500', revoked: 'bg-red-500' }[s] || 'bg-muted';
}
function formatDate(iso) {
  if (!iso) return 'unbegrenzt';
  return new Date(iso).toLocaleDateString('de-AT', { day: '2-digit', month: 'short', year: 'numeric' });
}
function formatDateTime(iso) {
  return new Date(iso).toLocaleString('de-AT', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
function formatSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}
function initials(email) {
  if (!email) return '?';
  return email.slice(0, 2).toUpperCase();
}
function eventLabel(t) {
  return {
    link_opened: 'Link geoeffnet',
    doc_viewed: 'Dokument angesehen',
    doc_downloaded: 'Heruntergeladen',
  }[t] || t;
}
function eventIcon(t) {
  return {
    link_opened: MousePointerClick,
    doc_viewed: Eye,
    doc_downloaded: Download,
  }[t] || Activity;
}
function eventColor(t) {
  return {
    link_opened: 'text-muted-foreground',
    doc_viewed: 'text-blue-500',
    doc_downloaded: 'text-primary',
  }[t] || 'text-muted-foreground';
}
async function copyUrl() {
  await navigator.clipboard.writeText(props.link.url);
  copied.value = true;
  setTimeout(() => (copied.value = false), 1800);
}
function resetSelection() {
  selectedIds.value = initialIds();
  saveMessage.value = '';
}
async function saveDocs() {
  if (selectedIds.value.length === 0) {
    saveMessage.value = 'Mindestens ein Dokument auswaehlen.';
    saveMessageType.value = 'error';
    return;
  }
  saving.value = true;
  saveMessage.value = '';
  try {
    await window.axios.put(
      `/admin/properties/${props.property.id}/links/${props.link.id}`,
      {
        name: props.link.name,
        expires_at: props.link.expires_at,
        file_ids: selectedIds.value,
      }
    );
    saveMessage.value = 'Gespeichert';
    saveMessageType.value = 'success';
    router.reload({ only: ['link', 'allFiles'], preserveScroll: true });
    setTimeout(() => (saveMessage.value = ''), 2500);
  } catch (err) {
    const errors = err?.response?.data?.errors || {};
    const first = Object.values(errors)[0];
    saveMessage.value = Array.isArray(first) ? first[0] : (first || err?.response?.data?.message || 'Fehler beim Speichern');
    saveMessageType.value = 'error';
  } finally {
    saving.value = false;
  }
}
</script>
