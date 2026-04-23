# Adaptives Exposé · Design

**Datum:** 2026-04-23
**Betroffen:** Neue Funktionalität in Property-Detail + Integration in bestehendes Freigabelink-System
**Autor:** Brainstorming-Session mit Maximilian

---

## 1 · Ziel

Ein adaptives, hochwertiges Immobilien-Exposé pro Property, das:

- Automatisch aus den bestehenden Property-Daten generiert wird (dieselben Felder wie der Website-Export)
- Im Magazin-Stil aufgemacht ist, mit edlen Display-Schriftarten und Orange-Akzent der Marke SR Homes
- Sich dynamisch an die Menge an Inhalten anpasst — lange Beschreibungen brechen um, viele Bilder verteilen sich auf mehrere Seiten, zu leere Seiten werden umgeschichtet
- Sowohl als interaktive Website als auch als druckfähiges PDF (A4 Querformat) ausgegeben wird
- Im bestehenden `property_links`-System als virtuelles Dokument erscheint, sodass Makler das Exposé mit Kaufinteressenten über den schon etablierten Flow teilen können (E-Mail + Datenschutz-Akzeptanz)

**Nicht-Ziele (YAGNI):**
- Eigener Drag-and-Drop-Layout-Editor für freie Positionierung (PowerPoint-Ersatz) — bewusst verworfen, weil HTML-basierter Editor mit vordefinierten Layouts für 95 % der Fälle reicht
- Eigene Exposé-Tracking-/Teilen-Infrastruktur — weil das bestehende Freigabelink-System schon alles bietet (Ablauf, Revoke, Session-Tracking, Mail-Gate)
- Mehrere Sprachen — Deutsch only (aktuelle Makler-Praxis)

## 2 · Architektur

### 2.1 Output-Format — HTML + Puppeteer-PDF

Das Exposé ist primär eine **responsive HTML-Website**, die via Puppeteer (Spatie Browsershot) on-the-fly in ein druckperfektes A4-Querformat-PDF gerendert werden kann.

Gründe (gegenüber PPTX-Output, der initial erwogen wurde):
- Bilder werden nicht verzerrt (CSS `object-fit: cover` reguliert die Box automatisch)
- Langer Text bricht nativ um (CSS `column-count`, `break-inside: avoid`)
- Ein Layout dient drei Zwecken: Editor-Live-Preview, öffentliche Kunden-Ansicht, Druck-PDF
- Freigabe-Integration ohne zusätzliche Konvertierungsschritte
- Kein Viewer-Problem beim Empfänger (Browser statt Office-Lock-in)

### 2.2 Datenmodell

**Neue Tabelle `property_expose_versions`:**

```
id              bigint unsigned
property_id     bigint unsigned (FK properties.id)
created_by      bigint unsigned (FK users.id)
name            varchar(200)       -- z. B. "Kau-Hau-MTX-01 · Entwurf 2"
config_json     longtext           -- siehe Konfigurationsstruktur 2.3
is_active       boolean            -- nur eine aktive Version pro property
created_at, updated_at
```

**Neue Spalte in bestehender Pivot-Tabelle `property_link_documents`:**

```
expose_version_id  bigint unsigned NULL  -- FK property_expose_versions.id
```

Entweder `property_file_id` ODER `expose_version_id` ist gesetzt (nicht beide). Der Link kann also klassische Dateien UND ein Exposé enthalten.

**Neue Spalte in bestehender Tabelle `properties`:**

```
expose_claim  varchar(200) NULL  -- Makler-kuratierter Claim-Satz für Editorial-Spreads
```

Wird vom Cover und den Editorial-Spreads als bevorzugter Quellwert verwendet; Fallback ist erster Satz der Beschreibung (siehe 3.4).

### 2.3 Konfigurationsstruktur (`config_json`)

JSON-Snapshot der Makler-Entscheidungen beim Generieren:

```json
{
  "cover_image_id": 123,
  "claim_text": "Wo Tageslicht den Raum formt.",
  "pages": [
    {
      "type": "cover",
      "image_id": 123
    },
    {
      "type": "details"
    },
    {
      "type": "description",
      "image_id": 456
    },
    {
      "type": "editorial",
      "style": "M4",
      "image_ids": [789],
      "claim_override": null
    },
    {
      "type": "location"
    },
    {
      "type": "impressions",
      "layout": "L3",
      "image_ids": [234, 567, 890]
    },
    {
      "type": "impressions",
      "layout": "editorial_mixed",
      "image_ids": [111, 222],
      "caption": "Wo **Tageslicht** den Raum formt."
    },
    {
      "type": "contact"
    }
  ]
}
```

Die Property-Daten selbst (Beschreibung, Lage, Details, Preise) werden nicht in der Config gespeichert — sie werden immer live aus der `properties`-Tabelle gelesen. Dadurch bleibt ein einmal gestaltetes Exposé „lebendig" und reflektiert Updates automatisch.

### 2.4 Routes & Controller

**Admin (Makler-Seite):**

- `GET /admin/property/{id}/expose` → Exposé-Editor-Tab (Live-Preview)
- `POST /admin/property/{id}/expose/save` → speichert Config-Snapshot
- `DELETE /admin/property/{id}/expose/{version_id}` → löscht Version

**Public (Kunden-Seite, Token-Gate):**

- `GET /link/{token}/expose` → HTML-Ansicht des Exposés (Token-Gate + Mail+Datenschutz schon etabliert)
- `GET /link/{token}/expose.pdf` → Puppeteer-gerendertes PDF-Download

Beide Public-Routes setzen die bestehende `property_links`-Session-Logik voraus (E-Mail + Datenschutz vorher).

## 3 · Seitenstruktur (Magazin-Stil)

Feste Reihenfolge:

| # | Seite | Inhalt |
|---|---|---|
| 1 | Cover | Titelbild vollflächig, Logo, Kicker ("TOP ZWEIFAMILIENHAUS"), Ort (Playfair 52pt), Adresse, 4 Badges (3 weiß + 1 orange Preis) |
| 2 | Details | Alle objektspezifischen Daten in 2 Spalten, gruppiert |
| 3 | Das Haus | Beschreibung (adaptiv) + Begleitbild |
| 4 | Editorial-Spread | Poetisches Zitat + Bilder (optional 1–2 × im Exposé) |
| 5 | Lage | Leaflet-Karte + Beschreibung + POIs |
| 6+ | Impressionen | Bildseiten, adaptiv verteilt nach Bildanzahl |
| n | Kontakt + Schluss | Ansprechpartner + Kaufnebenkosten + Haftungsausschluss |

### 3.1 Cover (Seite 1)

- Hero-Bild full-bleed mit Overlay-Gradient (oben leicht, unten stärker)
- Logo weiß oben links
- Kicker oben zentriert ("TOP ZWEIFAMILIENHAUS", 12pt Caps Sans)
- Haupttitel mittig: Ort in Playfair Display 52pt uppercase, Letter-Spacing 8px
- Adresse darunter: "Weiherweg 12 · 5083 Grödig" in 18pt Georgia Italic, dünne Linien flankiert
- Badges unten zentriert: `216 m²` · `8 Zimmer` · `Baujahr 2010` · **`€ 989.000`** (letzter in Orange)
- **Kein** Haftungsausschluss auf dem Cover

### 3.2 Details (Seite 2)

- Kopf: Seitenzahl rechts, "Details" in Georgia 36pt links, 3px Orange-Linie darunter
- Inhalt: 2 Spalten, Gruppen mit Orange-Überschriften (11pt Caps), dotted Zeilen
- Gruppen links: **Objekt** (Objektart, Zimmer, Baujahr, Wohnfläche, Grundstück, Verfügbar ab), **Nebenkosten (monatlich)** (Betriebs-, Heiz-, Rücklagen, Summe)
- Gruppen rechts: **Flächen & Räume** (Balkon, Terrasse, Garten, Keller, Garage), **Ausstattung** (Bodenbelag, Bad, Küche, Ausrichtung), **Energie** (Heizung, HWB, Klasse, PV, Wohnraumlüftung)
- Padding zwischen Zeilen: 3.5px. Gruppen-Margin: 14px. Schrift: Labels 12pt, Werte 13pt Georgia
- Nebenkosten-Summe in Orange, fett

### 3.3 Das Haus (Seite 3)

Adaptiver Text-Flow basierend auf Länge von `properties.realty_description`:

- **Kurz** (< 80 Wörter): Text links (1 Spalte), Bild rechts. Lead-Satz als Georgia-Italic 18pt, Fließtext 13pt
- **Mittel** (~ 300 Wörter): Lead oben, Fließtext 2-spaltig, Bild wandert auf Nachbarseite
- **Lang** (> 700 Wörter): 3-spaltig, Umbruch auf Folgeseite ("Das Haus · Fortsetzung"), kein Bild auf Textseite

### 3.4 Editorial-Spread (Claim-Seite)

Eine, zwei oder null Editorial-Spreads pro Exposé. Standardmäßig wird einer nach "Das Haus" automatisch eingefügt; Makler kann pro Spread einen von vier Stilen wählen:

- **M1** — 3 Bilder (2 oben, 1 unten-links) + Text-Zelle unten-rechts, *Cormorant Garamond 22pt Italic*
- **M2** — Großes Bild links, Text rechts, *Playfair Display 32pt Italic* + Sub-Zeile in Cormorant
- **M3** — Zitat-Band oben (*Fraunces 20pt*, Orange-Akzent auf einem Wort) + Bild-Trio unten
- **M4** — Vollbild mit dunklem Gradient-Overlay + Zitat unten links, *Playfair Display 32pt Italic*

Claim-Text:
1. Primär: eigenes Feld `properties.expose_claim` (Makler tippt selbst)
2. Fallback: erster Satz aus `realty_description`
3. Ergänzt durch Vorschlagsliste zum Durchklicken ("Wo Leben zu Raum wird.", "Mehr als vier Wände.", "Das nächste Kapitel beginnt hier.")

Bei `M1`/`M2`/`M3` kann ein einzelnes Wort im Claim fett-kursiv markiert werden (Markdown-artig: `**Wort**`).

### 3.5 Lage (Seite 5)

- Links: Leaflet-Karte (CARTO `light_all` Tiles, graustufen-Filter), zentriert auf `property.latitude`/`longitude`, Zoom 14, Orange-Kreis (`#ee7600`, Radius 400m, 2.5px Strichstärke, Füllung 22%). Stadt-Badge unten links der Karte.
- Rechts: Lead-Satz (Georgia Italic 16pt) → Fließtext (13pt) aus `properties.location_description` → Abschnitt "In der Nähe" mit POIs
- Text-Flow adaptiv nach demselben Schema wie "Das Haus"

### 3.6 Impressionen (Bildseiten)

Adaptive Seitenzahl basierend auf Gesamtbildanzahl. Pro Seite wählt der Generator automatisch ein Layout:

| Bilder | Layout | Seiten |
|---|---|---|
| 1 | L1 (Full Bleed) | 1 |
| 2 | L2 (Half/Half) | 1 |
| 3 | L3 (Big + 2) | 1 |
| 4 | L4 (2×2 Raster) | 1 |
| 5–7 | L4 + L2/L3 | 2 |
| 8–10 | L4 + L4 (+ L2) | 2–3 |
| 11+ | n × L4 + Rest | n |

Zusätzlich gibt es das **Editorial-Mixed-Layout** (wie Impressionen-Seite im Mockup): 1 großes Bild links + 1 kleines rechts oben + Text-Zelle mit Cormorant-Italic-Zitat rechts unten. Dieses Layout kann der Makler pro Bildseite manuell wählen. Die Zitate rotieren bei mehreren Bildseiten aus einem Pool (so steht nicht zweimal derselbe Satz).

Makler kann pro Seite das Layout überschreiben (L1–L5 oder "editorial_mixed") und Bilder per Drag & Drop zuordnen.

### 3.7 Kontakt + Schluss (letzte Seite)

- Links: Ansprechpartner-Block mit Avatar (Initialen, Orange-Gradient), Name (Georgia 18pt), Rolle, Kontaktdaten (Telefon/E-Mail/Web). Plus „Über SR Homes"-Absatz (13pt).
- Rechts: Kaufnebenkosten-Tabelle (gesetzliche Standard-Prozente) + ausführlicher Haftungsausschluss im fest definierten Wortlaut:

> Dieses Exposé wurde mit größter Sorgfalt erstellt und dient ausschließlich der unverbindlichen Information. Alle Angaben zu Flächen, Maßen, Preisen, Erträgen sowie sonstigen Daten beruhen auf den Informationen und Unterlagen des Eigentümers bzw. Dritter. Für deren Richtigkeit, Vollständigkeit und Aktualität wird keine Haftung übernommen. Das Exposé stellt kein verbindliches Angebot dar. Änderungen, Irrtümer und Zwischenverkauf bleiben ausdrücklich vorbehalten. Maßgeblich sind ausschließlich die im Kaufvertrag vereinbarten Inhalte. Dieses Dokument ist vertraulich zu behandeln und darf ohne unsere ausdrückliche Zustimmung weder vervielfältigt noch an Dritte weitergegeben werden.

## 4 · Design-System

### 4.1 Typografie

- **Serif-Titel (alle Seiten-Überschriften):** Georgia 36pt, Letter-Spacing 0.5px, Farbe `#1a1a1a`
- **Display-Zitate:**
  - Playfair Display Italic (filmisch, klassisch-elegant) — 32pt Cover, 32pt M2 & M4
  - Cormorant Garamond Italic (zart, magazinig) — 22–32pt M1 + Impressionen
  - Fraunces Italic (modern, mit Charakter) — 20–28pt M3
- **Body Text:** System-Sans (`-apple-system, BlinkMacSystemFont, sans-serif`), 13pt, Line-Height 1.5–1.6
- **Labels / Kicker:** 11–12pt Caps, Letter-Spacing 2.5px, Orange `#ee7600`
- **Seitenzahl:** 12pt, Letter-Spacing 2.5px, Farbe `#bbb`

### 4.2 Farben

- Akzent: **`#ee7600`** (SR Homes Orange) — ausschließlicher Akzent, keine anderen Farben außer Neutral-Grauwerten
- Hintergrund Normalseite: Weiß `#ffffff`
- Hintergrund Editorial-Textzellen: leicht cremig `#fdfcfa`
- Text primär: `#1a1a1a`
- Text sekundär (Labels): `#666`
- Trennlinien: `#e5e7eb` solid, `#f0f0f0` dotted
- Disclaimer-Block Hintergrund: `#fafafa`, Orange 3px Border-Left

### 4.3 Seitenkonstanten

- **Format:** A4 Querformat (297 × 210 mm, Ratio 1.414)
- **Seitenränder:** 48px links/rechts, 32–38px oben/unten (entsprechen ~13mm bei 300dpi)
- **Orange-Akzentlinie unter Titel:** 48px breit, 3px hoch
- **Border-Radius:** 3–4px auf Bildern und Elementen
- **Schatten:** moderate `box-shadow: 0 2–4px 10–24px rgba(0,0,0,0.06–0.10)`

## 5 · Harte Umbruchregeln (Paginierung)

Ein serverseitiger Layout-Balancer bestimmt vor dem Rendern, wie Gruppen/Zeilen auf Seiten verteilt werden. Deterministisch, nicht abhängig vom Browser-Rendering.

1. **Min. Füllgrad** pro Seite: 60 % (darunter → Content zurück auf vorherige Seite, Spaltenanzahl erhöhen oder engere Zeilen)
2. **Max. Füllgrad** pro Seite: 92 % (darüber → nächste Gruppe auf Folgeseite)
3. **Gruppen bleiben zusammen** — `break-inside: avoid`. Eine Gruppe, die nicht auf die aktuelle Seite passt, wandert komplett auf die nächste.
4. **Keine Waisen-Seiten:** Folgeseite mit weniger als 2 Gruppen oder 12 Zeilen ist verboten. In dem Fall: Gruppen auf Vorseite umverteilen (z. B. 3-spaltig statt 2-spaltig).
5. **2-Seiten-Balance:** Bei Umbruch Ziel-Verteilung Seite 1 = 55–80 %, Seite 2 = 40–70 %.
6. **Adaptive Spaltenanzahl:** Standard 2. Bei viel Content auf 3, bei sehr wenig auf 1. Gleiche Regel für Beschreibung + Lage.

Die Regeln werden in `app/Services/ExposePaginationService.php` implementiert; der Service bekommt pro Gruppe eine geschätzte Höhe (Zeilen × Zeilenhöhe + Header + Padding) und liefert eine Pages-Struktur zurück.

## 6 · Bildhandling

- Alle Bilder werden mit CSS `object-fit: cover` eingebettet — keine Pre-Cropping-Pipeline nötig
- Bildplätze haben feste Aspect-Ratios (Hero 16:9, Landscape-Zelle 4:3, Quadrat 1:1, Portrait 3:4); Bild wird auf Boxmitte gecropped
- Auf der Lage-Seite wird die Leaflet-Karte im Admin-Preview als lebendige Karte gerendert; im PDF-Export liefert Puppeteer einen Screenshot der gerenderten Karte
- Ausgangsbilder kommen aus `property_images` — keine neuen Bild-Uploads speziell für Exposé, alles aus dem bestehenden Medien-Pool

## 7 · Makler-Editor (Standard-Ausbaustufe)

In Property-Detail neuer Tab **„Exposé"** mit:

**Links (40% Breite): Editor-Panel**
- Bildauswahl aus Medien-Pool per Drag&Drop (Thumbnails)
- Layout-Wahl pro Seite über Mini-Icons (L1–L5, editorial_mixed, M1–M4)
- Claim-Text-Feld (Eingabe oder aus Vorschlagsliste)
- Reihenfolge der Impressionen-Seiten sortierbar
- „Speichern"-Button

**Rechts (60% Breite): Live-Preview**
- Eingebettete HTML-Version in A4-Querformat-Thumbnails aller Seiten
- Klick auf Thumbnail → Vollansicht der Seite
- Aktualisiert sich bei jeder Änderung

**Nicht im Standard-Umfang:**
- Inline-Text-Editing (Beschreibung, Lage, POIs kommen immer aus Property-Daten)
- Eigene Sektionsüberschriften
- Einzelne Seiten deaktivieren (kann später als „Voll"-Ausbaustufe ergänzt werden)

## 8 · Integration in Freigabelink-System

Der bestehende `property_links`-Flow bleibt unverändert. Änderungen:

- **Links-Editor (Admin):** Bei Dateiauswahl erscheint das aktive Exposé der Property als erster Eintrag mit EXPOSÉ-Badge (Orange). Kann an-/abgewählt werden.
- **Kunden-Ansicht:** Wenn der Link ein Exposé enthält, steht es ganz oben mit hervorgehobener Orange-Kachel und zwei Buttons: „Öffnen" (HTML-Route) und „PDF" (Download). Darunter die klassischen Dokumente.
- **Tracking:** Views auf `/link/{token}/expose` landen in der bestehenden `property_link_sessions`-Tabelle wie alle anderen Zugriffe.

Keine Änderung an:
- Mail- und Datenschutz-Gate
- Link-Ablauf, Revoke, Token-Generation
- Admin-Link-Liste

## 9 · Offene Annahmen / bewusst vertagt

- **Makler-Foto im Avatar:** Aktuell Initialen auf Orange-Gradient. Wenn Property-Team-Feature um echtes Porträtfoto ergänzt wird, kann das Avatar-Feld automatisch darauf wechseln.
- **POI-Liste automatisch:** Aktuell manuell im Text. Integration mit Maps-API zur automatischen Distanzberechnung ist ein späteres Thema.
- **Mehrere Expose-Versionen pro Property:** Datenmodell unterstützt es (is_active-Flag), Editor-UI arbeitet vorerst mit einer Version. Versionen-Historie ist optional.
- **Internationalisierung:** Deutsch only, keine Mehrsprachen-Config.

## 10 · Referenz-Mockups

Visual-Companion-Mockups in `.superpowers/brainstorm/20643-1776942961/content/`:

- `concrete-real.html` — Kompletter 7-Seiten-Flow mit echten Bildern
- `fixes-v2.html` — Cover mit Adresse, Details kompakt, Impressionen Editorial
- `impressionen-final.html` — Finale Impressionen-Seite
- `layout-rules.html` — Umbruchregeln visualisiert
- `link-integration.html` — Architektur der Freigabelink-Integration

