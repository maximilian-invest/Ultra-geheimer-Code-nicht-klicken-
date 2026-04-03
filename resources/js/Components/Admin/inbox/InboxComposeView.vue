<script setup>
import { ref, computed, watch } from "vue";
import {
  X, Paperclip, Send, Save, Sparkles, Loader2, ChevronDown
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from "@/components/ui/select";

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
});

const emit = defineEmits([
  "update:composeTo",
  "update:composeSubject",
  "update:composeBody",
  "update:composeTone",
  "update:selectedAccountId",
  "update:composePropertyId",
  "update:composeCc",
  "update:composeBcc",
  "update:showCcBcc",
  "send",
  "saveDraft",
  "close",
  "searchContacts",
  "selectContact",
  "blurContactSearch",
  "generateAiReply",
  "applyTemplate",
  "addAttachments",
  "removeAttachment",
]);

const fileInputRef = ref(null);
const showTemplateMenu = ref(false);

function onFileClick() {
  fileInputRef.value?.click();
}

function onFileChange(e) {
  emit("addAttachments", e);
}

function removeAttachment(idx) {
  emit("removeAttachment", idx);
}

const tones = [
  { value: "professional", label: "Professionell" },
  { value: "friendly", label: "Freundlich" },
  { value: "formal", label: "Formell" },
  { value: "casual", label: "Locker" },
];

const isReply = computed(() => !!props.replyContext);
const headerTitle = computed(() => isReply.value ? "Antworten" : "Neue Nachricht");
</script>

<template>
  <div class="flex flex-col h-full bg-background">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-border flex-shrink-0">
      <span class="text-[14px] font-semibold text-foreground">{{ headerTitle }}</span>
      <Button variant="ghost" size="icon" class="h-7 w-7" @click="emit('close')">
        <X class="h-4 w-4" />
      </Button>
    </div>

    <!-- Reply Context Banner -->
    <div
      v-if="replyContext"
      class="px-4 py-2 bg-muted/50 border-b border-border text-[11px] text-muted-foreground"
    >
      <span class="font-medium">Antwort an:</span>
      {{ replyContext.stakeholder }}
      <span v-if="replyContext.ref_id" class="ml-1 text-[10px]">({{ replyContext.ref_id }})</span>
    </div>

    <!-- Form Fields -->
    <div class="flex flex-col gap-2 px-4 pt-3 flex-shrink-0">
      <!-- Von (Account) -->
      <div class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">Von</span>
        <Select
          :model-value="selectedAccountId ? String(selectedAccountId) : ''"
          @update:model-value="emit('update:selectedAccountId', $event)"
        >
          <SelectTrigger class="h-8 text-[12px] flex-1">
            <SelectValue placeholder="Konto waehlen..." />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="acc in emailAccounts"
              :key="acc.id"
              :value="String(acc.id)"
              class="text-[12px]"
            >
              {{ acc.label || acc.email || acc.name }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- An (To) with autocomplete -->
      <div class="flex items-center gap-2 relative">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">An</span>
        <div class="flex-1 relative">
          <Input
            :model-value="composeTo"
            @update:model-value="emit('update:composeTo', $event)"
            @input="emit('searchContacts', $event.target.value)"
            @blur="emit('blurContactSearch')"
            placeholder="E-Mail-Adresse..."
            class="h-8 text-[12px]"
          />
          <!-- Contact autocomplete dropdown -->
          <div
            v-if="showContactSearch && (contactSearchResults.length || contactSearchLoading)"
            class="absolute z-50 top-full left-0 right-0 mt-1 bg-popover border border-border rounded-md shadow-lg max-h-[200px] overflow-y-auto"
          >
            <div v-if="contactSearchLoading" class="px-3 py-2 text-[11px] text-muted-foreground flex items-center gap-2">
              <Loader2 class="h-3 w-3 animate-spin" /> Suche...
            </div>
            <div
              v-for="contact in contactSearchResults"
              :key="contact.email"
              class="px-3 py-2 text-[12px] hover:bg-accent cursor-pointer flex items-center justify-between"
              @mousedown.prevent="emit('selectContact', contact)"
            >
              <span class="font-medium truncate">{{ contact.name || contact.email }}</span>
              <span class="text-[10px] text-muted-foreground ml-2 flex-shrink-0">{{ contact.email }}</span>
            </div>
          </div>
        </div>
        <!-- Cc/Bcc toggle -->
        <Button
          variant="ghost"
          size="sm"
          class="text-[10px] h-6 px-2 text-muted-foreground"
          @click="emit('update:showCcBcc', !showCcBcc)"
        >
          Cc/Bcc
        </Button>
      </div>

      <!-- Cc -->
      <div v-if="showCcBcc" class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">Cc</span>
        <Input
          :model-value="composeCc"
          @update:model-value="emit('update:composeCc', $event)"
          placeholder="Cc..."
          class="h-8 text-[12px] flex-1"
        />
      </div>

      <!-- Bcc -->
      <div v-if="showCcBcc" class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">Bcc</span>
        <Input
          :model-value="composeBcc"
          @update:model-value="emit('update:composeBcc', $event)"
          placeholder="Bcc..."
          class="h-8 text-[12px] flex-1"
        />
      </div>

      <!-- Objekt (Property) -->
      <div class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">Objekt</span>
        <Select
          :model-value="composePropertyId ? String(composePropertyId) : 'none'"
          @update:model-value="emit('update:composePropertyId', $event === 'none' ? null : $event)"
        >
          <SelectTrigger class="h-8 text-[12px] flex-1">
            <SelectValue placeholder="Kein Objekt" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="none" class="text-[12px]">Kein Objekt</SelectItem>
            <SelectItem
              v-for="p in properties"
              :key="p.id"
              :value="String(p.id)"
              class="text-[12px]"
            >
              {{ p.ref_id || p.address || ('Obj ' + p.id) }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- Betreff -->
      <div class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground w-[50px] flex-shrink-0">Betreff</span>
        <Input
          :model-value="composeSubject"
          @update:model-value="emit('update:composeSubject', $event)"
          placeholder="Betreff..."
          class="h-8 text-[12px] flex-1"
        />
      </div>

      <!-- Tone + AI + Template row -->
      <div class="flex items-center gap-2">
        <Select
          :model-value="composeTone"
          @update:model-value="emit('update:composeTone', $event)"
        >
          <SelectTrigger class="h-7 w-[130px] text-[11px]">
            <SelectValue placeholder="Ton" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="t in tones"
              :key="t.value"
              :value="t.value"
              class="text-[11px]"
            >
              {{ t.label }}
            </SelectItem>
          </SelectContent>
        </Select>

        <Button
          variant="outline"
          size="sm"
          class="h-7 text-[11px] gap-1"
          :disabled="aiLoading"
          @click="emit('generateAiReply')"
        >
          <Sparkles v-if="!aiLoading" class="h-3 w-3" />
          <Loader2 v-else class="h-3 w-3 animate-spin" />
          KI-Entwurf
        </Button>

        <!-- Template selector -->
        <div class="relative ml-auto">
          <Button
            variant="ghost"
            size="sm"
            class="h-7 text-[11px] gap-1"
            @click="showTemplateMenu = !showTemplateMenu"
          >
            Template
            <ChevronDown class="h-3 w-3" />
          </Button>
          <div
            v-if="showTemplateMenu && templates.length"
            class="absolute z-50 top-full right-0 mt-1 bg-popover border border-border rounded-md shadow-lg min-w-[200px] max-h-[250px] overflow-y-auto"
          >
            <div
              v-for="tpl in templates"
              :key="tpl.id"
              class="px-3 py-2 text-[12px] hover:bg-accent cursor-pointer"
              @click="emit('applyTemplate', tpl); showTemplateMenu = false"
            >
              <div class="font-medium">{{ tpl.name }}</div>
              <div v-if="tpl.category" class="text-[10px] text-muted-foreground">{{ tpl.category }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Body Textarea -->
    <div class="flex-1 px-4 py-2 min-h-0 overflow-hidden">
      <Textarea
        :model-value="composeBody"
        @update:model-value="emit('update:composeBody', $event)"
        placeholder="Nachricht schreiben..."
        class="h-full min-h-[300px] resize-none text-[13px] leading-relaxed border-0 shadow-none focus-visible:ring-0 p-0"
      />
    </div>

    <!-- Attachments list -->
    <div v-if="composeAttachments.length" class="px-4 pb-1 flex flex-wrap gap-1.5">
      <Badge
        v-for="(att, idx) in composeAttachments"
        :key="idx"
        variant="secondary"
        class="text-[10px] gap-1 pr-1"
      >
        <Paperclip class="h-2.5 w-2.5" />
        {{ att.name }}
        <button
          class="ml-0.5 hover:text-destructive"
          @click="removeAttachment(idx)"
        >
          <X class="h-2.5 w-2.5" />
        </button>
      </Badge>
    </div>

    <!-- Bottom Bar -->
    <div class="flex items-center gap-2 px-4 py-3 border-t border-border flex-shrink-0">
      <!-- Attachment button -->
      <Button variant="ghost" size="icon" class="h-8 w-8" @click="onFileClick">
        <Paperclip class="h-4 w-4" />
      </Button>
      <input
        ref="fileInputRef"
        type="file"
        multiple
        class="hidden"
        @change="onFileChange"
      />

      <div class="flex-1" />

      <!-- Save Draft -->
      <Button
        variant="outline"
        size="sm"
        class="h-8 text-[12px] gap-1.5"
        @click="emit('saveDraft')"
      >
        <Save class="h-3.5 w-3.5" />
        Entwurf speichern
      </Button>

      <!-- Send -->
      <Button
        size="sm"
        class="h-8 text-[12px] gap-1.5"
        :disabled="sending"
        @click="emit('send')"
      >
        <Loader2 v-if="sending" class="h-3.5 w-3.5 animate-spin" />
        <Send v-else class="h-3.5 w-3.5" />
        Senden
      </Button>
    </div>
  </div>
</template>
