<script setup>
import { ref, computed } from "vue";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { ChevronDown, ChevronRight } from "lucide-vue-next";

const props = defineProps({
  title: { type: String, required: true },
  color: { type: String, default: "#a1a1aa" },
  defaultOpen: { type: Boolean, default: false },
  // Optional: Anzahl ausgefuellter / gesamter Felder in der Sektion.
  // Wird rechts neben dem Titel angezeigt (z.B. "3 von 8").
  filled: { type: Number, default: null },
  total:  { type: Number, default: null },
});

const isOpen = ref(props.defaultOpen);

// "3 von 8" nur wenn beide Werte sinnvoll sind.
const showCount = computed(() =>
  props.filled !== null && props.total !== null && props.total > 0
);
const isComplete = computed(() => showCount.value && props.filled >= props.total);
</script>

<template>
  <Collapsible v-model:open="isOpen" class="rounded-xl border border-border overflow-hidden" style="box-shadow:0 1px 3px rgba(0,0,0,0.04)">
    <CollapsibleTrigger class="w-full flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-zinc-50/50 transition-colors" :class="isOpen ? 'bg-gradient-to-b from-zinc-50 to-zinc-100/50 border-b border-border/50' : ''">
      <div class="flex items-center gap-2 min-w-0">
        <div class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="{ background: color }"></div>
        <span class="text-[13px] font-semibold text-foreground tracking-tight truncate">{{ title }}</span>
        <span v-if="showCount"
              class="text-[10.5px] tabular-nums px-1.5 py-0.5 rounded-md flex-shrink-0"
              :class="isComplete ? 'text-emerald-700 bg-emerald-50' : 'text-muted-foreground bg-zinc-100'">
          {{ filled }} von {{ total }}
        </span>
      </div>
      <component :is="isOpen ? ChevronDown : ChevronRight" class="w-3.5 h-3.5 text-muted-foreground flex-shrink-0" />
    </CollapsibleTrigger>
    <CollapsibleContent>
      <div class="p-4 grid grid-cols-2 max-sm:grid-cols-1 gap-4 gap-x-3.5">
        <slot />
      </div>
    </CollapsibleContent>
  </Collapsible>
</template>
