# Objektansicht (PropertyDetailView) Redesign

## Zusammenfassung

Die aktuelle Objektansicht besteht aus 8-12 Kacheln, die jeweils ein Popup öffnen. Jeder Klick reißt den Nutzer aus dem Kontext. Das Redesign ersetzt die Kacheln durch einen breiten Dialog (~950px) mit 3 Tabs und klappbaren Sektionen (Collapsible). Alles inline, kein Modal-Hopping.

## Ziel

- Vereinfachung: Von 8-12 Kacheln mit Popups → 1 Dialog mit 3 Tabs
- Alle Informationen auf einer Ebene, kein verschachteltes Modal-Hopping
- shadcn-vue Komponenten: Dialog, Tabs, Collapsible, ScrollArea, Table, Badge
- Skaliert für Bestandsobjekte (7-8 Sektionen) und Neubauprojekte (9-10 Sektionen)
- Akzentfarbe: Orange (wie restliches Dashboard)

## Datei

**Hauptdatei:** `resources/js/Components/Admin/PropertyDetailView.vue` (~1420 Zeilen → Rewrite)

## Layout

### Dialog

- shadcn `Dialog` (nicht Sheet), ~950px breit, zentriert mit Overlay
- Header: Objekt-Icon (orange), Adresse, Ort + Kategorie + Einheiten-Anzahl, Status-Badge, "Bearbeiten"-Button, Close-Button
- Darunter: 3 Tabs

### Tab 1: Objekt

**KPI-Leiste** (immer sichtbar, 5-spaltig):
- Kaufpreis, Rendite, Fläche, Einheiten, Provision
- Werte aus `properties`-Tabelle
- Hintergrund: `muted` (hsl(240 4.8% 95.9%))

**Collapsible-Sektionen** (shadcn `Collapsible`):

Jede Sektion hat: Chevron-Icon (auf/zu), Bereichs-Icon (farbig), Titel, optional Badges/Buttons rechts.

1. **Objektdaten** (default: offen)
   - 3-Spalten Key-Value Grid (Label: muted, Wert: foreground)
   - Buttons rechts: "KI auslesen" (violet), "Bearbeiten"
   - "KI auslesen" → öffnet Datei-Auswahl, dann Exposé-Parsing
   - "Bearbeiten" → öffnet PropertyEditor (bestehend, emit `openEditor`)
   - Felder: Baujahr, Grundstück, Zustand, Heizung, Aufzug, HWB, Keller, Garage, Stockwerke, etc.

2. **Eigentümer & Portal** (default: offen)
   - Kontakt-Karten in Grid (3-spaltig bei breitem Dialog)
   - Jede Karte: Name, Rolle, E-Mail, Telefon
   - Portal-Login-Status als Badge (grün "Zugang aktiv" / orange "Kein Zugang")
   - Eigentümer-Erstellung inline (bestehendes Formular)

3. **Einheiten** (default: offen, NUR bei `property_category === 'newbuild'`)
   - Orange Border (Neubau-Markierung)
   - "Neubau"-Badge neben Titel
   - Status-Badges rechts: X frei (grün), Y reserviert (orange), Z verkauft (rot)
   - Inhalt: shadcn `Table` in `ScrollArea` (max-height: 280px)
     - Spalten: Top-Nr, Typ, Zimmer, Fläche, Preis, €/m², Status
     - Sticky Header
     - Zeilen farblich nach Status: reserviert → orange bg, verkauft → rot bg
   - Filter-Zeile über Tabelle: Status-Dropdown + Suche
   - Datenquelle: `property_units`-Tabelle

4. **Stellplätze** (default: zugeklappt, NUR bei `property_category === 'newbuild'`)
   - Orange Border (Neubau-Markierung)
   - Badge: "X Plätze"
   - Ähnliche Tabelle wie Einheiten

5. **Wissens-Datenbank** (default: zugeklappt)
   - Badge: "X Einträge"
   - "KI auslesen"-Button (violet)
   - Inhalt: Liste der Knowledge-Einträge
   - Bestehende Logik aus `openKnowledge` emit

6. **Dateien** (default: zugeklappt)
   - Badge: Anzahl Dateien (grün)
   - Dateiliste mit Upload-Möglichkeit
   - Bestehende Logik aus `openFiles` emit

7. **Historie** (default: zugeklappt, NUR bei `property_category !== 'newbuild'`)
   - Badge: "X Einträge"
   - Timeline-Darstellung der Preisänderungen/Statuswechsel
   - Datenquelle: `property_history` JSON-Feld

8. **Unterobjekt anlegen** (default: zugeklappt)
   - Badge: "X vorhanden" (violet)
   - Formular zum Erstellen neuer Unterobjekte
   - Bestehende Logik aus `openChildCreateModal`

9. **Hierarchie** (default: zugeklappt)
   - Badge: "Hauptobjekt" oder "Ist Unterobjekt"
   - Zuordnung verwalten
   - Bestehende Logik aus `assignParent` emit

### Tab 2: Aktivitäten

**Collapsible-Sektionen:**

1. **Protokoll & Einträge** (default: offen)
   - Timeline-Liste mit farbigen Dots
   - Jeder Eintrag: Titel, Datum/Uhrzeit, Makler, optional Top-Nr
   - Status-Badge rechts (Geplant/Erledigt)
   - Badge im Trigger: "X heute" (orange)
   - Bestehende Logik aus `openActivities` emit

2. **Nachrichten** (default: zugeklappt)
   - Portal-Kommunikation
   - Badge: "X ungelesen" (blau)
   - Bestehende Logik aus `openMessages` emit

### Tab 3: Kaufanbote

- Kein Collapsible nötig — der ganze Tab ist die Angebotsliste
- Angebots-Karten mit:
  - Links: Name, Top-Nr(n), Typ (Paket/Einzel), Datum
  - Rechts: Betrag (groß), €/m², Status-Badge
  - Angenommene Angebote: grüner Border + leichter grüner Hintergrund
  - Abgelehnte Angebote: gedimmt (opacity: 0.55)
  - Offene Angebote: normaler Border
- "Neues Kaufanbot erfassen"-Button unten (full-width, outline)
- Bestehende Logik aus `openSettings` emit

## Konditionale Logik

| Sektion | Bestandsobjekt | Neubauprojekt | Unterobjekt |
|---------|---------------|---------------|-------------|
| Objektdaten | ✅ | ✅ | ✅ |
| Eigentümer | ✅ | ✅ | ❌ |
| Einheiten | ❌ | ✅ | ❌ |
| Stellplätze | ❌ | ✅ | ❌ |
| Wissens-DB | ✅ | ✅ | ❌ |
| Dateien | ✅ | ✅ | ❌ |
| Historie | ✅ | ❌ | ❌ |
| Unterobjekt+ | ✅ | ✅ | ❌ |
| Hierarchie | ✅ | ✅ | ✅ |
| Aktivitäten | ✅ | ✅ | ❌ |
| Nachrichten | ✅ | ✅ | ❌ |
| Kaufanbote | ✅ | ✅ | ❌ |

Unterobjekte zeigen nur: Objektdaten + Hierarchie (wie bisher).

## shadcn-vue Komponenten

Benötigt (ggf. zu installieren):
- `Dialog` (DialogContent, DialogHeader, DialogTitle, DialogDescription)
- `Tabs` (TabsList, TabsTrigger, TabsContent)
- `Collapsible` (CollapsibleTrigger, CollapsibleContent)
- `ScrollArea`
- `Table` (Table, TableHeader, TableBody, TableRow, TableHead, TableCell)
- `Badge`
- `Button`
- `Input` (für Filter/Suche)

Bereits vorhanden: Tabs, Collapsible, ScrollArea, Badge, Button, Input

Zu installieren: Dialog, Table

## Bestehende Funktionalität beibehalten

Alle bestehenden emits und Funktionen bleiben erhalten:
- `openEditor` → Objektdaten bearbeiten
- `openActivities` → Aktivitäten öffnen
- `openKnowledge` → Wissens-DB öffnen
- `openFiles` → Dateien öffnen
- `openMessages` → Nachrichten öffnen
- `openSettings` → Kaufanbote öffnen
- `assignParent` → Hierarchie verwalten
- Exposé-Parsing (KI auslesen) → bestehende Logik
- Portal-Zugang erstellen → bestehende Logik
- Eigentümer erstellen → bestehende Logik
- Unterobjekt anlegen → bestehende Logik
- Historie anzeigen → bestehende Logik

## Design Tokens

- Akzentfarbe: Orange `hsl(21 90% 48%)` / `hsl(33 100% 96%)` (bg)
- Neubau-Markierung: Orange Border `hsl(33 100% 85%)`
- KPI-Hintergrund: `hsl(240 4.8% 95.9%)` (muted)
- Label-Text: `hsl(240 3.8% 46.1%)` (muted-foreground)
- Card-Border: `hsl(240 5.9% 90%)` (border)
- Status-Badges: Grün (frei/aktiv), Orange (reserviert/offen), Rot (verkauft/abgelehnt), Blau (ungelesen), Violet (KI/Struktur)

## Nicht im Scope

- PropertyEditor (bleibt eigenes Popup/Modal)
- API-Änderungen (bestehende Endpoints bleiben)
- Datenbank-Änderungen
- Neue Features — nur UI-Redesign der bestehenden Funktionalität
