# Inbox Outlook Reading Pane — Design

## Goal

Replace the chat-bubble rendering in the right pane of the admin inbox with
an Outlook-style accordion mail view that renders HTML mails natively
(images, clickable links, styled layout) and keeps the classic email thread
affordances users expect from Outlook/Gmail. The left conversation list and
the AI draft composer stay untouched.

## Context

The inbox currently renders each message in a thread as a chat bubble via
`InboxChatBubble.vue`. That component was built around three assumptions
that turned out to be wrong for a business mailbox:

1. Messages are short text. In reality Max reads HTML newsletters
   (WKOÖ.Insights with images and brand styling), Typeform inquiries with
   table layouts, Immowelt platform mails with ASCII-art dividers, and
   forwarded threads from colleagues.
2. The body_text column is the source of truth. In reality for most
   modern mail clients body_text is an afterthought (single unbroken line
   for Typeform) while the real content lives in body_html.
3. A conversation is a chat. In reality the user wants email semantics:
   subject line, headers, rendered HTML body, reply/forward actions,
   collapse older messages.

The chat bubble view has been accumulating compensating hacks — umlaut
repair dictionary, cleanEmailBody quoted-header stripping, forward
metadata annotation, HTML→text fallback. Each fix made the bubble
slightly less wrong, but the underlying shape was still "chat". Max's
verdict after a day of bug hunting: "vielleicht ist die chat ansicht
doch nicht so toll. kannst du das so nachbauen wie bei outlook?"

This design does the rebuild of the right pane only.

## Architecture

The current right-pane hierarchy is:

```
InboxTab.vue
 └─ InboxChatView.vue          (thread container, day dividers, scroll)
     └─ InboxChatBubble.vue    (per-message bubble — chat styling)
```

The new hierarchy keeps `InboxChatView` as the thread container but
swaps the leaf component from `InboxChatBubble` to two new components:

```
InboxTab.vue
 └─ InboxChatView.vue          (thread container, subject header, reply actions)
     └─ InboxMailMessage.vue   (per-message row — collapsed or expanded)
         └─ InboxMailBody.vue  (sanitised HTML body renderer)
```

The `InboxChatBubble.vue` file is removed once the new components are
live. Its useful helpers (cleanEmailBody, splitForwardedMessage variants,
repairUmlauts, htmlToText) migrate into `InboxMailBody.vue` and a shared
`inbox/mailText.js` util.

### InboxChatView (rewritten, keeps filename)

The container is trimmed down to:

- Subject line header pulled from the latest message, with metadata row
  (message count, participants, property ref, status badges for
  Nachfassen / Intern / info-cc).
- Scrollable message list rendering an `InboxMailMessage` per visible
  message. Messages are ordered oldest → newest (chronological, matches
  user's mental model for a thread history).
- Footer action bar with Reply / ReplyAll / Forward buttons. The Reply
  button hands off to the existing `InboxAiDraft` flow (emits
  `compose-reply` which `InboxTab` wires up to the current composer).
- The existing account-scoped message filtering, forward-sender dedup,
  and message flattening all stay put in `groupedMessages`.

### InboxMailMessage (new)

Renders a single message as either a collapsed row or an expanded card.

- **Collapsed state:** single-line row with avatar + sender name + short
  inline body preview + timestamp + expand chevron. Clicking anywhere on
  the row (except the sender name) toggles expansion.
- **Expanded state:** full header (avatar, sender name + email, To line,
  date), optional forward-from header strip (reuses existing
  `_forwardedFromName` detection), optional recipient header strip for
  intern mails (reuses existing `recipientLabel` logic), the rendered
  body (via `InboxMailBody`), attachments list.

Per Max's direct request: **the sender name is a clickable target**. It
calls the existing `inject('openContact', …)` function that is already
provided by `Dashboard.vue` and used elsewhere (e.g.
`InboxChatView.vue:507`). Hover and focus states on the name match the
existing Badge pattern.

**Avatar rendering — user profile image for outbound messages:** When
the message is outbound (sent from one of the current user's own email
accounts), the avatar displays the user's `users.profile_image` file
instead of the initials. Resolved via a new `inject('currentUserAvatar', …)`
in `Dashboard.vue` alongside the existing `openContact` provider, which
exposes `{ url: '/storage/<path>' | null, initials: 'MH' }` based on
`Auth::user()->profile_image`. `InboxMailMessage` reads the injection
once at component creation and renders:

- Outbound + `url` set → `<img src="url" alt="initials">` inside a round
  avatar container.
- Outbound + `url` null → initials avatar with the existing SR-Homes
  orange gradient (unchanged from today).
- Inbound (external or colleague) → initials avatar with the existing
  muted-blue gradient. External senders never get a photo because we
  don't track contact photos.

Max's `users.profile_image` is currently `NULL`, so today he'll see the
"MH" initials on his own outbound bubbles. The moment he uploads a
profile image via the existing profile settings UI (the same field that
powers the blog author photo and the website broker photo), the inbox
avatar switches automatically on next page load. No manual refresh of
the inbox component or backend data migration is required.

Default expansion rules:

- The newest message in the thread starts expanded.
- All other messages start collapsed.
- Expansion state is local to the message component (no global store).
- Multiple messages can be expanded simultaneously — the accordion is
  not mutually exclusive.

### InboxMailBody (new)

Sanitised HTML renderer with a text fallback.

Pipeline:

1. If `message.body_html` is non-empty → use it as the primary source.
2. Otherwise → `cleanEmailBody(message.body_text)` (the existing umlaut
   repair + reply-quote strip + forward header strip path), wrapped in
   a `<pre>`-like container with `white-space: pre-wrap`.
3. HTML is sanitised with DOMPurify using a permissive-but-safe config:
   - Allowed tags: all inline + block visual tags, tables, lists,
     headings, images, links, `blockquote`, `pre`, `code`.
   - Blocked tags: `script`, `iframe`, `object`, `embed`, `form`,
     `input`, `button`, `style`, `link`, `meta`, `base`.
   - Allowed attributes: `href`, `src`, `alt`, `title`, `class`,
     `style` (filtered), `width`, `height`, `colspan`, `rowspan`,
     `data-*`.
   - Blocked attributes: all `on*` event handlers, `srcdoc`, `form*`.
   - Link rewrite: every `<a href>` gets `target="_blank"` and
     `rel="noopener noreferrer"` appended.
   - Image loading: auto-loaded (SR-Homes is a private B2B mailbox — no
     mass-market tracking-pixel concern). If tracking becomes an issue
     later, a pref toggle can be added without touching this design.

The sanitised HTML is rendered via `v-html` inside a `.mail-body`
container that has scoped CSS resetting typography (font-family,
line-height, color) so sender inline styles don't break the app theme.

A content-security barrier: the body container uses `contain: content`
and its parent has `overflow-x: auto` so wide tables scroll instead of
stretching the layout.

### Shared helpers — inbox/mailText.js (new)

Extract these from `InboxChatBubble.vue`:

- `repairUmlauts(text)` — Windows-1252 damage repair dictionary
- `decodeHtmlEntities(text)`
- `cleanEmailBody(raw)` — umlaut repair + quoted-header strip + signature strip
- `htmlToText(html)` — structure-aware HTML → plain text for fallback cases
- `extractForwardMetadata(msg)` — the guts of the current
  `splitForwardedMessage` returning `{fromName, fromEmail, subject}` or null

`splitForwardedMessage` itself stays in `InboxChatView.vue` (it operates
on the full thread for sender dedup) but its body-extraction logic is
factored out as `extractForwardMetadata`.

### Data flow

```
InboxTab.vue
  ├── fetches conv_detail / email_context
  └── passes messages[] to InboxChatView
                                │
                                ▼
InboxChatView.vue
  ├── groupedMessages computes splits + account scope + forward annotations
  └── renders <InboxMailMessage> per message
                                │
                                ▼
InboxMailMessage.vue
  ├── local state: expanded (bool, defaults true for newest, false otherwise)
  ├── computes senderName, senderEmail, recipientLabel, forwardedFrom, …
  ├── click on sender name → inject('openContact')(senderName)
  └── renders body via <InboxMailBody>
                                │
                                ▼
InboxMailBody.vue
  ├── chooses html OR text source
  ├── sanitises html with DOMPurify
  └── renders via v-html (html) or <pre class="whitespace-pre-wrap"> (text)
```

### Reply / ReplyAll / Forward wiring

The new `InboxChatView` footer has three buttons. None of them open a
new composer — they all call into the existing `InboxAiDraft`
integration via events:

- **Reply** → `emit('reply', { toEmail: latestInboundFromEmail,
  subject: 'Re: …', quotedBody: latestInboundBody })`
- **ReplyAll** → same + `cc: otherRecipients`
- **Forward** → `emit('forward', { subject: 'WG: …', quotedBody: ... })`

`InboxTab.vue` listens for these and populates `expandedAiDraft` (the
existing drafting state) accordingly. The current auto-generation flow
for `offen`/`nachfassen` threads remains — clicking Reply just focuses
the existing AI draft pane.

## Key decisions (discussion-worthy)

### Why accordion, not linear

Max picked accordion directly during the visual companion exchange
("okay akkordeon, lets go"). The underlying rationale:

- Most threads Max reads are ≥ 3 messages long (customer inquiry → our
  reply → customer response → our followup). A linear all-expanded view
  would need 4× the screen real estate.
- Outlook's default is accordion. Muscle memory matches.
- The only risk — "I can't find info in a collapsed message" — is
  mitigated by showing a single-line preview on every collapsed row.

### Why DOMPurify over iframe sandbox

- iframe-per-message is expensive (each iframe runs its own document
  context). With 5–10 messages in a thread that's a lot of overhead.
- iframe height auto-sizing is janky without JavaScript communication,
  which defeats the security benefit.
- DOMPurify with a strict config gives us 95% of the security win at
  5% of the complexity.
- Our mailboxes receive mails from known business contacts, not
  adversarial attackers crafting exploits.

### Why keep InboxAiDraft as-is

- The composer has accumulated a lot of logic (account selection,
  property matching, file picking, KI tone tuning, Link-picker, magic
  draft generation). Rewriting it is a big orthogonal project.
- Visually integrating it into the new card is a later refinement once
  we see how the new reading pane feels.

## Out of scope

- Left conversation list (`InboxConversationList` / `InboxConversationItem`)
  is unchanged. No visual tweaks, no new filter logic.
- `InboxAiDraft` is unchanged. Any visual alignment with the new card is
  a follow-up project.
- Posteingang single-mail detail rendering — it uses the same
  `InboxChatView` so it gets the new look for free, but we don't add
  posteingang-specific features in this spec.
- Keyboard shortcuts (R for reply, F for forward, J/K for thread nav).
  Nice to have, not required.
- Image tracking-pixel blocking with an opt-in "Bilder laden" toggle.
  Add later if needed; for now images auto-load.
- Dark mode. The design uses the existing `--background` /
  `--foreground` tokens so dark mode falls out automatically, but no
  extra tuning pass.

## Success criteria

- Doris Hummer's WKOÖ newsletter renders as HTML with its image
  mastheads visible, article blocks laid out correctly, and every link
  in the body opens in a new tab when clicked.
- Schustereder's 2-message thread opens with the newest message
  expanded, Max's own earlier mail collapsed into a single row with a
  preview. Clicking the older row expands it.
- The Baldinger forward from Susanne shows the indigo
  `↪ Weiterleitung ursprünglich von: Baldinger Immobilien` header strip
  above the forwarded HTML content, correctly rendered with Umlauts and
  proper paragraph breaks.
- Clicking "Michael Schustereder" in the sender line calls
  `openContact('Michael Schustereder')` and routes to the contact
  settings, same behaviour as today's Badge click in `InboxChatView.vue`.
- Outbound messages show Max's real profile photo as the avatar once
  `users.profile_image` is uploaded via profile settings. Without an
  uploaded photo, the initials avatar is shown. External senders always
  get the initials avatar.
- Clicking Reply focuses the existing InboxAiDraft pane with the right
  prefilled recipient/subject.
- All existing badges (Nachfassen, Intern, info-cc, Auto-Reply) still
  render in the subject meta row.
- The Typeform "Eva Kaiser" inquiry renders from `body_html` with each
  form field on its own line (no more one-sentence wall of text).
- Immowelt platform mails render from HTML instead of their ASCII-art
  text/plain variant, with proper formatting and the deep-link to the
  Immowelt inquiry page clickable.

## Files touched

### New

- `resources/js/Components/Admin/inbox/InboxMailMessage.vue` — single message row
- `resources/js/Components/Admin/inbox/InboxMailBody.vue` — sanitised HTML body
- `resources/js/Components/Admin/inbox/mailText.js` — shared text helpers

### Modified

- `resources/js/Components/Admin/inbox/InboxChatView.vue` — swap bubble → message, add subject header + reply actions
- `resources/js/Pages/Admin/Dashboard.vue` — add `provide('currentUserAvatar', { url, initials })` alongside the existing `openContact` provider
- `package.json` — add `dompurify` dependency

### Removed

- `resources/js/Components/Admin/inbox/InboxChatBubble.vue` — replaced by the two new components

## Dependencies

- **DOMPurify** (≈ 20 kB gzipped) via `npm install dompurify` — industry
  standard HTML sanitiser, SSR-safe, no peer deps. Alternative considered:
  `sanitize-html` (heavier, node-oriented). DOMPurify wins on bundle size
  and browser ergonomics.

No backend changes. `PublicDocumentController`, `ConversationController`,
`ImapService`, `ConversationService` all stay as-is. The fetcher fix and
refetch tooling from recent commits mean body_html is populated for new
mails and for the 127 recovered legacy rows, so the new renderer has
data to work with out of the box.

## Risk / mitigation

- **Risk:** sanitised HTML still breaks the app's own layout (long
  tables, inline CSS with absolute positioning, `position: fixed`).
  **Mitigation:** wrap body in a container with `contain: content`,
  `overflow-x: auto`, and a scoped CSS reset.

- **Risk:** DOMPurify version drift introduces regressions.
  **Mitigation:** pin the version in package.json.

- **Risk:** users accidentally click tracking links inside HTML mails,
  alerting senders we're reading.
  **Mitigation:** accepted. B2B context, low adversarial exposure.

- **Risk:** old mails with no body_html fall back to text rendering and
  look worse than the new HTML ones, creating visual inconsistency.
  **Mitigation:** the fallback is the existing clean-text pipeline; it
  looks like the current chat bubble body minus the bubble shape. Not
  worse than today.
