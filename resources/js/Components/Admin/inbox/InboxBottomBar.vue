<script setup>
import { computed } from "vue"
import { Button } from "@/components/ui/button"
import { Paperclip, CalendarDays, CheckCircle, Send, Loader2, Trash2 } from "lucide-vue-next"

const props = defineProps({
  mode:            { type: String, default: "offen" },
  sending:         { type: Boolean, default: false },
  canSend:         { type: Boolean, default: false },
  attachmentCount: { type: Number, default: 0 },
  showCalendar:    { type: Boolean, default: false },
})

const emit = defineEmits(["send", "markHandled", "toggleAttach", "toggleCalendar", "delete"])

const sendLabel = computed(() => props.mode === "nachfassen" ? "Nachfassen" : "Senden")
</script>

<template>
  <div class="flex-shrink-0 border-t border-zinc-100 bg-background px-4 py-2.5 flex items-center gap-2">
    <!-- Left: Anhang + Calendar -->
    <Button
      variant="outline"
      size="sm"
      class="h-8 gap-1.5 text-xs"
      @click="emit('toggleAttach')"
    >
      <Paperclip class="w-3.5 h-3.5" />
      Anhang
      <span
        v-if="attachmentCount"
        class="ml-0.5 inline-flex items-center justify-center rounded-full bg-primary text-primary-foreground text-[10px] font-medium w-4 h-4 leading-none"
      >
        {{ attachmentCount }}
      </span>
    </Button>

    <Button
      variant="outline"
      size="icon"
      class="w-8 h-8"
      :class="showCalendar ? 'bg-accent' : ''"
      @click="emit('toggleCalendar')"
    >
      <CalendarDays class="w-3.5 h-3.5" />
    </Button>

    <!-- Spacer -->
    <div class="flex-1" />

    <!-- Right: Delete + Erledigt + Send -->
    <Button
      variant="outline"
      size="sm"
      class="h-8 gap-1.5 text-xs text-red-500 hover:text-red-600 hover:bg-red-50 border-red-200"
      @click="emit(delete)"
    >
      <Trash2 class="w-3.5 h-3.5" />
      Löschen
    </Button>
    <Button
      variant="outline"
      size="sm"
      class="h-8 gap-1.5 text-xs text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 border-emerald-200"
      @click="emit('markHandled')"
    >
      <CheckCircle class="w-3.5 h-3.5" />
      Erledigt
    </Button>

    <Button
      size="sm"
      class="h-8 gap-1.5 text-xs"
      :disabled="!canSend || sending"
      @click="emit('send')"
    >
      <Loader2 v-if="sending" class="w-3.5 h-3.5 animate-spin" />
      <Send v-else class="w-3.5 h-3.5" />
      {{ sendLabel }} &rarr;
    </Button>
  </div>
</template>
