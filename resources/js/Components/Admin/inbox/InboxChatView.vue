<script setup>
import { computed, inject, ref } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { X, Loader2, Clock } from 'lucide-vue-next'
import InboxChatBubble from './InboxChatBubble.vue'

const props = defineProps({
  item: { type: Object, required: true },
  messages: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
})

const emit = defineEmits(['close'])
const bgImage = inject("inboxBgImage", ref(""));

// ── Header badges ──
const contactBadge = computed(() => {
  const name = props.item.from_name || props.item.stakeholder || ''
  const email = props.item.from_email || props.item.contact_email || ''
  if (name && email) return `${name} <${email}>`
  return name || email || 'Unbekannt'
})

const refId = computed(() => props.item.ref_id || props.item.property_ref || null)

const platform = computed(() => {
  const p = props.item.platform || props.item.source || ''
  if (!p) return null
  const map = {
    willhaben: 'Willhaben',
    immoscout: 'ImmoScout',
    immo: 'Immo',
    website: 'Website',
    manual: 'Manuell',
  }
  return map[p.toLowerCase()] || p
})

const isNachfassen = computed(() => props.mode === 'nachfassen')
const daysWaiting = computed(() => props.item.days_waiting || props.item.days_since_last || 0)

// ── Date grouping ──
const groupedMessages = computed(() => {
  if (!props.messages?.length) return []

  const groups = []
  let currentKey = null

  const sorted = [...props.messages].sort((a, b) => {
    const da = new Date(a.email_date || a.activity_date || a.date || 0)
    const db = new Date(b.email_date || b.activity_date || b.date || 0)
    return da - db
  })

  for (const msg of sorted) {
    const raw = msg.email_date || msg.activity_date || msg.date
    const dateKey = raw ? new Date(raw).toDateString() : 'unknown'
    if (dateKey !== currentKey) {
      currentKey = dateKey
      groups.push({ dateKey, label: formatDateLabel(raw), messages: [] })
    }
    groups[groups.length - 1].messages.push(msg)
  }

  return groups
})

function formatDateLabel(raw) {
  if (!raw) return ''
  const d = new Date(raw)
  if (isNaN(d.getTime())) return ''
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const target = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diff = today - target

  if (diff === 0) return 'Heute'
  if (diff === 86400000) return 'Gestern'

  const day = d.getDate()
  const months = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
  ]
  return `${day}. ${months[d.getMonth()]} ${d.getFullYear()}`
}
</script>

<template>
  <div class="flex-1 min-w-0 flex flex-col h-full overflow-hidden" :class="bgImage ? 'bg-white/80 backdrop-blur-md' : 'bg-white'">
    <!-- Header -->
    <div class="flex-shrink-0 border-b border-zinc-100 px-5 py-3">
      <div class="flex items-start justify-between gap-3">
        <!-- Subject + badges -->
        <div class="min-w-0 flex-1">
          <h2 class="text-[15px] font-semibold leading-snug truncate">
            {{ item.subject || item.activity || 'Kein Betreff' }}
          </h2>
          <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <Badge variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal">
              {{ contactBadge }}
            </Badge>
            <Badge v-if="refId" variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal bg-muted/50">
              {{ refId }}
            </Badge>
            <Badge v-if="platform" variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal bg-muted/50">
              {{ platform }}
            </Badge>
            <Badge
              v-if="isNachfassen && daysWaiting"
              variant="outline"
              class="text-[10px] px-1.5 py-0 h-5 font-normal bg-amber-50 text-amber-700 border-amber-200"
            >
              <Clock class="w-3 h-3 mr-0.5" />
              {{ daysWaiting }}d wartend
            </Badge>
          </div>
        </div>

        <!-- Close button -->
        <Button
          variant="outline"
          size="icon"
          class="w-7 h-7 flex-shrink-0"
          @click="emit('close')"
        >
          <X class="w-4 h-4" />
        </Button>
      </div>
    </div>

    <!-- Chat area -->
    <div class="flex-1 overflow-y-auto px-5 py-4" :class="bgImage ? 'bg-white/60' : 'bg-white'">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center h-full">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
      </div>

      <!-- Empty -->
      <div v-else-if="!groupedMessages.length" class="flex items-center justify-center h-full text-sm text-muted-foreground">
        Keine Nachrichten
      </div>

      <!-- Messages grouped by date -->
      <template v-else>
        <div v-for="(group, gi) in groupedMessages" :key="gi">
          <!-- Date divider -->
          <div class="flex items-center gap-3 my-4" v-if="group.label">
            <div class="flex-1 h-px bg-zinc-100" />
            <span class="text-[11px] text-muted-foreground font-medium whitespace-nowrap">{{ group.label }}</span>
            <div class="flex-1 h-px bg-zinc-100" />
          </div>

          <!-- Messages -->
          <div class="space-y-3">
            <InboxChatBubble
              v-for="(msg, mi) in group.messages"
              :key="msg.id || mi"
              :message="msg"
              :sender-name="item.from_name || item.stakeholder || ''"
            />
          </div>
        </div>

        <!-- No-Reply Card -->
        <div
          v-if="isNachfassen && daysWaiting"
          class="flex justify-center mt-6 mb-2"
        >
          <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 max-w-sm text-center">
            <div class="text-2xl mb-1">&#9200;</div>
            <div class="text-sm font-semibold text-amber-800">
              Seit {{ daysWaiting }} Tagen keine Antwort
            </div>
            <div class="text-[11px] text-amber-600 mt-1">
              <template v-if="item.last_action">
                Letzte Aktion: {{ item.last_action }}
              </template>
              <template v-else-if="item.last_contact_date">
                Letzter Kontakt: {{ item.last_contact_date }}
              </template>
              <template v-else>
                Nachfass-Erinnerung aktiv
              </template>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Slots below chat -->
    <div class="flex-shrink-0"><slot name="ai-draft" /></div>
    <div class="flex-shrink-0"><slot name="bottom-bar" /></div>
  </div>
</template>
