# Deploy-Hinweise: Aufnahmeprotokoll

## Migrations (auf prod ausführen)

```
php artisan migrate --force
```

Diese Migrations werden aufgespielt:
- `2026_04_22_100001_create_intake_protocols_table`
- `2026_04_22_100002_create_intake_protocol_drafts_table`
- `2026_04_22_100003_add_intake_protocol_fields_to_properties`
- `2026_04_22_100004_add_customer_to_user_type_enum`
- `2026_04_22_100005_add_aufnahmeprotokoll_to_activity_category`
- `2026_04_22_100006_add_missing_property_cols_for_tests` (No-op auf prod, nur für SQLite Tests)
- `2026_04_22_100007_add_missing_image_unit_cols_for_tests` (No-op auf prod, nur für SQLite Tests)

## Queue-Worker

Der Wizard versendet E-Mails über Laravel Mailables (Queue). Queue-Worker muss laufen:

```
sudo supervisorctl status srhomes-queue-worker
```

## Storage-Symlink

PDFs werden in `storage/app/intake-protocols/` abgelegt, Signatur-PNGs ebenfalls. Kein public symlink nötig — Zugriff geht über `/api/admin_api.php?action=intake_protocol_get_pdf&protocol_id=X` (auth-geschützt).

## website-v2 Repo

Wenn `website-v2/js/detail.js` Property-Felder referenziert, prüfen:

- **NICHT anzeigen** (werden serverseitig gefiltert):
  - `encumbrances`
  - `approvals_status`
  - `approvals_notes`
  - `documents_available`
  - `internal_notes`

- **Optional anzeigen:** `parking_assignment` (Wert: `'assigned'` = „dem Objekt zugeordnet", `'shared'` = „gemeinsam")

Nichts zwingend zu ändern — der Website-API filtert interne Felder bereits. Dokumentation dient als Sicherheitsnetz für zukünftige Anpassungen.

## Smoke-Test nach Deploy

1. Admin → Objekte → „📋 Aufnahmeprotokoll" klickt
2. Wizard öffnet sich, alle 11 Schritte durchlaufen (Test-Daten)
3. Nach „Absenden": Property wurde angelegt, PDF als E-Mail-Anhang zugesandt
4. Property-Detail zeigt offene-Felder-Banner wenn Skipped-Fields vorhanden
