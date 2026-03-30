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
- **OS**: Ubuntu, PHP 8.3, Node 20
- **Portal-Pfad**: `/var/www/srhomes`
- **Website-Pfad**: `/var/www/sr-homes-website`
- **Webhook**: Port 9000, `/hooks/sr-homes-deploy`
- **Logs**: `/var/log/sr-homes-deploy.log`
- **Backups**: `/var/www/backups/` (letzte 5, auto-cleanup)

## Deploy-Workflow

```
git push origin main
  → GitHub Webhook (HMAC-SHA256 signiert)
  → Port 9000 auf VPS
  → deploy.sh
     1. git pull
     2. composer install + npm ci + npm run build (Portal)
     3. npm ci + npm run build (Website)
     4. Backup aktueller Stand
     5. rsync → /var/www/srhomes + /var/www/sr-homes-website
     6. php-fpm reload
     (bei Fehler: automatischer Rollback auf Backup)
```

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
- `admin` / `makler` / `backoffice` → Admin-Dashboard
- `kunde` → Portal-Dashboard (nur eigene Immobilien)

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
