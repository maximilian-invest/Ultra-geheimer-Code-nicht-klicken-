<script setup>
import { computed, ref, inject } from "vue";
import { Search, Loader2, Plus, RefreshCw, Flag } from "lucide-vue-next";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import InboxConversationItem from "./InboxConversationItem.vue";

const props = defineProps({
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  subtab: { type: String, default: "offen" },
  selectedId: { type: [Number, String], default: null },
  searchQuery: { type: String, default: "" },
  objectFilter: { type: String, default: "all" },
  properties: { type: Array, default: () => [] },
  emptyMessage: { type: String, default: "Keine Konversationen" },
  groupedSections: { type: Array, default: () => [] },
  /** Zeigt Aktualisieren neben dem Objekt-Filter (Inbox-Listen neu laden). */
  showToolbarRefresh: { type: Boolean, default: true },
  toolbarRefreshing: { type: Boolean, default: false },
});

const emit = defineEmits(["select", "update:searchQuery", "update:objectFilter", "compose", "delete", "batchDone", "batchTrash", "toolbarRefresh"]);

// Optionaler Flag-Kontext: Filter "nur markierte / nach Farbe" + Settings-Trigger.
const flagContext = inject("inboxFlags", null);
const flagLabels = computed(() => flagContext?.labels?.value || {});
const flagFilter = ref("");

// Frontend-Filter: items werden vom Parent geliefert. Wir filtern hier in
// der Liste, damit der Filter sofort wirkt ohne Roundtrip.
const visibleItems = computed(() => {
  if (!flagFilter.value) return props.items || [];
  if (flagFilter.value === "any") return (props.items || []).filter(i => !!i.flag_color);
  return (props.items || []).filter(i => i.flag_color === flagFilter.value);
});
const visibleGroupedSections = computed(() => {
  if (!flagFilter.value) return props.groupedSections || [];
  return (props.groupedSections || []).map(s => ({
    ...s,
    items: (s.items || []).filter(i => flagFilter.value === "any" ? !!i.flag_color : i.flag_color === flagFilter.value),
  }));
});

const selectedIds = ref(new Set());
const selectMode = ref(false);

function toggleSelect(id) {
  if (selectedIds.value.has(id)) selectedIds.value.delete(id);
  else selectedIds.value.add(id);
  selectedIds.value = new Set(selectedIds.value); // trigger reactivity
}

function selectAll(items) {
  if (selectedIds.value.size === items.length) {
    selectedIds.value = new Set();
  } else {
    selectedIds.value = new Set(items.map(i => i.id));
  }
}

function batchDone() {
  emit("batchDone", [...selectedIds.value]);
  selectedIds.value = new Set();
  selectMode.value = false;
}

const isGrouped = computed(() => props.groupedSections && props.groupedSections.length > 0);

const flagFilterColorClass = computed(() => {
  switch (flagFilter.value) {
    case "red":    return "text-red-500";
    case "orange": return "text-orange-500";
    case "yellow": return "text-yellow-500";
    case "green":  return "text-emerald-500";
    case "blue":   return "text-blue-500";
    case "purple": return "text-purple-500";
    case "any":    return "text-zinc-700";
    default:       return "text-zinc-400";
  }
});

const collapsedSections = ref({});
function toggleSection(label) {
  collapsedSections.value[label] = !collapsedSections.value[label];
}
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Toolbar: Suche eigene Zeile, Filter/Aktionen darunter -->
    <div class="flex flex-col gap-1.5 border-b border-zinc-100 px-3 py-1.5 flex-shrink-0">
      <div class="relative w-full min-w-0">
        <Search class="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
        <Input
          :model-value="searchQuery"
          @update:model-value="emit('update:searchQuery', $event)"
          placeholder="Suchen..."
          class="w-full pl-7 h-8 text-[12px]"
        />
      </div>

      <div class="flex min-w-0 flex-wrap items-center gap-1.5">
        <!-- Object Filter -->
        <Select
          :model-value="objectFilter"
          @update:model-value="emit('update:objectFilter', $event)"
        >
          <SelectTrigger class="h-8 w-[110px] text-[11px] shrink-0">
            <SelectValue placeholder="Objekt" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all" class="text-[11px]">Alle Objekte</SelectItem>
            <SelectItem
              v-for="p in properties"
              :key="p.id"
              :value="String(p.id)"
              class="text-[11px]"
            >
              {{ p.ref_id || 'Obj ' + p.id }}
            </SelectItem>
          </SelectContent>
        </Select>

        <slot name="toolbar-inline" />

        <div class="min-w-[2px] min-h-[1px] flex-1 basis-0"></div>

        <Button
          v-if="showToolbarRefresh"
          type="button"
          variant="outline"
          size="icon-sm"
          class="h-8 w-8 shrink-0 border-zinc-200 text-foreground"
          :disabled="toolbarRefreshing"
          title="Aktualisieren — Listen und geöffnete Konversation neu laden"
          @click="emit('toolbarRefresh')"
        >
          <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': toolbarRefreshing }" />
        </Button>

        <Button
          variant="ghost"
          size="icon"
          class="h-8 w-8 shrink-0"
          :class="selectMode ? 'bg-accent' : ''"
          @click="selectMode = !selectMode; if (!selectMode) selectedIds = new Set()"
          title="Mehrfachauswahl"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
        </Button>

        <Button
          variant="ghost"
          size="icon"
          class="h-8 w-8 shrink-0"
          @click="emit('compose')"
        >
          <Plus class="h-4 w-4" />
        </Button>

        <slot name="toolbar-icons-end" />
      </div>
    </div>

    <slot name="under-toolbar" />

    <!-- List Area -->
    <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-12">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <!-- Empty -->
      <div
        v-else-if="!isGrouped && visibleItems.length === 0"
        class="flex items-center justify-center py-12"
      >
        <span class="text-[12px] text-muted-foreground">
          {{ flagFilter ? 'Keine Konversation mit dieser Markierung' : emptyMessage }}
        </span>
      </div>

      <!-- Flat List -->
      <div v-else-if="!isGrouped" class="divide-y divide-zinc-100">
        <div v-for="item in visibleItems" :key="item.id" class="flex items-center min-w-0">
          <input
            v-if="selectMode"
            type="checkbox"
            :checked="selectedIds.has(item.id)"
            @change="toggleSelect(item.id)"
            class="ml-2 mr-1 rounded border-zinc-300 text-primary w-4 h-4 flex-shrink-0 cursor-pointer"
          />
          <InboxConversationItem
            class="flex-1"
            :item="item"
            :active="selectedId != null && String(item.id) === String(selectedId)"
            :subtab="subtab"
            @click="selectMode ? toggleSelect(item.id) : emit('select', item)" @delete="emit('delete', $event)"
          />
        </div>
      </div>

      <!-- Grouped List (nachfassen) -->
      <div v-else>
        <template v-for="section in visibleGroupedSections" :key="section.label">
          <div
            v-if="section.items && section.items.length"
          >
            <!-- Section Header (clickable to collapse) -->
            <div
              class="sticky top-0 z-10 px-3 py-1.5 bg-zinc-50 backdrop-blur-sm border-b border-zinc-100 cursor-pointer select-none flex items-center justify-between hover:bg-zinc-100/80 transition-colors"
              @click="toggleSection(section.label)"
            >
              <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ section.label }}
                <span class="ml-1 font-normal">({{ section.items.length }})</span>
              </span>
              <svg
                class="w-3 h-3 text-muted-foreground transition-transform"
                :class="collapsedSections[section.label] ? '-rotate-90' : ''"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </div>

            <!-- Section Items -->
            <div v-show="!collapsedSections[section.label]" class="divide-y divide-zinc-100">
              <div v-for="item in section.items" :key="item.id" class="flex items-center min-w-0">
                <input
                  v-if="selectMode"
                  type="checkbox"
                  :checked="selectedIds.has(item.id)"
                  @change="toggleSelect(item.id)"
                  class="ml-2 mr-1 rounded border-zinc-300 text-primary w-4 h-4 flex-shrink-0 cursor-pointer"
                />
                <InboxConversationItem
                  class="flex-1"
                  :item="item"
                  :active="selectedId != null && String(item.id) === String(selectedId)"
                  :subtab="subtab"
                  @click="selectMode ? toggleSelect(item.id) : emit('select', item)" @delete="emit('delete', $event)"
                />
              </div>
            </div>
          </div>
        </template>

        <!-- Empty grouped -->
        <div
          v-if="visibleGroupedSections.every(s => !s.items || s.items.length === 0)"
          class="flex items-center justify-center py-12"
        >
          <span class="text-[12px] text-muted-foreground">
            {{ flagFilter ? 'Keine Konversation mit dieser Markierung' : emptyMessage }}
          </span>
        </div>
      </div>
    </div>
    <!-- Bottom Bar -->
    <div class="p-3 border-t border-zinc-100 flex-shrink-0">
      <div v-if="selectMode && selectedIds.size > 0" class="flex items-center gap-2">
        <span class="text-[11px] text-muted-foreground flex-1">{{ selectedIds.size }} ausgewählt</span>
        <button v-if="subtab === 'offen' || subtab === 'nachfassen'" @click="batchDone()" class="h-8 px-3 flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          Alle erledigt
        </button>
        <button v-else @click="emit('batchTrash', [...selectedIds]); selectedIds = new Set(); selectMode = false;" class="h-8 px-3 flex items-center gap-1.5 text-[11px] font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
          Papierkorb
        </button>
      </div>
      <button v-else @click="emit('compose')" class="w-full h-9 flex items-center justify-center gap-2 text-[12px] font-medium text-zinc-600 bg-white border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-colors">
        <Plus class="w-3.5 h-3.5" />
        Neue Nachricht
      </button>
    </div>
  </div>
</template>
