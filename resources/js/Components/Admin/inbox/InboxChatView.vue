<script setup>
import { computed, inject, ref, watch, nextTick } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { X, Loader2, Clock, ChevronLeft, ChevronDown, ChevronUp, Sparkles, CheckCircle, Send, RefreshCw, Wand2, Link2, Pencil, Home, Forward } from 'lucide-vue-next'
import InboxMatchCard from './InboxMatchCard.vue'
import InboxMailMessage from './InboxMailMessage.vue'
import InboxComposePane from './InboxComposePane.vue'
import LinkPickerPopover from './LinkPickerPopover.vue'
import PropertyAssignDialog from './PropertyAssignDialog.vue'
import { extractForwardMetadata } from './mailText.js'

const props = defineProps({
  item: { type: Object, required: true },
  messages: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
})

const emit = defineEmits(['close', 'saveAttachment', 'matchDraft', 'matchDismiss', 'markHandled', 'propertyChanged'])

// Property assignment dialog
const assignDialogOpen = ref(false)
const assignSaving = ref(false)
const inboxAPI = inject('inboxAPI', null)
const inboxToast = inject('inboxToast', () => {})
const inboxProperties = inject('inboxProperties', ref([]))
const currentUserId = inject('userId', ref(null))
const currentUserType = inject('userType', ref('makler'))

// Unwrap to plain array + filter auf eigene + AKTIVE Properties.
// Regel 1: Zuordnung nur auf Objekte die dem Makler/Admin gehoeren dessen
//          Postfach die Mail erreicht hat. Assistenz/Backoffice duerfen shared
//          zugreifen.
// Regel 2: Nur Objekte mit realty_status='aktiv'. Verkaufte/inaktive werden
//          ausgeblendet — sonst koennte man Mails faelschlich verkauften
//          Objekten zuordnen, was in Statistiken verwirrt.
const propertiesArray = computed(() => {
  const src = inboxProperties
  let list = []
  if (Array.isArray(src)) list = src
  else if (src && 'value' in src) list = Array.isArray(src.value) ? src.value : []

  // Nur aktive Objekte
  list = list.filter(p => String(p.realty_status || '').toLowerCase() === 'aktiv')

  const userType = currentUserType.value
  const uid = Number(currentUserId.value || 0)

  // Assistenz/Backoffice sehen alle aktiven
  if (['assistenz', 'backoffice'].includes(userType)) return list

  // Admin + Makler: nur eigene aktive
  if (!uid) return []
  return list.filter(p => Number(p.broker_id || 0) === uid)
})

const currentPropertyAddress = computed(() => {
  const pid = props.item?.property_id
  if (!pid) return ''
  const prop = propertiesArray.value.find(p => Number(p.id) === Number(pid))
  return prop ? (prop.address || prop.title || '') : ''
})

async function onAssignConfirm(payload) {
  if (!props.item) return
  assignSaving.value = true
  const convId = props.item._conv_id || props.item.id
  try {
    const r = await fetch(inboxAPI.value + '&action=conv_set_property', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: convId,
        property_id: payload.property_id,
        migrate_activities: payload.migrate_activities,
      }),
    })
    const d = await r.json()
    if (d.success) {
      inboxToast(payload.property_id ? 'Objekt zugewiesen' : 'Zuordnung entfernt')
      assignDialogOpen.value = false
      emit('propertyChanged', {
        convId,
        oldPropertyId: d.old_property_id,
        newPropertyId: d.new_property_id,
        migrated: d.migrated,
      })
    } else {
      inboxToast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    inboxToast('Fehler: ' + e.message)
  } finally {
    assignSaving.value = false
  }
}

function latestInbound() {
  for (let i = 0; i < flatMessages.value.length; i++) {
    const m = flatMessages.value[i]
    if ((m.direction || '').toLowerCase() === 'inbound') return m
  }
  return flatMessages.value[0] || null
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
  const m = flatMessages.value[0]
  if (!m) return
  emit('forward', {
    subject: m.subject?.startsWith('WG: ') ? m.subject : 'WG: ' + (m.subject || ''),
    quotedMessageId: m.id || null,
  })
}

// ── Compose mode state (local to this component) ─────────────────
// When the user clicks "Antworten" in the thread footer, we flip mode
// to 'compose' and the template renders InboxComposePane instead of
// the accordion. The message being replied to is stashed in
// composeContext.referenceMessage and displayed as a collapsed strip
// below the pane.
const composeMode = ref('thread') // 'thread' | 'compose'
const composeContext = ref(null)   // { kind, withDraft, prefill, referenceMessage }

// Inject used only for seeding the shared draft state when entering
// compose mode — the compose pane itself reads/writes via its own
// inject('inboxCompose') call.
const inboxComposeInject = inject('inboxCompose', null)

function enterCompose(kind, withDraft) {
  const m = kind === 'forward'
    ? flatMessages.value[0]
    : latestInbound()
  if (!m) return

  // Prefer the conversation's stored contact_email as the reply target
  // over the raw from_email of the latest inbound mail. For platform
  // sources like Typeform/Willhaben/ImmoScout the raw from_email is a
  // noreply notifier (notifications@followups.typeform.io etc.) while
  // contact_email is the real customer address that ConversationService
  // resolved by parsing the body. Fall back to m.from_email only when
  // contact_email is empty or is a synthetic placeholder.
  const convContact = String(props.item?.contact_email || '').toLowerCase()
  const convContactIsReal = convContact && !convContact.endsWith('@placeholder.local')
  const replyTo = convContactIsReal ? props.item.contact_email : (m.from_email || '')

  composeContext.value = {
    kind,
    withDraft,
    prefill: {
      to: kind === 'forward' ? '' : replyTo,
      subject: kind === 'forward'
        ? (m.subject?.startsWith('WG: ') ? m.subject : 'WG: ' + (m.subject || ''))
        : (m.subject?.startsWith('Re: ') ? m.subject : 'Re: ' + (m.subject || '')),
    },
    referenceMessage: m,
  }
  composeMode.value = 'compose'

  // Seed the shared draft state (expandedAiDraft via inject) with the
  // prefill so the pane has a known starting shape. The pane itself
  // will overwrite body when the user types or the AI draft lands.
  //
  // Start with an empty body. Signature is shown separately as preview
  // and appended only at send-time to avoid duplicate/signature-in-body UX.
  if (inboxComposeInject?.draft) {
    const current = inboxComposeInject.draft.value || {}
    inboxComposeInject.draft.value = {
      body: '',
      subject: composeContext.value.prefill.subject,
      to: composeContext.value.prefill.to,
      cc: current.cc || '',
    }
  }
}

function exitCompose() {
  composeMode.value = 'thread'
  composeContext.value = null
  linkPickerOpen.value = false
}

// ── Persistent compose action bar state + handlers ─────────────────
// The Senden / Neu generieren / Verbessern / Link buttons are mounted
// in InboxChatView (not InboxComposePane) so they sit outside the
// scroll container and stay visible. They route through the same
// inject('inboxCompose') contract the compose pane uses.
const linkPickerOpen = ref(false)

const composeDraft = computed(() => inboxComposeInject?.draft?.value || null)
const composeBodyIsEmpty = computed(() => !(composeDraft.value?.body || '').trim())
const composeLoading = computed(() => inboxComposeInject?.loading?.value || false)

function onComposeSend() {
  inboxComposeInject?.send?.()
}
function onComposeRegenerate() {
  inboxComposeInject?.regenerate?.()
}
function onComposeImprove() {
  inboxComposeInject?.improve?.()
}
function onLinkPicked(link) {
  const draftRef = inboxComposeInject?.draft
  if (!draftRef) { linkPickerOpen.value = false; return }
  const current = draftRef.value || { body: '', subject: '', to: '' }
  const body = String(current.body || '')
  const insertion = `\n\nUnterlagen: ${link.url}`

  // Insert BEFORE the sign-off line so the link sits with the main
  // content, not tacked on at the very end below "Mit freundlichen
  // Grüßen". We search for the first German greeting marker and splice
  // above it. If no greeting is found, append at the end (current
  // behaviour).
  const signOffRe = /\n\s*(Mit\s+freundlichen\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)en|Beste\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Liebe\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Viele\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Ihre?\s+(Susanne|Maximilian))/i
  const match = body.match(signOffRe)
  let newBody
  if (match && typeof match.index === 'number') {
    newBody = body.slice(0, match.index) + insertion + body.slice(match.index)
  } else {
    newBody = (body + insertion).trim()
  }
  draftRef.value = { ...current, body: newBody }
  linkPickerOpen.value = false
}

// Reset compose mode when the user picks a different conversation.
// Without this, switching from mail A (where the user clicked
// "Mit KI-Entwurf antworten") to mail B would leave the compose pane
// open — still showing mail A's draft — over mail B's thread.
watch(
  () => props.item?.id,
  (newId, oldId) => {
    if (newId !== oldId && composeMode.value === 'compose') {
      exitCompose()
    }
  }
)

/** Reading pane: newest messages are at the top — keep scroll pinned there when switching threads or after load. */
const threadScrollEl = ref(null)
async function scrollThreadToTop() {
  await nextTick()
  const el = threadScrollEl.value
  if (el && typeof el.scrollTop === 'number') el.scrollTop = 0
}
watch(() => props.item?.id, () => { scrollThreadToTop() })
watch(() => props.loading, (loading) => {
  if (!loading) scrollThreadToTop()
})

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

  // Newest first (like an inbox reading pane — latest mail at the top).
  const sorted = [...props.messages].sort((a, b) => {
    const da = new Date(a.email_date || a.activity_date || a.date || 0)
    const db = new Date(b.email_date || b.activity_date || b.date || 0)
    return db - da
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
  const newest = flatMessages.value[0]
  return newest?.subject || newest?.email_subject || ''
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
            <!-- Objekt-Badge: klickbar zum Umzuordnen. Zeigt "Nicht zugeordnet" bei fehlender Zuordnung. -->
            <button
              type="button"
              class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0 h-5 text-[10px] font-normal transition-colors cursor-pointer"
              :class="refId
                ? 'border-border bg-muted/50 hover:bg-zinc-100 text-foreground'
                : 'border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800'"
              @click="assignDialogOpen = true"
              :title="refId ? 'Objekt ändern' : 'Objekt zuweisen'"
            >
              <Home class="w-3 h-3" />
              <span>{{ refId || 'Nicht zugeordnet' }}</span>
              <Pencil class="w-2.5 h-2.5 opacity-60" />
            </button>
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

    <!-- Immobilien anbieten panel — the trigger button is now in the
         sticky compose action bar; this block only renders the expanded
         panel content inline when offerOpen is true. -->
    <div v-if="offerOpen" class="flex-shrink-0 border-b border-zinc-100">
      <div class="border-t border-zinc-100">
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
    <div ref="threadScrollEl" class="flex-1 overflow-y-auto bg-white" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">
      <div v-if="loading" class="flex items-center justify-center h-full">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
      </div>
      <div v-else-if="!flatMessages.length" class="flex items-center justify-center h-full text-sm text-muted-foreground">
        Keine Nachrichten
      </div>
      <template v-else>
        <div class="sr-thread-card" :class="{ 'sr-thread-card--compose': composeMode === 'compose' }">
          <!-- Subject + badges live in the outer header at the top of the
               reading pane (flex-shrink-0 block above the scroll area).
               The inner subject-header was removed to save vertical space
               and eliminate the redundant title. -->

          <!-- Thread mode: accordion messages -->
          <div v-if="composeMode === 'thread'" class="sr-thread-body">
            <InboxMailMessage
              v-for="(msg, idx) in flatMessages"
              :key="msg.id || ('idx-' + idx)"
              :message="msg"
              :sender-name="item.from_name || item.stakeholder || ''"
              :is-initially-expanded="idx === 0"
              @save-attachment="emit('saveAttachment', $event)"
            />
          </div>

          <!-- Compose mode: the reply pane -->
          <InboxComposePane
            v-else-if="composeContext"
            :kind="composeContext.kind"
            :with-draft="composeContext.withDraft"
            :prefill="composeContext.prefill"
            :reference-message="composeContext.referenceMessage"
            :property-id="item?.property_id || null"
            @cancel="exitCompose"
          />
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

    <!-- Persistent action bar — always visible at the bottom of the reading
         pane, outside the scroll container so the user never has to scroll
         down to reach Antworten. Only shown in thread mode; compose mode
         has its own Senden / Abbrechen bar inside InboxComposePane. -->
    <footer
      v-if="composeMode === 'thread' && !loading && flatMessages.length"
      class="sr-thread-actions sr-thread-actions--sticky flex-shrink-0"
    >
      <Button variant="default" size="sm" @click="enterCompose('reply', false)">
        <svg class="sr-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
        Antworten
      </Button>
      <button type="button" class="sr-ai-reply-btn" @click="enterCompose('reply', true)">
        <Sparkles class="sr-action-icon" />
        Mit KI-Entwurf antworten
      </button>
      <div class="sr-thread-actions-spacer"></div>
      <Button variant="ghost" size="sm" @click="enterCompose('reply-all', false)">
        Allen antworten
      </Button>
      <Button variant="outline" size="sm" @click="enterCompose('forward', false)" title="Weiterleiten">
        <Forward class="w-3.5 h-3.5 mr-1" />
        Weiterleiten
      </Button>
      <Button variant="ghost" size="sm" class="sr-done-btn" @click="emit('markHandled')">
        <CheckCircle class="sr-action-icon" />
        Erledigt
      </Button>
    </footer>

    <!-- Persistent compose action bar — same pattern as the thread action
         bar above, but with Senden / Neu generieren / Verbessern / Link.
         Mounted outside the scroll container so it's always visible while
         the user scrolls through the body textarea or the reference strip. -->
    <footer
      v-if="composeMode === 'compose' && composeContext"
      class="sr-thread-actions sr-thread-actions--sticky sr-compose-actions--sticky flex-shrink-0"
    >
      <button
        type="button"
        class="sr-btn sr-btn-primary"
        :disabled="composeBodyIsEmpty"
        @click="onComposeSend"
      >
        <Send class="w-3.5 h-3.5" />
        Senden
      </button>
      <button
        type="button"
        class="sr-ai-reply-btn"
        :disabled="composeLoading"
        @click="onComposeRegenerate"
      >
        <RefreshCw class="sr-action-icon" :class="composeLoading ? 'animate-spin' : ''" />
        {{ composeLoading ? 'Generiere…' : 'Neu generieren' }}
      </button>
      <button
        type="button"
        class="sr-btn sr-btn-ghost"
        :disabled="composeBodyIsEmpty || composeLoading"
        @click="onComposeImprove"
      >
        <Wand2 class="w-3.5 h-3.5" />
        Verbessern
      </button>
      <button
        type="button"
        class="sr-btn sr-btn-ghost"
        title="Weitere Objekte als Alternativen vorschlagen"
        @click="toggleOfferPanel"
      >
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Immobilie anbieten
      </button>
      <div class="sr-thread-actions-spacer"></div>
      <div class="sr-link-picker-wrapper">
        <LinkPickerPopover
          v-if="linkPickerOpen && item?.property_id"
          :property-id="Number(item.property_id)"
          @close="linkPickerOpen = false"
          @pick="onLinkPicked"
        />
        <button
          type="button"
          class="sr-btn sr-btn-ghost"
          :disabled="!item?.property_id"
          :title="item?.property_id ? 'Docs-Link anfügen' : 'Kein Objekt in der Konversation'"
          @click="linkPickerOpen = !linkPickerOpen"
        >
          <Link2 class="w-3.5 h-3.5" />
          Link
        </button>
      </div>
      <button
        type="button"
        class="sr-btn sr-btn-ghost"
        @click="exitCompose"
      >
        <X class="w-3.5 h-3.5" />
        Abbrechen
      </button>
    </footer>

    <!-- Slots below chat -->
    <div class="flex-shrink-0"><slot name="ai-draft" /></div>

    <!-- Property-Umzuordnen-Dialog -->
    <PropertyAssignDialog
      v-model:open="assignDialogOpen"
      :current-property-id="item?.property_id || null"
      :current-property-ref="refId || ''"
      :current-property-address="currentPropertyAddress"
      :properties="propertiesArray"
      @confirm="onAssignConfirm"
    />
  </div>
</template>

<style scoped>
.sr-thread-card {
  background: hsl(0 0% 100%);
  border: none;
  border-radius: 0;
  box-shadow: none;
  overflow: hidden;
  margin: 0;
}
.sr-thread-card--compose {
  min-height: 100%;
  display: flex;
  flex-direction: column;
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
  display: flex; gap: 8px; align-items: center;
}
/* Persistent variant: sits outside the scroll container, always visible */
.sr-thread-actions--sticky {
  background: hsl(0 0% 100%);
  border-top: 1px solid hsl(0 0% 90%);
  box-shadow: 0 -4px 12px -6px rgb(0 0 0 / 0.08), 0 -1px 0 0 rgb(0 0 0 / 0.02);
}
/* Compose-mode variant gets a subtle orange tint so the user sees at a
   glance they're in reply mode, not thread mode. */
.sr-compose-actions--sticky {
  background: linear-gradient(180deg, hsl(28 98% 98%), hsl(0 0% 100%));
  border-top-color: hsl(28 80% 88%);
}
/* Button shared classes used by the compose sticky bar */
.sr-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  background: hsl(0 0% 100%);
  border: 1px solid hsl(0 0% 90%);
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  color: hsl(0 0% 15%);
  cursor: pointer;
  transition: all 120ms ease;
  height: 32px;
}
.sr-btn:hover:not(:disabled) {
  background: hsl(0 0% 97%);
  border-color: hsl(0 0% 80%);
}
.sr-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.sr-btn-primary {
  background: hsl(0 0% 9%);
  color: hsl(0 0% 100%);
  border-color: hsl(0 0% 9%);
}
.sr-btn-primary:hover:not(:disabled) { background: hsl(0 0% 18%); }
.sr-btn-ghost {
  background: transparent;
  border-color: transparent;
}
.sr-btn-ghost:hover:not(:disabled) {
  background: hsl(0 0% 96%);
  border-color: hsl(0 0% 90%);
}
.sr-link-picker-wrapper { position: relative; }
.sr-action-icon { width: 14px; height: 14px; margin-right: 6px; }
.sr-badge-orange { background: hsl(24 90% 96%); color: hsl(24 80% 38%); border-color: hsl(24 80% 90%); }
.sr-badge-sky    { background: hsl(199 85% 96%); color: hsl(199 85% 30%); border-color: hsl(199 85% 88%); }
.sr-badge-gray   { background: hsl(0 0% 96%); color: hsl(0 0% 40%); border-color: hsl(0 0% 88%); }
.sr-ai-reply-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, hsl(28 98% 54%), hsl(18 88% 48%));
  color: hsl(0 0% 100%);
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
  box-shadow: 0 1px 3px rgb(249 115 22 / 0.22);
  transition: filter 120ms ease;
  height: 32px;
}
.sr-ai-reply-btn:hover {
  filter: brightness(1.08);
}
.sr-thread-actions-spacer { flex: 1; min-width: 12px; }
.sr-done-btn { color: hsl(142 72% 32%); }
.sr-done-btn:hover { background: hsl(142 72% 95%); }
</style>
