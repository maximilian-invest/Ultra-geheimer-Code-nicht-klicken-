# Inbox Compose & Reply Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the dual compose surface (persistent AI-draft pane + new footer reply buttons) with an Outlook-style mode-switched reply flow. Clicking "Antworten" or "Mit KI-Entwurf antworten" in the thread card footer switches the reading pane into a compose view; the thread accordion is hidden and a new `InboxComposePane` takes its place, with a collapsed reference strip to the original message below.

**Architecture:** `InboxChatView.vue` gets a local `composeMode` ref (`'thread' | 'compose'`). When mode is `compose`, it renders a new `InboxComposePane.vue` child (with AI draft state, link picker, send bar) instead of the accordion messages. The existing AI draft state refs (`expandedAiDraft`, `sendAccountId`, `expandedSelectedFiles`, etc.) stay in `InboxTab.vue` as source of truth; the compose pane reaches them via `inject()` instead of prop/event plumbing. Send, improve, regenerate all route to the existing handlers in `InboxTab` — no rewrite of the send pipeline. `InboxAiDraft.vue` is deleted once the new pane is live.

**Tech Stack:** Vue 3 `<script setup>`, Tailwind 3, shadcn-vue (Button, Input, Textarea), lucide-vue-next, existing `LinkPickerPopover.vue` component, existing `conv_reply` / `conv_regenerate_draft` / `improve_text` admin API actions. No new dependencies.

**Related spec:** `docs/superpowers/specs/2026-04-15-inbox-compose-reply-flow-design.md`

---

## File Structure

**New files:**

- `resources/js/Components/Admin/inbox/InboxComposePane.vue` — the Outlook-style compose view. Orange header strip, To/Subject fields, body textarea with AI draft support, send action bar with link picker, AI regenerate/improve buttons. ~260 lines.

**Modified files:**

- `resources/js/Components/Admin/inbox/InboxChatView.vue` — add `composeMode` + `composeContext` refs, swap the three footer buttons for the new pair (Antworten + Mit KI-Entwurf) plus secondary ghost buttons (Allen antworten, Weiterleiten, Erledigt), conditionally render either the accordion or `InboxComposePane` + reference strip.
- `resources/js/Components/Admin/InboxTab.vue` — delete the `#ai-draft` slot content, delete `onComposeReply` / `onComposeForward` handlers (now handled inside InboxChatView's local mode state), add `provide('inboxCompose', { draft, sendAccountId, selectedFiles, sendAccounts, regenerate, improve, send, toggleFile })` so InboxComposePane can reach the shared state without prop-chaining.

**Removed files:**

- `resources/js/Components/Admin/inbox/InboxAiDraft.vue` — every feature migrates to `InboxComposePane`. ~400 lines removed.

---

## Task 1: Create InboxComposePane.vue

**Files:**
- Create: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxComposePane.vue`

- [ ] **Step 1: Write the component**

Write this EXACT content to `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxComposePane.vue`:

```vue
<script setup>
import { ref, computed, inject, onMounted, watch } from 'vue'
import { Send, RefreshCw, Sparkles, Paperclip, Wand2, X, Loader2, Link2 } from 'lucide-vue-next'
import LinkPickerPopover from './LinkPickerPopover.vue'

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

const draft = inboxCompose.draft
const sendAccountId = inboxCompose.sendAccountId
const sendAccounts = inboxCompose.sendAccounts
const loading = inboxCompose.loading

// ── Local UI state
const linkPickerOpen = ref(false)
const referenceExpanded = ref(false)

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
  if (props.withDraft && !(draft.value.body || '').trim()) {
    inboxCompose.regenerate()
  }
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

const bodyIsEmpty = computed(() => !(draft.value?.body || '').trim())
const showDraftBadge = computed(() => !bodyIsEmpty.value && props.withDraft)

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
    <!-- Orange header strip -->
    <header class="sr-compose-header">
      <div class="sr-compose-title">
        <Sparkles v-if="withDraft" class="w-4 h-4" />
        <Send v-else class="w-4 h-4" />
        <span>{{ kindLabel }} an {{ recipientName }}</span>
      </div>
      <button type="button" class="sr-compose-cancel" @click="onCancel">
        <X class="w-3.5 h-3.5" />
        <span>Abbrechen</span>
      </button>
    </header>

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
      <div v-if="showDraftBadge" class="sr-draft-badge">
        <Sparkles class="w-3 h-3" />
        KI-Entwurf
      </div>
      <div v-if="loading" class="sr-body-loading">
        <Loader2 class="w-4 h-4 animate-spin" />
        <span>KI-Entwurf wird generiert…</span>
      </div>
      <textarea
        v-else
        :value="draft?.body || ''"
        @input="updateDraftField('body', $event.target.value)"
        placeholder="Deine Antwort hier eintippen…"
        class="sr-body-textarea"
      ></textarea>
    </div>

    <!-- Send action bar -->
    <div class="sr-compose-actions">
      <button type="button" class="sr-btn sr-btn-primary" :disabled="bodyIsEmpty" @click="onSend">
        <Send class="w-3.5 h-3.5" />
        Senden
      </button>

      <button type="button" class="sr-btn sr-btn-ai" :disabled="loading" @click="onRegenerate">
        <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />
        {{ loading ? 'Generiere…' : 'Neu generieren' }}
      </button>

      <button type="button" class="sr-btn" :disabled="bodyIsEmpty || loading" @click="onImprove">
        <Wand2 class="w-3.5 h-3.5" />
        Verbessern
      </button>

      <div class="sr-compose-spacer"></div>

      <div class="sr-link-picker-wrapper">
        <LinkPickerPopover
          v-if="linkPickerOpen && propertyId"
          :property-id="Number(propertyId)"
          @close="linkPickerOpen = false"
          @pick="onLinkPicked"
        />
        <button
          type="button"
          class="sr-btn sr-btn-ghost"
          :disabled="!propertyId"
          :title="propertyId ? 'Docs-Link anfügen' : 'Kein Objekt in der Konversation'"
          @click="linkPickerOpen = !linkPickerOpen"
        >
          <Link2 class="w-3.5 h-3.5" />
          Link
        </button>
      </div>
    </div>

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
    </div>
  </div>
</template>

<style scoped>
.sr-compose {
  background: hsl(0 0% 100%);
  border-top: 1px solid hsl(0 0% 93%);
}

.sr-compose-header {
  padding: 14px 24px;
  background: linear-gradient(135deg, hsl(28 98% 96%), hsl(18 90% 97%));
  border-bottom: 1px solid hsl(28 80% 90%);
  display: flex;
  align-items: center;
  gap: 12px;
}
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
  color: hsl(0 0% 15%);
}
.sr-field-readonly {
  flex: 1;
  font-size: 13px;
  color: hsl(0 0% 35%);
}

.sr-compose-body {
  padding: 16px 24px 12px;
  min-height: 220px;
  position: relative;
}
.sr-draft-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  margin-bottom: 10px;
  background: linear-gradient(135deg, hsl(28 98% 54%), hsl(18 88% 48%));
  color: hsl(0 0% 100%);
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.01em;
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
  min-height: 200px;
  border: none;
  outline: none;
  background: transparent;
  font-family: inherit;
  font-size: 13.5px;
  line-height: 1.65;
  color: hsl(0 0% 15%);
  resize: vertical;
}
.sr-body-textarea::placeholder {
  color: hsl(0 0% 65%);
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
```

- [ ] **Step 2: Verify the component compiles**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: `✓ built in …s`. The file is not imported anywhere yet, so tree-shaking won't include it in the bundle — the build just validates syntax and template compilation.

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/InboxComposePane.vue && git commit -m "feat(inbox): InboxComposePane — Outlook-style reply compose view"
```

---

## Task 2: Rewire InboxTab.vue to provide compose state via inject

**Files:**
- Modify: `/Users/max/srhomes/resources/js/Components/Admin/InboxTab.vue`

- [ ] **Step 1: Add the provide block for inbox compose state**

Find the other `provide(...)` calls in this file (there are several) and add a new one that bundles the shared compose state into a single object. The new provide goes alongside the existing inbox-scope provides (search for `provide("inboxToast"` or `provide("inboxBgGradient"` to find the block).

Using the Edit tool, add this block immediately after the other inbox-scope `provide()` calls:

```js
// Shared compose state for InboxComposePane. Keeps sendDraft() and
// friends working unchanged — the new pane just reaches in via inject.
provide('inboxCompose', {
    draft: expandedAiDraft,
    sendAccountId,
    selectedFiles: expandedSelectedFiles,
    sendAccounts,
    loading: expandedAiLoading,
    regenerate: regenerateAiDraft,
    improve: improveWithAi,
    send: sendDraft,
    toggleFile: toggleFileSelection,
});
```

Before adding, verify these names exist in `InboxTab.vue`:

```bash
cd /Users/max/srhomes && grep -n "const expandedAiDraft\|const sendAccountId\|const expandedSelectedFiles\|const sendAccounts\|const expandedAiLoading\|function regenerateAiDraft\|function improveWithAi\|function sendDraft\|function toggleFileSelection" resources/js/Components/Admin/InboxTab.vue
```

Expected: all 9 symbols appear. If any is missing, stop and escalate NEEDS_CONTEXT — the refactor below depends on these existing.

- [ ] **Step 2: Delete the old compose handlers**

Remove the `onComposeReply` and `onComposeForward` functions from `<script setup>` (they were added in the reading-pane rebuild but are obsolete now — compose mode is owned by InboxChatView locally). Grep for their definitions:

```bash
cd /Users/max/srhomes && grep -n "function onComposeReply\|function onComposeForward" resources/js/Components/Admin/InboxTab.vue
```

Delete both function bodies from the script block.

Also delete the corresponding event listeners on the `<InboxChatView>` element in the template. Grep for them:

```bash
cd /Users/max/srhomes && grep -n "@reply\|@reply-all\|@forward" resources/js/Components/Admin/InboxTab.vue
```

Delete those three `@reply` / `@reply-all` / `@forward` bindings — the events still exist but they are now handled inside InboxChatView and don't need to bubble up.

- [ ] **Step 3: Delete the InboxAiDraft slot content**

Find the `<template #ai-draft>` block in the template (around the `<InboxChatView>` element). It currently renders `<InboxAiDraft>` with a large prop list. Delete the entire `<template #ai-draft>...</template>` block.

```bash
cd /Users/max/srhomes && grep -n "#ai-draft\|<InboxAiDraft" resources/js/Components/Admin/InboxTab.vue
```

Expected: both the slot definition and the InboxAiDraft usage are in the same `<template #ai-draft>` block. Remove the entire block.

Also remove the now-unused `import InboxAiDraft from "./inbox/InboxAiDraft.vue"` line at the top of the script block.

- [ ] **Step 4: Build and verify**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: `✓ built in …s`. If the build fails:
- `InboxAiDraft is not defined` — leftover reference somewhere, grep again and clean it
- `onComposeReply is not defined` — leftover event listener, remove it

- [ ] **Step 5: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/InboxTab.vue && git commit -m "refactor(inbox): provide compose state via inject, drop InboxAiDraft slot"
```

---

## Task 3: Add compose mode switch to InboxChatView.vue

**Files:**
- Modify: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxChatView.vue`

- [ ] **Step 1: Add new imports at the top of `<script setup>`**

Use the Edit tool to update the imports block. The file currently has (approximately):

```js
import { computed, inject, ref, watch } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { X, Loader2, Clock, ChevronLeft, ChevronDown, ChevronUp } from 'lucide-vue-next'
import InboxMatchCard from './InboxMatchCard.vue'
import InboxMailMessage from './InboxMailMessage.vue'
import { extractForwardMetadata } from './mailText.js'
```

Add one new import line:

```js
import InboxComposePane from './InboxComposePane.vue'
```

Also ensure `Sparkles` and `CheckCircle` are in the lucide import (for the new Mit-KI button and the Erledigt ghost button):

```js
import { X, Loader2, Clock, ChevronLeft, ChevronDown, ChevronUp, Sparkles, CheckCircle } from 'lucide-vue-next'
```

- [ ] **Step 2: Add compose mode state and handlers**

Add this block to `<script setup>` after the existing emit declaration (the `const emit = defineEmits([...])` line):

```js
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
    ? flatMessages.value[flatMessages.value.length - 1]
    : latestInbound()
  if (!m) return
  composeContext.value = {
    kind,
    withDraft,
    prefill: {
      to: kind === 'forward' ? '' : (m.from_email || ''),
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
  if (inboxComposeInject?.draft) {
    const current = inboxComposeInject.draft.value || {}
    inboxComposeInject.draft.value = {
      body: withDraft ? (current.body || '') : '',
      subject: composeContext.value.prefill.subject,
      to: composeContext.value.prefill.to,
    }
  }
}

function exitCompose() {
  composeMode.value = 'thread'
  composeContext.value = null
}
```

- [ ] **Step 3: Replace the thread actions footer with the new button set**

Find the existing `<footer class="sr-thread-actions">` block in the template and replace it with:

```vue
<footer class="sr-thread-actions" v-if="composeMode === 'thread'">
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
  <Button variant="ghost" size="sm" @click="enterCompose('forward', false)">
    Weiterleiten
  </Button>
  <Button variant="ghost" size="sm" class="sr-done-btn" @click="$emit('markHandled')">
    <CheckCircle class="sr-action-icon" />
    Erledigt
  </Button>
</footer>
```

- [ ] **Step 4: Add conditional rendering for compose pane**

Find the thread body block in the template (the `<div class="sr-thread-body">` with the `<InboxMailMessage>` v-for). Wrap it and the existing subject header so the compose pane can replace the thread body when `composeMode === 'compose'`. The template structure becomes:

```vue
<div class="sr-thread-card">
  <header v-if="subjectLine" class="sr-subject-header">
    <!-- unchanged subject header content -->
  </header>

  <!-- Thread mode: show accordion messages -->
  <div v-if="composeMode === 'thread'" class="sr-thread-body">
    <InboxMailMessage
      v-for="(msg, idx) in flatMessages"
      :key="msg.id || ('idx-' + idx)"
      :message="msg"
      :sender-name="item.from_name || item.stakeholder || ''"
      :is-initially-expanded="idx === flatMessages.length - 1"
      @save-attachment="emit('saveAttachment', $event)"
    />
  </div>

  <!-- Compose mode: show the compose pane with reference below -->
  <InboxComposePane
    v-else-if="composeContext"
    :kind="composeContext.kind"
    :with-draft="composeContext.withDraft"
    :prefill="composeContext.prefill"
    :reference-message="composeContext.referenceMessage"
    :property-id="item?.property_id || null"
    @cancel="exitCompose"
  />

  <!-- Thread footer (only in thread mode) -->
  <footer class="sr-thread-actions" v-if="composeMode === 'thread'">
    <!-- button block from Step 3 -->
  </footer>
</div>
```

- [ ] **Step 5: Add styles for the new AI button and the markHandled button**

Append these rules to the existing `<style scoped>` block:

```css
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
```

- [ ] **Step 6: Listen for `markHandled` emit in InboxTab**

This is a reminder step — the `<Button @click="$emit('markHandled')">` in Step 3 emits a new event up. Verify `InboxTab.vue` listens for it on its `<InboxChatView>` element:

```bash
cd /Users/max/srhomes && grep -n "<InboxChatView\|@mark-handled" resources/js/Components/Admin/InboxTab.vue
```

If `@mark-handled="markHandled(...)"` is already present (from the old InboxAiDraft wiring), good. If not, add it next to the other event bindings on `<InboxChatView>`. The existing `markHandled(stakeholder, propertyId)` function in InboxTab.vue expects 2 args — call it with `selectedItem.value?.from_name || selectedItem.value?.stakeholder` and `selectedItem.value?.property_id`.

Also declare the new emit on InboxChatView so Vue doesn't warn. Update the `defineEmits` call:

```js
const emit = defineEmits(['close', 'saveAttachment', 'matchDraft', 'matchDismiss', 'markHandled'])
```

(drop the obsolete `reply`, `reply-all`, `forward` — compose mode handles them internally now.)

- [ ] **Step 7: Build and fix any errors**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -15
```

Expected: `✓ built in …s`. If the build fails:
- `Cannot find name 'InboxComposePane'` — Task 1's file is missing, escalate BLOCKED
- `latestInbound is not defined` — the existing helper should still be in the script block from the earlier rewrite, grep for it
- `Sparkles is not exported from 'lucide-vue-next'` — check the icon name spelling
- `inboxComposeInject is not a function` — re-read the step 2 code carefully; it's a `const` not a function

- [ ] **Step 8: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/InboxChatView.vue && git commit -m "feat(inbox): compose mode switch with InboxComposePane integration"
```

---

## Task 4: Delete InboxAiDraft.vue

**Files:**
- Remove: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxAiDraft.vue`

- [ ] **Step 1: Verify there are no remaining imports**

```bash
cd /Users/max/srhomes && grep -rn "InboxAiDraft" resources/js/
```

Expected: no output. If anything matches, fix the importer first — the plan stops here until the grep is clean. (The only legitimate remaining reference would be a comment in `mailText.js` or a historical note, which is fine.)

- [ ] **Step 2: Remove the file**

```bash
cd /Users/max/srhomes && git rm resources/js/Components/Admin/inbox/InboxAiDraft.vue
```

Expected: `rm 'resources/js/Components/Admin/inbox/InboxAiDraft.vue'`.

- [ ] **Step 3: Verify build still passes**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: `✓ built in …s`.

- [ ] **Step 4: Commit**

```bash
cd /Users/max/srhomes && git commit -m "chore(inbox): remove InboxAiDraft — features migrated to InboxComposePane"
```

---

## Task 5: Deploy to VPS + smoke test

**Files:** none modified. Production deployment only.

- [ ] **Step 1: Push to origin**

```bash
cd /Users/max/srhomes && git push origin main 2>&1 | tail -3
```

Expected: "main -> main" in the push summary.

- [ ] **Step 2: Pull + build + clear caches on the VPS**

```bash
ssh srhomes-vps 'cd /var/www/srhomes && git fetch origin main && git merge --ff-only origin/main && npm run build 2>&1 | tail -6 && chown -R www-data:www-data bootstrap/cache storage public/build && sudo -u www-data env HOME=/tmp php artisan view:clear && sudo -u www-data env HOME=/tmp php artisan config:clear && sudo -u www-data env HOME=/tmp php artisan cache:clear'
```

Expected: fast-forward merge, `npm run build` reports `✓ built in …s`, and all three artisan clears return `INFO  … cleared successfully.`

- [ ] **Step 3: Verify on production**

Open `https://sr-homes.at/admin/inbox` in a logged-in browser session (hard reload with Ctrl+Shift+R to avoid cache issues). Smoke-test checklist:

| Action | Expected result |
|---|---|
| Open a conversation (e.g. Riccardo Leitner) | Thread card renders as before, subject header + accordion + footer with 5 buttons (Antworten, Mit KI-Entwurf antworten, Allen antworten, Weiterleiten, Erledigt) |
| Click **Antworten** | Thread body disappears, compose pane appears. Orange header strip "Antworten an Riccardo Leitner", Abbrechen button right. An/Betreff pre-filled. Body empty. Reference strip at bottom shows "Auf diese Nachricht von Riccardo Leitner · …". |
| Click **Abbrechen** | Compose pane disappears, thread body is back with the accordion |
| Click **Mit KI-Entwurf antworten** | Same compose view, but body shows "KI-Entwurf wird generiert…" briefly, then fills with the AI draft. Orange "KI-Entwurf" badge above the body. |
| Click **Neu generieren** inside compose | Body is replaced with a newly-generated AI draft |
| Click **Verbessern** inside compose | Body is sent to the improve endpoint; response replaces the body |
| Click **Link** button | LinkPickerPopover opens showing property docs links. Picking one appends its URL to the body as "Unterlagen: https://…" |
| Click **Senden** with a valid body | Compose pane closes, thread pane closes entirely (returns to conversation list), the existing send toast appears |
| Click **Erledigt** in thread footer | Conversation marked done via existing handler, same as before |

- [ ] **Step 4: If any check fails, log the specific failure and stop**

Common fixes:
- Compose pane doesn't render → `composeMode` not switching; check step 4 conditional in template
- Link picker doesn't open → `propertyId` prop is null, verify `item.property_id` is set
- Send does nothing → `inject('inboxCompose')` returned the default; check Task 2 provide
- Erledigt doesn't work → `@mark-handled` binding missing on InboxChatView in InboxTab

Each fix is its own commit with a clear message.

---

## Execution notes

- **Subagent isolation:** Tasks 1 and 4 are trivial (single file each). Task 2 touches InboxTab.vue in three specific places (provide block, delete handlers, delete slot). Task 3 is the most complex — touches InboxChatView.vue in several places but each edit is bounded.
- **Rollback:** each task commits atomically. If the smoke test reveals a regression, `git revert` on the offending commit is safe.
- **No database migration:** pure frontend change. All backend endpoints (`conv_reply`, `conv_regenerate_draft`, `improve_text`, send_email) are unchanged.
- **No new dependencies:** everything used here is already installed.
