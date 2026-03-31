# SR-Homes — Claude Code Kontext

## Was ist das hier?

**SR-Homes Immobilien GmbH** — Immobilienmakler Salzburg/OÖ.
Zwei Produkte in diesem Repo:

| Produkt | Stack | Live-URL |
|---------|-------|----------|
| **Kundenportal** | Laravel 11 + Vue 3 + Inertia | kundenportal.sr-homes.at |
| **Marketing-Website** | Plain HTML/CSS/JS | sr-homes.at |

## Repo-Struktur

```
/portal/          Laravel-App (Kundenportal + Admin)
  app/Http/Controllers/
    Admin/        AdminApiController + 20 Sub-Controller
    Portal/       PortalApiController (Kundensicht)
    WebsiteApiController.php  (Public Website API)
  resources/js/Pages/
    Admin/        Admin-Dashboard (Vue/Inertia)
    Portal/       Kunden-Dashboard (Vue/Inertia)
  routes/
    api.php       Alle API-Routen
    web.php       Inertia-Routen

/website-v2/      Marketing-Website (plain HTML/CSS/JS)
  index.html      Startseite
  immobilien.html Listings
  objekt.html     Immobilien-Detail
  css/styles.css  Globale Styles
  js/app.js       Gemeinsame Logik
  js/home.js      / js/listings.js / js/detail.js

/deploy.sh        Build + Deploy + Rollback (läuft auf VPS)
/hooks.json       Webhook-Config ({{WEBHOOK_SECRET}} Placeholder)
/setup-server.sh  Einmalig auf Server ausführen (generiert Secret)
```

## Server

- **IP**: 187.124.166.153
- **OS**: Ubuntu, PHP 8.3, Node 22
- **Portal-Pfad**: `/var/www/srhomes`
- **Website-Pfad**: `/var/www/sr-homes-website`
- **Webhook**: Port 9000, `/hooks/sr-homes-deploy`
- **Logs**: `/var/log/sr-homes-deploy.log`
- **Backups**: `/var/www/backups/` (letzte 5, auto-cleanup)

## Deploy-Workflow

**Primär: GitHub Actions** (`.github/workflows/deploy.yml`)
```
git push origin main
  → GitHub Actions
  → Ein einziger SSH-Step auf dem Server:
     1. cd /var/www/sr-deploy && git fetch && git reset --hard origin/main
     2. rsync portal/ → /var/www/srhomes/ (excl. vendor, node_modules, .env, storage/*)
     3. composer install --no-dev (für ziggy + andere Build-Dependencies)
     4. npm ci && npm run build (Vite/Vue JS-Build AUF DEM SERVER)
     5. php artisan config:clear + cache:clear + view:clear
     6. systemctl reload php8.3-fpm
     7. rsync website-v2/ → /var/www/sr-homes-v2/
```

**WICHTIG:**
- JS wird AUF DEM SERVER gebaut, NICHT im CI (CI-Build hat zu oft gefehlt)
- `composer install` ist nötig vor `npm run build` (ziggy Dependency)
- PHP-Änderungen UND JS-Änderungen werden im gleichen SSH-Step deployed
- Nur pushes auf `main` triggern den Deploy

**Sekundär: Webhook** (deploy.sh, Port 9000) — Fallback/Legacy

**Wichtig:** Nur Pushes auf `main` triggern Deploy. Der Webhook-Secret liegt auf dem Server unter `/opt/sr-homes/.webhook-secret`.

## API-Struktur

### Public Website API (kein Auth)
```
GET  /api/website/properties     Alle Immobilien
GET  /api/website/property/{id}  Einzelne Immobilie
GET  /api/website/content        CMS-Inhalt (Hero, Stats, Services, ...)
GET  /api/website/image/{id}     Bild-Proxy
```

### Admin API (Session-Auth oder api-key Header)
```
POST /api/admin_api.php?action=xxx
```
Wichtige Actions: `get_property`, `update_property`, `list_activities`,
`upload_property_file`, `create_customer`, `update_customer`,
`list_portal_messages`, `send_portal_message`, `upload_portal_document`,
`list_brokers`, `create_broker`, `import_expose`, `analyze_file`

### Portal API (Kunden, api-key)
```
POST /api/portal_api.php?action=xxx
```

## Datenmodelle

| Model | Wichtige Felder |
|-------|----------------|
| `Property` | broker_id, type, price, area_living, status, ref_id |
| `Customer` | name, email, phone, portal_user_id |
| `Activity` | property_id, type, description, created_at |
| `Viewing` | property_id, customer_id, scheduled_at, feedback |
| `PortalEmail` | property_id, direction (inbound/outbound), email_date |
| `PortalMessage` | property_id, customer_id, body, read_at |
| `PortalDocument` | property_id, filename, download_allowed |
| `Task` | property_id, broker_id, due_at, done |

## User-Typen (portal)
- `admin` / `makler` → Admin-Dashboard (sieht nur eigene Mails, gefiltert per email_accounts.user_id)
- `assistenz` / `backoffice` → Admin-Dashboard (sieht alle Mails, Makler-Filter-Dropdown, kann im Namen jedes Maklers senden)
- `kunde` → Portal-Dashboard (nur eigene Immobilien)

## Email-Account-System
- `email_accounts` Tabelle: IMAP/SMTP Konfiguration pro Makler
- `email_accounts.user_id` → verknüpft Account mit User (Makler)
- `portal_emails.account_id` → welcher Account die Mail empfangen/gesendet hat
- **Broker-Filter-Logik**: Conversations werden per `portal_emails.account_id` dem Makler zugeordnet (nicht per `properties.broker_id`)
- **Assistenz sendet**: Wählt Absender-Account im "Von:" Feld (pre-selected auf Makler des Items)

## Website CMS-Sections
`hero`, `stats`, `about`, `services`, `portal`, `contact`,
`testimonial`, `branding`, `seo`, `team`, `legal`

Bearbeitung über Admin → Einstellungen → Website-Tab.

## Häufige Aufgaben

**Website-Änderung deployen:**
```bash
# Änderung machen → commit → push to main → automatisch deployed
git add . && git commit -m "..." && git push origin main
```

**Portal lokal testen:**
```bash
cd portal && php artisan serve
```

**Website lokal testen:**
```bash
cd website-v2 && python3 -m http.server 8080
# oder einfach index.html im Browser öffnen
```

## Regeln

- Kein Commit von `.env`, Secrets, Credentials
- Nur auf `main` pushen für Deploy (andere Branches deployen nicht)
- `deploy.sh` nicht manuell auf dem Server starten — Webhook macht das
- Bilder werden über Immoji-CDN (`api.immoji.org`) ausgeliefert
- PHP-Dateien: PSR-12, keine direkten SQL-Queries (Eloquent)
- Website: kein Build-Step, direkt HTML/CSS/JS editieren
