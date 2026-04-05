<script setup>
import { computed, inject, ref, watch } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { X, Loader2, Clock, ChevronLeft, ChevronDown, ChevronUp } from 'lucide-vue-next'
import InboxChatBubble from './InboxChatBubble.vue'
import InboxMatchCard from './InboxMatchCard.vue'

const props = defineProps({
  item: { type: Object, required: true },
  messages: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
})

const emit = defineEmits(['close', 'saveAttachment', 'matchDraft', 'matchDismiss'])
const bgGradient = inject("inboxBgGradient", ref(""));
const bgOpacity = inject("inboxBgOpacity", ref(0.15));
const API = inject('API')

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

// ── Match panel ──
const hasMatches = computed(() => props.item.match_count > 0 && !props.item.match_dismissed)
const matchOpen = ref(false)
const matchLoading = ref(false)
const matchGenerating = ref(false)
const matches = ref([])
const criteria = ref(null)
const selectedIds = ref(new Set())

const selectedCount = computed(() => selectedIds.value.size)
const criteriaPills = computed(() => {
  if (!criteria.value) return []
  const pills = []
  if (criteria.value.object_types?.length) pills.push(...criteria.value.object_types)
  if (criteria.value.min_area) pills.push('ab ' + criteria.value.min_area + ' m\u00B2')
  if (criteria.value.max_price) pills.push('bis \u20AC ' + Number(criteria.value.max_price).toLocaleString('de-AT'))
  if (criteria.value.locations?.length) pills.push(...criteria.value.locations.slice(0, 3))
  if (criteria.value.features?.length) pills.push(...criteria.value.features.slice(0, 2))
  return pills
})

watch(() => props.item.id, () => {
  matchOpen.value = false
  matches.value = []
  criteria.value = null
  selectedIds.value = new Set()
})

async function toggleMatchPanel() {
  matchOpen.value = !matchOpen.value
  if (matchOpen.value && !matches.value.length) {
    matchLoading.value = true
    try {
      const r = await fetch(API.value + '&action=match_list&conversation_id=' + props.item.id)
      const d = await r.json()
      criteria.value = d.criteria
      matches.value = d.matches || []
      matches.value.forEach(m => {
        if (m.score >= 70) selectedIds.value.add(m.property_id)
      })
      selectedIds.value = new Set(selectedIds.value)
    } catch (e) {
      console.error('Failed to load matches', e)
    } finally {
      matchLoading.value = false
    }
  }
}

function toggleSelection(propertyId) {
  if (selectedIds.value.has(propertyId)) {
    selectedIds.value.delete(propertyId)
  } else {
    selectedIds.value.add(propertyId)
  }
  selectedIds.value = new Set(selectedIds.value)
}

async function generateDraft() {
  if (selectedCount.value === 0) return
  matchGenerating.value = true
  try {
    const r = await fetch(API.value + '&action=match_generate_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        conversation_id: props.item.id,
        property_ids: [...selectedIds.value],
      }),
    })
    const d = await r.json()
    if (!d.error) {
      matchOpen.value = false
      emit('matchDraft', {
        draft_body: d.draft_body,
        draft_subject: d.draft_subject,
        draft_to: d.draft_to,
        file_ids: d.file_ids || [],
        file_map: d.file_map || [],
      })
    }
  } catch (e) {
    console.error('Failed to generate draft', e)
  } finally {
    matchGenerating.value = false
  }
}

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
    'J\u00E4nner', 'Februar', 'M\u00E4rz', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
  ]
  return `${day}. ${months[d.getMonth()]} ${d.getFullYear()}`
}
</script>

<template>
  <div class="flex-1 min-w-0 flex flex-col h-full overflow-hidden bg-white" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">
    <!-- Header -->
    <div class="flex-shrink-0 border-b border-zinc-100 px-5 py-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <button class="md:hidden mr-2 flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-md hover:bg-zinc-100 -ml-1" @click="emit('close')"><ChevronLeft class="w-5 h-5" /></button>
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
        <Button variant="outline" size="icon" class="w-7 h-7 flex-shrink-0" @click="emit('close')">
          <X class="w-4 h-4" />
        </Button>
      </div>
    </div>

    <!-- Match banner (collapsed / expanded) -->
    <div v-if="hasMatches" class="flex-shrink-0 border-b border-zinc-100">
      <!-- Collapsed banner -->
      <button
        @click="toggleMatchPanel"
        class="w-full flex items-center justify-between px-5 py-2.5 hover:bg-zinc-50/50 transition-colors"
      >
        <div class="flex items-center gap-2.5">
          <div class="w-6 h-6 rounded-md bg-gradient-to-br from-violet-500 to-cyan-500 flex items-center justify-center flex-shrink-0">
            <span class="text-white text-[10px] font-bold">&starf;</span>
          </div>
          <span class="text-[13px] font-medium">
            {{ item.match_count }} passende{{ item.match_count === 1 ? 's Objekt' : ' Objekte' }} gefunden
          </span>
          <Badge
            v-for="pill in criteriaPills.slice(0, 3)"
            :key="pill"
            variant="outline"
            class="text-[9px] px-1.5 py-0 h-4 font-normal hidden sm:inline-flex"
          >{{ pill }}</Badge>
        </div>
        <component :is="matchOpen ? ChevronUp : ChevronDown" class="w-4 h-4 text-muted-foreground flex-shrink-0" />
      </button>

      <!-- Expanded panel -->
      <div v-if="matchOpen" class="border-t border-zinc-100">
        <!-- Criteria pills (full) -->
        <div v-if="criteriaPills.length" class="px-5 py-2 flex items-center gap-1.5 flex-wrap bg-zinc-50/50">
          <span class="text-[10px] text-muted-foreground font-medium">Suchkriterien:</span>
          <Badge v-for="pill in criteriaPills" :key="pill" variant="outline" class="text-[10px] px-1.5 py-0 h-4">
            {{ pill }}
          </Badge>
        </div>

        <!-- Match cards -->
        <div class="px-5 py-3 space-y-2 max-h-[320px] overflow-y-auto">
          <div v-if="matchLoading" class="flex items-center justify-center py-8">
            <Loader2 class="w-5 h-5 animate-spin text-violet-500" />
          </div>
          <template v-else>
            <InboxMatchCard
              v-for="m in matches"
              :key="m.property_id"
              :match="m"
              :selected="selectedIds.has(m.property_id)"
              @toggle="toggleSelection"
            />
          </template>
        </div>

        <!-- Action bar -->
        <div v-if="!matchLoading && matches.length" class="px-5 py-2.5 border-t border-zinc-100 flex items-center justify-between bg-zinc-50/30">
          <span class="text-[12px] text-muted-foreground">
            <strong class="text-violet-600">{{ selectedCount }}</strong> ausgew&auml;hlt
          </span>
          <div class="flex items-center gap-2">
            <Button variant="ghost" size="sm" class="h-7 text-[11px] text-muted-foreground" @click="emit('matchDismiss')">
              &Uuml;berspringen
            </Button>
            <Button
              size="sm"
              :disabled="selectedCount === 0 || matchGenerating"
              class="h-7 text-[11px] bg-gradient-to-r from-violet-500 to-cyan-500 text-white hover:opacity-90 disabled:opacity-50"
              @click="generateDraft"
            >
              {{ matchGenerating ? 'Generiere...' : '\u2726 Entwurf generieren' }}
            </Button>
          </div>
        </div>
      </div>
    </div>

    <!-- Chat area -->
    <div class="flex-1 overflow-y-auto px-5 py-4 bg-white" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">
      <div v-if="loading" class="flex items-center justify-center h-full">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
      </div>
      <div v-else-if="!groupedMessages.length" class="flex items-center justify-center h-full text-sm text-muted-foreground">
        Keine Nachrichten
      </div>
      <template v-else>
        <div v-for="(group, gi) in groupedMessages" :key="gi">
          <div class="flex items-center gap-3 my-4" v-if="group.label">
            <div class="flex-1 h-px bg-zinc-100" />
            <span class="text-[11px] text-muted-foreground font-medium whitespace-nowrap">{{ group.label }}</span>
            <div class="flex-1 h-px bg-zinc-100" />
          </div>
          <div class="space-y-3">
            <InboxChatBubble
              v-for="(msg, mi) in group.messages"
              :key="msg.id || mi"
              :message="msg"
              :sender-name="item.from_name || item.stakeholder || ''"
              @save-attachment="emit('saveAttachment', $event)"
            />
          </div>
        </div>
        <div v-if="isNachfassen && daysWaiting" class="flex justify-center mt-6 mb-2">
          <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 max-w-sm text-center">
            <div class="text-2xl mb-1">&#9200;</div>
            <div class="text-sm font-semibold text-amber-800">
              Seit {{ daysWaiting }} Tagen keine Antwort
            </div>
            <div class="text-[11px] text-amber-600 mt-1">
              <template v-if="item.last_action">Letzte Aktion: {{ item.last_action }}</template>
              <template v-else-if="item.last_contact_date">Letzter Kontakt: {{ item.last_contact_date }}</template>
              <template v-else>Nachfass-Erinnerung aktiv</template>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Slots below chat -->
    <div class="flex-shrink-0"><slot name="ai-draft" /></div>
  </div>
</template>
