# Inbox Compose & Reply Flow — Design

## Goal

Replace the current dual-path compose experience (a persistent "KI draft" pane below the thread plus new reply buttons in the thread footer) with a single Outlook-style reply flow: the user clicks "Antworten" or "Mit KI-Entwurf antworten" in the thread card footer, and the thread view switches to a full compose pane. The original message the user is replying to remains visible as a collapsed reference below the composer (scroll to read).

## Context

After the Task 6 rewrite the right pane currently has two mutually unaware compose surfaces:

- **`InboxAiDraft`** — the existing AI draft widget that renders below the thread card as a slot (`#ai-draft`) inside `InboxChatView`. It has its own state (`expandedAiDraft` in `InboxTab.vue`), its own UI (collapsed pill that expands to a textarea + action bar), its own link-picker popover, and its own `regenerate`/`improve`/`send` events.
- **Thread-card footer buttons** — the new "Antworten / Allen antworten / Weiterleiten" buttons in `InboxChatView`'s `.sr-thread-actions` footer, which emit `reply` / `reply-all` / `forward` events that `InboxTab` catches and funnels into `expandedAiDraft` state.

Two problems with today's state:

1. **The Antworten button doesn't feel clickable** — it triggers a state update (`expandedAiDraft.value = {...}`) but the visual effect of that is invisible unless the user scrolls down to find the old `InboxAiDraft` pane. Max's report: "ich kann nicht auf 'antworten' klicken".
2. **Compose is below the fold, not the focus** — when Max wants to answer a mail, the received mail should disappear (or collapse) and the compose area should take over the viewport. Today the received mail stays big at the top and the compose field sits at the bottom as a secondary element. That's upside-down for a "reply" flow.

The goal of this change is to make clicking "Antworten" switch the reading pane into compose mode — the composer becomes the primary content, the incoming mail collapses to a reference strip below.

## Architecture

The reading-pane container (`InboxChatView.vue`) gets a local `mode` state: `'thread'` (current default) or `'compose'`. When mode is `thread`, it renders exactly like today (subject header, accordion messages, actions footer). When mode is `compose`, it renders a new `InboxComposePane.vue` component instead of the accordion, with a collapsed reference strip below showing "Auf diese Nachricht von …". The subject header stays at the top in both modes.

The new component composition:

```
InboxChatView.vue            (thread container, owns mode state)
 ├── subject header          (always visible)
 ├── if mode === 'thread':
 │    ├── accordion messages (InboxMailMessage *)
 │    └── thread actions footer (Antworten / Mit KI-Entwurf / Allen antworten / Weiterleiten)
 └── if mode === 'compose':
      ├── InboxComposePane.vue
      │    ├── compose header (orange strip, title, Abbrechen button)
      │    ├── To/Cc/Subject fields
      │    ├── body textarea (with optional KI draft injected)
      │    ├── send bar (Senden, Verbessern, Neu generieren, Anhang, Link-Picker)
      │    └── AI draft state badge ("✦ KI-Entwurf")
      └── collapsed reference strip ("Auf diese Nachricht von Riccardo Leitner · 15.04.")
```

### InboxChatView (modified)

- New local `composeMode` ref: `'thread' | 'compose'`.
- New `composeContext` ref holding the data for the compose pane: `{ kind: 'reply' | 'reply-all' | 'forward', toEmail, ccEmail?, subject, body, quotedMessageId, withDraft }`.
- The two footer buttons now:
  - **Antworten**: sets `composeMode = 'compose'` with `composeContext = { kind: 'reply', toEmail: latestInbound.from_email, subject: 'Re: ...', body: '', withDraft: false }`.
  - **Mit KI-Entwurf antworten**: same, but `withDraft: true`. The compose pane triggers the AI draft generation on open.
  - **Allen antworten** and **Weiterleiten** become Ghost-style secondary buttons beside the two primaries.
- A new `onComposeCancel()` handler flips `composeMode` back to `'thread'` and clears `composeContext`.
- A new `onComposeSend(payload)` handler emits `send` to `InboxTab` with the full compose payload so the existing send pipeline handles it. On success, flip back to thread mode.

### InboxComposePane (new)

New file `resources/js/Components/Admin/inbox/InboxComposePane.vue`. Responsibilities:

- Render the compose UI: orange header strip, To/Cc/Subject fields, body textarea, send action bar.
- Hold local state for the editable draft (`body`, `to`, `cc`, `subject`) initialised from props.
- On mount with `withDraft === true`, trigger the AI draft fetch via an injected `generateDraft()` function (reusing the existing `conv_regenerate_draft` endpoint wiring in `InboxTab`).
- Integrate the existing `LinkPickerPopover` so "Link anfügen" still works — unchanged props (`property-id`, `@pick`), same orange styling.
- Emit `send`, `cancel`, `improve`, `regenerate` events.

Props:
- `context: { kind, toEmail, subject, body, quotedMessageId, withDraft }`
- `propertyId: Number | null` (for the link picker)
- `sendAccounts: Array<{id, email_address}>` (for the From selector)
- `sendAccountId: Number | null`
- `originalMessage: Object` (the message being replied to, for the collapsed reference strip)
- `loading: Boolean` (AI draft generation in progress)

Events:
- `send` → `{ to, cc, subject, body, sendAccountId, linkedFileIds }`
- `cancel` → void
- `improve` → forwarded to InboxTab (reuses the existing improveWithAi)
- `regenerate` → forwarded to InboxTab (reuses the existing regenerate handler)
- `update:sendAccountId` → number

### InboxAiDraft (removed)

The current `InboxAiDraft.vue` is deleted. Its features migrate:

- Textarea + `update:draft` → `InboxComposePane`'s body textarea + local state
- Regenerate button → `InboxComposePane` passes `regenerate` up
- Improve button → same
- Link picker → same (the `LinkPickerPopover` import stays, moved into `InboxComposePane`)
- Send / Nachfassen button → `InboxComposePane`'s "Senden" button with `send` event
- Mark-handled button → moves to thread actions footer instead (it's not a compose concern)
- Attachment toggle / calendar toggle → moves to thread actions footer (same reason)

The `#ai-draft` slot in `InboxChatView`'s template is deleted; `InboxAiDraft` has no more callers.

### InboxTab (modified)

- The existing `expandedAiDraft` ref is repurposed: instead of feeding the old draft pane, it holds the AI-generated draft body that `InboxComposePane` consumes when mode is `compose` + `withDraft`.
- The `onComposeReply` / `onComposeForward` handlers that prefilled `expandedAiDraft` when events bubbled up from the thread footer are removed — InboxChatView now handles those events locally by flipping its own mode, no parent state change needed.
- A new `onComposeSend(payload)` handler replaces the existing `sendDraft()` path for the in-thread compose flow. It reuses the existing send pipeline (`fetch POST send_email`, attachment handling, etc.) — just sourced from the new payload shape.
- The existing `regenerateAiDraft()` function stays as-is but gets called from `InboxComposePane` via the event pipeline instead of the old pane's click.

## Key design decisions

### Orange gradient for KI features

The earlier mockup used a violet-cyan gradient on the "Mit KI-Entwurf antworten" button and the "✦ KI-Entwurf" body badge. Max asked for SR-Homes orange instead. Both elements use the same gradient tokens the rest of the app already defines for the brand color:

```css
background: linear-gradient(135deg, hsl(28 98% 54%), hsl(18 88% 48%));
```

(Same gradient used on `.sr-avatar--me` in `InboxMailMessage.vue`.) The violet-cyan palette is not used anywhere in the redesign.

### Mode switch vs modal vs route

Three alternatives considered:

1. **Local mode ref inside `InboxChatView`** (chosen). No routing change, no modal. The reading pane just renders a different child. Simple, predictable, easy to revert.
2. **Modal overlay on top of the thread view**. Rejected: Max's mental model is "compose replaces the thread until I'm done" — a modal implies "temporary side task" and adds a layering complexity DOMPurify + z-index would have to worry about.
3. **Inertia route to `/admin/inbox/compose`**. Rejected: we lose all the in-memory state (selectedItem, allDetailMessages, thread cache), the URL change breaks the back button's semantic ("back" should undo the compose, not navigate away from the inbox), and it doesn't compose with the existing dual-pane layout.

### Collapsed reference strip vs full thread below

When compose mode is active, the incoming mail is shown as a **single collapsed strip** with sender + timestamp + one-click expand. The full thread (with accordion, older messages, etc.) is **not** rendered below the compose pane — only the specific message the reply is a reply TO.

Rationale: including the full thread below the compose means the already-long thread page gets even longer, and the user's focus is already on the compose content. If they need older context they can click "Abbrechen" to go back to the thread view.

An exception: "Allen antworten" and "Weiterleiten" need slightly different context below (the full mail body for Forward so the user can see what's being forwarded). For v1 we'll treat Forward the same as Reply (collapsed strip, expand to see content) and iterate if it feels wrong in use.

### AI draft generation entry points

Two ways to enter the compose with an AI draft:

1. **"Mit KI-Entwurf antworten"** → `withDraft: true` → draft is generated on compose mount
2. **"Antworten"** → `withDraft: false` → draft body starts empty; user can still click "Neu generieren" inside the compose pane to invoke AI later

The "Neu generieren" button inside the compose is always available regardless of entry point — just pulls the AI draft and overwrites the current body. Same with "Verbessern" which sends the current body to the improve endpoint.

### Send pipeline reuse

The existing `sendDraft()` function in `InboxTab.vue` handles the full send flow: validates fields, calls `send_email` action, attaches files, updates conversation state, triggers a toast. The new `onComposeSend()` handler is a thin wrapper that translates the compose payload into the same shape `sendDraft()` expects. No rewrite of the send logic — we reuse it and only change the UI that drives it.

## Out of scope

- **Fullscreen new-mail composer** (`composing` mode in `InboxTab`). That's the "Neue Nachricht"-from-scratch flow, a separate code path. Keep it as-is.
- **Reply-all and Forward deep logic**. They route to the same compose pane as Reply. Reply-all prefills Cc, Forward prefills an empty To. Any future differentiation (e.g., inline-quoted body in Forward) is a follow-up.
- **Keyboard shortcuts**. R for reply, Shift+R for reply-all, F for forward — nice to have, not required.
- **Draft autosave**. The current flow already discards unsent drafts on close. No change.
- **Attachment picker UI**. Today's attachment popup and file-checkbox UI carry over to the compose pane's attachment button. Same modal, same props.

## Success criteria

- Max opens Riccardo Leitner's conversation, clicks "Antworten" — the thread accordion is replaced by a compose pane with "Re: Informationen Grundstückskauf" subject pre-filled, empty body, "An: riccardo.leitner@gmail.com" pre-filled, orange header strip "Antworten an Riccardo Leitner", Abbrechen button on the right. Below: a single-line reference strip "Auf diese Nachricht von Riccardo Leitner · 15.04. 09:52" — expandable.
- Max clicks "Mit KI-Entwurf antworten" — same compose view appears but the body textarea is pre-filled with the AI-generated draft. A "✦ KI-Entwurf" orange pill is shown at the top of the body. "Neu generieren" button in the send bar works.
- Max types his own reply, clicks "Senden" — the existing `sendDraft` pipeline fires, the mail is sent, the compose pane closes, and the thread view reappears with the new outbound bubble at the bottom of the accordion.
- Max clicks "Abbrechen" mid-compose — the compose pane disappears, the thread view reappears unchanged, no draft persisted (matching current behavior).
- The "Link anfügen" button (docs-link picker) works inside the compose pane, uses the existing `LinkPickerPopover` with the same `propertyId` prop, and inserts the selected link into the body at the cursor position (or the end of the body — whatever the current behavior is).
- No dual-compose state: there is never a moment where both the thread actions footer and the compose pane are visible at once.

## Files touched

### New

- `resources/js/Components/Admin/inbox/InboxComposePane.vue` — the new compose pane. ~250-300 lines.

### Modified

- `resources/js/Components/Admin/inbox/InboxChatView.vue` — add `composeMode` state, render `InboxComposePane` when mode is `compose`, two new primary buttons in the actions footer (Antworten + Mit KI-Entwurf antworten) with orange gradient styling.
- `resources/js/Components/Admin/InboxTab.vue` — delete the `#ai-draft` slot wiring, delete `onComposeReply` / `onComposeForward` handlers, wire the new `send` / `cancel` events from `InboxComposePane`, keep `regenerateAiDraft` / `improveWithAi` / `sendDraft` as-is but called via the new event chain.

### Removed

- `resources/js/Components/Admin/inbox/InboxAiDraft.vue` — every feature migrates to `InboxComposePane`. ~400 lines removed.

## Dependencies

None new. `LinkPickerPopover` is already in the project. No schema changes. The existing `conv_regenerate_draft` and `send_email` admin API actions stay unchanged.

## Risk / mitigation

- **Risk**: The `sendDraft()` function in InboxTab is entangled with the old `expandedAiDraft` state shape. Rewiring it for the new payload might break the existing fullscreen `composing` flow that also uses it.
  **Mitigation**: keep two entry points to the same `sendDraft()` — the fullscreen composer and the new compose pane both build the same payload shape and pass it in. No internal refactor of `sendDraft()` itself.

- **Risk**: Deleting `InboxAiDraft.vue` while migration is in flight leaves a period where the thread has neither the old pane nor the new compose fully working.
  **Mitigation**: the implementation plan will stage the work: add `InboxComposePane` first, wire it in parallel to the old pane, delete the old pane in the final task.

- **Risk**: The AI draft generation flow has a lot of edge cases (loading state, error handling, tone selection, detail level, match files). Migrating all of that to the new pane without losing behavior is the biggest source of regressions.
  **Mitigation**: for v1, migrate only the core features (body textarea, improve, regenerate, send, link picker). Match files, tone, detail level — the refs for these already live in `InboxTab.vue` (not inside the deleted component), so the state is preserved; the v1 compose pane simply doesn't expose UI controls for them. If Max notices they're missing and asks for them, we add the controls in a follow-up without a schema or state change.

- **Risk**: Reply-All pre-fill semantics are subtle (sender + non-self original recipients into To, sometimes into Cc depending on client convention).
  **Mitigation**: for v1, Reply-All uses the same `InboxComposePane` as Reply with the original sender in `To` — no automatic Cc expansion. If Max wants the Gmail-style "everyone except me" behavior, add it in a follow-up once the core flow is stable.
