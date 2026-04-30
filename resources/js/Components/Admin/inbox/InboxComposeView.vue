<script setup>
import { ref, computed, watch, inject } from "vue";
import { X, Paperclip, Send, Save, Sparkles, Loader2, ChevronDown, RefreshCw, Link2, Home, FileText, Check } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import LinkPickerPopover from "./LinkPickerPopover.vue";
import PropertyAssignDialog from "./PropertyAssignDialog.vue";
import RichTextEditor from "@/Components/RichTextEditor.vue";

const API = inject("API");

const props = defineProps({
  composeTo: { type: String, default: "" },
  composeSubject: { type: String, default: "" },
  composeBody: { type: String, default: "" },
  composeTone: { type: String, default: "professional" },
  selectedAccountId: { type: [String, Number], default: null },
  emailAccounts: { type: Array, default: () => [] },
  composeAttachments: { type: Array, default: () => [] },
  sending: { type: Boolean, default: false },
  aiLoading: { type: Boolean, default: false },
  contactSearchResults: { type: Array, default: () => [] },
  contactSearchLoading: { type: Boolean, default: false },
  showContactSearch: { type: Boolean, default: false },
  properties: { type: Array, default: () => [] },
  composePropertyId: { type: [String, Number], default: null },
  templates: { type: Array, default: () => [] },
  composeCc: { type: String, default: "" },
  composeBcc: { type: String, default: "" },
  showCcBcc: { type: Boolean, default: false },
  replyContext: { type: Object, default: null },
  signatureData: { type: Object, default: null },
  // NEU: bereits hochgeladene Dateien (property_files, portal_documents,
  // global_files) per ID auswaehlbar machen — gleiche Quellen wie im
  // OwnerComposeDialog. Wert ist eine Liste von IDs (int oder
  // 'doc_<id>' / 'global_<id>'-prefix-Strings).
  composeFileIds: { type: Array, default: () => [] },
});

const emit = defineEmits([
  "update:composeTo", "update:composeSubject", "update:composeBody",
  "update:composeTone", "update:selectedAccountId", "update:composePropertyId",
  "update:composeCc", "update:composeBcc", "update:showCcBcc",
  "update:composeFileIds",
  "send", "saveDraft", "close", "searchContacts", "selectContact",
  "blurContactSearch", "generateAiReply", "applyTemplate",
  "addAttachments", "removeAttachment", "improveWording",
]);

const fileInputRef = ref(null);
const showTemplateMenu = ref(false);
const linkPickerOpen = ref(false);
const isReply = computed(() => !!props.replyContext);
const headerTitle = computed(() => isReply.value ? "Antworten" : "Neue Nachricht");

const linkPickerPropertyId = computed(() => {
  const raw = props.composePropertyId;
  if (raw === null || raw === undefined || raw === "") return null;
  const num = Number(raw);
  return Number.isFinite(num) ? num : null;
});

function insertLinkBlock(link) {
  const html = `
<div style="border:1px solid #E5E0D8; border-radius:12px; padding:16px; margin:16px 0; background:#FAF8F5; font-family:Outfit,sans-serif;">
  <div style="font-weight:500; color:#D4743B; font-size:14px;">Ihre Unterlagen</div>
  <a href="${link.url}" style="color:#0A0A08; text-decoration:none; font-weight:500;">
    ${link.name} · ${link.document_ids.length} Dokumente
  </a>
  ${link.expires_at ? `<div style="font-size:13px; color:#5A564E; margin-top:4px;">Gueltig bis ${new Date(link.expires_at).toLocaleDateString('de-AT')}</div>` : ''}
</div>
`.trim();

  // composeBody is bound via v-model on the parent; emit the updated value.
  // TODO: wire to real editor insertion point when a rich-text editor is introduced.
  const next = (props.composeBody || '') + '\n' + html;
  emit('update:composeBody', next);
  linkPickerOpen.value = false;
}

const senderLabel = computed(() => {
  if (!props.selectedAccountId || !props.emailAccounts.length) return "";
  const acc = props.emailAccounts.find(a => String(a.id) === String(props.selectedAccountId));
  return acc?.email || acc?.label || "";
});

function resolveSignatureUrl(url) {
  const raw = String(url || "").trim();
  if (!raw) return "";
  if (typeof window === "undefined") return raw;
  const isLocalUi = ["localhost", "127.0.0.1"].includes(window.location.hostname);
  const isLocalAsset = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//i.test(raw);
  if (isLocalUi && isLocalAsset) {
    return raw.replace(/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/i, "https://kundenportal.sr-homes.at");
  }
  return raw;
}

function propertyDisplayName(p) {
  if (!p) return "";
  const ref = String(p.ref_id || "").trim();
  const title = String(p.title || "").trim();
  const address = String(p.address || "").trim();
  const city = String(p.city || "").trim();
  if (ref && address) return `${ref} - ${address}`;
  if (ref && city) return `${ref} - ${city}`;
  if (ref) return ref;
  if (title && address) return `${title} - ${address}`;
  if (title) return title;
  if (address && city) return `${address}, ${city}`;
  if (address) return address;
  if (city) return city;
  return `Objekt ohne Bezeichnung (#${p.id})`;
}

const propertyLabel = computed(() => {
  if (!props.composePropertyId || !props.properties.length) return null;
  const p = props.properties.find(pr => String(pr.id) === String(props.composePropertyId));
  return p ? propertyDisplayName(p) : null;
});

// Compose-Property-Picker: oeffnet den gleichen PropertyAssignDialog wie im
// Chat-View "Objekt zuordnen" — ein-und-dasselbe UI, ein-und-dieselbe Liste.
const propertyDialogOpen = ref(false);

const selectedPropertyObj = computed(() => {
  if (!props.composePropertyId) return null;
  return (props.properties || []).find(p => String(p.id) === String(props.composePropertyId)) || null;
});

function openPropertyDialog() {
  propertyDialogOpen.value = true;
}

function onPropertyAssign(payload) {
  // payload: { property_id: number|null, migrate_activities: boolean }
  // Im Compose-Flow ignorieren wir migrate_activities (gibt es noch nicht).
  emit('update:composePropertyId', payload.property_id || null);
  propertyDialogOpen.value = false;
}

// ── Datei-Picker (bereits hochgeladene property_files / portal_documents) ──
const filePickerOpen = ref(false);
const propertyFiles = ref([]);
const propertyFilesLoading = ref(false);

const selectedFileIdsSet = computed(() => new Set(props.composeFileIds.map((x) => String(x))));
const selectedFileLabels = computed(() => {
  // Anzahl ausgewaehlter "Bibliothek"-Dateien fuer Counter im Button
  return props.composeFileIds.length;
});

async function loadPropertyFiles() {
  if (!props.composePropertyId) {
    propertyFiles.value = [];
    return;
  }
  propertyFilesLoading.value = true;
  try {
    const r = await fetch(API.value + '&action=get_property_files&property_id=' + props.composePropertyId);
    const d = await r.json();
    propertyFiles.value = d.files || [];
  } catch (e) {
    propertyFiles.value = [];
  } finally {
    propertyFilesLoading.value = false;
  }
}

function toggleFile(item) {
  const idStr = String(item.id);
  const current = [...props.composeFileIds];
  // composeFileIds enthaelt int IDs fuer property_files, 'doc_<id>'-Strings
  // fuer portal_documents — wir vergleichen string-coerced.
  const idx = current.findIndex((x) => String(x) === idStr);
  if (idx >= 0) current.splice(idx, 1);
  else current.push(idStr.startsWith('doc_') ? idStr : (isNaN(parseInt(idStr)) ? idStr : parseInt(idStr)));
  emit('update:composeFileIds', current);
}

function isFileSelected(item) {
  return selectedFileIdsSet.value.has(String(item.id));
}

function toggleFilePicker() {
  filePickerOpen.value = !filePickerOpen.value;
  if (filePickerOpen.value && propertyFiles.value.length === 0) {
    loadPropertyFiles();
  }
}

function fmtBytes(n) {
  if (!n) return '';
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
  return (n / 1024 / 1024).toFixed(1) + ' MB';
}

// Bei Property-Wechsel: Dateien neu laden, Auswahl resetten (alte property
// hat andere Dateien, alte IDs sind nicht mehr gueltig).
watch(() => props.composePropertyId, (newVal) => {
  emit('update:composeFileIds', []);
  propertyFiles.value = [];
  if (newVal && filePickerOpen.value) {
    loadPropertyFiles();
  }
});
</script>

<template>
  <div class="flex-1 min-w-0 flex flex-col h-full overflow-hidden bg-white">
    <!-- Header (matches InboxChatView) -->
    <div class="flex-shrink-0 border-b border-zinc-100 px-5 py-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <h2 class="text-[15px] font-semibold leading-snug">{{ headerTitle }}</h2>
          <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <Badge v-if="composeTo" variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal">
              {{ composeTo }}
            </Badge>
            <Badge v-if="propertyLabel" variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal bg-muted/50">
              {{ propertyLabel }}
            </Badge>
            <Badge v-if="replyContext" variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal bg-blue-50 text-blue-700 border-blue-200">
              Antwort
            </Badge>
          </div>
        </div>
        <Button variant="outline" size="icon" class="w-7 h-7 flex-shrink-0" @click="emit('close')">
          <X class="w-4 h-4" />
        </Button>
      </div>
    </div>

    <!-- Compact form fields -->
    <div class="flex-shrink-0 bg-zinc-50/50">
      <!-- Von -->
      <div class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Von</span>
        <Select :model-value="selectedAccountId ? String(selectedAccountId) : ''" @update:model-value="emit('update:selectedAccountId', $event)">
          <SelectTrigger class="h-7 border-0 shadow-none text-[12px] px-0 bg-transparent focus:ring-0 text-foreground font-medium [&_span]:text-foreground">
            <SelectValue placeholder="Konto..." />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="acc in emailAccounts" :key="acc.id" :value="String(acc.id)" class="text-[12px] text-foreground font-medium">
              {{ acc.email_address || acc.email || acc.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- An -->
      <div class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px] relative">
        <span class="text-muted-foreground w-14 flex-shrink-0">An</span>
        <Input :model-value="composeTo" @update:model-value="emit('update:composeTo', $event)" @input="emit('searchContacts', $event.target.value)" @blur="emit('blurContactSearch')" placeholder="E-Mail-Adresse..." class="h-7 border-0 shadow-none text-[12px] px-0 bg-transparent focus-visible:ring-0 flex-1" />
        <button class="text-[10px] text-muted-foreground hover:text-foreground ml-2 flex-shrink-0" @click="emit('update:showCcBcc', !showCcBcc)">Cc/Bcc</button>
        <div v-if="showContactSearch && (contactSearchResults.length || contactSearchLoading)" class="absolute z-50 top-full left-14 right-5 mt-0.5 bg-white border border-zinc-200 rounded-lg shadow-lg max-h-[180px] overflow-y-auto">
          <div v-if="contactSearchLoading" class="px-3 py-2 text-[11px] text-muted-foreground flex items-center gap-2"><Loader2 class="h-3 w-3 animate-spin" /> Suche...</div>
          <div v-for="c in contactSearchResults" :key="c.email" class="px-3 py-2 text-[12px] hover:bg-zinc-50 cursor-pointer flex justify-between" @mousedown.prevent="emit('selectContact', c)">
            <span class="font-medium truncate">{{ c.name || c.email }}</span>
            <span class="text-[10px] text-muted-foreground ml-2">{{ c.email }}</span>
          </div>
        </div>
      </div>

      <!-- Cc/Bcc -->
      <div v-if="showCcBcc" class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Cc</span>
        <Input :model-value="composeCc" @update:model-value="emit('update:composeCc', $event)" class="h-7 border-0 shadow-none text-[12px] px-0 bg-transparent focus-visible:ring-0 flex-1" />
      </div>
      <div v-if="showCcBcc" class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Bcc</span>
        <Input :model-value="composeBcc" @update:model-value="emit('update:composeBcc', $event)" class="h-7 border-0 shadow-none text-[12px] px-0 bg-transparent focus-visible:ring-0 flex-1" />
      </div>

      <!-- Objekt — gleicher Picker wie Chat-View "Objekt zuordnen" (Bilder, Ref-ID, Suche, broker-gefiltert) -->
      <div class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Objekt</span>
        <button
          type="button"
          class="flex items-center gap-2 flex-1 min-w-0 h-7 px-0 bg-transparent text-left hover:opacity-80 transition-opacity"
          @click="openPropertyDialog"
        >
          <template v-if="selectedPropertyObj">
            <div class="w-5 h-5 rounded overflow-hidden shrink-0 bg-zinc-100 flex items-center justify-center">
              <img v-if="selectedPropertyObj.thumbnail_url" :src="selectedPropertyObj.thumbnail_url" alt="" class="w-full h-full object-cover" loading="lazy" />
              <Home v-else class="w-3 h-3 text-[#EE7600]" />
            </div>
            <span class="font-mono text-[11px] font-semibold tracking-tight text-zinc-900 shrink-0">{{ selectedPropertyObj.ref_id || ('Obj ' + selectedPropertyObj.id) }}</span>
            <span class="text-zinc-600 truncate">
              {{ selectedPropertyObj.address || selectedPropertyObj.title || selectedPropertyObj.project_name || '' }}<span v-if="selectedPropertyObj.city" class="text-muted-foreground"> · {{ selectedPropertyObj.city }}</span>
            </span>
          </template>
          <span v-else class="text-muted-foreground">Kein Objekt</span>
          <ChevronDown class="w-3.5 h-3.5 text-muted-foreground ml-auto flex-shrink-0" />
        </button>
      </div>

      <!-- Property-Picker-Dialog (gleiche Komponente wie im Chat "Objekt zuordnen") -->
      <PropertyAssignDialog
        :open="propertyDialogOpen"
        @update:open="propertyDialogOpen = $event"
        :current-property-id="composePropertyId"
        :properties="properties"
        :hide-migrate="true"
        title="Objekt auswählen"
        unassign-label="Kein Objekt"
        unassign-hint="Mail ohne Objekt-Zuordnung versenden"
        @confirm="onPropertyAssign"
      />

      <!-- Betreff -->
      <div class="flex items-center h-9 px-5 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Betreff</span>
        <Input :model-value="composeSubject" @update:model-value="emit('update:composeSubject', $event)" placeholder="Betreff..." class="h-7 border-0 shadow-none text-[13px] font-medium px-0 bg-transparent focus-visible:ring-0 flex-1" />
      </div>
    </div>

    <!-- Body (main area). Outer-Container scrollt NICHT — sonst rutscht
         die Toolbar oben aus dem sichtbaren Bereich raus, wenn Editor +
         Signatur zusammen zu hoch werden. Der RichTextEditor scrollt
         selbst intern (Toolbar via flex-shrink:0 fix oben), die Signatur-
         Preview bleibt als kompakter Footer-Bereich unten sichtbar. -->
    <div class="flex-1 min-h-0 px-5 py-4 bg-white flex flex-col overflow-hidden">
      <RichTextEditor
        :model-value="composeBody"
        @update:model-value="emit('update:composeBody', $event)"
        placeholder="Nachricht schreiben..."
        min-height="220px"
        class="flex-1 min-h-0"
      />
      <!-- Signature Preview (read-only, appended automatically on send) -->
      <div v-if="signatureData" class="mt-3 pt-2 border-t border-dashed border-zinc-200 text-[12px] leading-relaxed select-none pointer-events-none flex-shrink-0 max-h-[140px] overflow-hidden">
        <div class="text-zinc-300">--</div>
        <img
          v-if="signatureData.signature_logo_url"
          :src="resolveSignatureUrl(signatureData.signature_logo_url)"
          alt="Signatur-Logo"
          class="max-h-10 object-contain mt-1 mb-2"
        />
        <div class="flex items-start gap-3">
          <img
            v-if="signatureData.signature_photo_url"
            :src="resolveSignatureUrl(signatureData.signature_photo_url)"
            alt="Signatur-Foto"
            class="w-[56px] h-[72px] object-cover rounded"
          />
          <div>
            <div v-if="signatureData.signature_name" class="text-zinc-500 font-medium">{{ signatureData.signature_name }}</div>
            <div v-if="signatureData.signature_title" class="text-zinc-400">{{ signatureData.signature_title }}</div>
            <div v-if="signatureData.signature_company" class="text-zinc-400">{{ signatureData.signature_company }}</div>
            <div v-if="signatureData.signature_phone" class="text-zinc-400">Tel: {{ signatureData.signature_phone }}</div>
            <div v-if="signatureData.signature_website" class="text-zinc-400">{{ signatureData.signature_website }}</div>
          </div>
        </div>
        <img
          v-if="signatureData.signature_banner_url"
          :src="resolveSignatureUrl(signatureData.signature_banner_url)"
          alt="Signatur-Banner"
          class="max-w-[320px] w-full rounded mt-2"
        />
      </div>
      <div v-else class="mt-3 pt-2 border-t border-dashed border-zinc-200 text-[12px] leading-relaxed select-none pointer-events-none">
        <div class="text-zinc-300">--</div>
        <div class="text-zinc-400">SR-Homes Immobilien GmbH</div>
        <div class="text-zinc-400">www.sr-homes.at</div>
      </div>
    </div>

    <!-- Attachments (frische Uploads + bereits-hochgeladene Dateien per ID) -->
    <div v-if="composeAttachments.length || composeFileIds.length" class="px-5 pb-2 flex flex-wrap gap-1.5 flex-shrink-0">
      <Badge v-for="(att, idx) in composeAttachments" :key="'up-'+idx" variant="secondary" class="text-[10px] gap-1 pr-1">
        <Paperclip class="h-2.5 w-2.5" />{{ att.name }}
        <button class="ml-0.5 hover:text-destructive" @click="emit('removeAttachment', idx)"><X class="h-2.5 w-2.5" /></button>
      </Badge>
      <Badge v-for="fid in composeFileIds" :key="'fid-'+fid" variant="secondary" class="text-[10px] gap-1 pr-1 bg-orange-50 border border-orange-200 text-orange-900">
        <FileText class="h-2.5 w-2.5" />
        {{ (propertyFiles.find(f => String(f.id) === String(fid))?.label) || (propertyFiles.find(f => String(f.id) === String(fid))?.filename) || ('Datei #' + fid) }}
        <button class="ml-0.5 hover:text-destructive" @click="toggleFile({ id: fid })"><X class="h-2.5 w-2.5" /></button>
      </Badge>
    </div>

    <!-- Bottom action bar (matches AiDraft action bar style) -->
    <div class="flex-shrink-0 relative">
      <LinkPickerPopover
        v-if="linkPickerOpen && linkPickerPropertyId"
        :property-id="linkPickerPropertyId"
        @close="linkPickerOpen = false"
        @pick="insertLinkBlock"
      />

      <!-- File-Picker-Popover: Liste der property_files / portal_documents
           des gewaehlten Objekts. Nur sichtbar wenn ein Objekt zugeordnet
           und der Picker explizit geoeffnet ist. -->
      <div
        v-if="filePickerOpen"
        class="absolute bottom-12 left-4 w-[360px] max-h-[420px] bg-white border border-zinc-200 rounded-xl shadow-lg z-30 flex flex-col overflow-hidden"
        @click.stop
      >
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-100 flex-shrink-0">
          <h4 class="text-[13px] font-semibold">Aus bestehenden Dateien wählen</h4>
          <button class="text-zinc-500 hover:text-zinc-900 text-lg leading-none" @click="filePickerOpen = false">×</button>
        </div>

        <div class="flex-1 overflow-y-auto p-2">
          <div v-if="!composePropertyId" class="px-3 py-4 text-center text-[11px] text-zinc-500">
            Bitte zuerst ein Objekt auswählen.
          </div>
          <div v-else-if="propertyFilesLoading" class="px-3 py-4 text-center text-[11px] text-zinc-500 flex items-center justify-center gap-1.5">
            <Loader2 class="h-3 w-3 animate-spin" /> Lade Dateien…
          </div>
          <div v-else-if="!propertyFiles.length" class="px-3 py-4 text-center text-[11px] text-zinc-500">
            Keine Dateien zu diesem Objekt hinterlegt.
          </div>
          <button
            v-for="f in propertyFiles" :key="String(f.id)"
            type="button"
            @click="toggleFile(f)"
            class="w-full flex items-center gap-2 px-2.5 py-2 rounded-md text-left text-[12px] mb-0.5 transition-colors"
            :class="isFileSelected(f) ? 'bg-orange-50 border border-orange-200 text-orange-900' : 'hover:bg-zinc-50 border border-transparent'"
          >
            <span
              class="w-3.5 h-3.5 rounded-sm border flex items-center justify-center shrink-0"
              :class="isFileSelected(f) ? 'bg-[#EE7600] border-[#EE7600]' : 'bg-white border-zinc-300'"
            >
              <Check v-if="isFileSelected(f)" class="w-2.5 h-2.5 text-white" />
            </span>
            <FileText class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
            <span class="flex-1 min-w-0 truncate">
              <span class="font-medium">{{ f.label || f.filename }}</span>
              <span v-if="f.label && f.label !== f.filename" class="text-zinc-500"> · {{ f.filename }}</span>
            </span>
            <span class="text-[10px] text-zinc-500 tabular-nums shrink-0">{{ fmtBytes(f.file_size) }}</span>
          </button>
        </div>
      </div>

      <div class="flex items-center gap-1.5 px-4 py-2 bg-zinc-50/80 border-t border-zinc-100">
        <!-- Left: tools -->
        <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" title="Datei vom Computer anhaengen" @click="fileInputRef?.click()">
          <Paperclip class="h-3 w-3" />
          Datei
        </Button>
        <input ref="fileInputRef" type="file" multiple class="hidden" @change="emit('addAttachments', $event)" />

        <Button
          variant="outline"
          size="sm"
          class="h-7 text-[11px] gap-1"
          :disabled="!composePropertyId"
          :title="composePropertyId ? 'Aus bereits hochgeladenen Dateien dieses Objekts wählen' : 'Bitte zuerst ein Objekt auswählen'"
          @click="toggleFilePicker"
        >
          <FileText class="h-3 w-3" />
          Aus Dateien
          <span v-if="selectedFileLabels" class="ml-0.5 text-[#EE7600] font-semibold tabular-nums">({{ selectedFileLabels }})</span>
        </Button>

        <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" :disabled="aiLoading" @click="emit('generateAiReply')">
          <Sparkles v-if="!aiLoading" class="h-3 w-3" /><Loader2 v-else class="h-3 w-3 animate-spin" />
          KI
        </Button>

        <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" :disabled="aiLoading || !composeBody?.trim()" @click="emit('improveWording')">
          <RefreshCw class="h-3 w-3" />
          Wording
        </Button>

        <Button
          variant="outline"
          size="sm"
          class="h-7 text-[11px] gap-1"
          :disabled="!linkPickerPropertyId"
          :title="linkPickerPropertyId ? 'Tracked Docs-Link einfuegen' : 'Bitte zuerst ein Objekt auswaehlen'"
          @click="linkPickerOpen = !linkPickerOpen"
        >
          <Link2 class="h-3 w-3" />
          Link einfuegen
        </Button>

        <div class="relative">
          <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" @click="showTemplateMenu = !showTemplateMenu">
            Template <ChevronDown class="h-3 w-3" />
          </Button>
          <div v-if="showTemplateMenu && templates.length" class="absolute z-50 bottom-full left-0 mb-1 bg-white border border-zinc-200 rounded-lg shadow-lg min-w-[200px] max-h-[200px] overflow-y-auto">
            <div v-for="tpl in templates" :key="tpl.id" class="px-3 py-2 text-[12px] hover:bg-zinc-50 cursor-pointer" @click="emit('applyTemplate', tpl); showTemplateMenu = false">
              <div class="font-medium">{{ tpl.name }}</div>
            </div>
          </div>
        </div>

        <div class="flex-1" />

        <!-- Right: save + send -->
        <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" @click="emit('saveDraft')">
          <Save class="h-3 w-3" /> Entwurf
        </Button>
        <Button size="sm" class="h-7 text-[11px] gap-1" :disabled="sending" @click="emit('send')">
          <Loader2 v-if="sending" class="h-3 w-3 animate-spin" /><Send v-else class="h-3 w-3" />
          Senden
        </Button>
      </div>
    </div>
  </div>
</template>
