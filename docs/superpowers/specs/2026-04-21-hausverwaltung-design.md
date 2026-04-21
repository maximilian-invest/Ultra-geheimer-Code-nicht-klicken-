# Hausverwaltung-Verwaltung + Kontakt-Flows

Zentrale Verwaltung von Hausverwaltungen (HVs) als erstklassige Entität: eigener Kontakte-Sub-Tab für CRUD, HV-Picker beim Objekt-Anlegen, ein-Klick-Kontaktieren aus dem Objekt mit vorgefertigten Templates, ein-Klick-Weiterleiten von Mieter-Mails aus der Inbox an die zuständige HV.

Zielgruppe: Makler-User inkl. älterer Personen. Design muss sehr einfach sein — klare deutsche Beschriftungen, große Buttons, wenige Klicks, klare Ja/Nein-Entscheidungen in Popups.

## Überblick

Heute ist `properties.property_manager` ein einfaches Textfeld (Firmenname ohne Struktur). Es gibt keine zentrale HV-Datenbank, keine Mehrfachnutzung, keine Kontakt-Automatisierung.

Nach dem Umbau:
- Neue Tabelle `property_managers` mit strukturierten Daten (Firmenname, Adresse, E-Mail, Telefon, Ansprechpartner).
- `properties.property_manager_id` Fremdschlüssel verknüpft Objekt mit HV. 1 HV kann mehreren Objekten zugewiesen sein. Das alte String-Feld bleibt als Legacy für Exports/Altdaten.
- Admin → Kontakte bekommt vierten Sub-Tab „Hausverwaltungen" mit Tabelle, Such-Filter, Anlegen/Bearbeiten/Löschen.
- Im Property-Editor wird aus dem String-Feld ein Autocomplete-Picker mit „+ Neue anlegen"-Option.
- Im Property-Detail gibt es einen Button „Hausverwaltung kontaktieren" der ein Template-Auswahl-Sheet öffnet.
- In der Inbox gibt es einen orangen Button „🏢 An HV weiterleiten" neben Antworten, der KI-basiert das Original-Issue in einen HV-Anschreiben-Entwurf umwandelt.
- Zwei Popup-Flows: „HV fehlt → inline anlegen" und „AVA fehlt → hochladen".

## User-Szenarien

### Szenario 1 — Mieter meldet Heizungsausfall
1. Martin Pühringer schreibt an hoelzl@sr-homes.at: „Heizung Lb 33 ist aus".
2. Max öffnet die Mail in der Inbox. Property-Badge zeigt „Kau-Hau-Ste-01 Weiherweg 2".
3. Max klickt im Thread-Footer auf den orangen Button **🏢 An HV weiterleiten**.
4. System prüft: hat Property 5 eine HV zugeordnet? Ja → KI generiert einen HV-Anschreiben-Entwurf mit dem Issue zusammengefasst. Compose-Pane öffnet sich mit ausgefüllter „An:"-Zeile (verwaltung@immofirst.at) und editierbarem Entwurf.
5. Max prüft kurz, klickt „Senden".
6. Mail geht raus, erscheint in seinem Gesendet-Ordner. Activity-Log-Eintrag am Property: „An HV weitergeleitet: Heizungsstörung Wohnung Lb 33".

### Szenario 2 — Neue HV inline anlegen
1. Max ist auf einem anderen Objekt, das noch keine HV hat. Mieter-Mail kommt rein, Max klickt **🏢 An HV weiterleiten**.
2. Popup: „Keine Hausverwaltung hinterlegt. Für Kau-Per-Vit-01 (Perwang) ist noch keine HV gespeichert. Jetzt anlegen:"
3. Formular mit 4 Feldern: Firmenname *, E-Mail *, Adresse, Telefon.
4. Max tippt „Wimmer", E-Mail, klickt „Anlegen & weiterleiten".
5. Neue HV wird in `property_managers` gespeichert, dem Property zugewiesen, KI-Entwurf wird generiert und Compose öffnet sich wie in Szenario 1.

### Szenario 3 — Unterlagen von HV anfordern
1. Max öffnet ein Property-Detail, sieht dass HV „ImmoFirst" zugewiesen ist.
2. Klickt Button **✉️ Hausverwaltung kontaktieren**.
3. Sheet öffnet mit Template-Liste: „📋 Unterlagen anfordern" / „⚠️ Mieter-Meldung weiterleiten" / „✏️ Freitext".
4. Max wählt „Unterlagen anfordern". System prüft: ist ein AVA hochgeladen?
5. Ja → Compose öffnet mit vorformuliertem Anschreiben + AVA als Anhang. Nein → Popup „AVA fehlt — Jetzt hochladen?" mit Upload-Zone.
6. Max lädt AVA hoch → wird als AVA getagt (`property_files.is_ava = 1`), Compose öffnet mit Anhang.

### Szenario 4 — HV zentral bearbeiten
1. Max geht zu Kontakte → Hausverwaltungen.
2. Sieht Tabelle: ImmoFirst (3 Objekte), Wimmer & Partner (1 Objekt). Sucht „Wimmer".
3. Klickt „Bearbeiten" → ändert E-Mail-Adresse, speichert.
4. Die neue E-Mail wird automatisch für alle 1 zugeordneten Objekte verwendet — keine Nacharbeit pro Objekt.

## Datenmodell

### Neue Tabelle `property_managers`

```sql
CREATE TABLE property_managers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(255) NOT NULL,
    address_street VARCHAR(255) NULL,
    address_zip VARCHAR(20) NULL,
    address_city VARCHAR(100) NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(100) NULL,
    contact_person VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_company_name (company_name),
    INDEX idx_email (email),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
```

Pflichtfelder: `company_name`, `email`. Rest optional.

### Erweiterung `properties`

```sql
ALTER TABLE properties
    ADD COLUMN property_manager_id BIGINT UNSIGNED NULL AFTER property_manager,
    ADD INDEX idx_property_manager_id (property_manager_id),
    ADD FOREIGN KEY (property_manager_id) REFERENCES property_managers(id) ON DELETE SET NULL;
```

Vorhandenes Textfeld `property_manager` bleibt. Wenn eine HV zugewiesen wird, schreibt das Backend den `company_name` zusätzlich ins Text-Feld (Legacy-Kompatibilität für bestehende Exports, OpenImmo-Feeds, Immoji-Sync).

### Erweiterung `property_files` — AVA-Kennzeichnung

```sql
ALTER TABLE property_files
    ADD COLUMN is_ava TINYINT(1) NOT NULL DEFAULT 0 AFTER is_website_download;
```

Eine Datei mit `is_ava = 1` gilt als der gültige Alleinvermittlungsauftrag. Pro Property sollte maximal eine Datei als AVA markiert sein; bei Upload eines neuen AVA wird ein eventuell existierender automatisch entmarkiert.

Migration für Altdaten: beim Deployment einmal alle `property_files` durchgehen wo `label LIKE '%Alleinvermittlungsauftrag%'` steht und `is_ava = 1` setzen.

## Backend-API

Alle neuen Endpoints laufen über den bestehenden `AdminApiController::handle`-Dispatcher mit `?action=`-Pattern. Auth-Check via bestehende `api.key`-Middleware + `Auth::check()`.

### Property-Manager CRUD

- `list_property_managers` — GET, liefert alle HVs mit Objekt-Count (`LEFT JOIN properties ... GROUP BY`) + optionalem Suchfilter.
  - Response: `{ success, managers: [{id, company_name, email, phone, address_street, address_zip, address_city, contact_person, notes, property_count, created_at}] }`
  - Scope: alle User sehen alle HVs (HVs werden team-übergreifend genutzt, wie contacts heute auch).

- `create_property_manager` — POST mit `company_name`, `email`, `address_street?`, `address_zip?`, `address_city?`, `phone?`, `contact_person?`, `notes?`.
  - Validierung: `company_name` und `email` pflicht.
  - Rückgabe: `{success, manager_id, manager}`.

- `update_property_manager` — POST mit `id` + Feldern.
- `delete_property_manager` — POST mit `id`. Löschen nur wenn keine Objekte mehr zugewiesen (sonst 409 mit Hinweis auf zugeordnete Objekte).

### Property-HV-Zuweisung

- `assign_property_manager` — POST mit `property_id`, `property_manager_id` (null zum Entfernen). Setzt `properties.property_manager_id` und synchronisiert das String-Feld `property_manager` auf den Firmennamen. Broker-Scope-Check: Makler darf nur auf eigene Properties zuweisen.

- `quick_create_and_assign_property_manager` — POST mit `property_id` + allen HV-Feldern. Legt neue HV an UND weist sie dem Property zu in einer Transaktion. Für den „HV fehlt"-Inline-Flow.

### Contact-Flow

- `contact_property_manager` — POST mit `property_id`, `template_kind` (`unterlagen` / `mieter_meldung` / `freitext`), optional `source_email_id` (für mieter_meldung-Kontext), optional `viewing_date` (falls später nochmal Besichtigungs-Template kommt).
  - Gibt KI-generierten Entwurf zurück: `{success, draft: {to, subject, body, attachments: [file_ids]}}`.
  - Für `unterlagen`-Template: prüft ob Property einen AVA hat. Wenn ja → `attachments` enthält die AVA-File-ID. Wenn nein → Response enthält `ava_missing: true` als Signal ans Frontend, dann zeigt das UI den Upload-Popup bevor das Compose-Fenster öffnet.
  - `template_kind = mieter_meldung` muss `source_email_id` enthalten. KI bekommt den Original-Body, fasst das Issue in 1-2 Sätzen zusammen und baut es in den vorgefertigten Text ein.
  - Der Entwurf wird NICHT direkt gesendet — er wird ans Frontend zurückgegeben, dort zeigt InboxComposePane den Entwurf zum Review.

- `upload_ava` — POST (multipart/form-data) mit `property_id` + `file`. Legt Datei in `property_files` mit `is_ava = 1` an. Entmarkiert eventuelle Alt-AVAs auf demselben Property. Gibt `{success, file_id, path}` zurück.

## Templates

### Template 1: `unterlagen`

System-Prompt an Claude (AnthropicService::chatJson oder ::chat):
> Du schreibst eine formale deutsche E-Mail an eine Hausverwaltung als Immobilienmakler. Sehr höflich, Sie-Form, sachlich. Keine Floskeln wie „in Anbetracht der Umstände". Schlicht und respektvoll.

Template-Body (wird als User-Content an Claude übergeben mit Platzhaltern):

```
Sehr geehrte Damen und Herren,

ich bin mit dem Verkauf des Objekts [OBJEKT_ADRESSE] beauftragt und darf
Sie in diesem Zusammenhang höflich um Zusendung folgender Unterlagen bitten:

- Aktuelle Betriebskostenabrechnung
- Nutzwertgutachten
- Pläne des Objekts
- Energieausweis
- Rücklagenstand
- Hausordnung
- Wohnungseigentumsvertrag
- Protokolle der letzten Eigentümerversammlungen

Im Anhang finden Sie den Alleinvermittlungsauftrag als Nachweis meiner
Beauftragung.

Vielen Dank im Voraus und mit freundlichen Grüßen
[MAKLER_NAME]
```

Claude wird gebeten, den Text leicht zu personalisieren (Objekt-Adresse einzusetzen, Makler-Name aus der Signatur, sonst unverändert).

Subject: `Verkauf [REF_ID] [ADRESSE] – Bitte um Unterlagen`

### Template 2: `mieter_meldung`

System-Prompt:
> Du formulierst eine formale Weiterleitung einer Mieter-Meldung an die Hausverwaltung. Kurz, sachlich, Sie-Form. Kein Boulevard-Deutsch.

User-Content enthält Original-Mail-Body + Stakeholder-Daten.

Template-Body:
```
Sehr geehrte Damen und Herren,

wir haben heute von den Mietern der Wohnung [ADRESSE_WOHNUNG] folgende
Meldung erhalten:

[KI-Zusammenfassung des Issues in 1-2 Sätzen]

Wir bitten Sie, zeitnah mit den Mietern Kontakt aufzunehmen und sich der
Angelegenheit anzunehmen.

Mit freundlichen Grüßen
[MAKLER_NAME]
```

Subject: `[ISSUE-SCHLAGWORT aus KI] – Wohnung [ADRESSE]`

Die KI muss:
1. Den Original-Body lesen
2. Den Kern des Issues in 1-2 Sätze zusammenfassen
3. Ein Schlagwort für die Betreff-Zeile extrahieren (z.B. „Heizungsstörung", „Wasserschaden")

### Template 3: `freitext`

Leeres Compose-Fenster. `to` ist auf HV-E-Mail gesetzt, `subject` und `body` sind leer. User schreibt selbst.

## Frontend-Architektur

### Neue Vue-Komponenten

- `resources/js/Components/Admin/HausverwaltungenTab.vue` — Liste aller HVs, Tabelle mit Suchfilter, Edit/Delete-Buttons, „+ Neue HV"-Button.
- `resources/js/Components/Admin/HausverwaltungFormDialog.vue` — Dialog mit 4 Feldern (company_name *, email *, address, phone) + optional contact_person/notes. Wird sowohl im Hausverwaltungen-Tab (neu anlegen / bearbeiten) als auch im Property-Edit (quick-create) als auch im Inbox-Missing-HV-Popup verwendet.
- `resources/js/Components/Admin/property-detail/PropertyManagerPicker.vue` — Picker im Property-Edit mit Autocomplete-Dropdown + „+ Neue anlegen"-Option.
- `resources/js/Components/Admin/property-detail/ContactManagerSheet.vue` — Sheet das nach Klick auf „Hausverwaltung kontaktieren" öffnet, zeigt 3 Template-Optionen, führt zur Compose-Pane.
- `resources/js/Components/Admin/inbox/ForwardToManagerButton.vue` — Der orange „🏢 An HV weiterleiten"-Button im InboxChatView-Footer.
- `resources/js/Components/Admin/inbox/MissingManagerDialog.vue` — Popup wenn keine HV hinterlegt ist (wiederverwendet `HausverwaltungFormDialog` als Child).
- `resources/js/Components/Admin/property-detail/MissingAvaDialog.vue` — Upload-Popup wenn AVA fehlt.

### Modifizierte Komponenten

- `resources/js/Components/Admin/AdminTab.vue` — vierter Sub-Tab „Hausverwaltungen" neben Kunden/Eigentümer/Team.
- `resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue` (und `EditTab.vue` wo immer das Feld heute steht) — ersetzt das String-Input durch `PropertyManagerPicker`.
- `resources/js/Components/Admin/property-detail/OverviewTab.vue` — neuer „Hausverwaltung kontaktieren"-Button in der Aktions-Sektion.
- `resources/js/Components/Admin/inbox/InboxChatView.vue` — integriert `ForwardToManagerButton` in Thread-Footer.

### Property-Manager Picker — Verhalten

Der Picker ist eine stilisierte `<Select>`-ähnliche Komponente, keine native Dropdown.

**Zustände:**
- Leer: Zeigt Platzhalter „Hausverwaltung wählen oder neu anlegen…"
- Ausgewählt: Zeigt Chip mit Firmenname + E-Mail/Ort als sekundäre Zeile. Klick öffnet Dropdown zum Ändern.

**Dropdown:**
- Oben Suchfeld (inkrementell filtert die Liste)
- Liste aller HVs aus `list_property_managers`
- Am Ende „+ Neue Hausverwaltung anlegen"-Option. Wenn der Suchtext einen Namen eingegeben hat der noch nicht existiert: Option wird zu „+ Neue Hausverwaltung „<Suchtext>" anlegen" (pre-fills das Dialog-Feld).

Klick auf Option aus Liste → ruft `assign_property_manager` auf.
Klick auf „+ Neue anlegen" → öffnet `HausverwaltungFormDialog` → nach Speichern wird automatisch `assign_property_manager` ausgelöst.

### ContactManagerSheet — Verhalten

Sheet (shadcn) slide-in von rechts oder Dialog in der Mitte. Enthält:
- Header: „Hausverwaltung kontaktieren" + Subtitel „An: ImmoFirst Hausverwaltung · verwaltung@immofirst.at"
- Liste mit 3 Karten:
  1. 📋 Unterlagen anfordern — mit Tag „Anhang: Alleinvermittlungsauftrag"
  2. ⚠️ Mieter-Meldung weiterleiten — Beschreibung „Aus einer Mieter-Mail heraus"
  3. ✏️ Freitext — „Leeren Entwurf starten"

Klick auf Karte 1:
1. Ruft `contact_property_manager` mit `template_kind=unterlagen` auf.
2. Response enthält `ava_missing: true`? → Zeigt `MissingAvaDialog`. Nach Upload: Endpoint nochmal aufrufen (jetzt mit AVA).
3. Sonst: Sheet schließt, Compose-Pane öffnet mit Draft + Attachment.

Klick auf Karte 2: NICHT aus dem Property-Detail direkt aufrufbar — nur aus der Inbox (braucht `source_email_id`). Im Property-Detail wird Option 2 ausgegraut mit Tooltip „Nur aus einer Mieter-Mail heraus".

Klick auf Karte 3:
1. Ruft `contact_property_manager` mit `template_kind=freitext` auf.
2. Response hat leere Body/Subject.
3. Compose öffnet mit leerem Entwurf, To ist HV-E-Mail.

### ForwardToManagerButton — Verhalten

Button ist nur sichtbar wenn die Conversation ein zugewiesenes Property hat (conv.property_id != null). Ohne Property macht der Button keinen Sinn (keine HV zum weiterleiten).

Klick:
1. Frontend prüft: hat Property eine HV?
   - Via bereits in Conversation-Detail geladenen `item.property_manager_id` (Feld muss im API-Response ergänzt werden).
2. Keine HV → `MissingManagerDialog` mit Quick-Form. Nach Save (via `quick_create_and_assign_property_manager`) direkt weiter zu Schritt 3.
3. HV da → ruft `contact_property_manager` mit `template_kind=mieter_meldung, source_email_id=<id>, property_id=<id>`.
4. Response mit Draft → Compose-Pane öffnet sich mit ausgefülltem Entwurf, AN-Zeile ist auf HV-E-Mail.
5. User klickt „Senden" im Compose → neuer Endpoint `send_to_manager` (POST mit `property_id`, `subject`, `body`, `attachments`, `source_email_id?`). Der Endpoint erzeugt einen neuen Conversation-Thread (separate Conv, nicht an die Mieter-Conv gehängt), setzt contact_email = HV-E-Mail, tagged die Conv als `category = 'hausverwaltung'`. Vorteil: klare Trennung zwischen Mieter-Thread und HV-Thread, aber per `source_email_id` bleibt der Ursprung nachvollziehbar.

## Broker-Scoping & Security

- HVs selbst sind team-übergreifend (wie Contacts heute). Susanne kann ImmoFirst genauso zuweisen wie Max. Das ist OK, weil HV-Daten nicht personenbezogen sind.
- `assign_property_manager` und `contact_property_manager` prüfen Property-Ownership: Makler darf nur auf eigene Properties zuweisen/senden. Admin auch nur auf eigene (wie bei anderen Actions). Assistenz/Backoffice auf alle.
- `quick_create_and_assign_property_manager` macht denselben Ownership-Check auf das `property_id`.
- `delete_property_manager` ist nur möglich wenn keine Objekte mehr zugewiesen — sonst müssten zuerst die Objekte umgestellt werden (verhindert Waisen-Records).
- `upload_ava`: Ownership-Check auf Property.

Alle neuen Endpoints verwenden dieselbe `Auth::check()`-Guard wie bestehende Actions.

## Activity-Logging

Jede HV-Kommunikation erzeugt eine Activity am Property:
- `category = 'hausverwaltung'` — neuer Enum-Wert wird via Migration zum bestehenden `activities.category`-Enum hinzugefügt (Liste steht heute: email-in, email-out, expose, besichtigung, kaufanbot, update, absage, sonstiges, anfrage, eigentuemer, partner, bounce, intern, makler, feedback_*, nachfassen, link_opened, objekt_edit)
- `stakeholder` = Firmenname der HV
- `activity` = „An HV gesendet: <subject>" für ausgehende Mails
- `source_email_id` = die neu erzeugte `portal_emails`-Row

Ausgehende Mails werden wie alle Outbound-Mails in `portal_emails` geloggt, mit `property_id` gesetzt.
Conversation bekommt ebenfalls `category = 'hausverwaltung'` damit im Inbox-Filter-Chip „Hausverwaltung" sichtbar werden kann.

## Phasing

Das Feature wird in zwei Implementierungs-Plans gesplittet:

### Phase 1 — HV-Core (Foundation)

Deliverables:
- Migration `property_managers` + `properties.property_manager_id` + `property_files.is_ava`
- Backfill-Migration für Alt-AVA-Labels
- Eloquent-Model `PropertyManager`
- API-Endpoints: `list_property_managers`, `create_property_manager`, `update_property_manager`, `delete_property_manager`, `assign_property_manager`, `quick_create_and_assign_property_manager`, `upload_ava`
- Vue-Components: `HausverwaltungenTab.vue`, `HausverwaltungFormDialog.vue`, `PropertyManagerPicker.vue`
- Integration in `AdminTab.vue` (neuer Sub-Tab)
- Integration in Property-Edit (Picker ersetzt String-Feld)
- Tests: Backend-CRUD, Picker-Interaktion

Nach Phase 1: HVs lassen sich zentral verwalten, Objekten zuweisen. Noch kein Kontaktieren.

### Phase 2 — Kontakt-Flows

Deliverables:
- API-Endpoints: `contact_property_manager` (Draft-Generation), `send_to_manager` (actual Versand als neuer HV-Thread)
- KI-Prompt-Builder für beide Templates (Unterlagen + Mieter-Meldung)
- Vue-Components: `ContactManagerSheet.vue`, `ForwardToManagerButton.vue`, `MissingManagerDialog.vue`, `MissingAvaDialog.vue`
- Integration in `OverviewTab.vue` („HV kontaktieren"-Button)
- Integration in `InboxChatView.vue` (oranger Button + Flow)
- Migration: Activity-Kategorie-Enum erweitern um `hausverwaltung`
- Tests: KI-Template-Generation mit Mock, Popup-Flows, AVA-Auto-Attach

## Edge Cases

- **HV wird gelöscht während sie einem Objekt zugewiesen ist**: Dank `ON DELETE SET NULL` wird `property_manager_id` auf null gesetzt. String-Feld bleibt mit Alt-Wert stehen. UI zeigt im Picker „(gelöscht)". User muss neu zuweisen oder leer lassen.
- **User ändert HV-E-Mail nach Versand**: Bereits gesendete Mails bleiben an der alten Adresse, das ist erwartetes Verhalten.
- **Property hat keine Adresse**: Template 1 verwendet dann nur Ref-ID („Verkauf Kau-Xyz – Bitte um Unterlagen").
- **Mehrere Mieter-Mails hintereinander zum gleichen Issue**: User kann das Weiterleiten beliebig oft triggern. Keine Deduplizierung — ist Makler-Verantwortung.
- **KI generiert unsinnigen Entwurf**: Makler sieht den Entwurf immer vor dem Senden (Compose-Pane). Er kann bearbeiten oder verwerfen.
- **AVA wird gelöscht nach Upload**: Beim Versand wird der aktuell gültige AVA angehängt (via `is_ava = 1` + `exists`-Check). Fehlt er, wird wieder der Missing-AVA-Popup gezeigt.
- **Mehrere AVAs pro Property**: Nur der zuletzt hochgeladene hat `is_ava = 1`. Das System erzwingt das beim Upload.

## Testing-Strategie

### Backend (PHPUnit)

- `PropertyManagerControllerTest`
  - `list_returns_all_managers_with_property_count`
  - `create_validates_required_fields`
  - `delete_fails_when_assigned_to_properties`
  - `assign_checks_broker_ownership`
  - `quick_create_creates_and_assigns_in_transaction`
- `UploadAvaTest`
  - `upload_sets_is_ava_flag`
  - `upload_unmarks_previous_ava_on_same_property`
- `ContactPropertyManagerTest` (mit AnthropicService-Mock)
  - `unterlagen_template_includes_ava_in_attachments_when_available`
  - `unterlagen_template_returns_ava_missing_flag_when_not_available`
  - `mieter_meldung_template_requires_source_email_id`
  - `mieter_meldung_template_summarizes_issue_in_subject`

### Frontend (manual QA-Liste)

- [ ] Neuer HV-Tab in Kontakte sichtbar, Liste lädt
- [ ] „+ Neue HV"-Button öffnet Dialog, 4 Felder, Save funktioniert
- [ ] Picker im Property-Edit zeigt Liste, Suche filtert, „+ Neue anlegen" öffnet Dialog
- [ ] Property-Detail „HV kontaktieren"-Button öffnet Sheet mit 3 Optionen
- [ ] Template 1 mit vorhandenem AVA: Compose öffnet mit Draft + Anhang
- [ ] Template 1 ohne AVA: Upload-Popup erscheint, nach Upload → Draft mit Anhang
- [ ] Inbox: Orange „An HV"-Button erscheint bei Mieter-Mail
- [ ] Inbox: Button ohne HV → Missing-HV-Popup → nach Save → Draft öffnet
- [ ] Inbox: Button mit HV → KI-Draft direkt
- [ ] Mobile: alle Popups lesbar, Buttons klickbar

## Out of Scope (v1)

- Template-Konfiguration durch User (Phase 3 falls Bedarf)
- Mehrere AVAs pro Property (immer genau einer gültig)
- HV-User-Login (HVs haben kein Portal-Access, sie bekommen nur Mails)
- HV-spezifische Berechtigungen / sichtbare Objekte
- Auto-Erkennung „Mieter-Meldung" durch KI im Posteingang (Button ist immer sichtbar)
- HV-Vertragsdokumente (Vertrag mit der HV selbst) — wäre eigene Datei-Kategorie
- Vertretungsregelungen / mehrere Kontaktpersonen pro HV (contact_person ist einer)

Diese können als Phase 3+ nachgelegt werden.

## Erfolgskriterien

- Makler kann innerhalb von 30 Sekunden eine Mieter-Mail an die HV weiterleiten (ohne manuelles Copy-Paste).
- 0 Makler vergessen den AVA als Anhang (weil System erzwingt / erinnert).
- Keine Doppel-Einträge von HVs (weil Autocomplete + zentrale Liste).
- Feature ist auch für einen 65-jährigen Makler ohne IT-Hintergrund bedienbar (Validation durch End-User-Testing nach Deployment).
