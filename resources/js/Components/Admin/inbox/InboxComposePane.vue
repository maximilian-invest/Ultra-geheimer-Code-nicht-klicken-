<script setup>
import { ref, computed, inject, onMounted } from 'vue'
import { Send, RefreshCw, Paperclip, Wand2, X, Loader2, Link2 } from 'lucide-vue-next'
import LinkPickerPopover from './LinkPickerPopover.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'

const props = defineProps({
  // "reply" | "reply-all" | "forward"
  kind: { type: String, default: 'reply' },
  // Whether to trigger AI draft generation on mount
  withDraft: { type: Boolean, default: false },
  // Pre-filled recipient/subject from the thread view
  prefill: { type: Object, default: () => ({ to: '', subject: '' }) },
  // Reference message (the one being replied to) — displayed as a
  // collapsed strip below the compose pane
  referenceMessage: { type: Object, default: null },
  // Property id (for link picker scope)
  propertyId: { type: [Number, String], default: null },
})

const emit = defineEmits(['cancel'])

// Shared compose state lives in InboxTab and is injected here so we can
// reuse the existing send pipeline unchanged. Each field is a ref.
const inboxCompose = inject('inboxCompose', {
  draft: ref({ body: '', subject: '', to: '' }),
  sendAccountId: ref(null),
  selectedFiles: ref([]),
  sendAccounts: ref([]),
  loading: ref(false),
  regenerate: () => {},
  improve: () => {},
  send: () => {},
  toggleFile: () => {},
})
const signatureData = inject('inboxSignatureData', ref(null))

const draft = inboxCompose.draft
const sendAccountId = inboxCompose.sendAccountId
const sendAccounts = inboxCompose.sendAccounts
const loading = inboxCompose.loading

// ── Local UI state
const linkPickerOpen = ref(false)
const referenceExpanded = ref(false)
const ccVisible = ref(false)

// Build a quoted block for forward: standard email client style header +
// original body. So the forwarded mail looks like a proper weitergeleitete
// Nachricht with attribution.
function buildForwardQuote(ref) {
  if (!ref) return ''
  const from = (ref.from_name || '') + (ref.from_email ? ' <' + ref.from_email + '>' : '')
  const whenRaw = ref.email_date || ref.activity_date || ref.date || ''
  let when = ''
  if (whenRaw) {
    const d = new Date(whenRaw)
    if (!isNaN(d.getTime())) when = d.toLocaleString('de-AT', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  }
  const to = ref.to_email || ref.to_name || ''
  const subj = ref.subject || ''
  const body = String(ref.body_text || ref.full_body || '').trim()

  return [
    '',
    '',
    '-------- Weitergeleitete Nachricht --------',
    'Von: ' + from,
    'Gesendet: ' + when,
    'An: ' + to,
    'Betreff: ' + subj,
    '',
    body,
  ].join('\n')
}

// On mount, seed the draft with the prefill if the body is still empty.
// Also trigger AI draft generation when withDraft is true and the body
// isn't already populated.
onMounted(() => {
  if (!draft.value) {
    draft.value = { body: '', subject: '', to: '' }
  }
  if (props.prefill?.to && !draft.value.to) {
    draft.value = { ...draft.value, to: props.prefill.to }
  }
  if (props.prefill?.subject && !draft.value.subject) {
    draft.value = { ...draft.value, subject: props.prefill.subject }
  }
  // Bei Weiterleiten: Original als Zitat im Body vorbefuellen, damit
  // Empfaenger die urspruengliche Nachricht sieht.
  if (props.kind === 'forward' && !(draft.value.body || '').trim() && props.referenceMessage) {
    draft.value = { ...draft.value, body: buildForwardQuote(props.referenceMessage) }
  }
  if (props.withDraft && !(draft.value.body || '').trim()) {
    inboxCompose.regenerate()
  }
  // Hoehe wird vom RichTextEditor selbst verwaltet — kein autoResize mehr.
})

// ── Computed labels
const kindLabel = computed(() => {
  if (props.kind === 'forward') return 'Weiterleiten'
  if (props.kind === 'reply-all') return 'Allen antworten'
  return 'Antworten'
})

const recipientName = computed(() => {
  const ref = props.referenceMessage || {}
  return ref.from_name || ref.from_email || draft.value?.to || ''
})

const referenceTimeLabel = computed(() => {
  const raw = props.referenceMessage?.email_date || props.referenceMessage?.activity_date || props.referenceMessage?.date
  if (!raw) return ''
  const date = new Date(raw)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleString('de-AT', {
    weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  })
})

const referencePreview = computed(() => {
  const raw = props.referenceMessage?.body_text || props.referenceMessage?.full_body || ''
  return String(raw).replace(/\s+/g, ' ').trim().slice(0, 220)
})

// Extract URLs from text (used to show clickable link list below textarea
// and in reference view — Textareas koennen nicht klickbar sein, daher separate Liste)
const URL_REGEX = /(https?:\/\/[^\s<>"'()]+)/gi
function extractLinks(text) {
  if (!text) return []
  const found = String(text).match(URL_REGEX) || []
  // Trailing punctuation strippen (Punkt, Komma, Klammer am Ende)
  const cleaned = found.map(u => u.replace(/[.,;:!?)\]}>]+$/, ''))
  return [...new Set(cleaned)]
}
const draftLinks = computed(() => extractLinks(draft.value?.body || ''))
const referenceLinks = computed(() => {
  const raw = props.referenceMessage?.body_text || props.referenceMessage?.full_body || ''
  return extractLinks(raw)
})
function shortLabel(url) {
  try {
    const u = new URL(url)
    const hostWithPath = u.hostname + (u.pathname === '/' ? '' : u.pathname)
    return hostWithPath.length > 50 ? hostWithPath.slice(0, 47) + '…' : hostWithPath
  } catch {
    return url.length > 50 ? url.slice(0, 47) + '…' : url
  }
}

const bodyIsEmpty = computed(() => !(draft.value?.body || '').trim())
const signature = computed(() => signatureData?.value || null)
const hasSignature = computed(() => !!(signature.value && (signature.value.signature_name || signature.value.signature_company)))

function resolveSignatureUrl(url) {
  const raw = String(url || '').trim()
  if (!raw) return ''
  if (typeof window === 'undefined') return raw
  const isLocalUi = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  const isLocalAsset = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//i.test(raw)
  if (isLocalUi && isLocalAsset) {
    return raw.replace(/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/i, 'https://kundenportal.sr-homes.at')
  }
  return raw
}

// ── Draft field setters
function updateDraftField(field, value) {
  draft.value = { ...(draft.value || {}), [field]: value }
}

// ── Handlers
function onCancel() {
  emit('cancel')
}

function onSend() {
  inboxCompose.send()
}

function onRegenerate() {
  inboxCompose.regenerate()
}

function onImprove() {
  inboxCompose.improve()
}

function onLinkPicked(link) {
  // Append the docs link URL to the end of the body on a fresh paragraph.
  const current = draft.value?.body || ''
  const append = `\n\nUnterlagen: ${link.url}`
  updateDraftField('body', (current + append).trim())
  linkPickerOpen.value = false
}
</script>

<template>
  <div class="sr-compose">
    <!-- To / Subject fields -->
    <div class="sr-compose-fields">
      <div class="sr-field">
        <label>Von</label>
        <select
          v-if="sendAccounts.length > 1"
          :value="sendAccountId"
          @change="sendAccountId = Number($event.target.value)"
          class="sr-field-input"
        >
          <option v-for="acc in sendAccounts" :key="acc.id" :value="acc.id">
            {{ acc.email_address || acc.email }}
          </option>
        </select>
        <span v-else class="sr-field-readonly">{{ sendAccounts[0]?.email_address || sendAccounts[0]?.email || '—' }}</span>
      </div>

      <div class="sr-field">
        <label>An</label>
        <input
          type="text"
          :value="draft?.to || ''"
          @input="updateDraftField('to', $event.target.value)"
          placeholder="Empfänger-E-Mail"
          class="sr-field-input"
        />
        <button
          v-if="!ccVisible && !(draft?.cc || '').trim()"
          type="button"
          class="sr-field-toggle"
          title="Cc hinzufügen"
          @click="ccVisible = true"
        >+ Cc</button>
      </div>

      <div v-if="ccVisible || (draft?.cc || '').trim()" class="sr-field">
        <label>Cc</label>
        <input
          type="text"
          :value="draft?.cc || ''"
          @input="updateDraftField('cc', $event.target.value)"
          placeholder="Weitere Empfänger (Komma-getrennt)"
          class="sr-field-input"
        />
      </div>

      <div class="sr-field">
        <label>Betreff</label>
        <input
          type="text"
          :value="draft?.subject || ''"
          @input="updateDraftField('subject', $event.target.value)"
          placeholder="Betreff"
          class="sr-field-input"
        />
      </div>
    </div>

    <!-- Body textarea -->
    <div class="sr-compose-body">
      <div v-if="loading" class="sr-body-loading">
        <Loader2 class="w-4 h-4 animate-spin" />
        <span>KI-Entwurf wird generiert…</span>
      </div>
      <RichTextEditor
        v-else
        :model-value="draft?.body || ''"
        @update:model-value="updateDraftField('body', $event)"
        placeholder="Deine Antwort hier eintippen…"
        min-height="200px"
        class="sr-body-richtext"
      />

      <!-- Klickbare Links aus dem Entwurf (Textarea selbst kann nicht klicken) -->
      <div v-if="draftLinks.length" class="sr-draft-links">
        <span class="sr-draft-links-label">Links im Entwurf:</span>
        <a v-for="link in draftLinks" :key="link" :href="link" target="_blank" rel="noopener noreferrer"
           class="sr-draft-link" :title="link">
          <Link2 class="w-3 h-3" />
          {{ shortLabel(link) }}
        </a>
      </div>

      <div v-if="hasSignature" class="sr-signature-inline">
        <div class="sr-signature-greeting">Mit freundlichen Grüßen</div>
        <img
          v-if="signature.signature_photo_url"
          :src="resolveSignatureUrl(signature.signature_photo_url)"
          alt="Signatur-Foto"
          class="sr-signature-photo"
        />
        <div class="sr-signature-text">
          <strong>{{ signature.signature_name || '' }}</strong>
          <span v-if="signature.signature_title">{{ signature.signature_title }}</span>
          <span v-if="signature.signature_company">{{ signature.signature_company }}</span>
          <span v-if="signature.signature_address">{{ signature.signature_address }}</span>
          <span v-if="signature.signature_phone">Tel: {{ signature.signature_phone }}</span>
          <span v-if="signature.signature_website">{{ signature.signature_website }}</span>
        </div>
      </div>
    </div>

    <!-- Send action bar is rendered as a sticky footer by InboxChatView,
         outside this component's scroll area, so it's always visible
         while the user scrolls through the body or the reference strip. -->

    <!-- Reference strip: the message being replied to -->
    <div v-if="referenceMessage" class="sr-reference-strip" @click="referenceExpanded = !referenceExpanded">
      <div class="sr-reference-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="sr-reference-icon" :class="referenceExpanded ? 'rotate-180' : ''"><polyline points="6 9 12 15 18 9"/></svg>
        <span class="sr-reference-label">Auf diese Nachricht von</span>
        <strong class="sr-reference-name">{{ referenceMessage.from_name || referenceMessage.from_email || 'Unbekannt' }}</strong>
        <span v-if="referenceTimeLabel" class="sr-reference-time">· {{ referenceTimeLabel }}</span>
      </div>
      <div v-if="!referenceExpanded && referencePreview" class="sr-reference-preview">{{ referencePreview }}…</div>
      <div v-if="referenceExpanded" class="sr-reference-body">{{ referenceMessage.body_text || referenceMessage.full_body || '' }}</div>

      <!-- Klickbare Links aus der Original-Nachricht -->
      <div v-if="referenceLinks.length" class="sr-draft-links" @click.stop>
        <span class="sr-draft-links-label">Links in der Original-Nachricht:</span>
        <a v-for="link in referenceLinks" :key="link" :href="link" target="_blank" rel="noopener noreferrer"
           class="sr-draft-link" :title="link" @click.stop>
          <Link2 class="w-3 h-3" />
          {{ shortLabel(link) }}
        </a>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sr-compose {
  background: hsl(0 0% 100%);
  border-top: none;
  height: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.sr-compose:focus-within {
  outline: none;
  box-shadow: none;
}

.sr-compose-header {
  padding: 14px 24px;
  background: linear-gradient(135deg, hsl(28 98% 96%), hsl(18 90% 97%));
  border-bottom: 1px solid hsl(28 80% 90%);
  display: flex;
  align-items: center;
  gap: 12px;
}
.sr-compose-header--compact {
  padding: 8px 16px;
}
.sr-compose-spacer { flex: 1; }
.sr-compose-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: hsl(18 80% 32%);
  flex: 1;
  min-width: 0;
}
.sr-compose-cancel {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 12px;
  background: hsl(0 0% 100%);
  border: 1px solid hsl(0 0% 88%);
  border-radius: 6px;
  font-size: 11px;
  font-weight: 500;
  color: hsl(0 0% 40%);
  cursor: pointer;
}
.sr-compose-cancel:hover {
  background: hsl(0 0% 97%);
  color: hsl(0 0% 20%);
}

.sr-compose-fields {
  padding: 12px 24px 4px;
}
.sr-field {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
  border-bottom: 1px solid hsl(0 0% 95%);
}
.sr-field label {
  font-size: 11px;
  color: hsl(0 0% 50%);
  min-width: 50px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  font-weight: 500;
}
.sr-field-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: 13px;
  color: hsl(0 0% 8%);
  font-weight: 500;
}
.sr-field-input option {
  color: hsl(0 0% 10%);
  font-weight: 500;
}
.sr-field-readonly {
  flex: 1;
  font-size: 13px;
  color: hsl(0 0% 10%);
  font-weight: 600;
}
.sr-field-toggle {
  font-size: 11px;
  color: hsl(28 80% 42%);
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 4px;
  font-weight: 500;
}
.sr-field-toggle:hover {
  background: hsl(28 90% 96%);
}

.sr-compose-body {
  padding: 0;
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}
.sr-body-loading {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 40px 0;
  font-size: 13px;
  color: hsl(28 80% 40%);
  justify-content: center;
}
.sr-body-textarea {
  width: 100%;
  flex: 0 0 auto;
  min-height: 38vh;
  padding: 14px 16px;
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
  border-radius: 0;
  background: transparent;
  font-family: inherit;
  font-size: 13.5px;
  line-height: 1.65;
  color: hsl(0 0% 15%);
  resize: none;
  overflow: hidden;
  appearance: none;
  -webkit-appearance: none;
}
.sr-body-textarea::placeholder {
  color: hsl(0 0% 65%);
}
.sr-body-textarea:focus,
.sr-body-textarea:focus-visible,
.sr-body-textarea:active {
  outline: none !important;
  box-shadow: none !important;
  border-color: transparent !important;
  background: transparent;
}

.sr-draft-links {
  padding: 8px 16px 10px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 11px;
  border-top: 1px solid rgba(0, 0, 0, 0.05);
}
.sr-draft-links-label {
  color: hsl(var(--muted-foreground));
  font-weight: 500;
  margin-right: 2px;
}
.sr-draft-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 6px;
  background: hsl(var(--muted));
  color: #2563eb;
  text-decoration: none;
  font-weight: 500;
  transition: background 120ms;
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sr-draft-link:hover {
  background: #dbeafe;
  text-decoration: underline;
}

.sr-signature-inline {
  background: transparent;
  padding: 0 16px 14px;
  display: flex;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 10px;
}
.sr-signature-greeting {
  width: 100%;
  margin-bottom: 6px;
  font-size: 13px;
  color: hsl(0 0% 28%);
}
.sr-signature-photo {
  width: 90px;
  height: 116px;
  object-fit: cover;
  border-radius: 2px;
  flex-shrink: 0;
}
.sr-signature-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 12px;
  line-height: 1.35;
  color: hsl(0 0% 24%);
}
.sr-signature-text strong {
  color: hsl(0 0% 12%);
  font-size: 16px;
  font-weight: 700;
}

.sr-compose-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  background: hsl(0 0% 99%);
  border-top: 1px solid hsl(0 0% 93%);
  flex-wrap: wrap;
}
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
.sr-btn-primary:hover:not(:disabled) {
  background: hsl(0 0% 18%);
}
.sr-btn-ai {
  background: linear-gradient(135deg, hsl(28 98% 54%), hsl(18 88% 48%));
  color: hsl(0 0% 100%);
  border: none;
  box-shadow: 0 1px 3px rgb(249 115 22 / 0.2);
}
.sr-btn-ai:hover:not(:disabled) {
  filter: brightness(1.08);
}
.sr-btn-ghost {
  background: transparent;
  border-color: transparent;
}
.sr-btn-ghost:hover:not(:disabled) {
  background: hsl(0 0% 96%);
  border-color: hsl(0 0% 90%);
}
.sr-compose-spacer { flex: 1; min-width: 20px; }
.sr-link-picker-wrapper { position: relative; }

.sr-reference-strip {
  padding: 10px 24px;
  background: hsl(0 0% 98%);
  border-top: 1px solid hsl(0 0% 93%);
  cursor: pointer;
  transition: background 120ms ease;
}
.sr-reference-strip:hover { background: hsl(0 0% 96%); }
.sr-reference-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: hsl(0 0% 45%);
}
.sr-reference-icon {
  width: 12px;
  height: 12px;
  transition: transform 120ms ease;
}
.sr-reference-icon.rotate-180 { transform: rotate(180deg); }
.sr-reference-name { color: hsl(0 0% 15%); font-weight: 600; }
.sr-reference-time { color: hsl(0 0% 55%); }
.sr-reference-preview {
  margin-top: 6px;
  margin-left: 18px;
  font-size: 11px;
  color: hsl(0 0% 45%);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sr-reference-body {
  margin-top: 8px;
  margin-left: 18px;
  padding: 12px;
  background: hsl(0 0% 100%);
  border: 1px solid hsl(0 0% 92%);
  border-radius: 6px;
  font-size: 12px;
  line-height: 1.55;
  color: hsl(0 0% 25%);
  white-space: pre-wrap;
  max-height: 280px;
  overflow-y: auto;
}
</style>
