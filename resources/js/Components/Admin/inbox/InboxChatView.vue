<script setup>
import { computed, inject, ref, watch } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { X, Loader2, Clock, ChevronLeft, ChevronDown, ChevronUp } from 'lucide-vue-next'
import InboxMatchCard from './InboxMatchCard.vue'
import InboxMailMessage from './InboxMailMessage.vue'
import { extractForwardMetadata } from './mailText.js'

const props = defineProps({
  item: { type: Object, required: true },
  messages: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
})

const emit = defineEmits(['close', 'saveAttachment', 'matchDraft', 'matchDismiss', 'reply', 'reply-all', 'forward'])

function latestInbound() {
  for (let i = flatMessages.value.length - 1; i >= 0; i--) {
    const m = flatMessages.value[i]
    if ((m.direction || '').toLowerCase() === 'inbound') return m
  }
  return flatMessages.value[flatMessages.value.length - 1] || null
}

function onReply() {
  const m = latestInbound()
  if (!m) return
  emit('reply', {
    toEmail: m.from_email || '',
    subject: m.subject?.startsWith('Re: ') ? m.subject : 'Re: ' + (m.subject || ''),
    quotedMessageId: m.id || null,
  })
}

function onReplyAll() {
  const m = latestInbound()
  if (!m) return
  emit('reply-all', {
    toEmail: m.from_email || '',
    subject: m.subject?.startsWith('Re: ') ? m.subject : 'Re: ' + (m.subject || ''),
    quotedMessageId: m.id || null,
  })
}

function onForward() {
  const m = flatMessages.value[flatMessages.value.length - 1]
  if (!m) return
  emit('forward', {
    subject: m.subject?.startsWith('WG: ') ? m.subject : 'WG: ' + (m.subject || ''),
    quotedMessageId: m.id || null,
  })
}
const bgGradient = inject("inboxBgGradient", ref(""));
const bgOpacity = inject("inboxBgOpacity", ref(0.15));
const API = inject('API')
const toast = inject('inboxToast', (msg) => console.log(msg))
const openContact = inject('openContact', () => {})
const allProperties = inject('inboxProperties', ref([]))

// -- Manual property offer panel --
const offerOpen = ref(false)
const offerSearch = ref('')
const offerSelectedIds = ref(new Set())
const offerGenerating = ref(false)

const filteredProperties = computed(() => {
  const q = offerSearch.value.toLowerCase().trim()
  const list = Array.isArray(allProperties) ? allProperties : (allProperties?.value || [])
  const active = list.filter(p => !p.realty_status || p.realty_status === 'auftrag' || p.realty_status === 'inserat')
  if (!q) return active
  return active.filter(p => {
    const hay = [p.ref_id, p.address, p.city, p.title, p.object_type].filter(Boolean).join(' ').toLowerCase()
    return hay.includes(q)
  })
})

const offerSelectedCount = computed(() => offerSelectedIds.value.size)

function toggleOfferPanel() {
  offerOpen.value = !offerOpen.value
  if (offerOpen.value) {
    offerSearch.value = ''
    offerSelectedIds.value = new Set()
  }
}

function toggleOfferProperty(id) {
  if (offerSelectedIds.value.has(id)) {
    offerSelectedIds.value.delete(id)
  } else {
    offerSelectedIds.value.add(id)
  }
  offerSelectedIds.value = new Set(offerSelectedIds.value)
}

async function generateOfferDraft() {
  if (offerSelectedCount.value === 0) return
  offerGenerating.value = true
  const cnt = offerSelectedCount.value
  toast("Generiere Entwurf für " + cnt + (cnt > 1 ? " Objekte…" : " Objekt…"))
  try {
    const r = await fetch(API.value + '&action=match_generate_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        conversation_id: props.item.id,
        property_ids: [...offerSelectedIds.value],
      }),
    })
    let d
    try {
      d = await r.json()
    } catch (parseErr) {
      console.error('match_generate_draft: invalid JSON response', parseErr, 'status', r.status)
      toast('Server-Fehler (HTTP ' + r.status + ') — bitte erneut versuchen')
      return
    }
    if (!r.ok || d.error) {
      console.warn('match_generate_draft error:', d)
      toast('Fehler beim Generieren: ' + (d.error || ('HTTP ' + r.status)))
      return
    }
    if (!d.draft_body) {
      console.warn('match_generate_draft: empty draft_body', d)
      toast('Kein Entwurf erhalten — bitte erneut versuchen')
      return
    }
    offerOpen.value = false
    toast('Entwurf generiert — siehe Reply-Bereich unten')
    emit('matchDraft', {
      draft_body: d.draft_body,
      draft_subject: d.draft_subject,
      draft_to: d.draft_to,
      file_ids: d.file_ids || [],
      file_map: d.file_map || [],
    })
  } catch (e) {
    console.error('Failed to generate offer draft', e)
    toast('Netzwerkfehler: ' + (e.message || 'unbekannt'))
  } finally {
    offerGenerating.value = false
  }
}

function formatOfferPrice(p) {
  if (!p) return 'Preis a.A.'
  return '\u20ac ' + Number(p).toLocaleString('de-AT')
}

// ── Header badges ──
const contactName = computed(() => props.item.from_name || props.item.stakeholder || '')

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
  offerOpen.value = false
  offerSelectedIds.value = new Set()
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

const flatMessages = computed(() => {
  if (!props.messages?.length) return []

  // Sort chronologically (oldest first).
  const sorted = [...props.messages].sort((a, b) => {
    const da = new Date(a.email_date || a.activity_date || a.date || 0)
    const db = new Date(b.email_date || b.activity_date || b.date || 0)
    return da - db
  })

  // Collect thread senders for forward dedup — if the forwarded sender
  // already appears as their own message in the thread, skip annotating.
  const threadSenders = new Set()
  for (const m of sorted) {
    const fe = (m.from_email || '').trim().toLowerCase()
    if (fe) threadSenders.add(fe)
  }

  return sorted.map((m) => {
    const meta = extractForwardMetadata(m)
    if (meta && meta.fromEmail && threadSenders.has(meta.fromEmail)) {
      return m // skip annotation — original is already in the thread
    }
    if (meta) {
      return {
        ...m,
        _forwardedFromName: meta.fromName,
        _forwardedFromEmail: meta.fromEmail,
        _forwardedSubject: meta.subject,
      }
    }
    return m
  })
})

const subjectLine = computed(() => {
  const last = flatMessages.value[flatMessages.value.length - 1]
  return last?.subject || last?.email_subject || ''
})

const refIdLabel = computed(() => props.refId || props.item?.ref_id || null)

const participantsLabel = computed(() => {
  const names = new Set()
  for (const m of flatMessages.value) {
    const d = (m.direction || '').toLowerCase()
    if (d === 'outbound') names.add('Sie')
    else if (m.from_name) names.add(String(m.from_name).replace(/\s*<[^>]+>\s*$/, '').trim())
  }
  return Array.from(names).join(', ')
})

const statusBadge = computed(() => {
  const item = props.item || {}
  const cat = (item.category || '').toLowerCase()
  if (item.status === 'nachfassen_1' || item.status === 'nachfassen_2' || item.status === 'nachfassen_3') {
    return { label: 'Nachfassen', classes: 'sr-badge-orange' }
  }
  if (cat === 'intern') return { label: 'Intern', classes: 'sr-badge-sky' }
  if (cat === 'info-cc') return { label: 'zur Info (CC)', classes: 'sr-badge-gray' }
  return null
})
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
            <Badge variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-normal cursor-pointer hover:bg-zinc-100 transition-colors" @click="contactName && openContact(contactName)" :title="contactName ? 'Kontakt öffnen' : ''">
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

    <!-- Manual property offer button (always visible) -->
    <div v-if="!hasMatches || !matchOpen" class="flex-shrink-0 border-b border-zinc-100">
      <button
        @click="toggleOfferPanel"
        class="w-full flex items-center justify-between px-5 py-2 hover:bg-zinc-50/50 transition-colors"
      >
        <div class="flex items-center gap-2.5">
          <div class="w-6 h-6 rounded-md bg-gradient-to-br from-orange-400 to-amber-500 flex items-center justify-center flex-shrink-0">
            <span class="text-white text-[11px] font-bold">+</span>
          </div>
          <span class="text-[13px] font-medium">Immobilien anbieten</span>
          <span v-if="offerSelectedCount > 0" class="text-[11px] text-orange-600 font-semibold">({{ offerSelectedCount }} ausgewahlt)</span>
        </div>
        <component :is="offerOpen ? ChevronUp : ChevronDown" class="w-4 h-4 text-muted-foreground flex-shrink-0" />
      </button>

      <div v-if="offerOpen" class="border-t border-zinc-100">
        <div class="px-5 py-2 bg-zinc-50/50">
          <input
            v-model="offerSearch"
            type="text"
            placeholder="Suche nach Adresse, Ref-ID, Ort..."
            class="w-full h-8 rounded-md border border-zinc-200 bg-white px-3 text-[12px] placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-orange-300"
          />
        </div>

        <div class="px-5 py-2 space-y-1.5 max-h-[320px] overflow-y-auto">
          <div v-if="!filteredProperties.length" class="text-center py-6 text-[12px] text-muted-foreground">
            Keine Objekte gefunden
          </div>
          <div
            v-for="p in filteredProperties"
            :key="p.id"
            @click="toggleOfferProperty(p.id)"
            class="flex items-center gap-3 px-3 py-2 rounded-lg cursor-pointer transition-all hover:bg-zinc-50"
            :class="offerSelectedIds.has(p.id) ? 'bg-orange-50 border border-orange-200 shadow-sm' : 'border border-transparent'"
          >
            <div class="w-14 h-11 rounded bg-muted flex-shrink-0 overflow-hidden flex items-center justify-center">
              <img v-if="p.thumbnail_url || p.main_image_url || p.image_url" :src="p.thumbnail_url || p.main_image_url || p.image_url" class="w-full h-full object-cover" />
              <span v-else class="text-lg text-muted-foreground/40">&#127968;</span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <span class="text-[12px] font-semibold truncate">{{ p.title || p.address }}</span>
                <Badge v-if="p.ref_id" variant="outline" class="text-[9px] px-1 py-0 h-3.5 font-normal flex-shrink-0">{{ p.ref_id }}</Badge>
              </div>
              <div class="text-[11px] text-muted-foreground truncate">
                {{ p.address }}, {{ p.city }} &mdash; {{ formatOfferPrice(p.purchase_price || p.rent_price) }}
                <span v-if="p.living_area || p.total_area"> &mdash; {{ p.living_area || p.total_area }} m&sup2;</span>
                <span v-if="p.rooms_amount"> &mdash; {{ p.rooms_amount }} Zi.</span>
              </div>
            </div>
            <div class="flex-shrink-0">
              <div
                class="w-5 h-5 rounded border-2 flex items-center justify-center transition-all"
                :class="offerSelectedIds.has(p.id) ? 'bg-gradient-to-br from-orange-400 to-amber-500 border-orange-500' : 'border-muted-foreground/30'"
              >
                <svg v-if="offerSelectedIds.has(p.id)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <div class="px-5 py-2.5 border-t border-zinc-100 flex items-center justify-between bg-zinc-50/30">
          <span class="text-[12px] text-muted-foreground">
            <strong class="text-orange-600">{{ offerSelectedCount }}</strong> ausgewahlt
          </span>
          <Button
            size="sm"
            :disabled="offerSelectedCount === 0 || offerGenerating"
            class="h-7 text-[11px] bg-gradient-to-r from-orange-400 to-amber-500 text-white hover:opacity-90 disabled:opacity-50"
            @click="generateOfferDraft"
          >
            {{ offerGenerating ? 'Generiere...' : 'Entwurf generieren' }}
          </Button>
        </div>
      </div>
    </div>

    <!-- Chat area -->
    <div class="flex-1 overflow-y-auto px-5 py-4 bg-white" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">
      <div v-if="loading" class="flex items-center justify-center h-full">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
      </div>
      <div v-else-if="!flatMessages.length" class="flex items-center justify-center h-full text-sm text-muted-foreground">
        Keine Nachrichten
      </div>
      <template v-else>
        <div class="sr-thread-card">
          <header v-if="subjectLine" class="sr-subject-header">
            <h3>{{ subjectLine }}</h3>
            <div class="sr-subject-meta">
              <Badge v-if="statusBadge" variant="outline" :class="statusBadge.classes">{{ statusBadge.label }}</Badge>
              <span>{{ flatMessages.length }} {{ flatMessages.length === 1 ? 'Nachricht' : 'Nachrichten' }}</span>
              <span v-if="participantsLabel" class="sr-sep">·</span>
              <span v-if="participantsLabel">{{ participantsLabel }}</span>
              <span v-if="refIdLabel" class="sr-sep">·</span>
              <span v-if="refIdLabel">{{ refIdLabel }}</span>
            </div>
          </header>

          <div class="sr-thread-body">
            <InboxMailMessage
              v-for="(msg, idx) in flatMessages"
              :key="msg.id || ('idx-' + idx)"
              :message="msg"
              :sender-name="item.from_name || item.stakeholder || ''"
              :is-initially-expanded="idx === flatMessages.length - 1"
              @save-attachment="emit('saveAttachment', $event)"
            />
          </div>

          <footer class="sr-thread-actions">
            <Button variant="default" size="sm" @click="onReply">
              <svg class="sr-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
              Antworten
            </Button>
            <Button variant="outline" size="sm" @click="onReplyAll">
              <svg class="sr-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 17 2 12 7 7"/><polyline points="12 17 7 12 12 7"/><path d="M22 18v-2a4 4 0 0 0-4-4H7"/></svg>
              Allen antworten
            </Button>
            <Button variant="outline" size="sm" @click="onForward">
              <svg class="sr-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/></svg>
              Weiterleiten
            </Button>
          </footer>
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

<style scoped>
.sr-thread-card {
  background: hsl(0 0% 100%);
  border: 1px solid hsl(0 0% 90%);
  border-radius: 12px;
  box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 1px -1px rgb(0 0 0 / 0.04);
  overflow: hidden;
  margin: 16px;
}
.sr-subject-header {
  padding: 20px 24px 18px;
  border-bottom: 1px solid hsl(0 0% 93%);
}
.sr-subject-header h3 {
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  color: hsl(0 0% 9%);
  letter-spacing: -0.01em;
  line-height: 1.35;
}
.sr-subject-meta {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin-top: 8px; font-size: 12px; color: hsl(0 0% 45%);
}
.sr-sep { color: hsl(0 0% 75%); }
.sr-thread-actions {
  padding: 14px 24px;
  background: hsl(0 0% 99%);
  border-top: 1px solid hsl(0 0% 93%);
  display: flex; gap: 8px;
}
.sr-action-icon { width: 14px; height: 14px; margin-right: 6px; }
.sr-badge-orange { background: hsl(24 90% 96%); color: hsl(24 80% 38%); border-color: hsl(24 80% 90%); }
.sr-badge-sky    { background: hsl(199 85% 96%); color: hsl(199 85% 30%); border-color: hsl(199 85% 88%); }
.sr-badge-gray   { background: hsl(0 0% 96%); color: hsl(0 0% 40%); border-color: hsl(0 0% 88%); }
</style>
