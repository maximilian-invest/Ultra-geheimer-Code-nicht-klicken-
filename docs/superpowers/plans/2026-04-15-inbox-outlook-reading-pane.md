# Inbox Outlook Reading Pane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the chat-bubble rendering in the admin inbox right pane with an Outlook-style accordion mail view that renders HTML mails natively (images, clickable links, styled layout) while preserving every existing feature (forward metadata, intern badges, attachments, reply flow into the AI draft composer).

**Architecture:** Rebuild the leaf render layer only. Keep `InboxChatView.vue` as the thread container but swap its per-message child from `InboxChatBubble.vue` to two new components — `InboxMailMessage.vue` (accordion message row, collapsed or expanded) and `InboxMailBody.vue` (sanitised HTML renderer with text fallback). Extract text helpers from `InboxChatBubble.vue` into a shared `mailText.js` util. Add a `currentUserAvatar` provider in `Dashboard.vue` so outbound messages show Max's profile image when one is uploaded. Backend is untouched — body_html is already populated by the fetcher fix and the legacy refetch sweep.

**Tech Stack:** Vue 3 `<script setup>`, shadcn-vue components (Avatar, Badge, Button, Separator already present), DOMPurify for HTML sanitisation, Tailwind 3, lucide-vue-next for icons. No test framework is installed in this project — verification is manual via `npm run build` + visual smoke testing on the dev server against a fixed set of real conversations (Schustereder, Hummer, Baldinger, Typeform Kaiser, Immowelt).

**Related spec:** `docs/superpowers/specs/2026-04-15-inbox-outlook-reading-pane-design.md`

---

## File Structure

**New files:**

- `resources/js/Components/Admin/inbox/mailText.js` — text helpers: `repairUmlauts`, `decodeHtmlEntities`, `htmlToText`, `cleanEmailBody`, `extractForwardMetadata`. Pure functions, no Vue deps.
- `resources/js/Components/Admin/inbox/InboxMailBody.vue` — single-responsibility HTML renderer. Chooses `body_html` or cleaned `body_text`, sanitises via DOMPurify, rewrites links to open in new tab.
- `resources/js/Components/Admin/inbox/InboxMailMessage.vue` — per-message row, collapsed or expanded, with avatar, sender header (clickable name), forward strip, intern recipient strip, body via `InboxMailBody`, attachments, timestamp.

**Modified files:**

- `resources/js/Components/Admin/inbox/InboxChatView.vue` — drop inline split/clean helpers (now in mailText.js), add subject header with metadata row, render `InboxMailMessage` instead of `InboxChatBubble`, add reply actions footer emitting `reply` / `reply-all` / `forward`.
- `resources/js/Components/Admin/InboxTab.vue` — listen for the new reply events and prefill `expandedAiDraft` accordingly. Minor edits only.
- `resources/js/Pages/Admin/Dashboard.vue` — add `provide('currentUserAvatar', computed)` alongside the existing `openContact` provider.
- `package.json` / `package-lock.json` — add `dompurify` dependency.

**Removed files:**

- `resources/js/Components/Admin/inbox/InboxChatBubble.vue` — replaced by the two new components. Delete after every caller is migrated.

---

## Task 1: Install DOMPurify

**Files:**
- Modify: `/Users/max/srhomes/package.json`
- Modify: `/Users/max/srhomes/package-lock.json`

- [ ] **Step 1: Install DOMPurify via npm**

```bash
cd /Users/max/srhomes && npm install dompurify@^3.1.6
```

Expected: `package.json` gains `"dompurify": "^3.1.6"` under `dependencies`, `package-lock.json` is updated.

- [ ] **Step 2: Verify the install produced a working import**

```bash
cd /Users/max/srhomes && node -e 'import("dompurify").then(m => console.log(typeof m.default.sanitize))'
```

Expected: prints `function`. If it prints `undefined`, the install failed or the wrong major version landed — re-run `npm install dompurify@^3.1.6`.

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes && git add package.json package-lock.json && git commit -m "chore(deps): add dompurify for inbox HTML sanitisation"
```

---

## Task 2: Create mailText.js with all helpers migrated from InboxChatBubble

**Files:**
- Create: `/Users/max/srhomes/resources/js/Components/Admin/inbox/mailText.js`

- [ ] **Step 1: Create the file with all five helpers**

Write this exact content to `/Users/max/srhomes/resources/js/Components/Admin/inbox/mailText.js`:

```js
// Shared text helpers for the inbox reading pane. Pure functions, no
// Vue dependencies — safe to import from components or tests.
//
// Migrated from the old InboxChatBubble.vue. The five exports cover:
//   - repairUmlauts: best-effort recovery of '?' damage from legacy rows
//   - decodeHtmlEntities: common HTML entity → character
//   - htmlToText: structure-aware HTML → plain text
//   - cleanEmailBody: the full display pipeline (entities → umlaut repair
//     → quote strip → signature strip → run-on unmangle)
//   - extractForwardMetadata: parse a mail body for forwarded-from info

// ──────────────────────────────────────────────────────────────────────
// Umlaut repair dictionary
// ──────────────────────────────────────────────────────────────────────
// Used for legacy rows where the pre-fix IMAP fetcher stored raw
// Windows-1252 bytes and MySQL replaced umlauts with '?'. New rows and
// the 127 refetched rows don't need this, but older rows still do.
//
// IMPORTANT: patterns starting with \? drop the leading \b because \b
// doesn't anchor before non-word characters. Specificity of the rest
// of the pattern prevents false positives.
const UMLAUT_REPAIRS = [
  // — ß family
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
  // — ü family (no \b before \?)
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
  [/\bm\?glich/g, 'möglich'],
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
  // — apostrophe mangling
  [/\bgeht\?s\b/g, "geht's"],
  [/\bhat\?s\b/g, "hat's"],
  [/\bist\?s\b/g, "ist's"],
  [/\bgibt\?s\b/g, "gibt's"],
  // — start-of-sentence Über
  [/^\s*\?ber\s/m, 'Über '],
]

export function repairUmlauts(text) {
  if (!text || !text.includes('?')) return text
  let out = String(text)
  for (const [pattern, replacement] of UMLAUT_REPAIRS) {
    out = out.replace(pattern, replacement)
  }
  return out
}

// ──────────────────────────────────────────────────────────────────────
// HTML entity decode
// ──────────────────────────────────────────────────────────────────────
export function decodeHtmlEntities(s) {
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

// ──────────────────────────────────────────────────────────────────────
// HTML → structured plain text (used as fallback when body_html is
// absent). Converts block-level tags to newlines so table-layout mails
// don't collapse into one sentence.
// ──────────────────────────────────────────────────────────────────────
export function htmlToText(html) {
  if (!html) return ''
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n\n')
    .replace(/<\/div>/gi, '\n')
    .replace(/<\/tr>/gi, '\n')
    .replace(/<\/td>/gi, '  ')
    .replace(/<\/li>/gi, '\n')
    .replace(/<\/h[1-6]>/gi, '\n\n')
    .replace(/<\/(?:pre|blockquote)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&(?:apos|#39);/gi, "'")
    .replace(/&#(\d+);/g, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 10)) } catch (e) { return '' }
    })
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 16)) } catch (e) { return '' }
    })
    .replace(/\r/g, '')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

// ──────────────────────────────────────────────────────────────────────
// Run-on un-mangling
// ──────────────────────────────────────────────────────────────────────
function unmangleRunOns(text) {
  return String(text || '')
    .replace(/([,;])([A-ZÄÖÜ])/g, '$1 $2')
    .replace(/([.!?])(\d{3,})/g, '$1 $2')
    .replace(/(\d)([A-ZÄÖÜ][a-zäöüß])/g, '$1 $2')
}

// ──────────────────────────────────────────────────────────────────────
// Full clean pipeline for display
// ──────────────────────────────────────────────────────────────────────
export function cleanEmailBody(raw) {
  if (!raw) return ''
  const original = String(raw)
  let text = decodeHtmlEntities(original)
  text = repairUmlauts(text)

  // Strip quoted Outlook/Gmail forward header blocks
  text = text.replace(/^\s*(Von|From)\s*:.+\n(?:\s*(Gesendet|Date)\s*:.+\n)?(?:\s*(An|To)\s*:.+\n)?(?:\s*(Cc|CC)\s*:.*\n)?(?:\s*(Betreff|Subject)\s*:.+\n)?/gim, '')

  // Signature / DSGVO stripping — but stop at the forward separator
  // inserted by extractForwardMetadata so we don't eat the payload.
  const forwardSep = text.indexOf('— — —')
  if (forwardSep >= 0) {
    const before = text.slice(0, forwardSep)
    const after = text.slice(forwardSep)
    const cleanedBefore = before
      .replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
      .replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
    text = cleanedBefore + after
    text = text.replace(/^\s*— — —\s*/, '')
  } else {
    text = text.replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
    text = text.replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
  }

  // Reply-quote chains (English + German)
  text = text.replace(/\n\s*On\s+[A-Z0-9][^\n]{0,240}wrote\s*:[\s\S]*$/im, '')
  text = text.replace(/\n\s*Am \d{1,2}\.\d{1,2}\.\d{2,4}.*schrieb.*:[\s\S]*$/im, '')
  text = text.replace(/\n\s*Am\s+\w+[.,]?\s+\d{1,2}\.\s*\w+[.\s]+\d{2,4}[^\n]*schrieb[\s\S]*$/im, '')

  // "> " quoted lines at the end
  const lines = text.split('\n')
  let cutIdx = lines.length
  for (let i = lines.length - 1; i >= 0; i--) {
    if (lines[i].match(/^\s*>/) || lines[i].match(/^\s*\|/)) {
      cutIdx = i
    } else if (lines[i].trim() === '') {
      // skip blank
    } else {
      break
    }
  }
  text = lines.slice(0, cutIdx).join('\n')

  // Signature after --
  text = text.replace(/\n--\s*\n[\s\S]*$/m, '')
  text = text.replace(/(\n\s*){3,}/g, '\n\n')
  text = unmangleRunOns(text)
  text = text.trim()

  if (text.length < 10 && original.trim().length > 0) {
    return decodeHtmlEntities(original).trim()
  }
  return text
}

// ──────────────────────────────────────────────────────────────────────
// Forward metadata extraction
// ──────────────────────────────────────────────────────────────────────
// Returns { fromName, fromEmail, subject } when the body looks like a
// forward, otherwise null. Does NOT modify the message — the caller
// uses the metadata to render a header strip above the body.

const REPLY_ATTRIBUTION_RE = /(?:^|\n)\s*(?:>\s*)?(On|Am)\b[^\n]{0,240}\b(wrote|schrieb)\s*:/i
const BARE_ATTRIBUTION_RE = /\n\s*(?:>\s*)?[^\n<>]{1,120}\s(wrote|schrieb)\s*:\s*(?:\n|$)/i
const OWN_DOMAIN_RE = /@sr-homes\.at$/i

function normalizeForForwardSplit(text) {
  return String(text || '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/^\s*>\s?/gm, '')
    .replace(/\s+(Von|From|Gesendet|Date|An|To|Betreff|Subject)\s*:/gi, '\n$1:')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

export function extractForwardMetadata(msg) {
  const bodyText = String(msg?.body_text || '')
  const htmlText = htmlToText(msg?.body_html || '')
  const body = normalizeForForwardSplit(bodyText || htmlText)
  if (!body) return null
  if (REPLY_ATTRIBUTION_RE.test(body) || BARE_ATTRIBUTION_RE.test(body)) return null

  const markerRegex = /\n-{2,}\s*(Weitergeleitete Nachricht|Forwarded message|Original Message|Original-Nachricht|Urspruengliche Nachricht)\s*-{2,}\n/i
  const markerMatch = body.match(markerRegex)
  let markerIndex = markerMatch?.index
  let markerLength = markerMatch?.[0]?.length || 0

  if (typeof markerIndex !== 'number') {
    const headerFallback = body.match(/\n\s*(Von|From)\s*:.+\n\s*(Gesendet|Date)\s*:.+\n\s*(An|To)\s*:.+\n\s*(Betreff|Subject)\s*:.+/im)
    if (headerFallback && typeof headerFallback.index === 'number') {
      markerIndex = headerFallback.index
      markerLength = 1
    }
  }
  if (typeof markerIndex !== 'number') {
    const genericHeader = body.match(/\n\s*(Von|From)\s*:.+\n[\s\S]{0,600}?\n\s*(Betreff|Subject)\s*:.+/im)
    if (genericHeader && typeof genericHeader.index === 'number' && genericHeader.index > 20) {
      markerIndex = genericHeader.index
      markerLength = 1
    }
  }
  if (typeof markerIndex !== 'number') return null

  const forwardedBlock = body.slice(markerIndex + markerLength).trim()
  if (!forwardedBlock) return null

  let forwardedFrom = ''
  let forwardedFromEmail = ''
  let forwardedSubject = ''

  const headerSplit = forwardedBlock.search(/\n\s*\n/)
  if (headerSplit >= 0) {
    const headerPart = forwardedBlock.slice(0, headerSplit)
    const fromMatch = headerPart.match(/^\s*(Von|From)\s*:\s*(.+)$/im)
    const subjectMatch = headerPart.match(/^\s*(Betreff|Subject)\s*:\s*(.+)$/im)
    forwardedFrom = (fromMatch?.[2] || '').trim()
    forwardedSubject = (subjectMatch?.[2] || '').trim()
    const angle = forwardedFrom.match(/<([^>]+@[^>]+)>/)
    const bare = forwardedFrom.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    if (angle?.[1]) {
      forwardedFromEmail = angle[1].trim().toLowerCase()
      forwardedFrom = forwardedFrom.replace(/\s*<[^>]+>\s*/, '').trim()
    } else if (bare?.[1]) {
      forwardedFromEmail = bare[1].trim().toLowerCase()
      forwardedFrom = forwardedFrom.replace(bare[1], '').replace(/\s+/g, ' ').trim()
    }
  }

  if (!forwardedFromEmail) {
    const directEmailMatch = forwardedBlock.match(/(?:^|\n)\s*(?:E-?Mail|Email)\s*:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    const mailtoMatch = forwardedBlock.match(/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    const candidate = (directEmailMatch?.[1] || mailtoMatch?.[1] || '').trim().toLowerCase()
    if (candidate && !candidate.includes('noreply') && !candidate.includes('no-reply')) {
      forwardedFromEmail = candidate
      if (!forwardedFrom || /noreply|no-reply|immoji/i.test(forwardedFrom)) {
        forwardedFrom = candidate
      }
    }
  }

  // Don't attribute our own outbound as "forward" — legacy reply chains
  // in the body will have our own domain here.
  if (forwardedFromEmail && OWN_DOMAIN_RE.test(forwardedFromEmail)) return null

  return {
    fromName: forwardedFrom || forwardedFromEmail || null,
    fromEmail: forwardedFromEmail || null,
    subject: forwardedSubject || null,
  }
}
```

- [ ] **Step 2: Smoke-test the helpers in Node**

```bash
cd /Users/max/srhomes && node -e '
import("./resources/js/Components/Admin/inbox/mailText.js").then(m => {
  console.log("repairUmlauts:", m.repairUmlauts("Gr??en Stra?e f?r ?berdachte"));
  console.log("decodeHtmlEntities:", m.decodeHtmlEntities("A &amp; B &nbsp; C"));
  console.log("htmlToText:", m.htmlToText("<p>Line 1</p><p>Line 2</p>"));
  console.log("cleanEmailBody:", m.cleanEmailBody("Hallo\n\nMit freundlichen Grüßen\nMax"));
  console.log("extractForwardMetadata:", m.extractForwardMetadata({body_text: "Some wrapper\n\nVon: Alice <a@b.com>\nBetreff: Test\n\nBody here"}));
})
'
```

Expected output lines:
- `repairUmlauts: Grüßen Straße für überdachte`
- `decodeHtmlEntities: A & B   C`
- `htmlToText: Line 1\n\nLine 2`
- `cleanEmailBody: Hallo`
- `extractForwardMetadata: { fromName: 'Alice', fromEmail: 'a@b.com', subject: 'Test' }`

If any output is wrong, fix the function and re-run.

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/mailText.js && git commit -m "feat(inbox): extract text helpers to mailText.js"
```

---

## Task 3: Create InboxMailBody.vue

**Files:**
- Create: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxMailBody.vue`

- [ ] **Step 1: Write the component**

Write this exact content to `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxMailBody.vue`:

```vue
<script setup>
import { computed } from 'vue'
import DOMPurify from 'dompurify'
import { cleanEmailBody, htmlToText } from './mailText.js'

const props = defineProps({
  message: { type: Object, required: true },
})

// Decide whether to render sanitised HTML or cleaned text. Prefer HTML
// when it exists AND the plain-text version is a one-line wall (the
// Typeform / Outlook HTML-only-layout signature). Otherwise fall back
// to the cleaned plain text path.
const rawHtml = computed(() => String(props.message.body_html || ''))
const rawText = computed(() => String(props.message.full_body || props.message.body_text || props.message.body || ''))

const preferHtml = computed(() => {
  if (!rawHtml.value) return false
  const nl = (rawText.value.match(/\n/g) || []).length
  return rawText.value.length > 200 ? nl < 3 : rawHtml.value.length > 0
})

// Sanitise HTML with a permissive-but-safe allowlist. Scripts, iframes,
// forms, and event handlers are stripped. Links get target="_blank"
// rel="noopener noreferrer" appended via a post-sanitise hook.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank')
    node.setAttribute('rel', 'noopener noreferrer')
  }
})

const sanitisedHtml = computed(() => {
  if (!preferHtml.value) return ''
  return DOMPurify.sanitize(rawHtml.value, {
    ALLOWED_TAGS: [
      'a', 'p', 'div', 'span', 'br', 'hr',
      'img', 'figure', 'figcaption',
      'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
      'ul', 'ol', 'li',
      'blockquote', 'pre', 'code',
      'strong', 'em', 'b', 'i', 'u', 's', 'small', 'sup', 'sub',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ],
    ALLOWED_ATTR: [
      'href', 'src', 'alt', 'title', 'class', 'style',
      'width', 'height', 'colspan', 'rowspan', 'align', 'valign',
      'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color',
      'target', 'rel',
    ],
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base', 'style'],
    FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur', 'srcdoc', 'formaction'],
  })
})

const fallbackText = computed(() => {
  if (preferHtml.value) return ''
  return cleanEmailBody(rawText.value || htmlToText(rawHtml.value))
})
</script>

<template>
  <div class="mail-body">
    <div v-if="preferHtml" class="mail-body-html" v-html="sanitisedHtml"></div>
    <div v-else class="mail-body-text">{{ fallbackText }}</div>
  </div>
</template>

<style scoped>
.mail-body {
  font-size: 13.5px;
  line-height: 1.65;
  color: hsl(var(--foreground));
  contain: content;
}
.mail-body-html {
  overflow-x: auto;
}
.mail-body-html :deep(p) { margin: 0 0 12px; }
.mail-body-html :deep(p:last-child) { margin-bottom: 0; }
.mail-body-html :deep(a) {
  color: hsl(217 91% 45%);
  text-decoration: none;
  border-bottom: 1px solid hsl(217 91% 85%);
}
.mail-body-html :deep(a:hover) { border-bottom-color: hsl(217 91% 45%); }
.mail-body-html :deep(img) { max-width: 100%; height: auto; }
.mail-body-html :deep(table) {
  border-collapse: collapse;
  max-width: 100%;
}
.mail-body-html :deep(blockquote) {
  margin: 0 0 12px;
  padding: 0 0 0 12px;
  border-left: 3px solid hsl(0 0% 88%);
  color: hsl(0 0% 40%);
}
.mail-body-html :deep(pre),
.mail-body-html :deep(code) {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  background: hsl(0 0% 96%);
  border-radius: 4px;
  padding: 2px 4px;
}
.mail-body-html :deep(pre) { padding: 8px 10px; overflow-x: auto; }
.mail-body-text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  margin: 0;
}
</style>
```

- [ ] **Step 2: Verify the component compiles**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: build finishes with `✓ built in …s` and no red errors. If there's a Vue compiler error, the `<style scoped>` `:deep()` selectors may need adjustment — check the error message for the exact line.

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/InboxMailBody.vue && git commit -m "feat(inbox): InboxMailBody renders sanitised HTML with text fallback"
```

---

## Task 4: Create InboxMailMessage.vue

**Files:**
- Create: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxMailMessage.vue`

- [ ] **Step 1: Write the component**

Write this exact content to `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxMailMessage.vue`:

```vue
<script setup>
import { ref, computed, inject } from 'vue'
import { ChevronDown, ChevronUp, Paperclip, FolderDown } from 'lucide-vue-next'
import InboxMailBody from './InboxMailBody.vue'
import { cleanEmailBody } from './mailText.js'

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

// ── Direction + category heuristics (unchanged from the old bubble)
const isOutbound = computed(() => {
  const d = props.message.direction
  const c = props.message.category
  return d === 'outbound' || d === 'out' || ['email-out', 'expose', 'nachfassen'].includes(c)
})
const isAutoReply = computed(() => props.message.category === 'auto-reply' || props.message.is_auto_reply)
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
  if (displayName.value && displayName.value !== 'Unbekannt') {
    openContact(displayName.value)
  }
}
</script>

<template>
  <div class="sr-msg" :class="{ 'sr-msg--expanded': expanded, 'sr-msg--collapsed': !expanded }">
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
            :title="displayName + ' — Kontakt öffnen'"
            @click.stop="onNameClick"
          >{{ displayName }}</button>
          <span v-if="senderAddress" class="sr-sender-addr">&lt;{{ senderAddress }}&gt;</span>
        </div>
        <div v-if="expanded && recipientLabel" class="sr-to-line">An: {{ recipientLabel }}</div>
      </div>

      <span v-if="timeLabel" class="sr-time">{{ timeLabel }}</span>
      <component :is="expanded ? ChevronUp : ChevronDown" class="sr-chevron" />
    </div>

    <div v-if="!expanded && bodyPreview" class="sr-preview">{{ bodyPreview }}…</div>

    <div v-if="expanded" class="sr-expanded-content">
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
            class="sr-attachment-save"
            title="Zum Objekt speichern"
            @click.stop="onSaveAttachment(att, i)"
          >
            <FolderDown class="sr-attachment-save-icon" />
            Speichern
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
.sr-msg--collapsed { background: hsl(0 0% 99%); cursor: pointer; transition: background 120ms ease; }
.sr-msg--collapsed:hover { background: hsl(0 0% 97%); }

.sr-msg-row { display: flex; align-items: center; gap: 12px; padding: 12px 24px; }
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
  padding: 0 24px 12px 68px;
  font-size: 12px; color: hsl(0 0% 45%);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.sr-expanded-content { padding: 4px 24px 24px 68px; }

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
.sr-attachment { display: flex; align-items: center; gap: 8px; font-size: 12px; }
.sr-attachment-icon { width: 14px; height: 14px; color: hsl(0 0% 50%); flex-shrink: 0; }
.sr-attachment-name { flex: 1; color: hsl(0 0% 25%); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sr-attachment-save {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 10px; font-size: 10px; font-weight: 500;
  background: hsl(28 98% 96%); color: hsl(28 80% 38%);
  border: 1px solid hsl(28 90% 86%); border-radius: 6px;
  cursor: pointer;
}
.sr-attachment-save-icon { width: 12px; height: 12px; }
</style>
```

- [ ] **Step 2: Verify the component compiles**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: build finishes with `✓ built in …s` and no red errors.

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/InboxMailMessage.vue && git commit -m "feat(inbox): InboxMailMessage accordion row with avatar, forward strip, attachments"
```

---

## Task 5: Provide currentUserAvatar in Dashboard.vue

**Files:**
- Modify: `/Users/max/srhomes/resources/js/Pages/Admin/Dashboard.vue`

- [ ] **Step 1: Read the existing provide block**

```bash
cd /Users/max/srhomes && grep -n "provide(" resources/js/Pages/Admin/Dashboard.vue
```

Expected: lines around 98-105 show `provide("openContactSearch", …)` and `provide("openContact", …)`. Insert the new provide immediately after these two.

- [ ] **Step 2: Add the provider**

Use the Edit tool to insert the new provider block after the existing `provide("openContact", openContact);` line in `/Users/max/srhomes/resources/js/Pages/Admin/Dashboard.vue`. The new content to insert:

```js

// Current user's avatar (profile_image + initials) — consumed by the
// inbox reading pane for outbound messages. Falls back to initials when
// no profile image is uploaded. Provided as a plain object (not a
// ref/computed) because user profile doesn't change mid-session —
// simpler downstream consumption, no .value unwrap needed.
const _avatarUser = page.props.auth?.user
const _avatarName = String(_avatarUser?.name || "")
const _avatarParts = _avatarName.trim().split(/\s+/).filter(Boolean)
const _avatarInitials = (_avatarParts.length >= 2
    ? _avatarParts[0][0] + _avatarParts[_avatarParts.length - 1][0]
    : _avatarName.slice(0, 2) || "??").toUpperCase()
const currentUserAvatar = {
    url: _avatarUser?.profile_image ? "/storage/" + _avatarUser.profile_image : null,
    initials: _avatarInitials,
};
provide("currentUserAvatar", currentUserAvatar);
```

The block references `page.props.auth?.user` — verify that the file already imports and defines `page` via `usePage()`. If not, add `import { usePage } from "@inertiajs/vue3"` at the top and `const page = usePage()` near the other refs. The `computed` import is NOT required by this block — it's a plain object. `provide` is already imported for the existing `openContact` provider.

- [ ] **Step 3: Verify the page renders**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: build finishes with `✓ built in …s`. If there's an `auth is not defined` error, the shared Inertia props aren't wired — check `app/Http/Middleware/HandleInertiaRequests.php` for how `auth.user` is shared. It should already be there; if not, add `'auth' => ['user' => $request->user() ? ['name' => ..., 'profile_image' => ...] : null]`.

- [ ] **Step 4: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Pages/Admin/Dashboard.vue && git commit -m "feat(inbox): provide currentUserAvatar for outbound message avatars"
```

---

## Task 6: Rewrite InboxChatView.vue

**Files:**
- Modify: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxChatView.vue`

- [ ] **Step 1: Open the file and inspect its current exports**

```bash
cd /Users/max/srhomes && wc -l resources/js/Components/Admin/inbox/InboxChatView.vue && head -50 resources/js/Components/Admin/inbox/InboxChatView.vue
```

Expected: file exists, roughly 500-700 lines, imports include `InboxChatBubble` and exposes a `messages` prop + `saveAttachment` emit.

- [ ] **Step 2: Replace the message rendering block**

Use the Edit tool to replace the existing message-list render (the `<InboxChatBubble>` loop inside `<template>`) with the new subject header + accordion list + actions footer. The old block looks roughly like:

```vue
<template v-for="group in groupedMessages" :key="group.dateKey">
  <div class="day-divider">{{ group.label }}</div>
  <InboxChatBubble
    v-for="(msg, i) in group.messages"
    :key="msg.id || i"
    :message="msg"
    :sender-name="stakeholderName"
    @save-attachment="$emit('saveAttachment', $event)"
  />
</template>
```

Replace it with:

```vue
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
      :sender-name="stakeholderName"
      :is-initially-expanded="idx === flatMessages.length - 1"
      @save-attachment="$emit('saveAttachment', $event)"
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
```

- [ ] **Step 3: Replace the `<script setup>` helpers block**

In the same file, the existing `<script setup>` contains `htmlToText`, `normalizeForForwardSplit`, `splitForwardedMessage`, various regexes, and `groupedMessages`. Replace them by:

1. Removing the helper function definitions `htmlToText`, `normalizeForForwardSplit`, the `REPLY_ATTRIBUTION_RE` / `BARE_ATTRIBUTION_RE` / `OWN_DOMAIN_RE` constants, and `splitForwardedMessage` itself.
2. Replacing the `groupedMessages` computed with a flatter `flatMessages` computed that annotates forward metadata directly on each message using `extractForwardMetadata` from `mailText.js`.
3. Adding the new imports at the top of the script:

```js
import { ref, computed } from 'vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import InboxMailMessage from './InboxMailMessage.vue'
import { extractForwardMetadata } from './mailText.js'
```

4. Replacing the `groupedMessages` computed with:

```js
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
```

5. Adding header derivation computed properties:

```js
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
```

6. Adding reply/forward event handlers that emit to the parent:

```js
const emit = defineEmits(['saveAttachment', 'reply', 'reply-all', 'forward'])

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
```

7. Adding styles at the bottom of `<style scoped>`:

```css
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
.sr-thread-body { /* messages container */ }
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
```

- [ ] **Step 4: Build and resolve any compiler errors**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -20
```

Expected: `✓ built in …s`. If the build fails:
- `extractForwardMetadata is not defined` → the import at the top of `<script setup>` is missing
- `Cannot find module './InboxMailMessage.vue'` → the component file from Task 4 wasn't committed or has a typo
- `Button is not a valid component name` → the shadcn button path is `@/components/ui/button`, not `@/components/ui/Button`

- [ ] **Step 5: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/inbox/InboxChatView.vue && git commit -m "feat(inbox): rewrite InboxChatView as Outlook-style thread card"
```

---

## Task 7: Wire reply/forward events from InboxChatView to InboxAiDraft

**Files:**
- Modify: `/Users/max/srhomes/resources/js/Components/Admin/InboxTab.vue`

- [ ] **Step 1: Find the existing InboxChatView render in InboxTab**

```bash
cd /Users/max/srhomes && grep -n "<InboxChatView" resources/js/Components/Admin/InboxTab.vue
```

Expected: a single match around line 2575-2585 showing the `<InboxChatView>` element with its props.

- [ ] **Step 2: Add reply event listeners**

Use the Edit tool to add three new event listeners on the `<InboxChatView>` element alongside the existing `@save-attachment`:

```vue
@reply="onComposeReply($event)"
@reply-all="onComposeReply({ ...$event, replyAll: true })"
@forward="onComposeForward($event)"
```

- [ ] **Step 3: Add the handler functions in the `<script setup>` block**

Add these two functions after `markHandled` (around line 1240):

```js
function onComposeReply(payload) {
  // Focus the existing AI draft pane and prefill recipient/subject.
  if (!expandedAiDraft.value) {
    expandedAiDraft.value = {
      body: expandedAiDraft.value?.body || '',
      subject: payload.subject || '',
      to: payload.toEmail || '',
    };
  } else {
    expandedAiDraft.value = {
      ...expandedAiDraft.value,
      subject: payload.subject || expandedAiDraft.value.subject,
      to: payload.toEmail || expandedAiDraft.value.to,
    };
  }
  showEmailFields.value = true;
}

function onComposeForward(payload) {
  expandedAiDraft.value = {
    body: '',
    subject: payload.subject || '',
    to: '',
  };
  showEmailFields.value = true;
}
```

- [ ] **Step 4: Verify the build passes**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: `✓ built in …s`.

- [ ] **Step 5: Commit**

```bash
cd /Users/max/srhomes && git add resources/js/Components/Admin/InboxTab.vue && git commit -m "feat(inbox): wire Reply/ReplyAll/Forward actions into AI draft pane"
```

---

## Task 8: Delete InboxChatBubble.vue

**Files:**
- Remove: `/Users/max/srhomes/resources/js/Components/Admin/inbox/InboxChatBubble.vue`

- [ ] **Step 1: Verify there are no remaining imports**

```bash
cd /Users/max/srhomes && grep -rn "InboxChatBubble" resources/js/
```

Expected: no output. If anything matches, fix the importer first — the plan stops here until the grep is clean.

- [ ] **Step 2: Remove the file**

```bash
cd /Users/max/srhomes && git rm resources/js/Components/Admin/inbox/InboxChatBubble.vue
```

Expected: `rm 'resources/js/Components/Admin/inbox/InboxChatBubble.vue'`.

- [ ] **Step 3: Verify build still passes**

```bash
cd /Users/max/srhomes && npm run build 2>&1 | tail -6
```

Expected: `✓ built in …s`.

- [ ] **Step 4: Commit**

```bash
cd /Users/max/srhomes && git commit -m "chore(inbox): remove InboxChatBubble — replaced by InboxMailMessage"
```

---

## Task 9: Smoke-test against real conversations

**Files:** none modified. Manual visual verification only.

- [ ] **Step 1: Start the dev server**

```bash
cd /Users/max/srhomes && npm run dev &
sleep 3 && lsof -i :5173 | head
```

Expected: Vite dev server running on port 5173.

- [ ] **Step 2: Open each target conversation and verify**

Open the app at `http://localhost:<laravel-port>/admin/inbox` in a browser (logged in as Max). For each of the following conversations, click to open it in the right pane and verify the listed criteria:

| Conversation | Criteria |
|---|---|
| **Schustereder (THE 37)** | 2 messages. Newest from Schustereder expanded. Older from Max collapsed with preview. Click on "Michael Schustereder" opens contact settings. HTML or text body renders with proper umlauts ("Grüßen" not "Gr??en"). |
| **Doris Hummer (WKOÖ.Insights)** | Single message. Body renders as HTML with images and branded layout, links clickable. Sender name shows "Doris Hummer", not "Hannes Buchinger". |
| **Baldinger forward from Susanne (conv 645)** | Single bubble (Susanne's forward — account-scoped to Max). Indigo forward strip at top reads "Weiterleitung ursprünglich von: Baldinger Immobilien". Body shows Baldinger's actual Penthouse inquiry, not Susanne's signature. Umlauts correct. |
| **Typeform "Eva Kaiser"** | HTML rendering with each form field on its own line (Vorname, Nachname, Phone, Email, etc.). No one-line wall of text. |
| **Immowelt Kontaktanfrage** | HTML rendering with formatted customer details, no ASCII-art dividers (`+-+`, `****`). |
| **Any outbound message from Max** | Avatar shows "MH" initials (his profile_image is NULL). No broken image icon. |

- [ ] **Step 3: Stop the dev server**

```bash
pkill -f "vite.*--" || true
```

- [ ] **Step 4: If any criterion failed, commit a fix before proceeding**

Common fixes:
- HTML not rendering → check DOMPurify allow-list, may need to add a tag (e.g. `font`, `center`)
- Umlauts broken → `repairUmlauts` dictionary missing a pattern, add it and rebuild
- Sender name not clickable → `provide('openContact')` in Dashboard.vue is missing, add it
- Reply button not wired → Task 7 steps 2-3 incomplete

Each fix is its own commit with a clear message.

---

## Task 10: Deploy to VPS

**Files:** none modified. Production deployment only.

- [ ] **Step 1: Push to origin**

```bash
cd /Users/max/srhomes && git push origin main 2>&1 | tail -3
```

Expected: "main -> main" in the push summary.

- [ ] **Step 2: Pull + build + clear caches on the VPS**

```bash
ssh srhomes-vps 'cd /var/www/srhomes && git fetch origin main && git merge --ff-only origin/main && npm install && npm run build 2>&1 | tail -6 && chown -R www-data:www-data bootstrap/cache storage public/build node_modules && sudo -u www-data env HOME=/tmp php artisan view:clear && sudo -u www-data env HOME=/tmp php artisan config:clear && sudo -u www-data env HOME=/tmp php artisan cache:clear'
```

Expected: fast-forward merge, `npm install` installs DOMPurify, `npm run build` reports `✓ built in …s`, and all three artisan clears return `INFO  … cleared successfully.`

- [ ] **Step 3: Verify on production**

Open `https://sr-homes.at/admin/inbox` in a logged-in browser session and repeat the Task 9 smoke tests against the live mailbox. The same six conversations should render correctly.

- [ ] **Step 4: No explicit commit**

Deployment doesn't create commits. The plan ends here.

---

## Execution notes

- **Subagent isolation:** every task lists the exact files to create/modify. A subagent reading this plan cold should not need to grep the codebase to understand what's where — except for Task 6 step 1 which intentionally inspects the file. Task 8 is defensive (verify no callers remain).
- **Rollback:** each task's commit is atomic and can be reverted independently if smoke tests reveal a regression. The old `InboxChatBubble.vue` only disappears in Task 8, so reverting Task 6 restores the chat bubble rendering.
- **No database migration:** the plan does not touch PHP, Laravel, or MySQL. `body_html` is already populated for recent and refetched-legacy rows. If a legacy row hit during testing has NULL body_html, `InboxMailBody` falls back to the text path automatically.
- **Dependency pin:** DOMPurify is pinned to the `^3.1.6` major via the install command. No package.json surgery required — npm will record the exact resolved version in `package-lock.json`.
