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

// Forwarded-mail metadata surfaced by InboxChatView.splitForwardedMessage.
// When present we render a header strip ("Weiterleitung ursprünglich von: X")
// so the user can tell at a glance that a colleague forwarded someone
// else's mail — critical for the Susanne → Max → Baldinger case.
const forwardedFromName = computed(() => props.message._forwardedFromName || null)
const forwardedFromEmail = computed(() => props.message._forwardedFromEmail || null)
const forwardedSubject = computed(() => props.message._forwardedSubject || null)
const isForwardedBy = computed(() => !!forwardedFromName.value || !!forwardedFromEmail.value)
const isIntern = computed(() => {
  const cat = (props.message.category || '').toLowerCase()
  const from = (props.message.from_email || '').toLowerCase()
  const to = (props.message.to_email || '').toLowerCase()
  const direction = (props.message.direction || '').toLowerCase()
  if (cat === 'intern') return true
  // Both ends on our own domain.
  if (from.endsWith('@sr-homes.at') && to.endsWith('@sr-homes.at')) return true
  // Inbound copy of an internal mail (we were CC'd on something our own
  // colleague sent out to a third party). Surface it as intern so it
  // doesn't masquerade as a customer message.
  if (from.endsWith('@sr-homes.at') && direction === 'inbound') return true
  return false
})

// Recipient shown on internal bubbles so the user can tell at a glance
// where the mail was actually addressed (useful when we were only CC'd).
const recipientLabel = computed(() => {
  const raw = props.message.to_email || ''
  if (!raw) return ''
  // Best effort: strip angle-bracket wrappers, pick the first address.
  const first = String(raw).split(',')[0].trim()
  const angleMatch = first.match(/<([^>]+)>/)
  return (angleMatch?.[1] || first).trim()
})

const typeBadge = computed(() => {
  const m = props.message
  if (isAutoReply.value) return { label: '\u26A1 Auto-Reply', classes: 'bg-emerald-50 text-emerald-700 border-emerald-200' }
  if ((m.category || '').toLowerCase() === 'forwarded') {
    return { label: '↪ Weitergeleitet', classes: 'bg-indigo-50 text-indigo-700 border-indigo-200' }
  }
  if (m.category === 'nachfassen') {
    const stage = m.followup_stage || 1
    const bg = stage >= 2 ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200'
    return { label: `Nachfassen ${stage}`, classes: bg }
  }
  return null
})

const displayName = computed(() => props.message.from_name || props.senderName || props.message.from_email || 'Unbekannt')

const senderToneClasses = [
  'bg-rose-50 text-rose-700 border-rose-200',
  'bg-amber-50 text-amber-700 border-amber-200',
  'bg-emerald-50 text-emerald-700 border-emerald-200',
  'bg-cyan-50 text-cyan-700 border-cyan-200',
  'bg-indigo-50 text-indigo-700 border-indigo-200',
  'bg-violet-50 text-violet-700 border-violet-200',
]

function hashString(value) {
  let hash = 0
  const s = String(value || '').toLowerCase().trim()
  for (let i = 0; i < s.length; i++) {
    hash = (hash << 5) - hash + s.charCodeAt(i)
    hash |= 0
  }
  return Math.abs(hash)
}

const senderToneClass = computed(() => {
  const idx = hashString(displayName.value) % senderToneClasses.length
  return senderToneClasses[idx]
})

// Decode HTML entities that some mail clients leave in the text/plain part.
// Without this, bodies show literal "&nbsp;", "&lt;", "&gt;", "&amp;" etc.
function decodeHtmlEntities(s) {
  if (!s) return ''
  return String(s)
    .replace(/&nbsp;/gi, ' ')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&(?:apos|#39);/gi, "'")
    .replace(/&amp;/gi, '&')
    .replace(/&#(\d+);/g, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 10)) } catch (e) { return '' }
    })
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 16)) } catch (e) { return '' }
    })
}

// Best-effort repair of '?' characters that an IMAP encoding bug left in
// the stored body_text. The original umlaut bytes are LOST in MySQL, so
// this is a dictionary-based guess based on the most common German
// patterns in business mails. Fully rolled out encoding-fixed mails (post
// ImapService fix) don't need this, but legacy rows like Susanne's 2042
// forward do.
const UMLAUT_REPAIRS = [
  // — ß family (end-of-word or well-known compounds)
  [/\bStra\?e\b/g, 'Straße'],
  [/\bstra\?e\b/g, 'straße'],
  [/Bundesstra\?e/g, 'Bundesstraße'],
  [/Hauptstra\?e/g, 'Hauptstraße'],
  [/\bGro\?/g, 'Groß'],
  [/\bgro\?/g, 'groß'],
  [/\bwei\?\b/g, 'weiß'],
  [/\bWei\?\b/g, 'Weiß'],
  [/\bhei\?\b/g, 'heiß'],
  [/\bFu\?/g, 'Fuß'],
  [/\bFlu\?/g, 'Fluß'],
  [/\bRei\?/g, 'Reiß'],
  [/\bausschlie\?lich/g, 'ausschließlich'],
  [/\bAusschlie\?lich/g, 'Ausschließlich'],
  [/\bSchlu\?/g, 'Schluß'],
  [/Gr(?:ü|\?)(?:ß|\?)en/g, 'Grüßen'],
  [/Gr(?:ü|\?)(?:ß|\?)e\b/g, 'Grüße'],
  [/\bgem\?\?/g, 'gemäß'],

  // — ü family. NOTE: \b before \? doesn't anchor because \? is non-word,
  // so patterns that start with \? drop the leading \b and rely on the
  // uniqueness of the rest of the match to avoid mid-word false positives.
  [/\bf\?r\b/g, 'für'],
  [/\bF\?r\b/g, 'Für'],
  [/\?berdachte/g, 'überdachte'],
  [/\?berblick/g, 'Überblick'],
  [/\?bermittelt/g, 'übermittelt'],
  [/\?bersetzt/g, 'übersetzt'],
  [/\?bernehmen/g, 'übernehmen'],
  [/\?bernimmt/g, 'übernimmt'],
  [/\?berweis/g, 'überweis'],
  [/\?berpr\?fen/g, 'überprüfen'],
  [/\?berpr\?ft/g, 'überprüft'],
  [/\?berwiegend/g, 'überwiegend'],
  [/\?ber eine/g, 'Über eine'],
  [/\?ber\b/g, 'über'],
  [/\bw\?rden\b/g, 'würden'],
  [/\bW\?rden\b/g, 'Würden'],
  [/\bw\?rde\b/g, 'würde'],
  [/\bW\?rde\b/g, 'Würde'],
  [/\bm\?ssen/g, 'müssen'],
  [/\bM\?ssen/g, 'Müssen'],
  [/\bm\?glich/g, 'möglich'], // ö
  [/\bM\?glich/g, 'Möglich'],
  [/\bpr\?fen/g, 'prüfen'],
  [/\bPr\?fen/g, 'Prüfen'],
  [/\bzur\?ck/g, 'zurück'],
  [/\bZur\?ck/g, 'Zurück'],
  [/\bSt\?ck/g, 'Stück'],
  [/\bst\?ck/g, 'stück'],
  [/\bnat\?rlich/g, 'natürlich'],
  [/\bNat\?rlich/g, 'Natürlich'],
  [/\bgr\?n/g, 'grün'],
  [/\bGr\?n/g, 'Grün'],
  [/\bn\?tz/g, 'nütz'],
  [/\bGl\?ck/g, 'Glück'],
  [/\bgl\?ck/g, 'glück'],
  [/\bStellpl\?tze/g, 'Stellplätze'],
  [/Immobilientreuh\?nder/g, 'Immobilientreuhänder'],
  [/Gesch\?ftsf\?hrer/g, 'Geschäftsführer'],
  [/Gesch\?ftsabwick/g, 'Geschäftsabwick'],
  [/Vermittlungsb\?hne/g, 'Vermittlungsbühne'],
  [/\bB\?ro/g, 'Büro'],
  [/\bb\?ro/g, 'büro'],

  // — ä family
  [/\bGesch\?ft/g, 'Geschäft'],
  [/\bgesch\?ft/g, 'geschäft'],
  [/\bt\?glich/g, 'täglich'],
  [/\bT\?glich/g, 'Täglich'],
  [/\bn\?chst/g, 'nächst'],
  [/\bN\?chst/g, 'Nächst'],
  [/\bn\?mlich/g, 'nämlich'],
  [/\bst\?ndig/g, 'ständig'],
  [/\bverf\?gbar/g, 'verfügbar'],
  [/\bVerf\?gbar/g, 'Verfügbar'],
  [/\bw\?hrend/g, 'während'],
  [/\bW\?hrend/g, 'Während'],
  [/\btats\?chlich/g, 'tatsächlich'],
  [/\bTats\?chlich/g, 'Tatsächlich'],
  [/\bverst\?ndlich/g, 'verständlich'],
  [/\bL\?nge/g, 'Länge'],
  [/\bl\?nge/g, 'länge'],
  [/\bh\?tte\b/g, 'hätte'],
  [/\bh\?tten\b/g, 'hätten'],
  [/\bw\?re\b/g, 'wäre'],
  [/\bW\?re\b/g, 'Wäre'],
  [/\bS\?tze/g, 'Sätze'],
  [/\bPl\?tze/g, 'Plätze'],
  [/\bB\?ume/g, 'Bäume'],
  [/\bG\?ste/g, 'Gäste'],
  [/\bverk\?uf/g, 'verkäuf'],
  [/\bVerk\?uf/g, 'Verkäuf'],
  [/\?rzte/g, 'Ärzte'],
  [/\?hnlich/g, 'ähnlich'],
  [/\?ltest/g, 'ältest'],
  [/\?ltere/g, 'ältere'],
  [/\bM\?rz/g, 'März'],
  [/\bst\?rker/g, 'stärker'],

  // — ö family
  [/\bk\?nn/g, 'könn'],
  [/\bK\?nn/g, 'Könn'],
  [/\bsch\?n/g, 'schön'],
  [/\bSch\?n/g, 'Schön'],
  [/\bh\?ren/g, 'hören'],
  [/\bH\?ren/g, 'Hören'],
  [/\bm\?cht/g, 'möcht'],
  [/\bM\?cht/g, 'Möcht'],
  [/\bgeh\?rt/g, 'gehört'],
  [/\bgel\?st/g, 'gelöst'],
  [/\?ffn/g, 'öffn'],
  [/\?stlich/g, 'östlich'],
  [/\?ffentlich/g, 'öffentlich'],
  [/\bgr\?\?er/g, 'größer'],

  // — apostrophe mangling (client exported a straight ' as ? too)
  [/\bgeht\?s\b/g, "geht's"],
  [/\bhat\?s\b/g, "hat's"],
  [/\bist\?s\b/g, "ist's"],
  [/\bgibt\?s\b/g, "gibt's"],

  // — start-of-sentence Über
  [/^\s*\?ber\s/m, 'Über '],
]

function repairUmlauts(text) {
  if (!text || !text.includes('?')) return text
  let out = String(text)
  for (const [pattern, replacement] of UMLAUT_REPAIRS) {
    out = out.replace(pattern, replacement)
  }
  return out
}

// Some mail clients (especially Apple Mail quoted-printable output) strip
// paragraph breaks and collapse sentences into one run-on line, e.g.
// "Hölzl,Wir können ... Auto.069910859884Beste Grüße Michael". Re-insert
// spaces at the points where a sentence clearly restarts so the body is
// readable again. Heuristic is conservative to avoid breaking URLs / CamelCase.
function unmangleRunOns(text) {
  return String(text || '')
    // comma/semicolon directly followed by uppercase letter → insert space
    .replace(/([,;])([A-ZÄÖÜ])/g, '$1 $2')
    // period followed by a long digit run (phone numbers mashed onto end of sentence)
    .replace(/([.!?])(\d{3,})/g, '$1 $2')
    // digit run followed by a capitalized word → insert space ("884Beste" → "884 Beste")
    .replace(/(\d)([A-ZÄÖÜ][a-zäöüß])/g, '$1 $2')
}

// Clean and format email body for display
function cleanEmailBody(raw) {
  if (!raw) return ""
  const original = String(raw)
  let text = decodeHtmlEntities(original)

  // Best-effort German umlaut repair for legacy rows where the IMAP
  // fetcher stored raw Windows-1252 bytes that MySQL replaced with '?'.
  text = repairUmlauts(text)

  // Strip quoted Outlook/Gmail forward header blocks (Von:/Gesendet:/An:/
  // Betreff: groups). When the bubble also has extracted forward metadata
  // shown in the header strip, these lines are pure noise inside the body.
  text = text.replace(/^\s*(Von|From)\s*:.+\n(?:\s*(Gesendet|Date)\s*:.+\n)?(?:\s*(An|To)\s*:.+\n)?(?:\s*(Cc|CC)\s*:.*\n)?(?:\s*(Betreff|Subject)\s*:.+\n)?/gim, '')

  // Strip the classic SR-Homes footer block (signature + full DSGVO
  // boilerplate) that gets dragged into every bubble. IMPORTANT: stop at
  // the first "— — —" forwarded-content separator that splitForwardedMessage
  // inserts — previously these strippers greedily ate everything to
  // end-of-string, including the Baldinger payload below the separator.
  const forwardSep = text.indexOf('— — —')
  if (forwardSep >= 0) {
    // Signature cleanup only inside the wrapper region.
    const before = text.slice(0, forwardSep)
    const after = text.slice(forwardSep)
    let cleanedBefore = before
      .replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
      .replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
    text = cleanedBefore + after
    // If the wrapper was nothing but a signature, drop the now-leading
    // separator so the body starts with the forwarded content directly.
    text = text.replace(/^\s*— — —\s*/, '')
  } else {
    text = text.replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
    text = text.replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
  }

  // Strip English reply-quote chains ("On 08.04.26 at 21:29, X wrote:" + rest)
  // Apple Mail and Gmail use this exact attribution format.
  text = text.replace(/\n\s*On\s+[A-Z0-9][^\n]{0,240}wrote\s*:[\s\S]*$/im, "")

  // Strip quoted reply chains ("Am ... schrieb ...:" + everything after)
  text = text.replace(/\n\s*Am \d{1,2}\.\d{1,2}\.\d{2,4}.*schrieb.*:[\s\S]*$/im, "")
  text = text.replace(/\n\s*Am\s+\w+[.,]?\s+\d{1,2}\.\s*\w+[.\s]+\d{2,4}[^\n]*schrieb[\s\S]*$/im, "")
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

  // Strip forwarded header blocks only when they appear after real intro text.
  // If the mail itself is a forward, keep the forwarded content visible.
  const forwardedHeaderMatch = text.match(/\n-{3,}\s*(Weitergeleitete Nachricht|Forwarded message|Original Message)\s*-{3,}/im)
  if (forwardedHeaderMatch && typeof forwardedHeaderMatch.index === 'number' && forwardedHeaderMatch.index > 120) {
    text = text.slice(0, forwardedHeaderMatch.index)
  }

  // Strip signature blocks (after --)
  text = text.replace(/\n--\s*\n[\s\S]*$/m, "")

  // Collapse 3+ consecutive blank lines to 2
  text = text.replace(/(\n\s*){3,}/g, "\n\n")

  // Re-insert whitespace at sentence restart points (mangled bodies).
  text = unmangleRunOns(text)

  text = text.trim()

  // Safety fallback: if cleaning removed almost everything, show original text.
  if (text.length < 10 && original.trim().length > 0) {
    return decodeHtmlEntities(original).trim()
  }

  return text
}

function htmlToText(html) {
  if (!html) return ""
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/p>/gi, "\n\n")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/\r/g, "")
    .replace(/[ \t]+\n/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim()
}

const rawBody = computed(() => {
  const src = props.message.full_body
    || props.message.body_text
    || props.message.body
    || htmlToText(props.message.body_html)
    || props.message.ai_summary
    || props.message.result
    || ""
  return decodeHtmlEntities(src)
})
const displayBody = computed(() => cleanEmailBody(rawBody.value))
const hasQuotedContent = computed(() => rawBody.value.length > displayBody.value.length + 20)

const isTruncatable = computed(() => displayBody.value.length > 300)
const truncatedBody = computed(() => isTruncatable.value ? displayBody.value.slice(0, 300).trimEnd() : displayBody.value)

// Exact timestamp label shown at the bottom of each bubble.
// Format: HH:MM (today) or DD.MM. HH:MM (other days, as a safety fallback).
const timeLabel = computed(() => {
  const raw = props.message.email_date || props.message.activity_date || props.message.date || props.message.created_at
  if (!raw) return ''
  const date = new Date(raw)
  if (isNaN(date.getTime())) return ''
  const hh = String(date.getHours()).padStart(2, '0')
  const mm = String(date.getMinutes()).padStart(2, '0')
  const now = new Date()
  const isToday = date.toDateString() === now.toDateString()
  if (isToday) return `${hh}:${mm}`
  const dd = String(date.getDate()).padStart(2, '0')
  const mo = String(date.getMonth() + 1).padStart(2, '0')
  return `${dd}.${mo}. ${hh}:${mm}`
})

// Full timestamp shown as a tooltip when hovering the time label.
const timeTooltip = computed(() => {
  const raw = props.message.email_date || props.message.activity_date || props.message.date || props.message.created_at
  if (!raw) return ''
  const date = new Date(raw)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleString('de-AT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
})

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

const hasRenderableContent = computed(() => {
  const text = (displayBody.value || '').trim()
  const raw = (rawBody.value || '').trim()
  return text.length > 0 || raw.length > 0 || attachments.value.length > 0
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
  <div v-if="hasRenderableContent" class="flex w-full" :class="isOutbound ? 'justify-end' : 'justify-start'">
    <div
      class="max-w-[95%] md:max-w-[92%] px-4 py-3"
      :class="[bubbleClasses, isTruncatable && !expanded ? 'cursor-pointer' : '']"
      @click="toggleExpand"
    >
      <!-- Recipient header (only for intern bubbles so we can see where the
           mail actually went when we were just CC'd) -->
      <div v-if="isIntern && recipientLabel" class="flex items-center gap-1 text-[10px] text-sky-700/80 font-medium mb-1 -mt-0.5">
        <span class="opacity-70">An:</span>
        <span class="truncate">{{ recipientLabel }}</span>
      </div>

      <!-- Forwarded-from header strip: shows when the bubble's sender is
           relaying someone else's mail. Keeps the display attribution
           clear — the outer bubble is still "from Susanne" but the
           content originally came from the extracted forwarded sender. -->
      <div v-if="isForwardedBy" class="flex items-start gap-1.5 text-[10px] text-indigo-700 font-medium mb-2 -mt-0.5 px-2 py-1 rounded-md bg-indigo-50/70 border border-indigo-100">
        <svg class="w-3 h-3 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/></svg>
        <div class="min-w-0">
          <div class="flex flex-wrap items-baseline gap-x-1">
            <span class="opacity-70">Weiterleitung ursprünglich von:</span>
            <span class="font-semibold text-indigo-900">{{ forwardedFromName || forwardedFromEmail }}</span>
          </div>
          <div v-if="forwardedSubject" class="text-[10px] text-indigo-700/80 truncate">
            Betreff: {{ forwardedSubject }}
          </div>
        </div>
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

      <!-- Timestamp (always shown at bottom of bubble) -->
      <div v-if="timeLabel" class="flex justify-end mt-1.5 -mb-0.5">
        <span
          class="text-[10px] text-zinc-400 font-medium tabular-nums select-none"
          :title="timeTooltip"
        >
          {{ timeLabel }}
        </span>
      </div>
    </div>
  </div>
</template>
