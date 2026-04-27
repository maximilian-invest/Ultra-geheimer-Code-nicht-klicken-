<script setup>
import { computed } from 'vue'
import { Loader2, CheckCircle2, XCircle, X } from 'lucide-vue-next'
import { immojiSyncState, dismissImmojiResult } from '@/composables/immojiSync'

// Eigener Counter waehrend aktivem Sync — damit "Sync laeuft seit Xs"
// fortlaufend ohne setInterval weiterticken kann (wir lesen Date.now()
// bei jedem Render).
const elapsedSeconds = computed(() => {
  if (!immojiSyncState.value.active || !immojiSyncState.value.startedAt) return 0
  return Math.floor((Date.now() - immojiSyncState.value.startedAt) / 1000)
})
</script>

<template>
  <div
    v-if="immojiSyncState.active || immojiSyncState.result"
    class="fixed bottom-4 right-4 z-[60] max-w-sm w-[360px]"
  >
    <!-- Aktiver Sync — kann NICHT weggeklickt werden, verschwindet
         automatisch wenn der Sync abschliesst. -->
    <div
      v-if="immojiSyncState.active"
      class="flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl bg-zinc-900 text-white border border-zinc-800"
    >
      <Loader2 class="w-5 h-5 animate-spin shrink-0 mt-0.5 text-[#EE7600]" />
      <div class="flex-1 min-w-0">
        <div class="text-[10px] font-semibold tracking-widest uppercase text-[#EE7600]">
          Immoji-Sync läuft
        </div>
        <div class="text-sm font-medium truncate mt-0.5">
          {{ immojiSyncState.message }}
        </div>
        <div class="text-[11px] text-zinc-400 mt-1 tabular-nums">
          Du kannst währenddessen weiterarbeiten · {{ elapsedSeconds }}s
        </div>
      </div>
    </div>

    <!-- Ergebnis — MUSS aktiv weggeklickt werden, damit der Makler
         sicher mitbekommt dass der Hintergrund-Sync fertig ist. -->
    <div
      v-else-if="immojiSyncState.result"
      class="flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl border-2"
      :class="immojiSyncState.result.success
        ? 'bg-white border-emerald-300'
        : 'bg-white border-red-300'"
    >
      <component
        :is="immojiSyncState.result.success ? CheckCircle2 : XCircle"
        class="w-5 h-5 shrink-0 mt-0.5"
        :class="immojiSyncState.result.success ? 'text-emerald-600' : 'text-red-600'"
      />
      <div class="flex-1 min-w-0">
        <div
          class="text-[10px] font-semibold tracking-widest uppercase"
          :class="immojiSyncState.result.success ? 'text-emerald-700' : 'text-red-700'"
        >
          {{ immojiSyncState.result.success ? 'Sync abgeschlossen' : 'Sync fehlgeschlagen' }}
        </div>
        <div class="text-sm font-medium text-zinc-900 mt-0.5 truncate">
          {{ immojiSyncState.result.propertyTitle || 'Property' }}
        </div>
        <div class="text-[12px] text-zinc-600 mt-1 leading-snug">
          {{ immojiSyncState.result.message }}
        </div>
      </div>
      <button
        @click="dismissImmojiResult"
        class="text-zinc-400 hover:text-zinc-900 shrink-0 -mr-1 -mt-1 p-1"
        title="Schließen"
      >
        <X class="w-4 h-4" />
      </button>
    </div>
  </div>
</template>
