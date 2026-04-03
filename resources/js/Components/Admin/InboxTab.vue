<script setup>
import { ref, inject, onMounted, computed, watch, provide } from "vue";
import { catBadgeStyle, catLabel, catIsInbound } from "@/utils/categoryBadge.js";
import {
  Mail, Clock, Send, CheckCircle, X, ChevronDown, CalendarDays,
  Paperclip, Loader2, Search, Sparkles, ArrowUp, ArrowDown,
  PenSquare, History, FileEdit, Trash2, Inbox, LayoutTemplate, Plus
} from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

// === INJECTIONS (same as PrioritiesTab + CommsTab) ===
const API = inject("API");
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const unmatchedCount = inject("unmatchedCount");
const refreshCounts = inject("refreshCounts", () => {});
const properties = inject("properties");
const calendarEmbedUrl = inject("calendarEmbedUrl", "");
const userType = inject("userType", ref("makler"));

// === SUBTAB STATE ===
const activeSubtab = ref(localStorage.getItem("sr-admin-inboxview") || "offen");
watch(activeSubtab, (v) => localStorage.setItem("sr-admin-inboxview", v));

// === SELECTED CONVERSATION ===
const selectedItem = ref(null);
const selectedMode = ref("offen");

// === PROVIDE TO CHILDREN ===
provide("inboxAPI", API);
provide("inboxToast", toast);
provide("inboxProperties", properties);
provide("inboxCalendarUrl", calendarEmbedUrl);
</script>

<template>
  <div class="flex flex-col h-full" style="min-height:0">
    <!-- Subtab Navigation -->
    <div class="flex items-center gap-1 px-4 pt-3 pb-0 border-b border-border">
      <button
        v-for="st in [
          { key: 'offen', label: 'Offen', count: unansweredCount, color: 'bg-red-50 text-red-600' },
          { key: 'nachfassen', label: 'Nachfassen', count: (followupCount || 0), color: 'bg-amber-50 text-amber-700' },
          { key: 'posteingang', label: 'Posteingang', count: null, color: '' },
          { key: 'gesendet', label: 'Gesendet', count: null, color: '' },
          { key: 'entwuerfe', label: 'Entwürfe', count: null, color: '' },
          { key: 'templates', label: 'Templates', count: null, color: '' },
        ]"
        :key="st.key"
        @click="activeSubtab = st.key"
        class="px-3 py-2 text-[12px] font-medium transition-colors border-b-2 -mb-px whitespace-nowrap"
        :class="activeSubtab === st.key
          ? 'border-foreground text-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground'"
      >
        {{ st.label }}
        <span v-if="st.count" class="ml-1 text-[10px] font-bold px-1.5 py-0 rounded-full" :class="st.color">{{ st.count }}</span>
      </button>
    </div>

    <!-- Content Area: Split Panel -->
    <div class="flex flex-1 min-h-0">
      <!-- Left: Conversation List (placeholder) -->
      <div class="w-[400px] flex-shrink-0 border-r border-border flex flex-col h-full overflow-hidden">
        <div class="p-4 text-sm text-muted-foreground">Konversationsliste wird geladen...</div>
      </div>

      <!-- Right: Chat View (placeholder) -->
      <div class="flex-1 min-w-0 flex flex-col h-full overflow-hidden items-center justify-center">
        <div class="text-sm text-muted-foreground">Konversation auswählen</div>
      </div>
    </div>
  </div>
</template>
