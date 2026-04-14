<script setup>
import { ref, computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Paperclip, Download, FolderDown } from 'lucide-vue-next'

const props = defineProps({
  message: { type: Object, required: true },
  senderName: { type: String, default: '' },
})

const emit = defineEmits(['saveAttachment'])
const expanded = ref(false)

const isOutbound = computed(() => {
  const d = props.message.direction
  const c = props.message.category
  return d === 'outbound' || d === 'out' || ['email-out', 'expose', 'nachfassen'].includes(c)
})

const isAutoReply = computed(() => props.message.category === 'auto-reply' || props.message.is_auto_reply)
const isNachfassen = computed(() => props.message.category === 'nachfassen')
const isIntern = computed(() => {
  const cat = (props.message.category || '').toLowerCase()
  const from = (props.message.from_email || '').toLowerCase()
  const to = (props.message.to_email || '').toLowerCase()
  return cat === 'intern' || (from.endsWith('@sr-homes.at') && to.endsWith('@sr-homes.at'))
})

const typeBadge = computed(() => {
  const m = props.message
  if (isAutoReply.value) return { label: '\u26A1 Auto-Reply', classes: 'bg-emerald-50 text-emerald-700 border-emerald-200' }
  if (m.category === 'nachfassen') {
    const stage = m.followup_stage || 1
    const bg = stage >= 2 ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200'
    return { label: `Nachfassen ${stage}`, classes: bg }
  }
  if (isOutbound.value) return { label: '\u2192 Ausgehend', classes: 'bg-green-50 text-green-700 border-green-200' }
  return { label: '\u2190 Eingehend', classes: 'bg-blue-50 text-blue-700 border-blue-200' }
})

const displayName = computed(() => props.message.from_name || props.senderName || props.message.from_email || 'Unbekannt')

// Clean and format email body for display
function cleanEmailBody(raw) {
  if (!raw) return ""
  let text = raw

  // Strip quoted reply chains ("Am ... schrieb ...:" + everything after)
  text = text.replace(/\n\s*Am \d{1,2}\.\d{1,2}\.\d{2,4}.*schrieb.*:[\s\S]*$/im, "")
  // Strip "> " quoted lines at the end
  const lines = text.split("\n")
  let cutIdx = lines.length
  for (let i = lines.length - 1; i >= 0; i--) {
    if (lines[i].match(/^\s*>/) || lines[i].match(/^\s*\|/)) {
      cutIdx = i
    } else if (lines[i].trim() === "") {
      // skip blank
    } else {
      break
    }
  }
  text = lines.slice(0, cutIdx).join("\n")

  // Strip forwarded headers block
  text = text.replace(/\n-{3,}\s*(Weitergeleitete Nachricht|Forwarded message|Original Message)\s*-{3,}[\s\S]*$/im, "")

  // Strip signature blocks (after --)
  text = text.replace(/\n--\s*\n[\s\S]*$/m, "")

  // Collapse 3+ consecutive blank lines to 2
  text = text.replace(/(\n\s*){3,}/g, "\n\n")

  return text.trim()
}

const rawBody = computed(() => props.message.full_body || props.message.body_text || props.message.body || props.message.ai_summary || props.message.result || "")
const displayBody = computed(() => cleanEmailBody(rawBody.value))
const hasQuotedContent = computed(() => rawBody.value.length > displayBody.value.length + 20)

const isTruncatable = computed(() => displayBody.value.length > 300)
const truncatedBody = computed(() => isTruncatable.value ? displayBody.value.slice(0, 300).trimEnd() : displayBody.value)

// Parse attachments from has_attachment + attachment_names OR attachments array
const attachments = computed(() => {
  const m = props.message
  // If already an array, use it
  if (Array.isArray(m.attachments) && m.attachments.length) return m.attachments
  // Parse from attachment_names string
  if (m.has_attachment && m.attachment_names) {
    const names = typeof m.attachment_names === 'string'
      ? m.attachment_names.split(',').map(n => n.trim()).filter(Boolean)
      : []
    return names.map((name, idx) => ({ name, index: idx }))
  }
  return []
})

const bubbleClasses = computed(() => {
  if (isIntern.value) return 'bg-sky-50 border border-sky-100 text-zinc-800 rounded-xl rounded-bl-sm'
  if (isAutoReply.value) return 'bg-emerald-50 border border-emerald-100 text-zinc-800 rounded-xl rounded-bl-sm'
  if (isNachfassen.value) return 'bg-amber-50 border border-amber-100 text-zinc-800 rounded-xl rounded-bl-sm'
  if (isOutbound.value) return 'bg-zinc-100 border border-zinc-100 text-zinc-800 rounded-xl rounded-br-sm'
  return 'bg-blue-50 border border-blue-100 text-zinc-800 rounded-xl rounded-bl-sm'
})

function toggleExpand() {
  if (isTruncatable.value) expanded.value = !expanded.value
}

function formatDate(d) {
  if (!d) return ''
  const date = new Date(d)
  if (isNaN(date.getTime())) return ''
  const now = new Date()
  const isToday = date.toDateString() === now.toDateString()
  const time = date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
  if (isToday) return time
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  return day + '.' + month + '. ' + time
}

function onSaveAttachment(att, idx) {
  emit('saveAttachment', {
    emailId: props.message.id || props.message.email_id,
    fileIndex: att.index !== undefined ? att.index : idx,
    filename: att.name || att.filename || 'Anhang',
    propertyId: props.message.property_id || null,
  })
}
</script>

<template>
  <div class="flex w-full" :class="isOutbound ? 'justify-end' : 'justify-start'">
    <div
      class="max-w-[80%] px-4 py-3"
      :class="[bubbleClasses, isTruncatable && !expanded ? 'cursor-pointer' : '']"
      @click="toggleExpand"
    >
      <!-- Meta line -->
      <div class="flex items-center gap-2 mb-1 flex-wrap">
        <span class="text-[10px] font-medium opacity-70">{{ displayName }}</span>
        <span class="text-[10px] opacity-50">{{ formatDate(message.email_date || message.activity_date || message.date) }}</span>
        <Badge variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border" :class="typeBadge.classes">
          {{ typeBadge.label }}
        </Badge>
      </div>

      <!-- Body -->
      <div class="text-[13px] leading-[1.6] whitespace-pre-wrap break-words">
        <template v-if="!isTruncatable || expanded">{{ displayBody }}</template>
        <template v-else>
          <span>{{ truncatedBody }}...</span>
          <button class="text-[11px] text-blue-600 hover:text-blue-800 font-medium mt-1 block" @click.stop="expanded = true">Mehr anzeigen</button>
        </template>
      </div>

      <!-- Collapse + Quoted toggle -->
      <div v-if="expanded" class="flex items-center gap-2 mt-1">
        <button v-if="isTruncatable" class="text-[11px] text-blue-600 hover:text-blue-800 font-medium" @click.stop="expanded = false">
          Weniger anzeigen
        </button>
        <button v-if="hasQuotedContent" class="text-[10px] text-zinc-400 hover:text-zinc-600 font-medium" @click.stop="expanded = expanded === 'full' ? true : 'full'">
          {{ expanded === 'full' ? 'Zitat ausblenden' : '... Zitierte Nachricht' }}
        </button>
      </div>
      <div v-if="expanded === 'full'" class="text-[12px] leading-relaxed whitespace-pre-wrap text-zinc-400 mt-1 pl-2 border-l-2 border-zinc-200">{{ rawBody }}</div>

      <!-- Attachments -->
      <div v-if="attachments.length" class="mt-2 pt-2 border-t border-black/5 space-y-1">
        <div v-for="(att, i) in attachments" :key="i" class="flex items-center gap-1.5 group/att">
          <Paperclip class="w-3 h-3 shrink-0 text-zinc-500" />
          <span class="text-[11px] text-zinc-700 truncate flex-1">{{ att.name || att.filename || 'Anhang' }}</span>
          <!-- Save to property button -->
          <button
            class="opacity-0 group-hover/att:opacity-100 flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 border border-orange-200 transition-all"
            @click.stop="onSaveAttachment(att, i)"
            title="Zum Objekt speichern"
          >
            <FolderDown class="w-3 h-3" />
            Speichern
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
