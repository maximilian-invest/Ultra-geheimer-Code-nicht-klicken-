# Design: Dokumente als Zugriffs-Links statt PDF-Anhaenge

**Status:** Draft — bereit fuer Implementation Plan
**Datum:** 2026-04-14
**Autor:** Max + Claude (Brainstorming-Session)

---

## 1. Motivation

Heute verschickt Max Immobilien-Unterlagen als PDF-Anhaenge per Email. Nachteile:

- Empfaenger kann Unterlagen 1:1 weiterleiten, ohne dass wir's mitbekommen
- Kein Tracking, ob und wann ein Dokument tatsaechlich geoeffnet wurde
- Keine Staffelung moeglich — Erstinteressent kriegt entweder alles oder nichts
- Grosse Anhaenge blockieren Posteingaenge und sehen unprofessionell aus
- Nachtraegliche Aenderungen an einem Dokument erreichen den Empfaenger nie

Zielzustand: **Ein Link pro Kunde/Phase**. Der Kunde klickt, bestaetigt DSGVO + Email, sieht und laed exakt die Dokumente die wir fuer diese Phase freigegeben haben, wir tracken jede Interaktion.

## 2. Scope

### In-Scope

- Pro Immobilie beliebig viele benannte Zugriffs-Links mit handverlesenen Dokumenten
- Ein Link-pro-Kunde Ansatz (nicht upgradebar — neue Phase = neuer Link)
- Public Landing-Page mit Email-Gate + DSGVO-Checkbox
- Inline PDF-Viewer (PDF.js) mit Download-Button
- Tracking: Link-Open, Dokument-Viewer-Aufruf, Dokument-Download
- Summary-Eintrag pro Session im Haupt-Zeitstrahl, Drilldown im Link-Tab
- Admin-UI: neuer Tab "Links" in der Property-Seite, Composer-Integration in der Inbox
- Auto-Insertion des Default-Erstanfrage-Links bei AI-generierten Replies auf Erstanfragen
- Pro-User konfigurierbarer Default-Expiry (7/14/30/90/unbegrenzt)
- Link-Revoke und automatische Expiry
- 90-Tage Retention fuer Sessions und Events

### Out-of-Scope

- Passwort-Schutz fuer Links (reines Email-Gate genuegt)
- Magic-Link-Codes per Email (zu viel Friction)
- Automatische Tier-Logik ("erst Level 1, spaeter Level 2") — alles manuell
- Upgradable Links (ein Link der spaeter mehr zeigt)
- Watermarking der PDFs mit Empfaenger-Email
- Foto-/Video-Preview (nur PDFs)
- Zugriff via WhatsApp/SMS (nur Email-Delivery)

## 3. Architektur

Alles in der bestehenden Laravel-App auf `kundenportal.sr-homes.at`. Kein neuer Server, keine neue Subdomain.

```
┌─────────────────────────────────────────────────────────────┐
│  kundenportal.sr-homes.at                                   │
│                                                             │
│  Admin (Inertia+Vue)        Public (Blade + Vanilla JS)     │
│  ├─ Property                ├─ GET  /docs/{token}           │
│  │   └─ Tab "Links"         ├─ POST /docs/{token}/unlock    │
│  │   └─ /links/{id} Detail  ├─ GET  /docs/{token}/file/{id} │
│  └─ Inbox Composer          └─ POST /docs/{token}/event     │
│                                                             │
│  Controller:                                                │
│   • Admin\PropertyLinkController   (CRUD + Detail)          │
│   • PublicDocumentController       (Landing, Unlock, File,  │
│                                     Event)                  │
│                                                             │
│  Services:                                                  │
│   • PropertyLinkService     (Token-Gen, Accessibility)      │
│   • LinkActivityLogger      (Session-Upsert, Event-Write)   │
└─────────────────────────────────────────────────────────────┘
```

### Neue Routes

```php
// routes/web.php — public group (kein auth middleware)
Route::prefix('docs')->group(function () {
    Route::get('{token}', [PublicDocumentController::class, 'show']);
    Route::post('{token}/unlock', [PublicDocumentController::class, 'unlock']);
    Route::get('{token}/file/{fileId}/{mode}', [PublicDocumentController::class, 'file']); // mode=view|download
    Route::post('{token}/event', [PublicDocumentController::class, 'event']);
});

// routes/web.php — admin group
Route::middleware(['auth', 'role:admin|assistenz|makler'])->group(function () {
    Route::get('/admin/properties/{property}/links', [PropertyLinkController::class, 'index']);
    Route::post('/admin/properties/{property}/links', [PropertyLinkController::class, 'store']);
    Route::get('/admin/properties/{property}/links/{link}', [PropertyLinkController::class, 'show']);
    Route::put('/admin/properties/{property}/links/{link}', [PropertyLinkController::class, 'update']);
    Route::delete('/admin/properties/{property}/links/{link}', [PropertyLinkController::class, 'destroy']);
    Route::post('/admin/properties/{property}/links/{link}/revoke', [PropertyLinkController::class, 'revoke']);
    Route::post('/admin/properties/{property}/links/{link}/reactivate', [PropertyLinkController::class, 'reactivate']);
});
```

## 4. Datenmodell

### Neue Tabellen

```sql
-- 4.1 property_links
CREATE TABLE property_links (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id     BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    token           CHAR(43) NOT NULL UNIQUE,         -- Str::random(43), URL-safe
    is_default      BOOLEAN DEFAULT FALSE,            -- Default fuer Erstanfragen
    expires_at      TIMESTAMP NULL,                   -- NULL = unbegrenzt
    revoked_at      TIMESTAMP NULL,
    revoked_by      BIGINT UNSIGNED NULL,             -- users.id
    created_by      BIGINT UNSIGNED NOT NULL,         -- users.id
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id),
    FOREIGN KEY (revoked_by)  REFERENCES users(id),
    INDEX idx_token (token),
    INDEX idx_property_active (property_id, revoked_at, expires_at)
);

-- Nur 1 Default pro Property:
-- MySQL unterstuetzt keine partial indexes; wir enforcen das in der Application-Layer:
-- - PropertyLinkService::markAsDefault() unset vorher alle anderen is_default-Flags fuer diese Property innerhalb einer Transaction
-- - Ein DB-Trigger BEFORE INSERT/UPDATE waere optional fuer Defense-in-Depth, ist aber nicht zwingend
```

```sql
-- 4.2 property_link_documents (N:M)
CREATE TABLE property_link_documents (
    property_link_id BIGINT UNSIGNED NOT NULL,
    property_file_id BIGINT UNSIGNED NOT NULL,
    sort_order       INT UNSIGNED DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (property_link_id, property_file_id),
    FOREIGN KEY (property_link_id) REFERENCES property_links(id) ON DELETE CASCADE,
    FOREIGN KEY (property_file_id) REFERENCES property_files(id) ON DELETE CASCADE
);
```

```sql
-- 4.3 property_link_sessions
CREATE TABLE property_link_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_link_id    BIGINT UNSIGNED NOT NULL,
    email               VARCHAR(255) NOT NULL,        -- Kunde-eingegeben, lowercased + trim
    dsgvo_accepted_at   TIMESTAMP NOT NULL,
    ip_hash             CHAR(64) NOT NULL,            -- sha256(ip + app_key)
    user_agent_hash     CHAR(64) NOT NULL,            -- sha256(ua + app_key)
    first_seen_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (property_link_id) REFERENCES property_links(id) ON DELETE CASCADE,
    INDEX idx_link_email (property_link_id, email),
    INDEX idx_last_seen (last_seen_at)                -- fuer Retention-Job
);
```

```sql
-- 4.4 property_link_events
CREATE TABLE property_link_events (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id        BIGINT UNSIGNED NOT NULL,
    property_file_id  BIGINT UNSIGNED NULL,           -- NULL bei link_opened
    event_type        ENUM('link_opened','doc_viewed','doc_downloaded') NOT NULL,
    duration_s        INT UNSIGNED NULL,              -- bei doc_viewed: Verweildauer
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id)       REFERENCES property_link_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (property_file_id) REFERENCES property_files(id) ON DELETE SET NULL,
    INDEX idx_session (session_id),
    INDEX idx_created_at (created_at)
);
```

### Modifikationen bestehender Tabellen

```sql
-- 4.5 users: per-user Default-Expiry
ALTER TABLE users ADD COLUMN default_link_expiry_days INT UNSIGNED NULL DEFAULT 30;

-- 4.6 activities: neue Kategorie fuer Link-Summary-Eintraege
ALTER TABLE activities MODIFY COLUMN category ENUM(
    'email-in','email-out','expose','besichtigung','kaufanbot',
    'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',
    'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',
    'nachfassen','link_opened'
) DEFAULT 'sonstiges';

-- 4.7 activities: Referenz auf property_link_session fuer Deep-Linking
ALTER TABLE activities ADD COLUMN link_session_id BIGINT UNSIGNED NULL;
ALTER TABLE activities ADD FOREIGN KEY (link_session_id) REFERENCES property_link_sessions(id) ON DELETE SET NULL;
```

## 5. Customer-Flow

### URL + States

`GET /docs/{token}` — drei Zustaende:

1. **Token gueltig + keine Session-Cookie** → Landing mit Email-Gate
2. **Token gueltig + Session-Cookie aktiv (<24h)** → Unlocked View
3. **Token ungueltig / abgelaufen / revoked** → Friendly Error Page (410 Gone oder 404)

### Session-Cookie

```
Name:     sr_link_session_{token_prefix}    (erste 8 Zeichen)
Value:    {session_id}.{hmac(session_id, app_key)}
HttpOnly: true
SameSite: Lax
Secure:   true (nur HTTPS)
MaxAge:   86400 (24h)
```

Beim Request wird das Cookie validiert (HMAC-Check), die Session-Row geladen, `last_seen_at` ge-UPDATEd.

### Email-Gate Flow

1. Kunde klickt Email-Link → `GET /docs/{token}`
2. Laravel validiert Token: aktiv? nicht abgelaufen? nicht revoked?
3. Wenn OK + kein Cookie: Landing-Page mit Email-Form
4. Kunde gibt Email ein, haeckchen DSGVO, klickt "Unterlagen ansehen"
5. `POST /docs/{token}/unlock` mit `{email, dsgvo: true}`
6. Validation: Email-Format, DSGVO = true, Rate-Limit Check (max 10 unlocks/Stunde/Token)
7. Session-Row wird erstellt (oder vorhandene fuer selbe Email reused wenn <24h alt)
8. Event `link_opened` wird geschrieben + Activity-Summary ge-upserted
9. Cookie wird gesetzt, Redirect auf `GET /docs/{token}` (selber URL, jetzt im Unlocked-State)

### Unlocked View

- Property-Hero (gleiches Bild wie im Email-Gate)
- Dokument-Kacheln (Grid, 3 col Desktop)
- Jede Kachel: Icon, Name, Groesse, "Ansehen"-Button, "Download"-Link
- Klick auf "Ansehen" → Viewer-Modal oeffnet sich, `POST /docs/{token}/event` mit `{type: doc_viewed, file_id}`
- Klick auf "Download" → `GET /docs/{token}/file/{fileId}/download`, `POST /docs/{token}/event` mit `{type: doc_downloaded}`

### Viewer (PDF.js)

- Full-Screen Modal, dunkler Hintergrund (#0A0A08)
- Header-Bar: Dokument-Name, Download-Button, Schliessen
- Canvas: PDF.js rendert die Seiten nativ
- Footer: Seiten-Navigation
- Mobile: Swipe-Gesten fuer Seitenwechsel, Pinch-Zoom
- Verweildauer-Tracking: Interval-Timer sendet alle 30s Heartbeat mit `{file_id, duration_s}` → beim Schliessen letzter Heartbeat mit Final-Dauer
- Fallback bei PDF.js-Fehler: Direct-Download-Link

## 6. Admin-Flow

### 6.1 Property-Seite → Tab "Links"

Neue Vue-Komponente `PropertyLinksTab.vue` in `resources/js/Components/Admin/Property/`. Liste der Links mit Cards:

- **Sortierung:** Default-Erstanfrage zuerst, dann aktive nach `created_at DESC`, dann abgelaufene/revoked gedimmt am Ende
- **Card-Inhalt:** Name, Doc-Count, Expiry, Status (AKTIV/ABGELAUFEN/GESPERRT), Aktivitaets-Summary
- **Card-Actions:** URL kopieren, Bearbeiten, Sperren/Reaktivieren, Details
- **Top-Button:** "Neuer Link" → Slide-Over-Dialog

### 6.2 Slide-Over: "Neuer Link"

Komponente `PropertyLinkForm.vue` — wiederverwendet fuer Create + Edit:

- **Name** (Text-Input, autocomplete mit History-Eintraegen)
- **Default-fuer-Erstanfragen** (Toggle, max 1 pro Property)
- **Expiry-Dropdown** (7/14/30/90/unbegrenzt) — Default = `auth()->user()->default_link_expiry_days`
- **Dokumente-Checklist** (alle `property_files` dieser Property, mit Groesse)
- **Buttons:** Abbrechen, Speichern

Beim Speichern:
- `POST /admin/properties/{id}/links` → `{ name, is_default, expires_at, file_ids[] }`
- Response: `{ link: {...}, url: 'https://kundenportal.sr-homes.at/docs/xyz' }`
- URL wird automatisch in Zwischenablage kopiert (JS `navigator.clipboard.writeText`)
- Toast "Link erstellt & kopiert"
- Dialog schliesst, Liste re-fetched, neue Card pulsiert kurz

### 6.3 Link-Detail-View

Eigene Route `/admin/properties/{id}/links/{linkId}`, eigene Vue-Page `PropertyLinkDetail.vue`:

- **Header:** Link-Name, Status, Ablauf-Datum, Erstellt-Info
- **URL mit Copy-Button**
- **Metric-Cards:** Aufrufe, Personen, Dokument-Ansichten, Downloads
- **Enthaltene Dokumente** (Liste mit Per-Dokument-Stats)
- **Aktivitaets-Timeline** (chronologisch, gruppiert nach Session)
- **Actions:** Bearbeiten, Sperren, Loeschen

### 6.4 Inbox-Composer Integration

Aenderungen in `InboxChatView.vue` (Composer-Bereich):

- Neuer Button **"Link einfuegen ▾"** neben dem bestehenden "Anhang"-Button
- Klick → Popover mit den aktiven Links der Property der aktuellen Konversation
- Klick auf Link → formatierter HTML-Block wird an Cursor-Position eingefuegt:

```html
<div style="border:1px solid #E5E0D8; border-radius:12px; padding:16px; margin:16px 0; background:#FAF8F5; font-family:Outfit,sans-serif;">
  <div style="font-weight:500; color:#D4743B; font-size:14px;">🔗 Ihre Unterlagen</div>
  <a href="https://kundenportal.sr-homes.at/docs/{token}" style="color:#0A0A08; text-decoration:none; font-weight:500;">
    Erstanfrage · 2 Dokumente
  </a>
  <div style="font-size:13px; color:#5A564E; margin-top:4px;">Gueltig bis 12.05.2026</div>
</div>
```

Beim Senden hat die Email sowieso eine Multipart-Struktur (`text/html` + `text/plain`); der Plain-Text-Fallback enthaelt nur die reine URL. Funktioniert in allen Clients.

### 6.5 Auto-Insertion fuer Erstanfragen

Aenderung in `Admin\ConversationController::matchGenerateDraft()`:

```php
// bestehender AI-Prompt-Build
$prompt = $this->buildPrompt(...);

// neu: Check ob Erstanfrage + Default-Link existiert
$isErstanfrage = $this->detectErstanfrage($conversation);
$defaultLink = $property->propertyLinks()
    ->where('is_default', true)
    ->whereNull('revoked_at')
    ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
    ->first();

if ($isErstanfrage && $defaultLink) {
    $linkUrl = route('docs.show', $defaultLink->token);
    $prompt .= "\n\nWICHTIG: Schreibe am Ende der Mail einen Absatz in dem du die Unterlagen anbietest und diese URL einfuegst: {$linkUrl}";
}

// AI wird aufgerufen, der fertige Draft enthaelt die URL im Fliesstext
```

**Erstanfrage-Detection:**
- Das erste eingehende Email auf dem Thread, und
- `portal_emails` hat fuer dieses Thread nur 1 Zeile mit `direction='in'`, und
- Category = `anfrage` oder nicht gesetzt

**Fallback:** wenn kein Default-Link existiert, wird ein Banner im Composer angezeigt: "Kein Standard-Link fuer diese Property. [Jetzt erstellen →]".

### 6.6 Haupt-Zeitstrahl Integration

**LinkActivityLogger-Service:**

```php
class LinkActivityLogger
{
    public function recordLinkOpened(PropertyLinkSession $session): void
    {
        // Upsert Activity: wenn fuer diese Session heute schon eine existiert → updaten, sonst neue
        Activity::updateOrCreate(
            ['link_session_id' => $session->id],
            [
                'property_id'   => $session->link->property_id,
                'activity_date' => now()->toDateString(),
                'stakeholder'   => $session->email,
                'category'      => 'link_opened',
                'activity'      => $this->buildSummaryText($session),
            ]
        );
    }

    public function recordEvent(PropertyLinkSession $session, string $type, ?int $fileId, ?int $durationS): void
    {
        PropertyLinkEvent::create([...]);
        // Nach Event-Write: Activity-Summary-Text ge-UPDATEd
        $this->refreshActivitySummary($session);
    }

    private function buildSummaryText(PropertyLinkSession $session): string
    {
        $events = $session->events;
        $viewed = $events->where('event_type', 'doc_viewed')->count();
        $downloaded = $events->where('event_type', 'doc_downloaded')->count();
        $durationMin = ceil($events->sum('duration_s') / 60);

        return "hat Link '{$session->link->name}' geoeffnet → {$viewed} Dokumente angesehen, {$downloaded} heruntergeladen · {$durationMin} Min";
    }
}
```

## 7. Security & DSGVO

### Token

- 43-Zeichen URL-safe Random-String (`Str::random(43)` liefert 32 Byte Base64 = 256 Bit Entropie)
- Im Klartext in DB gespeichert (wir brauchen Lookup; Hashing wuerde scan erfordern)
- Nie in Server-Logs — Middleware maskiert Token in Log-URLs (`/docs/xxxxxxxx...`)

### Rate-Limiting

- `/docs/{token}/unlock` — 10 Versuche pro Token pro Stunde (Brute-Force-Schutz auf Email-Gate)
- `/docs/{token}/event` — 100 Events pro Session pro Stunde (Tracking-Spam-Schutz)

### PII-Minimierung

- **IP + User-Agent werden NICHT roh gespeichert**, nur als SHA256-Hash mit `APP_KEY` als Salt
- Zweck: Wiedererkennung derselben Session-Quelle bei Support-Faellen, ohne IP direkt zu tracken
- **Email wird roh gespeichert** (die braucht Max fuer Zuordnung) — das ist der explizite DSGVO-Consent-Punkt

### DSGVO-Consent

- Checkbox muss aktiviert sein, sonst blockiert Frontend + Backend-Validation
- Zeitpunkt wird in `dsgvo_accepted_at` gespeichert als Nachweis
- Consent-Text ist im Admin editierbar (neues Feld in CMS-Content), nicht hardcoded

**Default-Text:**

> Ich stimme zu, dass meine Email-Adresse und mein Zugriff auf diese Unterlagen im Rahmen der Betreuung des angebotenen Immobilien-Projekts verarbeitet werden. Die Daten werden zur Nachvollziehbarkeit des Interessenten-Kontakts gespeichert und nach 90 Tagen automatisch geloescht, sofern kein weiterer Kontakt erfolgt. Details in unserer Datenschutzerklaerung.

### Retention

- Scheduled Job `PurgeOldLinkSessions` laeuft taeglich (via Laravel Scheduler)
- Loescht `property_link_sessions` mit `last_seen_at < now() - 90 days`
- Cascaded Delete loescht automatisch die zugehoerigen `property_link_events`
- Activity-Eintraege in `activities` bleiben (wichtig fuer Makler-Historie), aber `stakeholder` wird pseudonymisiert zu `geloeschter-empfaenger@deleted.local`

### DSGVO-Rechte

- **Auskunftsrecht:** Admin kann im Backend per Email-Adresse alle Sessions + Events abrufen → Export als JSON
- **Loeschrecht:** Admin kann per Email-Adresse alle Sessions + Events sofort loeschen (Button "Daten dieser Person loeschen")
- **Berichtigungsrecht:** nicht noetig, da Max nur Zugriffs-Logs speichert, keine Korrektur-faehigen Daten

## 8. Visual Design

### Design-Tokens (1:1 von sr-homes.at)

```css
:root {
  --bg-cream:    #FAF8F5;
  --bg-dark:     #0A0A08;
  --accent:      #D4743B;
  --accent-hover:#C0551F;
  --text-strong: #0A0A08;
  --text-muted:  #5A564E;
  --border-soft: #E5E0D8;

  --radius-sm: 8px;  --radius-md: 12px;  --radius-lg: 16px;  --radius-xl: 24px;
  --shadow-soft:  0 4px 24px rgba(10,10,8,0.06);
  --shadow-hover: 0 12px 48px rgba(10,10,8,0.12);

  --font: 'Outfit', -apple-system, system-ui, sans-serif;
  --ease: cubic-bezier(0.25, 0.46, 0.45, 0.94);
  --dur:  300ms;
}
```

### Landing-Page Layout

- **Hero:** Property-Main-Image full-bleed, 560px Desktop / 320px Mobile, rounded 16px, Dark-Gradient-Overlay
- **Hero-Text:** Outfit 64px/36px "Wohnprojekt ...", Outfit 18px Meta-Zeile
- **Email-Gate-Card:** max-w 560px, center, rounded-xl, padding 48px, shadow-soft
- **Underline-Input:** 2px border-bottom, focus-state orange
- **CTA-Button:** height 56px, bg accent, hover scale 1.02 + bg accent-hover

### Unlocked-Page Layout

- **Kompakter Hero** (360px)
- **Dokument-Grid:** 3 col Desktop, 2 col Tablet, 1 col Mobile
- **Kachel:** rounded-2xl, padding 32px, shadow-soft, hover lift -4px + shadow-hover, 250ms ease
- **Stagger-Animation:** Kacheln erscheinen nacheinander (80ms delay pro Kachel)

### Viewer Modal

- **Full-Screen**, slidet von unten (400ms cubic-bezier)
- **Header:** dark, 80px, Dokument-Name links, Download + Schliessen rechts
- **Canvas:** zoom-to-fit, smooth Scroll
- **Footer:** sticky, Seiten-Nav

### Mobile Adaptations

- Hero-Text 36px (statt 64px)
- Kacheln 1-spaltig, Touch-Target min 80px
- Viewer Bottom-Sheet statt Modal auf iOS Safari (sticky am unteren Rand)
- Pinch-Zoom + Swipe-Navigation im Viewer
- Viewport-fit=cover gegen iOS Notch-Issues

### Motion-Prinzipien

- Alle Transitions 300ms ease-custom (nie linear, nie instant)
- Hover-States immer mit `transform` + `shadow` (nicht nur color)
- Scroll-based Fade-In via `IntersectionObserver` wie in `website-v2/js/app.js`
- Loading-States: Skeleton-Screens statt Spinner

## 9. Edge Cases

| Situation | Verhalten |
|---|---|
| Token nicht gefunden | 404 mit Friendly-Message + Kontakt-CTA |
| Token abgelaufen | 410 Gone mit "abgelaufen am {date}" + "neuer Kontakt" CTA |
| Token revoked | 410 Gone mit "Zugriff beendet" + Kontakt-CTA |
| Keine Dokumente im Link | Validierung beim Create verhindert; Fehlerfall: Empty-State-Card |
| Dokument waehrend Link-Lebenszeit geloescht | Kachel zeigt "Dokument nicht mehr verfuegbar", Viewer-Klick blockiert |
| Gleiche Email auf zwei Geraeten | Zwei Sessions, beide aktiv, beide getrackt — kein Konflikt |
| Concurrent Sessions selbe Email selber Link | Reuse existing session wenn `last_seen_at` < 24h, sonst neue |
| Email-Gate Rate-Limit erreicht | "Zu viele Versuche, bitte in 1 Stunde erneut versuchen" |
| DSGVO-Checkbox nicht aktiviert | Frontend-Validation blockiert Submit, Backend returnt 422 |
| Viewer kann PDF nicht rendern | Fallback: direkter Download-Link in Viewer-Header |
| Browser ohne PDF.js-Support (sehr alt) | Detection → `<iframe src="...">` als Native-Viewer-Fallback |
| Token im Email-Preview angezeigt | Kein Problem — Preview-Bots klicken nicht durch Email-Gate |
| Spam-Bot POSTet Unlock mit Fake-Emails | Rate-Limit fangt ab; Fake-Emails landen in Sessions als Rauschen (akzeptabel, DSGVO-Konsequenz: retention loescht nach 90d) |

## 10. Testing-Strategie

### 10.1 Unit Tests (`tests/Unit/`)

- `PropertyLinkServiceTest::test_generates_unique_token()`
- `PropertyLinkServiceTest::test_is_accessible_respects_expiry()`
- `PropertyLinkServiceTest::test_is_accessible_respects_revoke()`
- `LinkActivityLoggerTest::test_upserts_activity_for_same_session()`
- `LinkActivityLoggerTest::test_summary_text_includes_counts_and_duration()`
- `PurgeOldLinkSessionsTest::test_deletes_sessions_older_than_90_days()`

### 10.2 Feature Tests (`tests/Feature/`)

- **Customer-Flow:**
  - `test_landing_page_shows_email_gate_for_valid_token()`
  - `test_unlock_creates_session_and_cookie()`
  - `test_unlock_fails_without_dsgvo_consent()`
  - `test_unlock_fails_when_rate_limited()`
  - `test_unlocked_user_can_view_pdf()`
  - `test_unlocked_user_can_download_pdf()`
  - `test_expired_token_returns_410()`
  - `test_revoked_token_returns_410()`
  - `test_event_endpoint_creates_events_and_updates_activity()`

- **Admin CRUD:**
  - `test_admin_can_create_property_link()`
  - `test_admin_can_mark_link_as_default_erstanfrage()`
  - `test_only_one_default_erstanfrage_per_property()`
  - `test_admin_can_revoke_and_reactivate()`
  - `test_admin_can_view_link_detail_with_sessions()`

- **Auto-Insertion:**
  - `test_ai_draft_on_erstanfrage_includes_default_link_url()`
  - `test_ai_draft_without_default_link_shows_banner()`

### 10.3 Manueller Browser-Test (Pre-Deploy-Checklist)

- Landing-Page auf Chrome, Safari, Firefox (Desktop)
- iPhone Safari + Android Chrome
- Viewer oeffnet PDF, Seitennavigation, Zoom, Download
- Mobile: Swipe-Gesten, Pinch-Zoom
- Email-Client-Test: Gmail, Outlook, Apple Mail — HTML-Block-Rendering
- Plain-Text-Client-Test: Link ist lesbar und klickbar

## 11. Offene Fragen (keine — alle geklaert in Brainstorming)

Alle Fragen wurden im Brainstorming beantwortet. Offene Admin-Flow-Details (Auto-Insert-Format, Dialog-Stil, Detail-View-Route) wurden von Claude im Sinne der bestehenden Admin-Konsistenz entschieden.

## 12. Decisions Log

| Entscheidung | Gewaehlte Option | Warum |
|---|---|---|
| Auth-Modell | Link + Email-Gate (any email accepted, tracked per email) | Minimal Friction, GDPR-konform mit Consent |
| Access-Control | Manual pro Link, viele Links pro Property, keine Auto-Tiers | Volle Kontrolle fuer Max |
| Viewer | Inline PDF.js + Download-Button (Hybrid) | Modern + druckbar in einem Flow |
| Lifecycle | Default 30d pro User konfigurierbar, Dropdown beim Erstellen | Hygiene + Flexibilitaet |
| Delivery | Property-Tab + Composer-Dropdown + Auto-Insert auf Erstanfrage | Effizient im Daily-Flow |
| Tracking | Link-Open + Doc-Viewed + Doc-Downloaded | Aussagekraeftige Events ohne Noise |
| Anzeige | Summary im Haupt-Zeitstrahl + Drilldown im Link-Tab | Overview + Detail je nach Bedarf |
| URL | `kundenportal.sr-homes.at/docs/{token}` | Simplest Stack, Branding via Design |
| Auto-Insert-Format | Formatierter HTML-Block | Modern, Plain-Text-Fallback automatisch |
| Create-Dialog | Slide-Over | Konsistent mit Rest vom Admin |
| Detail-View | Eigene Route | Browser-Back + Screenshots moeglich |

## 13. Implementation-Reihenfolge (fuer den Implementation Plan)

Grobe Phasen — der Implementation-Plan wird das noch feiner zerlegen:

1. **Datenmodell** — Migrations + Eloquent-Modelle + Relationen
2. **PropertyLinkService + LinkActivityLogger** — Business-Logik + Unit-Tests
3. **Admin CRUD** — Controller + Routes + Policies + Feature-Tests
4. **Admin UI: Property-Links-Tab** — Vue-Komponente, CRUD gegen Backend
5. **Admin UI: Link-Detail-View** — Eigene Vue-Page mit Metriken + Timeline
6. **Public Controller + Routes** — Show, Unlock, File, Event-Endpoints + Feature-Tests
7. **Public Landing-Page** — Blade-Template mit hypermodernem Design (Taste-Regeln)
8. **PDF.js Viewer** — Integration, Mobile-Tuning, Tracking-Heartbeat
9. **Inbox-Composer Integration** — Button + Popover + Link-Insert
10. **AI-Auto-Insert fuer Erstanfragen** — Prompt-Aenderung + Detection-Logic
11. **Retention-Job + DSGVO-Tools** — Scheduled Purge, Auskunfts- und Loesch-Endpoints
12. **End-to-End Manuelle Tests** — Browser + Email-Clients
