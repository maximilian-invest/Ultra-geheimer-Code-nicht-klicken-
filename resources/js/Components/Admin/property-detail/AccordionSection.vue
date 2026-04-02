<script setup>
import { ref } from "vue";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { ChevronDown, ChevronRight } from "lucide-vue-next";

const props = defineProps({
  title: { type: String, required: true },
  color: { type: String, default: "#a1a1aa" },
  defaultOpen: { type: Boolean, default: false },
});

const isOpen = ref(props.defaultOpen);
</script>

<template>
  <Collapsible v-model:open="isOpen" class="rounded-xl border border-border overflow-hidden" style="box-shadow:0 1px 3px rgba(0,0,0,0.04)">
    <CollapsibleTrigger class="w-full flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-zinc-50/50 transition-colors" :class="isOpen ? 'bg-gradient-to-b from-zinc-50 to-zinc-100/50 border-b border-border/50' : ''">
      <div class="flex items-center gap-2">
        <div class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="{ background: color }"></div>
        <span class="text-[13px] font-semibold text-foreground tracking-tight">{{ title }}</span>
      </div>
      <component :is="isOpen ? ChevronDown : ChevronRight" class="w-3.5 h-3.5 text-muted-foreground" />
    </CollapsibleTrigger>
    <CollapsibleContent>
      <div class="p-4 grid grid-cols-2 max-sm:grid-cols-1 gap-4 gap-x-3.5">
        <slot />
      </div>
    </CollapsibleContent>
  </Collapsible>
</template>
