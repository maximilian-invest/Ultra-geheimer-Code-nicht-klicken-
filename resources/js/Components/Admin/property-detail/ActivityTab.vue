<script setup>
import { ClipboardList, MessageCircle, ChevronRight } from "lucide-vue-next";

defineProps({
  property: { type: Object, required: true },
});

const emit = defineEmits(["openActivities", "openMessages"]);
</script>

<template>
  <div class="space-y-3">
    <div
      class="flex items-center gap-3 p-4 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors border border-border"
      @click="emit('openActivities', property.id, property.address)"
    >
      <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-cyan-50">
        <ClipboardList class="w-5 h-5 text-cyan-600" />
      </div>
      <div class="flex-1">
        <div class="text-[13px] font-semibold text-foreground">Protokoll & Einträge</div>
        <div class="text-[11px] mt-0.5 text-muted-foreground">Aktivitäten, Notizen und Protokoll verwalten</div>
      </div>
      <ChevronRight class="w-4 h-4 text-muted-foreground" />
    </div>

    <div
      class="flex items-center gap-3 p-4 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors border border-border"
      @click="emit('openMessages', property.id, property.address)"
    >
      <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-blue-50">
        <MessageCircle class="w-5 h-5 text-blue-600" />
      </div>
      <div class="flex-1">
        <div class="text-[13px] font-semibold text-foreground">Nachrichten</div>
        <div class="text-[11px] mt-0.5 text-muted-foreground">Portal-Kommunikation und Nachrichten</div>
      </div>
      <ChevronRight class="w-4 h-4 text-muted-foreground" />
    </div>
  </div>
</template>
