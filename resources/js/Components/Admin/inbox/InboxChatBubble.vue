<script setup>
import { ref, computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Paperclip } from 'lucide-vue-next'

const props = defineProps({
  message: { type: Object, required: true },
  senderName: { type: String, default: '' },
})

const expanded = ref(false)

const isOutbound = computed(() => {
  const d = props.message.direction
  const c = props.message.category
  return d === 'outbound' || d === 'out' || ['email-out', 'expose', 'nachfassen'].includes(c)
})

const isAutoReply = computed(() => {
  return props.message.category === 'auto-reply' || props.message.is_auto_reply
})

const isNachfassen = computed(() => {
  return props.message.category === 'nachfassen'
})

const typeBadge = computed(() => {
  const m = props.message
  if (isAutoReply.value) {
    return { label: '\u26A1 Auto-Reply', classes: 'bg-emerald-50 text-emerald-700 border-emerald-200' }
  }
  if (m.category === 'nachfassen') {
    const stage = m.followup_stage || 1
    const bg = stage >= 2 ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200'
    return { label: `Nachfassen ${stage}`, classes: bg }
  }
  if (isOutbound.value) {
    return { label: '\u2192 Ausgehend', classes: 'bg-green-50 text-green-700 border-green-200' }
  }
  return { label: '\u2190 Eingehend', classes: 'bg-blue-50 text-blue-700 border-blue-200' }
})

const displayName = computed(() => {
  return props.message.from_name || props.senderName || props.message.from_email || 'Unbekannt'
})

const displayBody = computed(() => {
  const m = props.message
  return m.body_text || m.body || m.full_body || m.ai_summary || m.result || ''
})

const isTruncatable = computed(() => {
  return displayBody.value.length > 150
})

const truncatedBody = computed(() => {
  if (!isTruncatable.value) return displayBody.value
  return displayBody.value.slice(0, 150).trimEnd()
})

const attachments = computed(() => {
  return props.message.attachments || []
})

/* Bubble style classes based on message type */
const bubbleClasses = computed(() => {
  if (isAutoReply.value) {
    return 'bg-emerald-50 border border-emerald-100 text-zinc-800 rounded-xl rounded-bl-sm'
  }
  if (isNachfassen.value) {
    return 'bg-amber-50 border border-amber-100 text-zinc-800 rounded-xl rounded-bl-sm'
  }
  if (isOutbound.value) {
    return 'bg-zinc-100 border border-zinc-200 text-zinc-800 rounded-xl rounded-br-sm'
  }
  /* Inbound */
  return 'bg-blue-50 border border-blue-100 text-zinc-800 rounded-xl rounded-bl-sm'
})

function toggleExpand() {
  if (isTruncatable.value) expanded.value = !expanded.value
}

function formatDate(d) {
  if (!d) return ''
  const date = new Date(d)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="flex w-full" :class="isOutbound ? 'justify-end' : 'justify-start'">
    <div
      class="max-w-[80%] px-4 py-3"
      :class="[
        bubbleClasses,
        isTruncatable && !expanded ? 'cursor-pointer' : '',
      ]"
      @click="toggleExpand"
    >
      <!-- Meta line -->
      <div class="flex items-center gap-2 mb-1 flex-wrap">
        <span class="text-[10px] font-medium opacity-70">{{ displayName }}</span>
        <span class="text-[10px] opacity-50">{{ formatDate(message.email_date || message.activity_date || message.date) }}</span>
        <Badge
          variant="outline"
          class="text-[9px] px-1.5 py-0 h-4 font-normal border"
          :class="typeBadge.classes"
        >
          {{ typeBadge.label }}
        </Badge>
      </div>

      <!-- Body -->
      <div class="text-[13px] leading-relaxed whitespace-pre-wrap">
        <template v-if="!isTruncatable || expanded">{{ displayBody }}</template>
        <template v-else>
          <span class="line-clamp-3">{{ truncatedBody }}...</span>
          <span class="text-[11px] text-blue-600 hover:text-blue-800 font-medium mt-1 inline-block">Mehr anzeigen</span>
        </template>
      </div>

      <!-- Collapse link when expanded -->
      <button
        v-if="isTruncatable && expanded"
        class="text-[11px] text-blue-600 hover:text-blue-800 font-medium mt-1"
        @click.stop="expanded = false"
      >
        Weniger anzeigen
      </button>

      <!-- Attachments -->
      <div v-if="attachments.length" class="mt-2 space-y-1">
        <a
          v-for="(att, i) in attachments"
          :key="i"
          :href="att.url || att.path || '#'"
          target="_blank"
          class="flex items-center gap-1.5 text-[11px] text-zinc-600 opacity-70 hover:opacity-100 transition-opacity"
          @click.stop
        >
          <Paperclip class="w-3 h-3 shrink-0" />
          <span class="truncate">{{ att.name || att.filename || 'Anhang' }}</span>
        </a>
      </div>
    </div>
  </div>
</template>
