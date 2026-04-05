# AI Cross-Match System

Property-Matching-Feature das bei eingehenden Anfragen/Absagen automatisch andere passende Immobilien vorschlaegt. Integrated in den Anfragen-Tab des SR-Homes Portals.

## Ueberblick

Wenn ein Kunde eine Anfrage oder Absage sendet, analysiert die KI die Nachricht und matcht den Kunden gegen alle aktiven Objekte des Maklers. Passende Objekte werden im Anfragen-Tab visuell hervorgehoben (animierter Gradient-Border). Beim Klick auf die Konversation oeffnet sich ein Full-Screen Property-Matching-View statt dem normalen Nachrichten-Screen.

Der Makler waehlt Objekte aus, klickt "Entwurf generieren" → KI erstellt einen Draft der alle ausgewaehlten Objekte erwaehnt + haengt die Exposes an → normaler Nachrichten-Screen erscheint mit Draft.

## User Flow

1. Neue Email kommt rein → IMAP-Sync verarbeitet sie
2. Background-Job: KI analysiert Nachricht → extrahiert Suchkriterien → matcht gegen Properties → speichert Matches in DB
3. Makler oeffnet Anfragen-Tab → Konversation mit Match hat animierten lila-cyan Gradient-Border + "✦ N Matches" Badge
4. Makler klickt drauf → Full-Screen Property Matching View (ersetzt Nachrichten-Bereich komplett):
   - Header: "Property Matching" + "Ueberspringen" Button
   - Kundenkontext: Name + extrahierte Suchkriterien als Pills
   - Property-Cards im Grid: Bild, Titel, Adresse, Preis/m²/Zimmer, Match-Score, Match-Grund
   - Cards klickbar (Checkbox oben rechts) zum Aus-/Abwaehlen
   - Bottom-Bar: Zaehler + ausgewaehlte Pills + "Entwurf generieren" Button
5. Makler waehlt Objekte aus → klickt "Entwurf generieren"
6. System generiert KI-Draft der alle gewaehlten Objekte erwaehnt + haengt Expose-PDFs an
7. Matching-Screen verschwindet → normaler Nachrichten-Screen mit Draft + Anhaenge erscheint
8. Auf jeder ausgewaehlten Property wird eine Aktivitaet erstellt (Kategorie: expose, Stakeholder, Cross-Match Referenz)
9. "Ueberspringen" → Match wird dismissed, kommt nicht wieder fuer diese Konversation

## Intelligentes Triggering

Nicht jede Nachricht loest Matching aus. Die KI bewertet jede eingehende Email mit einem `cross_match_intent`:

| Intent | Bedeutung | Beispiel | Mindest-Score |
|--------|-----------|----------|---------------|
| high | Absage mit Kriterien, explizite Suchwuensche | "Passt nicht, suche ab 100m² im Sueden" | 60% |
| medium | Erwaehnt Praeferenzen, Objekt reserviert/verkauft | "Fuer Familie", Property status=verkauft | 80% |
| low | Standard-Erstanfrage ohne besondere Signale | "Bitte Expose senden" | 90% |
| none | Interne Mail, Spam, Eigentuemer-Kommunikation | Eigentuemer-Email, Systemmail | kein Matching |

Zusaetzliche Regeln:
- Einmal "Ueberspringen" geklickt → Match fuer diese Konversation dismissed, kommt nicht wieder
- Keine Matches innerhalb desselben Neubauprojekts (The37-Wohnung → nicht andere The37-Wohnungen vorschlagen)
- Nur Properties mit `realty_status IN ('auftrag', 'inserat')` und `broker_id` des Maklers
- Maximum 5 Matches pro Konversation (sortiert nach Score)

## Matching-Logik

### Suchkriterien-Extraktion (KI)

Die KI extrahiert aus der Kundennachricht + vorherigem Thread-Kontext:

```json
{
  "cross_match_intent": "high",
  "criteria": {
    "object_types": ["Einfamilienhaus", "Zweifamilienhaus"],
    "min_area": 100,
    "max_price": 1200000,
    "locations": ["Salzburg-Sued", "Groedig", "Hallein", "Kuchl"],
    "features": ["Ausbaupotential", "Garten"],
    "household": "4-koepfige Familie"
  },
  "reason": "Absage mit konkreten Suchkriterien"
}
```

### Score-Berechnung

Gewichteter Score basierend auf Kriterien-Uebereinstimmung:

- object_type Match: 30%
- Lage/Region Match: 25%
- Preis innerhalb Range: 20%
- Flaeche >= Minimum: 15%
- Features Match: 10%

Score wird als Prozentwert gespeichert. KI liefert zusaetzlich eine natuerlichsprachliche Match-Begruendung pro Property.

### Ausschluss

- Die Property der aktuellen Konversation (bereits angefragt)
- Properties im selben `project_group_id` (selbes Neubauprojekt)
- Properties mit `realty_status` ausserhalb von auftrag/inserat

## Datenmodell

### Neue Tabelle: `property_matches`

```sql
CREATE TABLE property_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    score INT NOT NULL DEFAULT 0,           -- 0-100
    match_reason TEXT,                       -- KI-generierte Begruendung
    criteria_json JSON,                      -- extrahierte Suchkriterien
    cross_match_intent VARCHAR(20),          -- high/medium/low
    status ENUM('pending','selected','sent','dismissed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conv (conversation_id),
    INDEX idx_conv_status (conversation_id, status),
    UNIQUE KEY uq_conv_prop (conversation_id, property_id)
);
```

### Conversations-Tabelle Erweiterung

```sql
ALTER TABLE conversations
    ADD COLUMN match_count INT DEFAULT 0,
    ADD COLUMN match_dismissed BOOLEAN DEFAULT FALSE;
```

## Backend-Architektur

### 1. PropertyMatcherService (NEU)

`app/Services/PropertyMatcherService.php`

Verantwortlichkeiten:
- `analyzeAndMatch(Conversation $conv, PortalEmail $email): void` — Hauptmethode, aufgerufen als Background-Job
- `extractCriteria(string $threadContext): array` — KI-Call zur Kriterien-Extraktion + Intent-Bestimmung
- `findMatches(array $criteria, int $excludePropertyId, int $brokerId): Collection` — Score-Berechnung gegen aktive Properties
- `getMatchesForConversation(int $convId): Collection` — Liest gespeicherte Matches

Ablauf in `analyzeAndMatch`:
1. Thread-Kontext laden (letzte 3 Nachrichten)
2. `extractCriteria()` via AnthropicService (Haiku, ~300 Tokens)
3. Wenn intent == "none" → return
4. `findMatches()` → Score berechnen, filtern nach Mindest-Score
5. Matches in `property_matches` speichern
6. `conversations.match_count` updaten

### 2. Job: ProcessPropertyMatching

`app/Jobs/ProcessPropertyMatching.php`

- Dispatched nach Email-Verarbeitung im IMAP-Sync (nach ConversationService::updateFromEmail)
- Queue: `default` (selbe Queue wie andere Jobs)
- Ruft `PropertyMatcherService::analyzeAndMatch()` auf

### 3. API-Endpoints (AdminApiController Actions)

- `match_list` → PropertyMatcherService->getMatchesForConversation($convId) — liefert Matches + Kriterien
- `match_select` → Setzt Status auf 'selected' fuer gewaehlte Properties
- `match_dismiss` → Setzt conversation.match_dismissed = true, loescht pending Matches
- `match_generate_draft` → Generiert KI-Draft mit ausgewaehlten Matches + haengt Exposes an
- `match_send` → Sendet Email mit Draft + Expose-Anhaenge, erstellt Aktivitaet auf jeder Property

### 4. Draft-Generierung mit Matches

Erweiterung von `AnthropicService::generateFollowupDraft()`:
- Neuer Parameter: `$matchedProperties` (Array von Property-Daten)
- System-Prompt erhaelt Anweisung: "Der Kunde hat folgende Objekte vorgeschlagen bekommen: [...]  Erwaehne sie im Antwort-Entwurf als natuerliche Empfehlung."
- Draft formatiert die Objekte als hervorgehobenen Block

### 5. Aktivitaeten-Erstellung

Wenn Matches gesendet werden, fuer JEDE ausgewaehlte Property:
```php
Activity::create([
    'property_id' => $matchedPropertyId,
    'stakeholder' => $conversation->stakeholder,
    'category'    => 'expose',
    'activity'    => "Cross-Match von {$originalProperty->ref_id} — Exposé gesendet",
    'activity_date' => now(),
    'source_email_id' => $sentEmailId,
]);
```

Damit erscheint der Lead in der Timeline jeder Immobilie und Nachfassen greift automatisch.

## Frontend-Architektur

### Neue Vue-Komponenten

#### `InboxMatchView.vue`
Full-Screen Property-Matching View (ersetzt Nachrichten-Bereich):
- Header mit AI-Icon, Titel, "Ueberspringen" Button
- Kundenkontext-Bar mit extrahierten Kriterien-Pills
- Property-Cards Grid (shadcn Card-Komponenten)
- Bottom-Bar mit Zaehler + "Entwurf generieren"

#### `InboxMatchCard.vue`
Einzelne Property-Card im Match-Grid:
- Property-Bild (main_image oder Placeholder)
- Titel, Adresse, Preis/m²/Zimmer Stats
- Match-Score Badge (gruen >=80%, gelb >=60%)
- Match-Begruendung in subtle lila Box
- Klickbar mit Checkbox-Toggle (selected State = lila Border + Shadow)

### Aenderungen an bestehenden Komponenten

#### `InboxConversationItem.vue`
- Wenn `item.match_count > 0 && !item.match_dismissed`: animierter Gradient-Border Wrapper
- Neues Badge: `✦ N Matches` mit AI-Gradient Hintergrund

#### `InboxTab.vue`
- Neuer State: `matchMode` (Boolean) — wenn true, zeigt InboxMatchView statt InboxChatView
- Wenn Konversation mit Matches geklickt wird: `matchMode = true`
- Nach "Entwurf generieren" oder "Ueberspringen": `matchMode = false`, normaler Chat-View

#### `InboxAiDraft.vue`
- Neues Badge "inkl. N Objekte" wenn Draft Match-Empfehlungen enthaelt
- Expose-Attachments werden automatisch angezeigt

### Design-Tokens

Animierter Gradient-Border (Conversation-Item):
```css
background: linear-gradient(270deg, hsl(263 70% 58%), hsl(187 72% 53%), hsl(292 84% 60%), hsl(263 70% 58%));
background-size: 600% 600%;
animation: aiGlow 4s ease infinite;
```

Match-Badge: `background: linear-gradient(135deg, hsl(263 70% 58%), hsl(187 72% 53%))`

Selected-Card: `border-color: hsl(263 70% 58%); box-shadow: 0 0 0 1px hsl(263 70% 58%), 0 4px 16px hsl(263 70% 58% / 0.1)`

Alle UI-Elemente nutzen bestehende shadcn-Komponenten (Card, Badge, Button, Avatar) mit Inter Font.

## Matches Tab

Neuer Pill-Tab "Matches" neben Anfragen/Nachfassen/Alle:
- Zeigt alle Konversationen die aktive (nicht-dismissed) Matches haben
- Sortiert nach Match-Score (hoechster zuerst)
- Badge mit Gesamtzahl aktiver Matches
- Klick oeffnet direkt den Property-Matching-View

## Reihenfolge der Implementierung

1. DB-Migration (property_matches Tabelle + conversations Erweiterung)
2. PropertyMatcherService (Kriterien-Extraktion, Scoring, Match-Logik)
3. ProcessPropertyMatching Job + Integration in IMAP-Sync
4. API-Endpoints (match_list, match_select, match_dismiss, match_generate_draft, match_send)
5. InboxMatchCard.vue + InboxMatchView.vue (Frontend-Komponenten)
6. InboxConversationItem.vue (animierter Border + Badge)
7. InboxTab.vue (matchMode State, Matches Tab)
8. InboxAiDraft.vue (Match-Draft Anpassungen)
9. Aktivitaeten-Erstellung bei Send
10. Test mit Obernitz-Case (Absage → Groedig Match)
