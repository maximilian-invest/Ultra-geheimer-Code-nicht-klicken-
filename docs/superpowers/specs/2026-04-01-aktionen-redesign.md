# Aktionen-Seite (PrioritiesTab) Redesign

## Zusammenfassung

Komplettes Frontend-Redesign der Aktionen-Seite (`PrioritiesTab.vue`). Die aktuelle Implementierung hat 7 Sub-Tabs und ~3800 Zeilen Code. Das Redesign reduziert auf 2 Tabs (Offen + Nachfassen) und nutzt das shadcn Mail-Example Pattern für eine aufgeräumte, fokussierte UX.

**Ziel:** Von 7 überladenen Tabs auf 2 essentielle Views reduzieren. shadcn-vue Komponenten durchgehend einsetzen. Detail-Ansicht als Sheet (Side-Panel) statt Inline-Expand.

## Was entfällt

| Tab | Grund |
|-----|-------|
| Hinweise (insights) | Wird nie genutzt |
| Matches (matching) | Funktioniert nicht, schlecht platziert |
| Auto-Reply Settings (auto) | Wird klappbarer Banner statt Tab |
| Angebote (angebote) | Falsche Stelle (gehört woanders hin) |
| Pause (onhold) | Wird nie genutzt |

## Architektur

### Gesamtstruktur

```
┌─────────────────────────────────────────────────────────┐
│ [Offen (5)]  [Nachfassen (8)]          ← shadcn Tabs    │
├─────────────────────────────────────────────────────────┤
│ ✉ 3 automatische Antworten heute       ← Auto-Reply Ban │
├─────────────────────────────────────────────────────────┤
│ [🔍 Suche...]  [Objekt ▾]  [Kategorie ▾]  ← Toolbar    │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  MH  Maria Huber          Dringend        vor 2 Std.   │
│      Anfrage zu Wohnung Top 3 — Meidling               │
│      willhaben · Meidling 23                            │
│  ─────────────────────────────────────────────────────  │
│  TB  Thomas Berger                       vor 5 Std.    │
│      Interesse Dachgeschoss — Penzing                   │
│      immowelt · Penzing 7                               │
│  ─────────────────────────────────────────────────────  │
│  AS  Anna Schmidt          Fällig         vor 1 Tag    │
│      Besichtigungstermin anfragen                       │
│      E-Mail · Meidling 23                               │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Detail-Sheet (öffnet von rechts, ~60% Breite)

```
┌──────────────────────────────────────┐
│  MH  Maria Huber                  ✕  │
│       maria.huber@gmail.com          │
│       willhaben · Meidling 23 · Dri  │
├──────────────────────────────────────┤
│  ✉ Eingehende Nachricht         ▲   │
│  ┌────────────────────────────────┐  │
│  │ Sehr geehrte Damen und Herren,│  │
│  │ ich interessiere mich für...  │  │
│  └────────────────────────────────┘  │
├──────────────────────────────────────┤
│  🕐 Verlauf (2)                  ▼   │
├──────────────────────────────────────┤
│  🤖 KI-Entwurf      Von/An/Betr. ▼  │
│  ┌────────────────────────────────┐  │
│  │ Sehr geehrte Frau Huber,     │  │
│  │ vielen Dank für Ihr...        │  │
│  └────────────────────────────────┘  │
│                                      │
│  📎 2 Dateien  Standard▾  📅         │
│                    ☑ Erledigt  Senden │
└──────────────────────────────────────┘
```

## Komponenten

### shadcn-vue Komponenten (zu verwenden)

- `Tabs`, `TabsList`, `TabsTrigger`, `TabsContent` — Offen/Nachfassen Tabs
- `Input` — Suchfeld
- `Select`, `SelectTrigger`, `SelectValue`, `SelectContent`, `SelectItem` — Filter-Dropdowns
- `Sheet`, `SheetContent`, `SheetHeader`, `SheetTitle` — Detail-Panel
- `Badge` — Priority-Badges (Dringend, Fällig), Plattform/Objekt-Tags
- `Button` — Toolbar-Actions, Senden
- `ScrollArea` — Liste + Sheet Body scrollbar
- `Collapsible`, `CollapsibleTrigger`, `CollapsibleContent` — Eingehende Nachricht, Verlauf
- `Textarea` — KI-Draft Editor
- `Avatar`, `AvatarFallback` — Initialen-Avatar
- `Separator` — Trenner

### Neue Komponenten (NICHT nötig)

Keine neuen Komponenten erstellen. Alles mit bestehenden shadcn Primitives abbilden.

## Datenfluss

### API-Calls (bleiben unverändert)

| Action | Zweck |
|--------|-------|
| `getUnansweredCount` | Anzahl offene Anfragen für Tab-Badge |
| `getFollowupCount` | Anzahl Follow-ups für Tab-Badge |
| `unanswered` | Liste offener Anfragen laden |
| `followups` | Liste Follow-up Items laden |
| `email_context` | Thread + Original-Mail für Sheet laden |
| `ai_reply` | KI-Draft generieren |
| `send_email` | E-Mail senden |
| `mark_handled` | Als erledigt markieren |
| `get_property_files` | Anhänge für Property laden |
| `get_auto_reply_log` | Auto-Reply Log für Banner |

### State Management

```
// Tabs
activeTab: 'offen' | 'nachfassen'

// Listen
unansweredItems: ref([])
followupItems: ref([])
loading: ref(false)

// Filter
searchQuery: ref('')
objectFilter: ref('all')
categoryFilter: ref('all')

// Sheet
selectedItem: ref(null)      // öffnet Sheet wenn gesetzt
sheetOpen: ref(false)

// Sheet Detail
expandedDetail: ref(null)    // email_context Response
expandedAiDraft: ref(null)   // AI draft
expandedFiles: ref([])       // Property files
selectedFiles: ref([])       // Ausgewählte Anhänge
aiDetailLevel: ref('standard')

// Auto-Reply Banner
autoReplyLog: ref([])
autoReplyBannerOpen: ref(false)
```

## Listen-Items

### Offen Tab
Jedes Item zeigt:
- **Avatar**: Initialen (erste Buchstaben Vor+Nachname), farbig basierend auf Priorität
- **Name**: `item.from_name` oder `item.stakeholder`
- **Priority Badge**: "Dringend" (rot) wenn `item.priority === 'high'`, "Fällig" (orange) wenn Follow-up fällig
- **Betreff**: `item.subject` (einzeilig, truncated)
- **Preview**: `item.ai_summary` oder `item.body` (einzeilig, line-clamp-1)
- **Tags**: Plattform (`item.platform`), Objekt (`item.property_title`)
- **Zeit**: Relative Zeitangabe (`item.received_at`)

### Nachfassen Tab
Gleiches Layout, aber:
- Items sind Follow-ups die eine Nachfass-Aktion brauchen
- Badge zeigt "Fällig" wenn Termin überschritten
- Zusätzlich: Anzahl Tage seit letztem Kontakt

### Selektion
- Klick auf Item: `selectedItem = item`, `sheetOpen = true`
- Selektiertes Item: `bg-orange-50 border border-orange-200`
- Nicht selektiert: `hover:bg-muted/50`

## Sheet Detail-Panel

### Header
- Avatar (32px) + Name + E-Mail
- Tags: Plattform, Objekt, Priority
- Close-Button (X)
- Relative Zeitangabe

### Eingehende Nachricht (Collapsible, default offen)
- Icon: Mail
- Betreff fett
- Body in `bg-muted` Container mit `rounded-lg`
- Max-height 300px mit Scroll

### Verlauf (Collapsible, default geschlossen)
- Icon: Clock
- Badge mit Anzahl Thread-Messages
- Aufgeklappt: Messages mit Richtungs-Indikator (↑ ausgehend blau, ↓ eingehend grau)
- Jede Message: Sender, Zeitstempel, aufklappbarer Body

### KI-Entwurf
- Header: "KI-Entwurf" + Toggle für E-Mail-Felder (Von/An/Betr.)
- E-Mail-Felder (klappbar): Von-Dropdown, An-Input (editierbar), Betreff-Input
- Textarea: Min-height 160px, resizable, gebunden an `expandedAiDraft.body`
- Loading-State: Spinner + "KI-Entwurf wird generiert..."

### Toolbar (unter dem Draft)
- **Links**: Anhänge-Button (Paperclip + Anzahl), Detailgrad-Select (Knapp/Standard/Ausführlich), Kalender-Button
- **Rechts**: Erledigt-Button (outline), Senden-Button (orange gradient, prominent)

## Styling

### Farben
- Akzent: Orange (`--chart-1: 12 76% 61%`, Tailwind `orange-500/600`)
- Selektiertes Item: `bg-orange-50`, `border-orange-200`
- Dringend Badge: `bg-red-50 text-red-600`
- Fällig Badge: `bg-orange-50 text-orange-600`
- Auto-Reply Banner: `bg-green-50 border-green-200 text-green-800`
- Senden Button: `bg-gradient-to-r from-orange-500 to-orange-600 text-white`

### Responsive
- Sheet: Auf Desktop ~60% Breite, auf Mobile Fullscreen
- Liste: Scrollbar via `ScrollArea`
- Toolbar: Wrap auf kleinen Screens

## Datei-Struktur

### Geänderte Datei
- `resources/js/Components/Admin/PrioritiesTab.vue` — **Kompletter Rewrite**

### Keine neuen Dateien
Alles in einer Datei (wie bisher), da die Komponente nur innerhalb von Dashboard.vue verwendet wird.

### Zu installierende shadcn Komponenten (fehlen noch)
- `Collapsible` — FEHLT, installieren
- `Textarea` — FEHLT, installieren  
- `Sheet` — FEHLT, installieren
- `Tabs` — FEHLT, installieren
- `ScrollArea` — FEHLT, installieren

## Verifizierung

1. `npm run build` erfolgreich
2. Offen-Tab zeigt unbeantwortete Anfragen mit korrekter Anzahl
3. Nachfassen-Tab zeigt Follow-up Items
4. Suche filtert nach Name/Betreff
5. Objekt/Kategorie Filter funktionieren
6. Klick auf Item öffnet Sheet mit korrekten Details
7. KI-Draft wird generiert und ist editierbar
8. Senden funktioniert (E-Mail wird verschickt)
9. Erledigt markiert Item und entfernt es aus der Liste
10. Auto-Reply Banner zeigt Log
11. Keine Console-Errors
12. Responsive auf Mobile
