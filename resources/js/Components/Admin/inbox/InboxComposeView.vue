<script setup>
import { ref, computed } from "vue";
import { X, Paperclip, Send, Save, Sparkles, Loader2, ChevronDown, RefreshCw, Link2 } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import LinkPickerPopover from "./LinkPickerPopover.vue";
import RichTextEditor from "@/Components/RichTextEditor.vue";

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
});

const emit = defineEmits([
  "update:composeTo", "update:composeSubject", "update:composeBody",
  "update:composeTone", "update:selectedAccountId", "update:composePropertyId",
  "update:composeCc", "update:composeBcc", "update:showCcBcc",
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

      <!-- Objekt -->
      <div class="flex items-center h-9 px-5 border-b border-zinc-100/80 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Objekt</span>
        <Select :model-value="composePropertyId ? String(composePropertyId) : 'none'" @update:model-value="emit('update:composePropertyId', $event === 'none' ? null : $event)">
          <SelectTrigger class="h-7 border-0 shadow-none text-[12px] px-0 bg-transparent focus:ring-0"><SelectValue placeholder="Kein Objekt" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="none" class="text-[12px]">Kein Objekt</SelectItem>
            <SelectItem v-for="p in properties" :key="p.id" :value="String(p.id)" class="text-[12px]">{{ propertyDisplayName(p) }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- Betreff -->
      <div class="flex items-center h-9 px-5 text-[12px]">
        <span class="text-muted-foreground w-14 flex-shrink-0">Betreff</span>
        <Input :model-value="composeSubject" @update:model-value="emit('update:composeSubject', $event)" placeholder="Betreff..." class="h-7 border-0 shadow-none text-[13px] font-medium px-0 bg-transparent focus-visible:ring-0 flex-1" />
      </div>
    </div>

    <!-- Body (main area, matches chat area styling) -->
    <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 bg-white flex flex-col">
      <RichTextEditor
        :model-value="composeBody"
        @update:model-value="emit('update:composeBody', $event)"
        placeholder="Nachricht schreiben..."
        min-height="220px"
        class="flex-1"
      />
      <!-- Signature Preview (read-only, appended automatically on send) -->
      <div v-if="signatureData" class="mt-3 pt-2 border-t border-dashed border-zinc-200 text-[12px] leading-relaxed select-none pointer-events-none">
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

    <!-- Attachments -->
    <div v-if="composeAttachments.length" class="px-5 pb-2 flex flex-wrap gap-1.5 flex-shrink-0">
      <Badge v-for="(att, idx) in composeAttachments" :key="idx" variant="secondary" class="text-[10px] gap-1 pr-1">
        <Paperclip class="h-2.5 w-2.5" />{{ att.name }}
        <button class="ml-0.5 hover:text-destructive" @click="emit('removeAttachment', idx)"><X class="h-2.5 w-2.5" /></button>
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
      <div class="flex items-center gap-1.5 px-4 py-2 bg-zinc-50/80 border-t border-zinc-100">
        <!-- Left: tools -->
        <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" title="Datei anhaengen" @click="fileInputRef?.click()">
          <Paperclip class="h-3 w-3" />
          Datei
        </Button>
        <input ref="fileInputRef" type="file" multiple class="hidden" @change="emit('addAttachments', $event)" />

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
