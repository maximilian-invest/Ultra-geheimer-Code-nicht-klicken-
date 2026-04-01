# Dashboard (TodayTab) Redesign — Design Spec

**Ziel:** TodayTab.vue komplett auf shadcn-vue Komponenten umstellen. Gleiche Funktionalität, neues Design mit Card, Table, Badge und unovis Charts. Akzentfarbe Orange (#f97316).

**Scope:** Nur `TodayTab.vue` wird geändert. Keine Backend-Änderungen. Keine Funktions-Änderungen. Alle bestehenden Daten, APIs, Modals bleiben erhalten.

---

## Neue shadcn-Komponenten installieren

Folgende Komponenten müssen via `npx shadcn-vue@latest add` installiert werden:

- **card** — Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter
- **table** — Table, TableHeader, TableBody, TableRow, TableHead, TableCell
- **badge** — Badge (für Status-Labels und sekundäre KPIs)
- **select** — Select, SelectTrigger, SelectValue, SelectContent, SelectItem (für Ranking-Filter)
- **chart** — ChartContainer, ChartTooltip, ChartCrosshair, ChartTooltipContent (shadcn chart wrapper)

Neue npm-Abhängigkeit:
- `@unovis/vue` und `@unovis/ts` — Chart-Rendering-Engine für shadcn Charts

ApexCharts (`apexcharts`, `vue3-apexcharts`) wird nach Migration entfernt.

## Layout-Struktur (von oben nach unten)

### 1. Action Card — Tagesüberblick

**Komponente:** shadcn `Card`

- **CardHeader:** Titel "Tagesüberblick", CardDescription "Hier ist was heute ansteht"
- **Rechts im Header:** Sekundäre KPIs als shadcn `Badge` Komponenten:
  - Unbeantwortet (rot) — nur wenn > 0
  - Nachfassen (orange) — nur wenn > 0
  - Posteingang (blau) — nur wenn > 0
- **CardContent:** Klickbare Action Items als Liste (wie bisher):
  - Farbiger Dot links (rot=dringend, orange=fällig, teal=besichtigung, grün=tasks)
  - Text mit Anzahl
  - Status-Badge rechts (Dringend, Fällig)
  - ChevronRight Icon
  - Klick navigiert zum entsprechenden Tab via `switchTab()`
- **"Alles erledigt"** State: Grüner CheckCircle wenn keine offenen Aktionen

### 2. KPI Cards — 4er Grid

**Komponente:** shadcn `Card` × 4 in `grid grid-cols-2 md:grid-cols-4 gap-4`

Die 4 wichtigsten KPIs als große Cards:

| Card | Titel | Wert | Trend-Subtitle | Icon | Klick-Aktion |
|------|-------|------|----------------|------|-------------|
| Kaufanbote | "Kaufanbote" | `realKaufanbote.length` | "+X diese Woche" (grün) | BadgeCheck | `showKaufanboteModal = true` |
| Verkaufsvolumen | "Verkaufsvolumen" | `€ salesVolumeData.total_volume` | "+X% vs. Vorjahr" (grün) | DollarSign (lucide) | `showSalesModal = true` |
| Provisionen | "Provisionen" | `€ commissionData.total_makler` | "Netto Makler" (muted) | Wallet (lucide) | `showCommissionModal = true` |
| Objekte | "Objekte" | `stats.properties` | "X aktiv beworben" (muted) | Home | `switchTab('properties')` |

Jede Card:
- **CardHeader:** Titel (text-sm text-muted-foreground) + Icon rechts (text-muted-foreground)
- **CardContent:** Großer Zahlenwert (text-2xl font-bold) + Trend-Text darunter (text-xs)
- Provisionen-Card: Hidden für Assistenz (`v-if="userType !== 'assistenz'"`)
- Provisionen ausgeblendet → 3er Grid statt 4er

### 3. Charts — shadcn Chart mit @unovis/vue

**Komponente:** shadcn `Card` mit `ChartContainer` + unovis Komponenten

Layout: Zwei Reihen mit `grid grid-cols-1 lg:grid-cols-7 gap-4`:

**Reihe 1:**
- **Anfragen-Trend** (lg:col-span-4): Area Chart via `VisXYContainer` + `VisArea` + `VisLine`
  - CardHeader: Titel + "Letzte 8 Wochen"
  - Daten: `perfData.trend_labels` / `perfData.trend_data` (bestehende API)
  - Farbe: `--chart-1` (orange)
- **Plattform-Verteilung** (lg:col-span-3): Donut via `VisDonut` (oder `VisBulletLegend` + `VisSingleContainer`)
  - CardHeader: Titel + "Anfragen nach Quelle"
  - Daten: `perfData.platform_labels` / `perfData.platform_data`
  - Farben: chart-1 bis chart-5

**Reihe 2:**
- **Verkaufstrichter** (lg:col-span-4): Horizontal Bar via `VisGroupedBar`
  - CardHeader: Titel + "Conversion Pipeline"
  - Daten: Anfragen → Besichtigungen → Kaufanbote (aus perfData)
  - Farbe: Orange-Gradient
- **Antwortzeit** (lg:col-span-3): Radial/Gauge
  - CardHeader: Titel + "Durchschnitt"
  - Daten: `perfData.avg_response_h`
  - Grün <4h, Gelb 4-24h, Rot >24h

Alle Charts nutzen `ChartContainer` mit `ChartConfig` Objekt für Labels und Farben. `ChartTooltip` + `ChartCrosshair` für Interaktivität.

### 4. Termine diese Woche

**Komponente:** shadcn `Card`

- **CardHeader:** Titel "Termine diese Woche", rechts Button "Alle anzeigen →" (`switchTab('calendar')`)
- **CardContent:** Liste der Events (`upcomingEvents.slice(0, 5)`)
  - Icon-Box links (teal für Besichtigung, orange für sonstige)
  - Titel + Datum/Uhrzeit/Ort
  - shadcn `Badge` variant="outline" für "Besichtigung"
- Nur anzeigen wenn `upcomingEvents.length > 0`

### 5. Makler-Ranking

**Komponente:** shadcn `Card` mit shadcn `Table`

- **CardHeader:** Titel "Makler-Ranking", rechts zwei shadcn `Select` Dropdowns (Zeitraum + Sortierung)
- **CardContent:** shadcn Table
  - `TableHeader` mit sortierbaren Spalten (Klick ändert `rankingSort`)
  - Aktive Sortier-Spalte in Orange hervorgehoben
  - `TableBody` mit Ranking-Zeilen
  - Platz 1-3 mit farbigen Medaillen-Badges (Gold, Silber, Bronze)
  - Antwortzeit farbcodiert: grün <4h, gelb 4-24h, rot >24h
- Nur anzeigen wenn `rankingData.length > 1`

### 6. Modals (unverändert)

Die drei bestehenden Modals (Kaufanbote, Verkaufsvolumen, Provisionen) bleiben funktional identisch. Styling wird minimal angepasst um shadcn Card-Patterns zu nutzen (CardHeader/CardContent statt custom Divs), aber die Logik bleibt exakt gleich.

## Responsive Verhalten

- **Mobile (<768px):** KPI-Grid wird 2x2, Charts werden einspaltrig, Ranking-Tabelle horizontal scrollbar
- **Tablet (768-1024px):** KPI-Grid 4er, Charts 4/3 Split
- **Desktop (>1024px):** Volle Breite wie im Mockup

## Dark Mode

Alle shadcn-Komponenten unterstützen Dark Mode automatisch über CSS-Variablen. Charts nutzen `ChartConfig` Farben die sich an `--chart-*` Variablen orientieren. Keine manuellen Dark Mode Anpassungen nötig.

## Dateien die geändert werden

1. **Modify:** `resources/js/Components/Admin/TodayTab.vue` — Komplett-Redesign
2. **Install:** shadcn-Komponenten: card, table, badge, select, chart
3. **Install:** npm: `@unovis/vue`, `@unovis/ts`
4. **Remove:** npm: `apexcharts`, `vue3-apexcharts` (nach Migration)
