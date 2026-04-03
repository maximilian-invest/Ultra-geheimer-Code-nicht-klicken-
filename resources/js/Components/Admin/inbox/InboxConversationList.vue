<script setup>
import { computed } from "vue";
import { Search, Loader2, Plus } from "lucide-vue-next";
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
});

const emit = defineEmits(["select", "update:searchQuery", "update:objectFilter", "compose"]);

const isGrouped = computed(() => props.groupedSections && props.groupedSections.length > 0);
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Toolbar -->
    <div class="flex items-center gap-1.5 px-3 py-2 border-b border-zinc-100 flex-shrink-0">
      <!-- Search -->
      <div class="relative flex-1 min-w-0">
        <Search class="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
        <Input
          :model-value="searchQuery"
          @update:model-value="emit('update:searchQuery', $event)"
          placeholder="Suchen..."
          class="pl-7 h-8 text-[12px]"
        />
      </div>

      <!-- Object Filter -->
      <Select
        :model-value="objectFilter"
        @update:model-value="emit('update:objectFilter', $event)"
      >
        <SelectTrigger class="h-8 w-[110px] text-[11px] flex-shrink-0">
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

      <!-- Compose Button -->
      <Button
        variant="ghost"
        size="icon"
        class="h-8 w-8 flex-shrink-0"
        @click="emit('compose')"
      >
        <Plus class="h-4 w-4" />
      </Button>
    </div>

    <!-- List Area -->
    <div class="flex-1 overflow-y-auto min-h-0">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-12">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <!-- Empty -->
      <div
        v-else-if="!isGrouped && items.length === 0"
        class="flex items-center justify-center py-12"
      >
        <span class="text-[12px] text-muted-foreground">{{ emptyMessage }}</span>
      </div>

      <!-- Flat List -->
      <div v-else-if="!isGrouped" class="divide-y divide-zinc-100">
        <InboxConversationItem
          v-for="item in items"
          :key="item.id"
          :item="item"
          :active="selectedId != null && String(item.id) === String(selectedId)"
          :subtab="subtab"
          @click="emit('select', item)"
        />
      </div>

      <!-- Grouped List (nachfassen) -->
      <div v-else>
        <template v-for="section in groupedSections" :key="section.label">
          <div
            v-if="section.items && section.items.length"
          >
            <!-- Section Header -->
            <div class="sticky top-0 z-10 px-3 py-1.5 bg-muted/50 backdrop-blur-sm border-b border-zinc-100">
              <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ section.label }}
                <span class="ml-1 font-normal">({{ section.items.length }})</span>
              </span>
            </div>

            <!-- Section Items -->
            <div class="divide-y divide-zinc-100">
              <InboxConversationItem
                v-for="item in section.items"
                :key="item.id"
                :item="item"
                :active="selectedId != null && String(item.id) === String(selectedId)"
                :subtab="subtab"
                @click="emit('select', item)"
              />
            </div>
          </div>
        </template>

        <!-- Empty grouped -->
        <div
          v-if="groupedSections.every(s => !s.items || s.items.length === 0)"
          class="flex items-center justify-center py-12"
        >
          <span class="text-[12px] text-muted-foreground">{{ emptyMessage }}</span>
        </div>
      </div>
    </div>
    <!-- Neue Nachricht Button -->
    <div class="p-3 border-t border-zinc-100 flex-shrink-0">
      <button @click="emit('compose')" class="w-full h-9 flex items-center justify-center gap-2 text-[12px] font-medium text-zinc-600 bg-white border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-colors">
        <Plus class="w-3.5 h-3.5" />
        Neue Nachricht
      </button>
    </div>
  </div>
</template>
