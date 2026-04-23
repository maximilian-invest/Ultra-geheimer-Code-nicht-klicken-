# Design-Richtlinie: Selektierbare Items

**Gilt für:** Pills, Tiles, Toggle-Gruppen, Ampel-Karten, Tri-State-Buttons — überall wo der User aus Optionen eine auswählt.

## Kernprinzip

**Subtile 1px-Border + shadow-Wechsel + Orange-Fill bei Aktiv.**
**Keine `border-2`. Keine dicken schwarzen Rahmen.**
Shadow und Farb-Fill tragen das Feedback, nicht die Border.

**Selection-Farbe:** `orange-500` (passt zur SR-Homes-Brand `#EE7600`).
Der Theme-`--primary` (near-black) ist bewusst nicht die Selection-Farbe —
Primary-Schwarz ist für CTAs (Absenden, Speichern), Orange für Auswahl-Status.

## Canonical Classes

### Kleine Pills (ToggleGroup, Multi-Select, Single-Select)

```
rounded-full px-3.5 h-9 text-xs font-medium
border border-border bg-card shadow-sm transition-all
hover:shadow
data-[state=on]:bg-orange-500
data-[state=on]:text-white
data-[state=on]:border-transparent
data-[state=on]:shadow-md
data-[state=on]:shadow-orange-500/40
```

### Große Tiles (4-Spalten-Kacheln, Ampel-Karten)

```
rounded-lg p-3 text-center border transition-all
[inactive:]
bg-card border-border shadow-sm
hover:shadow hover:-translate-y-0.5
[active:]
bg-orange-500 text-white border-transparent
shadow-lg shadow-orange-500/40
```

### Feature-Toggles (breite Text-Buttons)

```
justify-start h-10
border border-border bg-card shadow-sm transition-all
hover:shadow
data-[state=on]:bg-orange-500
data-[state=on]:text-white
data-[state=on]:border-transparent
data-[state=on]:shadow-md
data-[state=on]:shadow-orange-500/40
```

### Semantische Tri-State (DocumentChecklistItem: Da / Fehlt / N/A)

Gleiche Struktur, aber **semantische Farb-Kodierung** statt Primary:

| Status | Farbe | Klassen |
|---|---|---|
| `available` („Da") | Grün | `data-[state=on]:bg-emerald-600 data-[state=on]:text-white data-[state=on]:shadow-emerald-500/25` |
| `missing` („Fehlt") | Destructive | `data-[state=on]:bg-destructive data-[state=on]:text-destructive-foreground data-[state=on]:shadow-destructive/25` |
| `na` („N/A") | Muted | `data-[state=on]:bg-muted-foreground data-[state=on]:text-background` |

## Was NICHT tun

- ❌ `border-2` — zu hart, sieht nicht nach shadcn aus
- ❌ `bg-accent` als Aktiv-Farbe — in diesem Theme fast identisch mit `bg-card`, unsichtbar
- ❌ Nur Border-Farbe wechseln — reicht nicht als Feedback
- ❌ `ring-4` oder sehr dicke Ringe — auch zu laut
- ❌ Selbst-gemischte Orange-Hex-Farben — immer über `bg-primary` (Theme-Variable)

## Was IST OK

- ✅ `border-transparent` bei aktiv (Fill nimmt visuell die Border-Rolle ein)
- ✅ `shadow-md shadow-primary/25` für dezenten Primary-Glow
- ✅ `hover:shadow` für sanften Hover
- ✅ Farbgebundene Shadow-Glows (`shadow-emerald-500/25`, `shadow-destructive/25`) bei semantischen Buttons
- ✅ Leichte Hover-Lift (`hover:-translate-y-0.5`) bei großen Tiles

## Referenz-Files

- `resources/js/Components/Admin/IntakeProtocol/shared/PillRow.vue` — kleine Single-Pills
- `resources/js/Components/Admin/IntakeProtocol/shared/MultiPillRow.vue` — kleine Multi-Pills
- `resources/js/Components/Admin/IntakeProtocol/shared/DocumentChecklistItem.vue` — semantische Tri-State
- `resources/js/Components/Admin/IntakeProtocol/steps/Step01_ObjectType.vue` — große Tiles
- `resources/js/Components/Admin/IntakeProtocol/steps/Step08_LegalDocuments.vue` — Ampel-Tiles
- `resources/js/Components/Admin/IntakeProtocol/steps/Step06_FeaturesParking.vue` — Feature-Buttons + Allgemeinräume-Pills

Neue Komponenten sollten sich an diese Klassen halten.
