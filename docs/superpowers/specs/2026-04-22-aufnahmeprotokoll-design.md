# Aufnahmeprotokoll — Design-Spec

**Status:** Draft · **Erstellt:** 2026-04-22 · **Owner:** SR-Homes Admin-Cockpit

## 1. Zweck

Ein geführter 11-Schritt-Wizard zur Neuanlage einer Bestands-Immobilie (Wohnung, Haus, Grundstück, Gewerbe) direkt vor Ort beim Eigentümer. Ersetzt das simple „Neues Objekt"-Formular durch einen vollständigen Aufnahmeprozess, der alle vermarktungsrelevanten Daten, eine Foto-Session, eine Dokumenten-Checkliste und eine unterschriebene PDF-Bestätigung in einem Ablauf bündelt.

**Neubauprojekte mit Einheiten** sind bewusst ausgeschlossen — diese bleiben beim bestehenden Create-Flow, weil sie komplexe Unit-Verwaltung erfordern.

## 2. Erfolgskriterien

Ein erfolgreich abgeschlossenes Protokoll produziert:

1. Eine neue `properties`-Row mit allen erfassten Feldern
2. Optional einen neuen `customers`-Eintrag (Eigentümer), falls nicht bereits existiert
3. Optional einen neuen `users`-Eintrag mit `user_type='customer'` für Portalzugang
4. Einen Eintrag in der neuen Tabelle `intake_protocols` mit Unterschrift, PDF-Pfad, Disclaimer-Snapshot
5. Einen `activities`-Eintrag am Property mit `category='Aufnahmeprotokoll'` und Link zum PDF
6. Zwei ausgehende E-Mails an den Eigentümer (PDF + optional Portalzugang)
7. Hochgeladene Fotos in `property_images` inkl. Kategorisierung

## 3. Nutzer-Flow

```
Makler öffnet Portal am Handy vor Ort
  ↓
Tab „Objekte" → Button „+ Aufnahmeprotokoll" (orange, prominent)
  ↓
11 Steps mit Sticky-Bottom-Nav (Zurück / Weiter)
  ↓
Am Ende: Zusammenfassung, Notizen, Disclaimer, Swipe-Unterschrift
  ↓
Submit:
  → property wird angelegt
  → owner wird angelegt oder verknüpft
  → portal user wird angelegt (falls Toggle aktiv)
  → intake_protocol-Row wird angelegt (Unterschrift als PNG)
  → activities-Eintrag
  → PDF wird generiert
  → Mail 1: Portalzugang-Credentials (wenn aktiv)
  → Mail 2: Protokoll-PDF + dynamische Dokumenten-Request-Liste
  → Redirect zum neu angelegten Property-Detail
```

Bei Netzausfall: Jeder Step speichert sofort als Draft in `localStorage`. Server-Sync läuft im Hintergrund — bei Fehler Retry alle 30 Sek. Beim Abschluss wird der komplette Draft einmalig an den Submit-Endpoint geschickt.

## 4. Die 11 Steps im Detail

### Step 1 — Objekttyp & Vermarktung
| Feld | Pflicht | Widget |
|---|---|---|
| `object_type` | ✓ | 4 Tiles: Haus · Wohnung · Grundstück · Gewerbe |
| `object_subtype` | — | Pill-Row, dynamisch gefiltert nach `object_type` |
| `marketing_type` | ✓ | 3 Pills: Kauf · Miete · Pacht |
| `ref_id` | — | Text-Input mit Auto-Vorschlag `{Typ}-{Subtyp}-{Nachname}-{Counter}` |

**Subtyp-Listen:**
- **Haus:** Einfamilienhaus, Doppelhaushälfte, Reihenhaus, Zweifamilienhaus, Mehrfamilienhaus, Villa, Bauernhaus, Stadthaus, Landhaus, Ferienhaus, Berghaus, Sonstiges
- **Wohnung:** Eigentumswohnung, Maisonette, Dachgeschoss, Penthouse, Loft, Souterrain, Terrassenwohnung, Gartenwohnung, Studio/Einzimmer, Appartement, Sonstiges
- **Grundstück:** Baugrund, Landwirtschaftlich, Gewerbegrund, Wald/Forst, Freizeitgrund, Sonstiges
- **Gewerbe:** Büro, Ladenfläche, Gastronomie, Hotel, Lager, Produktion, Werkstatt, Praxis, Anlageobjekt, Sonstiges

Der Subtyp beeinflusst Sichtbarkeit von Folge-Feldern (z.B. `floor_number` nur bei Wohnung, `plot_buildable` nur bei Grundstück) und die Foto-Kategorien-Liste.

### Step 2 — Adresse
| Feld | Pflicht | Widget |
|---|---|---|
| `address` (Straße) | ✓ | OSM-Autocomplete (bestehende Komponente) |
| `house_number` | ✓ | Text-Input |
| `zip` | ✓ | Text-Input |
| `city` | ✓ | Text-Input |
| `staircase`, `door`, `address_floor` | — | Text-Inputs, nur wenn `object_type='Wohnung'` |
| `latitude`, `longitude` | — | Auto-gefüllt durch OSM; Live-Karten-Preview (CARTO Positron) |

### Step 3 — Eigentümer + Portalzugang
- **Suche:** Input mit Live-Suche über bestehende `customers` / `contacts` (Name, E-Mail, Telefon)
- **Ergebnis-Liste:** max 5 Treffer, Tap wählt aus
- **Fallback:** Button „+ Neuer Eigentümer" öffnet Inline-Form:
  - `name` *(Pflicht)*
  - `email` *(Pflicht — sonst kein PDF-Versand möglich)*
  - `phone`
  - `address`, `zip`, `city` (Wohnsitz-Adresse des Eigentümers)
- **Multi-Owner:** Button „+ Weiteren Eigentümer" erlaubt mehrere Einträge (gespeichert als Array). Das PDF geht an den primären Eigentümer.
- **Portalzugang-Toggle** (prominent, orange):
  > ☐ **Eigentümer bekommt Portalzugang**
  > *Er erhält separate E-Mail mit Login-Daten zum Kundenportal.*

Wenn aktiv: bei Submit wird ein `users`-Eintrag mit `user_type='customer'` angelegt, Passwort auto-generiert (12 Zeichen, mixed case + digits + special), E-Mail an Eigentümer mit Credentials + Login-URL.

### Step 4 — Kerndaten
| Feld | Pflicht | Widget | Anmerkung |
|---|---|---|---|
| `living_area` | ✓ | Numeric-Input m² | |
| `free_area` | — | Numeric-Input m² | Nur bei Haus/Grundstück |
| `total_area` | — | Numeric-Input m² | Gesamtfläche |
| `realty_area` | — | Numeric-Input m² | Nutzfläche (bei Gewerbe) |
| `rooms_amount` | ✓ | Numeric-Input, step=0.5 | |
| `bedrooms`, `bathrooms`, `toilets` | — | Numeric-Inputs | |
| `floor_count` | — | Numeric-Input | Nur bei Haus |
| `floor_number` | — | Numeric-Input | Nur bei Wohnung |
| `construction_year` | ✓ | Numeric-Input, YYYY | |

### Step 5 — Zustand & Sanierungen
**Zustand-Block:**
| Feld | Pflicht | Widget |
|---|---|---|
| `realty_condition` | ✓ | Pill-Row: Neuwertig · Gebraucht · Saniert · Kernsaniert · Renoviert · Erstbezug · Abbruchreif |
| `construction_type` | — | Pill-Row: Massiv · Holz · Fertigteil · Mischbauweise |
| `quality` | — | Pill-Row: Einfach · Normal · Gehoben · Luxus |
| `ownership_type` | — | Pill-Row: Wohnungseigentum · Baurecht · Pacht |
| `furnishing` | — | Pill-Row: Unmöbliert · Teilmöbliert · Vollmöbliert (nur bei Miete) |
| `condition_note` | — | Textarea |

**Sanierungen-Block:** Integration mit bestehendem `property_history`-JSON-Schema. 12 Kategorien (Generalsanierung, Fenster, Türen, Fußböden, Heizung, Leitungssystem, Anschlüsse, Fassade, Bäder, Küche, Sonstige, Erforderliche Maßnahmen). Pro Kategorie: Jahr-Input + Notiz-Input. Nur ausgefüllte Kategorien werden gespeichert. Storage-Format kompatibel zu existierender Sanierungen-UI im Property-Detail.

### Step 6 — Ausstattung, Merkmale & Stellplätze
**Toggle-Group 1 (mit optionaler m²-Angabe):**
- Balkon → wenn aktiv: `area_balcony` (m²) + `balcony_count`
- Terrasse → `area_terrace`, `terrace_count`
- Loggia → `area_loggia`, `loggia_count`
- Garten → `area_garden`
- Keller → `area_basement`, `basement_count`

**Toggle-Group 2 (einfache Booleans):** Aufzug, Einbauküche, Klimaanlage, Kamin, Pool, Sauna, Alarmanlage, Barrierefrei, Gäste-WC, Abstellraum

**Allgemeinräume** (bestehend): `common_areas` JSON-Array mit 14 vordefinierten Optionen (Fahrradraum, Müllraum, Trockenraum, Waschküche, Kinderwagenraum, Hobbyraum, Partyraum, Fitnessraum, Gemeinschafts-Sauna, Kinderspielplatz, Gemeinschafts-Dachterrasse, Gemeinschaftsgarten, Heizraum, Lagerraum)

**Zusatzfelder:** `flooring`, `bathroom_equipment`, `orientation` (Ausrichtung, 8 Pills N/NO/O/SO/S/SW/W/NW)

**Stellplätze-Block:**
| Feld | Widget |
|---|---|
| `garage_spaces` | Numeric-Input |
| `parking_spaces` | Numeric-Input |
| `parking_type` | Pill-Row: Garage · Tiefgarage · Carport · Stellplatz |
| `parking_assignment` **NEU** | Pill-Row: „Dem Objekt zugeordnet" · „Allgemein/gemeinsam" |

### Step 7 — Energie & Heizung
| Feld | Widget |
|---|---|
| `energy_certificate` vorhanden? | Ja/Nein-Toggle, öffnet Folgefelder wenn Ja |
| `heating_demand_value` (HWB) | Numeric-Input kWh/m²a |
| `heating_demand_class` | Pill-Row: A++, A+, A, B, C, D, E, F, G |
| `energy_efficiency_value` (fGEE) | Numeric-Input |
| `energy_valid_until` | Date-Input |
| Heizungsart (`building_details.heating.types`) | Multi-Select: Fußbodenheizung, Radiatoren, Luftheizung, Fernwärme |
| Befeuerung (`building_details.heating.fuel`) | Dropdown: Gas, Öl, Pellets, Wärmepumpe, Strom, Fernwärme |
| Warmwasser (`building_details.heating.hot_water`) | Dropdown: Zentral, Elektroboiler, Solar, Wärmepumpe |
| `energy_primary_source` | Text-Input |
| `has_photovoltaik` | Toggle |
| `charging_station_status` | 3-Pills: Keine · Vorkehrung · Vorhanden |

### Step 8 — Hausverwaltung, Rechtliches, Bewilligungen, Dokumente

**Hausverwaltung-Picker:**
- Suche über bestehende `property_managers`
- Fallback: „+ Neue Hausverwaltung" Inline-Form (Firma, Ansprechpartner, E-Mail, Telefon, Adresse)
- Verlinkung via `properties.property_manager_id`

**Belastungen:** Textarea (`encumbrances` NEU) — z.B. Pfandrechte, Wohnrechte, Dienstbarkeiten

**Bewilligungs-Block:**
- 3 Buttons: ✓ Alles bewilligt · ⚠️ Teilweise · ❓ Unbekannt (`approvals_status` NEU, ENUM `complete|partial|unknown`)
- Bei „Teilweise" oder „Unbekannt" erscheint Pflicht-Textarea (`approvals_notes` NEU). Bei „Teilweise": Placeholder „Terrasse: nicht bewilligt / Gartenhaus: ohne Genehmigung". Bei „Unbekannt": „Was muss geprüft werden?". Ohne Eingabe ist „Weiter"-Button inaktiv (außer via „↷ später"-Skip).

**Dokumenten-Checkliste** (`documents_available` NEU als JSON-Objekt):
13 Dokumente, jedes mit Tri-State `available|missing|na`:
- grundbuchauszug, energieausweis, plaene, nutzwertgutachten, ruecklagenstand, wohnungseigentumsvertrag, hausordnung, letzte_jahresabrechnung, betriebskostenabrechnung, schaetzwert_gutachten, baubewilligung, mietvertrag, hypothekenvertrag

Pro Dokument: Bei „available" erscheint Kamera-Icon zum direkten Abfotografieren (landet in `property_files` mit Kategorie).

### Step 9 — Preise, Provision, Kosten
| Feld | Bedingung |
|---|---|
| `purchase_price` | Bei marketing_type='Kauf' |
| `rental_price`, `rent_warm`, `rent_deposit` | Bei marketing_type='Miete' |
| `price_per_m2` | Auto-berechnet aus Preis/Fläche |
| `operating_costs`, `maintenance_reserves`, `heating_costs`, `warm_water_costs`, `admin_costs`, `elevator_costs` | Numeric-Inputs |
| `monthly_costs` | Auto-Summe (overridable) |
| `commission_percent` (intern) | Numeric-Input % |
| `buyer_commission_percent` (öffentlich) | Numeric-Input % |
| `available_from` | Date-Input |

### Step 10 — Fotos
Foto-Kategorien (dynamisch nach `object_type`):
- **Wohnung/Haus:** Außenansicht · Eingang · Wohnzimmer · Küche · Schlafzimmer (beliebig viele) · Badezimmer (beliebig viele) · WC · Balkon/Terrasse · Garten · Keller · Stellplatz · Grundriss · Sonstige
- **Grundstück:** Panorama · Straßenzugang · Parzellen-Eckpunkte · Ausblick · Sonstige
- **Gewerbe:** Außenansicht · Haupteingang · Innenraum · Lager · Bürobereich · Technik · Sonstige

Pro Kategorie: Multi-Upload via `<input type="file" accept="image/*" capture="environment">`. Fotos werden beim Submit an den bestehenden `property_images`-Endpoint geschickt und mit `category` + `property_id` verknüpft. Reihenfolge via `sort_order`.

### Step 11 — Zusammenfassung, Notizen, Unterschrift

**Live-Zusammenfassung:** Scrollbarer Block mit allen eingegebenen Daten, gruppiert nach Steps. Kompakt.

**„Offene Felder"-Banner:** Auflistung aller via „↷ später" markierten Felder. Makler sieht was er später nachtragen muss.

**Makler-Notizen-Textarea** (`broker_notes` in `intake_protocols`, + Copy zu `properties.internal_notes` NEU):
> „Besondere Hinweise vom Termin"
> Placeholder: *„z.B. Vollmacht für Gemeinde nicht notwendig, wartet noch auf Scheidungsurteil"*

**Disclaimer (nicht editierbar):**
> „Die im Aufnahmeprotokoll angegebenen Informationen stammen vom Eigentümer. Der Eigentümer bestätigt durch seine Unterschrift, dass diese Infos von ihm weitergegeben wurden."

**Signature-Pad:**
- Canvas-basiert, 320×120 px auf Handy (Portrait), 640×180 px auf Tablet/Desktop
- Swipe-Erfassung per `pointerdown`/`pointermove`/`pointerup`
- Button „Zurücksetzen" bei Fehler
- Export als PNG via `canvas.toDataURL('image/png')`

**Versand-Optionen (Checkboxen, default beide aktiv):**
- ☑ Kopie des PDFs an den Eigentümer per E-Mail
- ☑ Bei fehlenden Dokumenten: Vermittlungsauftrag-Vorschlag als 2. PDF-Anhang

**Submit-Button:** „Protokoll abschicken & Immobilie anlegen"

## 5. Datenbank-Änderungen

### Neue Spalten auf `properties`
```sql
ALTER TABLE properties ADD COLUMN encumbrances TEXT NULL AFTER property_manager_id;
ALTER TABLE properties ADD COLUMN parking_assignment ENUM('assigned','shared') NULL AFTER parking_type;
ALTER TABLE properties ADD COLUMN documents_available JSON NULL AFTER encumbrances;
ALTER TABLE properties ADD COLUMN approvals_status ENUM('complete','partial','unknown') NULL AFTER documents_available;
ALTER TABLE properties ADD COLUMN approvals_notes TEXT NULL AFTER approvals_status;
ALTER TABLE properties ADD COLUMN internal_notes TEXT NULL AFTER approvals_notes;
```

### Neue Tabelle `intake_protocols`
```sql
CREATE TABLE intake_protocols (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    broker_id BIGINT UNSIGNED NOT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_name VARCHAR(200) NULL,
    signature_png_path VARCHAR(500) NULL,
    disclaimer_text TEXT NOT NULL,
    pdf_path VARCHAR(500) NULL,
    owner_email_sent_at TIMESTAMP NULL,
    portal_email_sent_at TIMESTAMP NULL,
    portal_access_granted BOOLEAN DEFAULT 0,
    broker_notes TEXT NULL,
    open_fields JSON NULL,
    form_snapshot LONGTEXT NULL,
    client_ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (broker_id) REFERENCES users(id),
    INDEX idx_property (property_id),
    INDEX idx_created (created_at)
);
```

### Drafts-Tabelle `intake_protocol_drafts`
```sql
CREATE TABLE intake_protocol_drafts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    broker_id BIGINT UNSIGNED NOT NULL,
    draft_key VARCHAR(100) NOT NULL,  -- UUID aus Browser
    form_data LONGTEXT NOT NULL,
    current_step SMALLINT DEFAULT 1,
    last_saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (broker_id, draft_key),
    FOREIGN KEY (broker_id) REFERENCES users(id)
);
```

### Export-Mapping
Neu als **nur intern** (`targets: ['l']`):
- `encumbrances`
- `documents_available`
- `approvals_status`
- `approvals_notes`
- `internal_notes`
- `intake_protocols.*`

Neu **mit Website-Export** (`targets: ['w']`):
- `parking_assignment` → Details-Tabelle: „Zugeordneter Stellplatz" / „Allgemein-Stellplatz"

## 6. Tech-Architektur

### Frontend-Struktur
```
resources/js/Components/Admin/IntakeProtocol/
├── IntakeProtocolWizard.vue      (Root, State, Nav)
├── steps/
│   ├── Step01_ObjectType.vue
│   ├── Step02_Address.vue
│   ├── Step03_Owner.vue
│   ├── Step04_CoreData.vue
│   ├── Step05_ConditionRenovations.vue
│   ├── Step06_FeaturesParking.vue
│   ├── Step07_Energy.vue
│   ├── Step08_LegalDocuments.vue
│   ├── Step09_PriceCosts.vue
│   ├── Step10_Photos.vue
│   └── Step11_SignatureSummary.vue
├── shared/
│   ├── StepHeader.vue           (Progress-Bar, "X von 11")
│   ├── StepNavigation.vue       (Sticky Bottom-Bar)
│   ├── SkipFieldSwitch.vue      ("↷ später")
│   ├── SignaturePad.vue         (Canvas)
│   ├── OwnerPicker.vue
│   ├── HausverwaltungPicker.vue (wiederverwendet)
│   ├── DocumentChecklistItem.vue (Tri-State + Kamera)
│   ├── PhotoCategoryUploader.vue
│   ├── AddressAutocomplete.vue  (wiederverwendet)
│   └── PillRow.vue              (generic Pill-Select)
└── composables/
    ├── useIntakeForm.js         (Master-State)
    ├── useAutoSave.js           (Draft sync)
    └── useSubtypes.js           (Subtyp-Listen nach Typ)
```

### Backend-Struktur
```
app/Http/Controllers/Admin/
└── IntakeProtocolController.php
   ├── draftSave($request)
   ├── draftLoad($request)
   ├── submit($request)            ← atomare Transaktion
   ├── getPdf($protocolId)
   └── resendEmail($protocolId, $type)

app/Services/
├── IntakeProtocolPdfService.php             (Blade→DomPDF)
├── IntakeProtocolEmailService.php           (Mail 1 + Mail 2)
└── VermittlungsauftragTemplateService.php   (PDF-Anhang)

database/migrations/
├── YYYY_04_22_create_intake_protocols_table.php
├── YYYY_04_22_create_intake_protocol_drafts_table.php
├── YYYY_04_22_add_intake_protocol_fields_to_properties.php

resources/views/
├── pdf/intake-protocol.blade.php
├── pdf/vermittlungsauftrag.blade.php
├── emails/intake-protocol-complete.blade.php
├── emails/intake-protocol-missing-docs.blade.php
└── emails/portal-access.blade.php
```

### Admin-API-Actions (neu)
- `intake_protocol_draft_save`
- `intake_protocol_draft_load`
- `intake_protocol_submit`
- `intake_protocol_get_pdf`
- `intake_protocol_resend_email`

### Submit-Transaktion
```
BEGIN TRANSACTION
  1. customer anlegen oder verknüpfen
  2. (optional) user mit user_type='customer' anlegen
  3. property_manager anlegen oder verknüpfen
  4. property anlegen mit allen form-daten
  5. intake_protocol-row mit signature + snapshot
  6. activities-row mit link_session_id='intake_protocol:{id}'
  7. fotos → property_images (via bestehendem Upload-Endpoint)
  8. draft löschen
COMMIT

→ queue:
  - SendPortalAccessEmailJob (wenn aktiv)
  - SendIntakeProtocolEmailJob
```

## 7. PDF-Struktur (Blade-Template)

**Seite 1 — Deckblatt:**
- Logo SR-Homes
- „Aufnahmeprotokoll"
- Adresse des Objekts (groß)
- Ref-ID
- Datum der Aufnahme
- Makler-Name + Signatur-Bild (Makler)
- Eigentümer-Name + Adresse

**Seite 2+ — Daten-Übersicht:**
Alle 10 Daten-Steps als strukturierte Tabellen. Nicht-ausgefüllte Felder werden weggelassen, „↷ später"-Felder als „offen" markiert.

**Sanierungen:** Eigene Tabelle mit Kategorie · Jahr · Notiz.

**Dokumenten-Checkliste:** Tabelle mit Status ✓ / ✗ / N/A pro Dokument.

**Bewilligungen:** Status + Details (wenn vorhanden).

**Letzte Seite — Notizen & Unterschrift:**
- Makler-Notizen-Textblock
- Disclaimer-Text (wörtlich)
- Unterschrift-Bild (Eigentümer, aus PNG-Blob eingebettet)
- Unterschrift-Datum + Uhrzeit
- IP-Adresse + User-Agent (Audit-Trail klein am Seitenende)

## 8. E-Mail-Templates

### Mail 1 — Portalzugang (wenn aktiviert)
Betreff: *„Ihr Zugang zum SR-Homes Kundenportal"*
Inhalt: Login-URL, Username (E-Mail), initiales Passwort, Aufforderung beim ersten Login zu ändern, Kontakt-Info des Maklers.

### Mail 2a — Protokoll komplett (alle Dokumente vorhanden)
Betreff: *„Ihr Aufnahmeprotokoll · {Ref-ID}"*
> „Sehr geehrte/r {Eigentümer-Name},
>
> vielen Dank für unseren Termin. Anbei das unterschriebene Aufnahmeprotokoll zu Ihrer Unterlage.
>
> Wir melden uns in den nächsten Tagen mit dem Vermittlungsauftrag und den nächsten Schritten.
>
> Herzliche Grüße, {Makler-Name}"

Anhang: `intake-protocol-{RefId}.pdf`

### Mail 2b — Protokoll mit fehlenden Dokumenten
Betreff: *„Ihr Aufnahmeprotokoll · {Ref-ID} — noch fehlende Unterlagen"*
> „Sehr geehrte/r {Eigentümer-Name},
>
> vielen Dank für unseren Termin. Anbei das unterschriebene Aufnahmeprotokoll.
>
> Damit wir Ihr Objekt bestmöglich vermarkten können, benötigen wir noch folgende Unterlagen:
> {Dynamische Liste der documents_available='missing'-Items}
>
> **Zwei Möglichkeiten:**
>
> **Variante A** — Sie senden uns diese Unterlagen selbst per E-Mail an {broker_email}
>
> **Variante B** — Sie unterschreiben den beigefügten Vermittlungsauftrag, dann holen wir die fehlenden Unterlagen direkt bei Ihrer Hausverwaltung ein.
>
> Herzliche Grüße, {Makler-Name}"

Anhänge: `intake-protocol-{RefId}.pdf`, `vermittlungsauftrag-{RefId}.pdf`

## 9. Editierbarkeit nach Submit

Nach Submit landet der Makler auf der Property-Detail-Seite. Oberhalb der Tabs erscheint:

**Banner (orange, wenn `intake_protocol_id` auf Property gesetzt):**
> 📋 *Aufnahmeprotokoll vom 22.04.2026 · [PDF öffnen] · {X Felder offen} →*

**„Offene Felder"-Markierung:** Jedes skipped Feld bekommt gelben Punkt neben dem Label. Banner verschwindet sobald alle offenen Felder befüllt sind.

**Original-PDF unveränderlich:** Das ursprüngliche PDF wird **nicht** neu generiert wenn der Makler Felder später ändert. Es bleibt als historisches Dokument mit Unterschrift. Eine Re-Generation erzeugt bei Bedarf ein **Update-Protokoll** mit Kennzeichnung „Update vom XX.XX.XXXX" (nicht in v1 enthalten, spätere Erweiterung).

## 10. Mobile-First Design

**Layout-Constraints:**
- Max Content-Breite: **640 px** auf Desktop (auf Tablet/Phone fullwidth)
- Min Touch-Target: **44 px** (Apple HIG)
- Sticky-Elemente: Progress-Bar oben, Action-Bar unten
- Input-Font-Größe: **16 px** (verhindert iOS-Zoom beim Fokus)
- Wizard in eigenem Viewport (fullscreen auf Mobile, Modal auf Desktop)

**Eingabe-Muster:**
- Tiles/Pills statt Dropdowns wo möglich
- Numeric-Inputs mit `inputmode="decimal"` oder `inputmode="numeric"`
- Dates mit nativem Date-Picker (`<input type="date">`)
- Multi-Select als Chip-Group (Tap toggle)

**Kamera-Integration:**
- `<input type="file" accept="image/*" capture="environment">` öffnet native Kamera direkt
- Nach Aufnahme Thumbnail-Preview, kann re-taken werden

**Offline/Auto-Save:**
- Jeder Step-Wechsel POST `intake_protocol_draft_save`
- Bei Netzfehler → localStorage-Puffer + Retry alle 30 Sek
- Bei Submit: ein großer Request, Retry-Logik im Frontend

## 11. Testing-Strategie

**Backend:**
- Unit-Tests für `IntakeProtocolPdfService` (PDF-Generation, korrekte Disclaimer)
- Unit-Tests für `IntakeProtocolEmailService` (Content je nach fehlenden Docs)
- Feature-Test `submit` endpoint (atomare Transaktion: alles oder nichts)
- Feature-Test Auto-Save (Draft-Persistenz)

**Frontend:**
- Manuelles Testing via Checkliste (Handy, Tablet, Desktop)
- Szenario: Offline → Online → Submit
- Szenario: Submit mit fehlenden Docs → Mail-Body enthält richtige Items
- Szenario: Submit ohne Portalzugang → nur eine Mail raus

**Regressions:**
- Property-Detail zeigt alle Aufnahmeprotokoll-Daten korrekt
- Sanierungen-Anzeige funktioniert mit neuen Daten aus dem Wizard
- Website-Export berücksichtigt `parking_assignment`

## 12. Rollout & Feature-Flag

Initiales Rollout mit `features.intake_protocol_enabled`-Config-Flag. Standard aus → später für alle Makler an. Flag ermöglicht Rollback falls kritische Bugs.

## 13. Scope — Nicht enthalten in v1

Bewusst ausgeschlossen (spätere Erweiterungen):
- Neubauprojekte mit Einheiten-Verwaltung
- Multi-Makler-Review eines Protokolls
- Versionierung des PDFs bei späteren Änderungen
- KI-Auto-Befüllung aus Sprachnachrichten
- Integration mit Meta Business Suite (Social Posting nach Abschluss)
- Unterschrift mehrerer Eigentümer (nur primärer Eigentümer unterschreibt in v1)
