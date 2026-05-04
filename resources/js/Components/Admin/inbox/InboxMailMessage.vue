<script setup>
import { ref, computed, inject } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ChevronDown, ChevronUp, Paperclip, FolderDown, Download, AlertTriangle, Move } from 'lucide-vue-next'
import InboxMailBody from './InboxMailBody.vue'
import { cleanEmailBody } from './mailText.js'

const API = inject('API')

const props = defineProps({
  message: { type: Object, required: true },
  senderName: { type: String, default: '' },
  isInitiallyExpanded: { type: Boolean, default: false },
})

const emit = defineEmits(['saveAttachment'])

const expanded = ref(props.isInitiallyExpanded)

// Injected from Dashboard.vue
const openContact = inject('openContact', () => {})
const currentUserAvatar = inject('currentUserAvatar', { url: null, initials: '??' })
const inboxSignatureData = inject('inboxSignatureData', ref(null))

const page = usePage()
function normalizeAddr(raw) {
  const s = String(raw || '').trim()
  const angle = s.match(/<([^>]+)>/)
  return (angle ? angle[1] : s).trim().toLowerCase()
}
const authEmailNorm = computed(() => normalizeAddr(page.props.auth?.user?.email))

// ── Direction + category heuristics (unchanged from the old bubble)
const isOutbound = computed(() => {
  const d = props.message.direction
  const c = props.message.category
  return d === 'outbound' || d === 'out' || ['email-out', 'expose', 'nachfassen'].includes(c)
})
const isAutoReply = computed(() => props.message.category === 'auto-reply' || props.message.is_auto_reply)
// Auto-archived: vom System nach einer Reply-Sende-Aktion in den Papierkorb
// verschoben. Bleibt im Conv-Verlauf sichtbar, aber leicht abgesetzt damit
// der User nicht denkt der Verlauf ist unvollstaendig.
const isArchived = computed(() => !!(props.message.is_archived || props.message.is_deleted))
const isIntern = computed(() => {
  const cat = (props.message.category || '').toLowerCase()
  const from = (props.message.from_email || '').toLowerCase()
  const to = (props.message.to_email || '').toLowerCase()
  const direction = (props.message.direction || '').toLowerCase()
  if (cat === 'intern') return true
  if (from.endsWith('@sr-homes.at') && to.endsWith('@sr-homes.at')) return true
  if (from.endsWith('@sr-homes.at') && direction === 'inbound') return true
  return false
})

// ── Display fields
const displayName = computed(() => {
  const raw = props.message.from_name || props.senderName || props.message.from_email || 'Unbekannt'
  return String(raw).replace(/\s*<[^>]+>\s*$/, '').trim() || 'Unbekannt'
})
const senderAddress = computed(() => {
  const raw = String(props.message.from_email || '')
  return raw ? raw : ''
})
const recipientLabel = computed(() => {
  const raw = props.message.to_email || ''
  if (!raw) return ''
  const first = String(raw).split(',')[0].trim()
  const angle = first.match(/<([^>]+)>/)
  return (angle?.[1] || first).trim()
})

// ── Avatar
const avatarInitials = computed(() => {
  const name = displayName.value || ''
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return '??'
})
const avatarImageUrl = computed(() => {
  if (!isOutbound.value) return null
  const outboundAvatar = String(props.message.sender_avatar_url || '').trim()
  if (outboundAvatar) return outboundAvatar
  const fromNorm = normalizeAddr(props.message.from_email)
  if (fromNorm && authEmailNorm.value && fromNorm === authEmailNorm.value) {
    const sigUrl = String(inboxSignatureData?.value?.signature_photo_url || '').trim()
    if (sigUrl) return sigUrl
  }
  const avatar = currentUserAvatar
  return avatar?.url || null
})

// ── Body preview for collapsed state
const bodyPreview = computed(() => {
  const raw = props.message.body_text || props.message.full_body || ''
  const cleaned = cleanEmailBody(raw).replace(/\n+/g, ' ').replace(/\s{2,}/g, ' ').trim()
  return cleaned.slice(0, 140)
})

// ── Forward metadata (already resolved by InboxChatView before handing
// the message down — we just read the _forwarded* fields)
const forwardedFromName = computed(() => props.message._forwardedFromName || null)
const forwardedFromEmail = computed(() => props.message._forwardedFromEmail || null)
const forwardedSubject = computed(() => props.message._forwardedSubject || null)
const isForwardedBy = computed(() => !!forwardedFromName.value || !!forwardedFromEmail.value)

// ── Attachments (unchanged)
const attachments = computed(() => {
  const m = props.message
  if (Array.isArray(m.attachments) && m.attachments.length) return m.attachments
  if (m.has_attachment && m.attachment_names) {
    const names = typeof m.attachment_names === 'string'
      ? m.attachment_names.split(',').map(n => n.trim()).filter(Boolean)
      : []
    return names.map((name, idx) => ({ name, index: idx }))
  }
  return []
})

function onSaveAttachment(att, idx) {
  emit('saveAttachment', {
    emailId: props.message.id || props.message.email_id,
    fileIndex: att.index !== undefined ? att.index : idx,
    filename: att.name || att.filename || 'Anhang',
    propertyId: props.message.property_id || null,
  })
}

// Direkter Download auf den Rechner — geht ueber den bestehenden
// download_attachment-Endpoint mit dl_mode=download. Browser handelt
// Content-Disposition: attachment automatisch.
function onDownloadAttachment(att, idx) {
  const emailId = props.message.id || props.message.email_id
  const fileIndex = att.index !== undefined ? att.index : idx
  if (!emailId) return
  const apiBase = (API && API.value) || ''
  if (!apiBase) return
  const url = apiBase + '&action=download_attachment&email_id=' + encodeURIComponent(emailId) + '&file_index=' + encodeURIComponent(fileIndex) + '&dl_mode=download'
  // Hidden anchor mit download-Attribut: forciert Browser-Download in den
  // Default-Downloads-Ordner statt Inline-Anzeige (z.B. PDF im Tab).
  const a = document.createElement('a')
  a.href = url
  a.download = att.name || att.filename || 'Anhang'
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  setTimeout(() => a.remove(), 0)
}

// ── Time label
const timeLabel = computed(() => {
  const raw = props.message.email_date || props.message.activity_date || props.message.date || props.message.created_at
  if (!raw) return ''
  const date = new Date(raw)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleString('de-AT', {
    weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  })
})

function toggle() {
  expanded.value = !expanded.value
}

function onNameClick() {
  if (isOutbound.value) return
  if (displayName.value && displayName.value !== 'Unbekannt') {
    openContact(displayName.value)
  }
}

// ── Property-Mismatch-Hint
// Wenn der Backend-Importer im Body eine Ref-ID gefunden hat, die zu einem
// anderen Objekt gehoert als die Mail aktuell zugeordnet ist, blenden wir
// hier ein Banner ein. Mit One-Click verschiebt der User die Mail in die
// passende Conversation (neu angelegt oder existierend).
const splitMail = inject('inboxSplitMail', null)
const propertiesArray = inject('inboxPropertiesArray', null)

const mismatchRefId = computed(() => props.message?.property_mismatch_ref_id || null)
const mismatchTargetProperty = computed(() => {
  const ref = mismatchRefId.value
  if (!ref) return null
  const list = propertiesArray?.value || []
  return list.find(p => String(p.ref_id || '').toLowerCase() === String(ref).toLowerCase()) || null
})
const splitting = ref(false)
const splitDismissed = ref(false)

async function onSplitMail() {
  if (splitting.value) return
  const target = mismatchTargetProperty.value
  if (!target || !splitMail) return
  splitting.value = true
  try {
    const ok = await splitMail(props.message.id, Number(target.id))
    if (ok) {
      // Banner sofort ausblenden — die Mail wechselt gleich die Conversation,
      // bis das Refresh durch ist.
      splitDismissed.value = true
    }
  } finally {
    splitting.value = false
  }
}

function dismissMismatch() {
  splitDismissed.value = true
}
</script>

<template>
  <div class="sr-msg" :class="{ 'sr-msg--expanded': expanded, 'sr-msg--collapsed': !expanded, 'sr-msg--archived': isArchived }">
    <div class="sr-msg-row" @click="toggle">
      <div class="sr-avatar" :class="isOutbound ? 'sr-avatar--me' : 'sr-avatar--them'">
        <img v-if="avatarImageUrl" :src="avatarImageUrl" :alt="avatarInitials" />
        <span v-else>{{ avatarInitials }}</span>
      </div>

      <div class="sr-sender-block">
        <div class="sr-sender-line">
          <button
            type="button"
            class="sr-sender-name"
            :title="isOutbound ? displayName : (displayName + ' — Kontakt öffnen')"
            @click.stop="onNameClick"
          >{{ displayName }}</button>
          <span v-if="senderAddress" class="sr-sender-addr">&lt;{{ senderAddress }}&gt;</span>
          <span v-if="isArchived" class="sr-archived-badge" title="Diese Nachricht wurde nach deiner Antwort automatisch ins Archiv (Papierkorb) verschoben — sie bleibt im Verlauf sichtbar.">archiviert</span>
        </div>
        <div v-if="expanded && recipientLabel" class="sr-to-line">An: {{ recipientLabel }}</div>
      </div>

      <span v-if="timeLabel" class="sr-time">{{ timeLabel }}</span>
      <component :is="expanded ? ChevronUp : ChevronDown" class="sr-chevron" />
    </div>

    <div v-if="!expanded && bodyPreview" class="sr-preview">{{ bodyPreview }}…</div>

    <div v-if="expanded" class="sr-expanded-content">
      <!-- Property-Mismatch-Banner: gelber Hinweis dass diese Mail eine
           Ref-ID nennt, die zu einem anderen Objekt gehoert als die
           Conversation aktuell zugeordnet ist. One-Click-Verschieben. -->
      <div
        v-if="mismatchRefId && mismatchTargetProperty && !splitDismissed"
        class="sr-mismatch-banner"
        @click.stop
      >
        <AlertTriangle class="sr-mismatch-icon" />
        <div class="sr-mismatch-text">
          <strong>Diese Mail nennt Ref-ID {{ mismatchRefId }}</strong>
          — sie ist aber aktuell der Conversation zu einem anderen Objekt zugeordnet.
        </div>
        <button
          type="button"
          class="sr-mismatch-action"
          :disabled="splitting"
          @click.stop="onSplitMail"
        >
          <Move class="sr-mismatch-action-icon" />
          {{ splitting ? 'Verschiebe…' : `Nach ${mismatchRefId} verschieben` }}
        </button>
        <button
          type="button"
          class="sr-mismatch-dismiss"
          title="Ausblenden"
          @click.stop="dismissMismatch"
        >×</button>
      </div>

      <!-- Fallback: Mail nennt eine Ref-ID, aber das Ziel-Objekt ist im
           Property-Picker nicht sichtbar (anderer Makler / inaktiv).
           Trotzdem informieren, aber ohne Aktions-Button. -->
      <div
        v-else-if="mismatchRefId && !splitDismissed"
        class="sr-mismatch-banner sr-mismatch-banner--info"
        @click.stop
      >
        <AlertTriangle class="sr-mismatch-icon" />
        <div class="sr-mismatch-text">
          Diese Mail nennt Ref-ID <strong>{{ mismatchRefId }}</strong> —
          das Objekt ist hier nicht verfuegbar (anderer Makler oder inaktiv).
        </div>
        <button
          type="button"
          class="sr-mismatch-dismiss"
          title="Ausblenden"
          @click.stop="dismissMismatch"
        >×</button>
      </div>

      <div v-if="isIntern && recipientLabel" class="sr-intern-strip">
        <span class="sr-strip-label">Intern · An:</span>
        <span class="sr-strip-value">{{ recipientLabel }}</span>
      </div>

      <div v-if="isForwardedBy" class="sr-forward-strip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/>
        </svg>
        <div class="sr-strip-body">
          <div><span class="sr-strip-label">Weiterleitung ursprünglich von:</span> <strong>{{ forwardedFromName || forwardedFromEmail }}</strong></div>
          <div v-if="forwardedSubject" class="sr-strip-sub">Betreff: {{ forwardedSubject }}</div>
        </div>
      </div>

      <div v-if="isAutoReply" class="sr-auto-reply-hint">⚡ Auto-Reply</div>

      <InboxMailBody :message="message" class="sr-body" />

      <div v-if="attachments.length" class="sr-attachments">
        <div v-for="(att, i) in attachments" :key="i" class="sr-attachment">
          <Paperclip class="sr-attachment-icon" />
          <span class="sr-attachment-name">{{ att.name || att.filename || 'Anhang' }}</span>
          <button
            type="button"
            class="sr-attachment-action"
            title="Auf Computer herunterladen"
            @click.stop="onDownloadAttachment(att, i)"
          >
            <Download class="sr-attachment-save-icon" />
            Download
          </button>
          <button
            type="button"
            class="sr-attachment-action"
            title="Zum Objekt speichern"
            @click.stop="onSaveAttachment(att, i)"
          >
            <FolderDown class="sr-attachment-save-icon" />
            Zum Objekt
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sr-msg {
  border-bottom: 1px solid hsl(0 0% 93%);
  background: hsl(0 0% 100%);
}
.sr-msg:last-child { border-bottom: none; }
.sr-msg--collapsed { background: hsl(0 0% 100%); cursor: pointer; transition: background 120ms ease; }
.sr-msg--collapsed:hover { background: hsl(0 0% 98%); }

.sr-msg-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; }
.sr-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600; color: white;
  overflow: hidden;
}
.sr-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sr-avatar--me { background: linear-gradient(135deg, hsl(28 98% 54%), hsl(18 88% 48%)); }
.sr-avatar--them { background: linear-gradient(135deg, hsl(215 25% 65%), hsl(215 30% 48%)); }

.sr-sender-block { flex: 1; min-width: 0; }
.sr-sender-line { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
.sr-sender-name {
  font-size: 13px; font-weight: 600; color: hsl(0 0% 9%);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  background: none; border: none; padding: 0; cursor: pointer;
  border-radius: 2px;
}
.sr-sender-name:hover { color: hsl(217 91% 45%); text-decoration: underline; }
.sr-sender-name:focus-visible { outline: 2px solid hsl(217 91% 45%); outline-offset: 2px; }
.sr-sender-addr { font-size: 11px; color: hsl(0 0% 50%); font-weight: 400; white-space: nowrap; }
.sr-to-line { font-size: 11px; color: hsl(0 0% 50%); margin-top: 1px; }
.sr-time {
  font-size: 11px; color: hsl(0 0% 50%); white-space: nowrap;
  font-variant-numeric: tabular-nums; flex-shrink: 0;
}
.sr-chevron { width: 16px; height: 16px; color: hsl(0 0% 60%); flex-shrink: 0; }

.sr-preview {
  padding: 0 12px 10px 52px;
  font-size: 12px; color: hsl(0 0% 45%);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.sr-expanded-content { padding: 2px 12px 16px 52px; }

.sr-intern-strip,
.sr-forward-strip {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 8px 12px; margin-bottom: 12px;
  font-size: 11px; border-radius: 6px;
}
.sr-intern-strip { background: hsl(199 85% 96%); color: hsl(199 85% 30%); border: 1px solid hsl(199 85% 88%); }
.sr-forward-strip { background: hsl(230 70% 96%); color: hsl(230 70% 30%); border: 1px solid hsl(230 70% 88%); }
.sr-forward-strip svg { width: 13px; height: 13px; margin-top: 1px; flex-shrink: 0; }
.sr-strip-label { opacity: 0.7; }
.sr-strip-value { font-weight: 500; }
.sr-strip-body { min-width: 0; flex: 1; }
.sr-strip-sub { opacity: 0.75; margin-top: 2px; }

.sr-auto-reply-hint {
  display: inline-block; padding: 2px 8px; margin-bottom: 12px;
  background: hsl(140 60% 95%); color: hsl(140 60% 30%);
  border: 1px solid hsl(140 60% 85%); border-radius: 999px;
  font-size: 11px; font-weight: 500;
}

.sr-body { margin-top: 4px; }

.sr-attachments {
  margin-top: 16px; padding-top: 12px;
  border-top: 1px dashed hsl(0 0% 88%);
  display: flex; flex-direction: column; gap: 6px;
}
.sr-attachment { display: flex; align-items: center; gap: 6px; font-size: 12px; flex-wrap: wrap; }
.sr-attachment-icon { width: 14px; height: 14px; color: hsl(0 0% 50%); flex-shrink: 0; }
.sr-attachment-name { flex: 1; min-width: 120px; color: hsl(0 0% 25%); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sr-attachment-action {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 10px; font-size: 10px; font-weight: 500;
  background: hsl(28 98% 96%); color: hsl(28 80% 38%);
  border: 1px solid hsl(28 90% 86%); border-radius: 6px;
  cursor: pointer;
  transition: background 100ms ease, border-color 100ms ease;
}
.sr-attachment-action:hover {
  background: hsl(28 98% 92%);
  border-color: hsl(28 90% 76%);
}
.sr-attachment-save-icon { width: 12px; height: 12px; }

/* Archivierte Nachrichten — automatisch nach Reply in den Papierkorb
   verschoben, bleiben aber im Conv-Verlauf sichtbar. Leicht ausgegraut
   damit der User auf einen Blick sieht: das ist erledigte Historie, nicht
   eine offene Nachricht. */
.sr-msg--archived { opacity: 0.7; }
.sr-msg--archived:hover { opacity: 0.92; }
.sr-archived-badge {
  display: inline-block;
  padding: 1px 6px;
  margin-left: 6px;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.4px;
  text-transform: uppercase;
  color: hsl(240 5% 50%);
  background: hsl(240 5% 95%);
  border: 1px solid hsl(240 5% 88%);
  border-radius: 3px;
  cursor: help;
  flex-shrink: 0;
}

.sr-mismatch-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  margin: 8px 12px 0;
  background: hsl(48 96% 92%);
  border: 1px solid hsl(45 92% 78%);
  border-left: 3px solid hsl(38 92% 50%);
  border-radius: 8px;
  font-size: 12px;
  color: hsl(28 60% 25%);
}
.sr-mismatch-banner--info {
  background: hsl(45 80% 96%);
  color: hsl(28 40% 35%);
}
.sr-mismatch-icon { width: 16px; height: 16px; flex-shrink: 0; color: hsl(38 92% 50%); }
.sr-mismatch-text { flex: 1; line-height: 1.35; }
.sr-mismatch-action {
  display: inline-flex; align-items: center; gap: 4px;
  background: hsl(28 92% 50%); color: white;
  border: none; padding: 6px 10px; border-radius: 6px;
  font-size: 11px; font-weight: 600;
  cursor: pointer; transition: background 120ms;
  flex-shrink: 0;
}
.sr-mismatch-action:hover { background: hsl(28 92% 44%); }
.sr-mismatch-action:disabled { opacity: 0.6; cursor: wait; }
.sr-mismatch-action-icon { width: 12px; height: 12px; }
.sr-mismatch-dismiss {
  background: transparent; border: none;
  color: hsl(28 30% 45%);
  font-size: 18px; line-height: 1; cursor: pointer;
  padding: 0 4px; flex-shrink: 0;
}
.sr-mismatch-dismiss:hover { color: hsl(28 60% 25%); }
</style>
