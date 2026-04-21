# Tagesbriefing

KI-generiertes tägliches Briefing im Admin-Dashboard (TodayTab). Fasst die letzten 24h zusammen, zeigt laufende Gesprächsfäden mit Kontext-Trail, und listet das, was heute ansteht. Öffnet als Sheet aus einer neuen Card oberhalb der bestehenden "Guten Morgen"-Card.

## Überblick

Ein Makler startet den Tag, öffnet das Dashboard, sieht eine neue Card mit einer 1-Zeilen-Preview des wichtigsten was gestern passiert ist. Klickt "Vollständig lesen" → Sheet slide-in von rechts mit vier Blöcken:

1. **Gestern in 3 Sätzen** — KI-Narrativ, 100-150 Wörter
2. **Aktive Threads mit Kontext** — bis zu 8 laufende Dialoge mit Tag-für-Tag-Trail ("Mo: Anfrage → Di: KI-Entwurf → heute: wartet auf Antwort")
3. **Anstehend heute** — Besichtigungen, offene Anfragen, Tasks, Nachfass-Batch-Status
4. **Auffälligkeiten** — KI-erkannte Muster (Hot Property, Cooling Property, möglicher Eigentümer-Unmut)

Der Zweck: Statt am Morgen 10 Minuten durch Inbox/Aktivitäten/Tasks zu scrollen bis man weiss was läuft, liefert das Briefing die kuratierte Executive-Summary mit klickbaren Drilldowns in die jeweiligen Detail-Screens.

## User Flow

1. Makler öffnet Dashboard-Tab (Standardansicht)
2. Oben sieht er die neue Tagesbriefing-Card mit 1-Zeilen-Preview: *"Gestern 4 Anfragen, 1 Kaufanbot für Klessheimer 74. Frau Schmitt beschwert sich erneut über Riverside. Heute 3 Besichtigungen geplant."*
3. Klick auf **Vollständig lesen** → Sheet slide-in von rechts (max-w-2xl, nicht modal-blockierend, schließbar mit ESC)
4. Sheet zeigt 4 Blöcke von oben nach unten scrollbar
5. Jede Thread-Zeile klickbar → springt in Inbox-Tab zum entsprechenden Thread (bestehende `switchTab` + conv-id Deep-Link)
6. Jede Agenda-Zeile klickbar → springt in entsprechenden Tab (Besichtigung → Kalender, Anfragen → Inbox, Tasks → Aufgaben)
7. Schließen → kehrt zum Dashboard zurück, Card zeigt weiterhin 1-Zeilen-Preview
8. Card hat ein kleines "⟳" Icon rechts oben zur manuellen Regenerierung (rate-limit: 1×/Minute)

## Trigger & Caching

**Generierungszeitpunkt:**
- Automatisch jeden Werktag um 06:30 Europe/Vienna via Laravel Scheduled Job
- Manuell via "⟳"-Button (max 1× pro Minute, storage cache lock)
- Fallback: Wenn Card geöffnet wird und kein Briefing für heute existiert → on-demand generieren

**Cache-Strategie:**
- Ein Briefing pro Broker pro Kalendertag
- Speicherort: neue Tabelle `daily_briefings` (user_id, briefing_date, data JSON, generated_at)
- Storage cache-lock verhindert Doppelgenerierung bei parallelen Requests
- Daten bleiben für 30 Tage (automatischer Cleanup)

**Kosten-Rahmen:**
- GPT-4o-mini reicht (strukturierte Zusammenfassung, keine kreative Textgenerierung)
- ~3000 Input-Tokens, ~800 Output-Tokens → ~0.4 Cent pro Briefing
- Bei 5 aktiven Brokern × 1 Briefing/Tag = 2 Cent/Tag

## UI — Einstiegspunkt (Card)

Neue Card direkt unter dem Header des TodayTab, **oberhalb** der bestehenden "Guten Morgen"-Action-Card.

**Struktur:**
- shadcn `Card` mit `rounded-xl border border-border/40 bg-card shadow-sm`
- Linke Akzent-Leiste 3px breit in `#EE7600` (SR-Homes Orange)
- Icon-Box (40×40, rounded-lg, bg `#fff7ed`, Sun-Icon in `#EE7600`)
- Titel "Tagesbriefing" + `Badge` mit Text "KI" (variant secondary)
- Datum rechts in `text-muted-foreground` ("Dienstag, 21. April 2026")
- 1-Zeilen-Preview-Text in `text-sm text-muted-foreground`, darin der Top-Highlight-Satz aus der Narrative
- Beschwerde/Dringlich-Erwähnungen in Preview bekommen `.text-red-700 font-medium`
- Button rechts: `Button variant="default"` mit Text "Vollständig lesen →"
- Wenn kein Briefing für heute existiert: Preview-Text zeigt "Briefing wird generiert…" mit kleinem Spinner

**Layout-Datei:** `resources/js/Components/Admin/TodayTab.vue` — neue Sektion direkt unter `<template>`-root-div, vor Section 1 "Action Card".

## UI — Sheet-Content

**Container:** shadcn `Sheet` mit `SheetContent side="right" class="w-full sm:max-w-2xl"`.

**Header:** `SheetHeader` mit Title "Tagesbriefing · {weekday}, {date}" + Description "Zusammenfassung der letzten 24h & heute anstehend". Schließ-X rechts (Sheet-integriert).

**Body:** Scrollbar, padding `p-6`, 4 Sektionen mit `space-y-6` Abstand.

### Section 1: Gestern in 3 Sätzen

- Section-Head: kleine Uppercase-Label "Gestern in 3 Sätzen"
- Content: muted-background Box (`bg-muted p-4 rounded-lg`), Text ~100-150 Wörter
- Wichtige Elemente mit `<strong>` (Zahlen, Objektnamen, Beträge)
- Beschwerden/Alarm-Signale in `<mark class="bg-red-100 text-red-900 font-medium">`

### Section 2: Aktive Threads mit Kontext

- Section-Head: "Aktive Threads mit Kontext" + rechts `Badge variant="outline"` mit Count
- Liste mit `divide-y divide-border/60`
- Pro Thread: Name (bold) + farbiger Dot + optional Status-Badge + Chevron rechts
- Trail darunter: kleinschriftiger Text mit `→`-Separatoren, letzter Eintrag bold+dunkel ("cur")
- Hover: `bg-accent/40`, cursor-pointer
- Klick: `router.visit('/admin#inbox?conv=' + conv_id)` bzw. direkter Tab-Switch mit selected-conv-id im Store

**Dot-Farben (semantisch):**
- Rot: wartet >2 Tage auf Antwort ODER Beschwerde erkannt
- Orange: wartet 1-2 Tage / mittlere Priorität
- Gelb: Kaufanbot offen
- Grün: Eigentümer-Kommunikation

**Thread-Auswahl-Logik (max 8 im Frontend angezeigt, 20 im Backend geladen):**
- Backend-SQL lädt bis zu 20 Kandidaten mit Inbound in den letzten 5 Tagen
- KI erhält alle 20 und annotiert jede mit Priorität + Label
- Frontend zeigt die Top 8 nach Priorität rot > orange > gelb > grün, innerhalb: nach `last_inbound_at DESC`
- Thread mit `match_dismissed=true` oder `status=erledigt` werden bereits im SQL ausgeschlossen
- Wenn weniger als 20 qualifizierende Threads existieren → alle werden angezeigt (bis max 8)

### Section 3: Anstehend heute

- Section-Head: "Anstehend heute"
- Zwei Gruppen getrennt durch Separator:
  - **Termine** (mit Uhrzeit in orange, width 56px)
    - Besichtigungen heute (aus `viewings` Tabelle)
    - Tasks mit `due_date = today`
  - **Offen** (mit Kategorie-Label "offen/laufend/fällig")
    - Unbeantwortete Anfragen >24h
    - Nachfass-Batch-Status von gestern + Antwort-Quote
    - Offene Tasks ohne fixes Datum

### Section 4: Auffälligkeiten

- Section-Head: "Auffälligkeiten"
- Farb-kodierte Boxen (`rounded-lg p-3 border`), nur angezeigt wenn KI Anomalie findet:
  - **🔥 Rot** (`bg-red-50 border-red-200 text-red-900`): Hot Property — Link-Sessions oder Anfragen sprunghaft über Baseline
  - **📉 Blau** (`bg-blue-50 border-blue-200 text-blue-900`): Cooling Property — Anfragen-Rückgang >50% in 2 Wochen
  - **⚠️ Amber** (`bg-amber-50 border-amber-200 text-amber-900`): Eigentümer-Unmut, Cluster-Signale aus Nachrichten

Wenn keine Auffälligkeiten → Section ganz ausblenden.

## Daten-Input (an KI & Template-Logik)

Eingesammelt von Backend-Service `DailyBriefingService::gatherContext()`:

### 1. Activities letzte 24h
```sql
SELECT activity, category, stakeholder, property_id, activity_date
FROM activities
WHERE activity_date >= NOW() - INTERVAL 24 HOUR
  AND broker_filter_applied
ORDER BY activity_date DESC
```
Filter: `link_opened` nur wenn >5/Tag für ein Property (sonst Rauschen).

### 2. Active Conversations (letzte 5 Tage)
```sql
SELECT c.id, c.stakeholder, c.contact_email, c.property_id, c.status,
       c.last_inbound_at, c.last_outbound_at, c.inbound_count, c.outbound_count,
       p.ref_id, p.address
FROM conversations c LEFT JOIN properties p ON c.property_id = p.id
WHERE c.last_inbound_at >= NOW() - INTERVAL 5 DAY
  AND c.status NOT IN ('erledigt')
  AND c.match_dismissed = 0
  AND broker_scope_applied
ORDER BY c.last_inbound_at DESC
LIMIT 20
```

Pro Conversation werden zusätzlich die **letzten 3 Message-Subjects** aus `portal_emails` geladen (mit Direction inbound/outbound + timestamp) für den Trail.

### 3. Tasks
```sql
SELECT title, description, priority, due_date, property_id
FROM tasks WHERE is_done = 0
  AND (due_date <= CURDATE() OR due_date IS NULL)
  AND assigned_to = :broker_id
```

### 4. Viewings heute
```sql
SELECT viewing_time, person_name, property_id, notes
FROM viewings
WHERE viewing_date = CURDATE()
  AND status != 'storniert'
  AND property.broker_id = :broker_id
```

### 5. Property-Signale (für Anomalien)
- Link-Sessions pro Property letzte 24h vs. 7-Tages-Median
- Anfragen-Count pro Property letzte 14 Tage vs. vorherige 14 Tage
- Owner-Nachrichten-Count pro Property letzte 3 Tage (stakeholder matching owner pattern)

### 6. Nachfass-Batch Outcomes
```sql
SELECT
  COUNT(*) as sent_count,
  SUM(CASE WHEN c.last_inbound_at > a.activity_date THEN 1 ELSE 0 END) as replied_count
FROM activities a LEFT JOIN conversations c ON c.id = a.conversation_id
WHERE a.category = 'nachfassen' AND a.activity_date >= NOW() - INTERVAL 48 HOUR
```

## KI-Prompt-Design

**Model:** `gpt-4o-mini` mit `response_format: {type: "json_object"}`

**System-Prompt:**
> Du bist ein Assistenz-System für einen Immobilienmakler. Fasse den gestrigen Tag in 3 Sätzen zusammen und erkenne Muster. Bleib faktisch, keine Floskeln. Beschwerde-Signale in Kundennachrichten immer markieren.

**User-Prompt-Struktur (JSON):**
```json
{
  "date": "2026-04-21",
  "broker": "Maximilian",
  "activities_24h": [...],
  "active_threads": [...],
  "property_signals": [...],
  "nachfass_outcome": {"sent": 89, "replied": 12}
}
```

**Expected Output (JSON):**
```json
{
  "preview": "Eine Zeile für die Card-Vorschau (max 180 Zeichen)",
  "narrative": "100-150 Wörter für Block A, HTML mit <strong>, <mark>",
  "anomalies": [
    {"kind": "hot|cool|warn", "property_ref": "KAU-XX-204", "text": "..."}
  ],
  "thread_annotations": {
    "<conversation_id>": {"priority": "red|orange|yellow|green", "label": "Beschwerde 2×|wartet 3 Tage|…"}
  }
}
```

**Validierung:**
- Wenn KI-Response nicht valides JSON → Fallback auf deterministische Template-Zusammenfassung (zähle Activities, listet Properties, ohne Narrative)
- Wenn narrative länger als 300 Wörter → abschneiden bei Satzende
- Anomalien max 3, wenn mehr → top 3 nach Severity

## Backend-Architektur

**Neue Files:**

- `app/Services/DailyBriefingService.php` — Haupt-Service
  - `generate(int $userId, ?string $date = null): array` — vollständige Generierung
  - `gatherContext(int $userId, ?string $date): array` — Daten sammeln
  - `callAi(array $context): array` — LLM-Call mit Retry + Validierung
  - `fallbackTemplate(array $context): array` — deterministische Zusammenfassung ohne KI
  - `saveToCache(int $userId, string $date, array $data): void`
  - `loadFromCache(int $userId, string $date): ?array`

- `app/Http/Controllers/Admin/BriefingController.php` — HTTP-Endpoints
  - `GET /admin/api/briefing?date=...` — lädt aktuelles Briefing (cache-hit or on-demand)
  - `POST /admin/api/briefing/regenerate` — manueller Refresh (rate-limited 1/Min via `throttle` middleware)

- `app/Console/Commands/GenerateDailyBriefings.php` — Scheduled Command
  - Läuft täglich 06:30 Europe/Vienna
  - Iteriert über alle aktiven `users` mit `user_type IN ('admin', 'makler', 'assistenz', 'backoffice')` und mindestens einer Aktivität letzte 7 Tage (Assistenz/Backoffice bekommen Admin-scope Briefing)
  - Ruft `DailyBriefingService::generate()` pro User auf
  - Loggt Erfolge/Fehler nach `storage/logs/briefing.log`

- `app/Models/DailyBriefing.php` — Eloquent-Model für neue Tabelle

**Modifizierte Files:**

- `app/Console/Kernel.php` bzw. `bootstrap/app.php` — Scheduled Task registrieren:
  ```php
  $schedule->command('briefing:generate-daily')
           ->dailyAt('06:30')
           ->timezone('Europe/Vienna');
  ```

- `routes/web.php` oder `routes/admin.php` — neue Routes für Briefing-Controller

## DB-Schema

Neue Migration: `2026_04_21_120000_create_daily_briefings_table.php`

```php
Schema::create('daily_briefings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->date('briefing_date');
    $table->longText('data');                  // JSON: {preview, narrative, threads, agenda, anomalies}
    $table->string('model_used')->nullable();  // gpt-4o-mini / fallback
    $table->timestamp('generated_at');
    $table->timestamps();

    $table->unique(['user_id', 'briefing_date']);
    $table->index('briefing_date');
});
```

## Broker-Scoping & Security

- Alle Context-Queries nutzen dieselbe Logik wie `Conversation::scopeForBroker`:
  - **Admin + Assistenz/Backoffice**: sehen alles vom eigenen Portfolio
  - **Makler**: nur eigene Properties (`broker_id = user_id`)
- Scheduled Command erzeugt Briefings pro User — kein Shared-Content
- Controller prüft `Auth::check()` + `Auth::id()` gegen `briefing.user_id`
- Fallback auf empty result wenn kein `Auth::id()` (kein Datenleck wie bei historischem Session-Bug)

## Frontend-Architektur

**Neue Files:**

- `resources/js/Components/Admin/TagesbriefingCard.vue` — Einstiegspunkt-Card (preview + button)
- `resources/js/Components/Admin/TagesbriefingSheet.vue` — Sheet-Content mit 4 Sektionen
- `resources/js/Components/Admin/TagesbriefingThread.vue` — einzelne Thread-Row mit Trail

**Modifizierte Files:**

- `resources/js/Components/Admin/TodayTab.vue` — oben einfügen `<TagesbriefingCard>` und `<TagesbriefingSheet v-model:open="briefingOpen">`

**State:**
- `briefingOpen: ref(false)` zum Öffnen/Schließen des Sheets
- `briefingData: ref(null)` geladen von API beim Mount
- `briefingLoading: ref(true)` während initialem Load
- Regenerate-Button setzt `briefingLoading=true`, POST /regenerate, reload

## Fallback-Verhalten

**Wenn <3 Activities aller Kategorien zusammen in den letzten 24h:**
- Zählung ohne `link_opened` (Rauschen ausschließen)
- KI-Call wird übersprungen, Template liefert Preview + Narrative mit "Ruhiger Tag — keine besonderen Vorkommnisse"
- Block A zeigt die Zeile, Blöcke B-D werden normal befüllt (aktive Threads und heute-anstehend können trotzdem relevant sein)

**Wenn KI-Call fehlschlägt:**
- Deterministisches Template-Fallback: zählt Activities nach Kategorie, listet Top 3 Properties mit meisten Activities, keine Tonalitäts-Erkennung
- Block D (Auffälligkeiten) bleibt leer
- Card zeigt kleinen Warnings-Icon neben "KI"-Badge

**Wenn keine aktiven Threads:**
- Block B wird komplett ausgeblendet

**Wenn nichts heute ansteht:**
- Block C zeigt "Keine Termine geplant"

## Integration mit bestehendem System

- **Keine** Änderungen an bestehender `TodayTab.vue`-Logik (Action-Card, KPIs, Charts bleiben unverändert)
- **Keine** Änderungen an `Conversation`-Modell oder anderen bestehenden Tabellen
- Neue API-Routes nutzen bestehendes `api.key` Auth-Middleware und `scopeForBroker`-Pattern
- Thread-Klick in Sheet → emit `open-conversation` Event mit `conv_id` → `Dashboard.vue` verarbeitet via bereits existierender `switchTab('inbox')` und setzt `InboxTab`-Prop `selectedConversationId` (selbes Pattern wie bestehender Notification-Click in Dashboard.vue Zeile 342)

## Testing

**Backend (PHPUnit):**
- `DailyBriefingServiceTest`:
  - `generate_returns_cached_briefing_if_exists`
  - `generate_calls_ai_when_no_cache`
  - `gatherContext_respects_broker_scope_for_makler`
  - `gatherContext_sees_all_for_admin`
  - `gatherContext_returns_empty_without_auth_id` (security regression)
  - `fallbackTemplate_produces_valid_structure_without_ai`
  - `callAi_handles_invalid_json_response_gracefully`
- `BriefingControllerTest`:
  - `get_briefing_returns_404_for_other_users`
  - `regenerate_rate_limited_to_1_per_minute`

**Frontend (Vitest + component tests):**
- `TagesbriefingCard.test.js`: rendert preview, button click öffnet sheet
- `TagesbriefingSheet.test.js`: rendert 4 sections, hides empty sections
- `TagesbriefingThread.test.js`: trail renders last entry as current

**Manual QA-Checkliste:**
- [ ] Card oberhalb "Guten Morgen" sichtbar
- [ ] Sheet öffnet von rechts, schließt mit ESC
- [ ] Thread-Klick springt in Inbox zum richtigen Thread
- [ ] Regenerate-Button erzeugt neues Briefing
- [ ] Nach Logout/Login sieht Makler X nicht Briefing von Makler Y
- [ ] Bei leerem Tag wird Block B/D ausgeblendet
- [ ] Bei KI-Ausfall erscheint Fallback-Template ohne Crash

## Edge Cases

- **Erster Login eines neuen Brokers (keine Daten)**: Briefing zeigt "Willkommen — sobald erste Aktivitäten da sind, erscheint hier dein Tagesüberblick"
- **Admin-User mit mehreren Makler-Accounts**: Sieht ein Briefing pro Account — aber im Dashboard nur sein eigenes (basierend auf `Auth::id()`)
- **Extrem stiller Tag (0 Activities)**: Ruhige-Tag-Version wird angezeigt, keine KI-Generierung, statische Template-Nachricht
- **Briefing wurde 06:30 generiert, User macht 10:00 wichtigen Deal**: Manuell über Regenerate-Button aktualisierbar, sonst erscheint erst morgen 06:30 das neue
- **Sehr lange Narrative von KI**: Abschneiden bei 300 Wörtern am Satzende
- **Mehrere aktive Threads zum selben Kunden**: Werden zu einer Zeile zusammengefasst (Gruppierung nach contact_email + property_id)
- **Dark Mode**: Sheet und Card nutzen bestehende `--card`, `--muted` etc. Tokens die sich automatisch umschalten. Semantische Farben (rot/amber/grün für Dots und Anomaly-Boxes) verwenden Tailwind-Paare (`dark:bg-red-950/20 dark:border-red-800/40 dark:text-red-200`)

## Out of Scope (v1)

- Push-Notification wenn Briefing fertig ist
- Export als PDF/Email
- Historien-View (gestriges Briefing nachlesen)
- Konfiguration der Sektionen (Blöcke ein/ausschalten)
- Mehrsprachigkeit (DE-only in v1)
- Mobile-optimierte Sheet-Darstellung (funktioniert auf mobile, aber nicht optimiert)

Diese können in späteren Versionen ergänzt werden.

## Erfolgskriterien

- Makler öffnet Briefing mindestens 1× pro Werktag (Telemetrie via Activity-Log)
- Thread-Klickrate aus Briefing heraus >30% (Makler nutzen es als Navigation)
- KI-generiertes Briefing erkennt 80%+ der tatsächlichen Beschwerden korrekt als rot markiert
- Kosten pro Broker/Monat unter 1€
- Zero Cross-Broker Data-Leaks (geprüft durch Security-Tests)
