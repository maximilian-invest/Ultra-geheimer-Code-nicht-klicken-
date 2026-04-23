# Adaptives Exposé · Generator (Phase 1) · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Baue das adaptive Exposé-Rendering-System (Backend + minimale Admin-UI + Link-Integration). Makler klickt "Speichern", sieht Preview, bindet das Exposé in Freigabelinks ein, Kunde sieht es als HTML und kann PDF herunterladen.

**Architecture:** Laravel-Blade-Templates pro Seitentyp, serverseitiger `ExposePaginationService` berechnet Seiten-Umbrüche deterministisch, `ExposeConfigBuilder` erstellt Default-Konfiguration aus Property-Daten, `ExposePdfService` nutzt das bereits installierte Puppeteer für PDF-Export. Integration ins bestehende `property_links`-System via neuer Spalte `expose_version_id` in der Pivot-Tabelle — ohne Änderung am Mail-/Datenschutz-Gate.

**Tech Stack:** Laravel 11, Blade, Vue 3 (Inertia), Tailwind, Puppeteer (`v24.40.0` bereits in `package.json`), Leaflet (via CDN für Lage-Karte).

**Out of Scope (Phase 2, separater Plan):** Editor-UI mit Bild-Picker, Layout-Wahl pro Seite, Claim-Textfeld, Editorial-Spreads (M1–M4). Phase 1 erzeugt immer ein Default-Exposé mit automatischer Bildverteilung.

**Simplification bei Umbruchregeln:** Die Spec definiert in Sektion 5 sechs harte Regeln (60–92 % Füllgrad, Balance-Ziel 55–80 %/40–70 %, Waisen-Schutz). Phase 1 nutzt die **zwei wichtigsten** davon:
1. Textflow (kurz/mittel/lang → 1 / 2 / 3 Spalten) — im `ExposePaginationService`
2. `break-inside: avoid` auf allen Gruppen — CSS-nativ

Die anspruchsvolleren Regeln (Balance zwischen 2 Seiten, Mindest-Füllgrad pro Seite) werden erst dann nötig, wenn der Editor in Phase 2 beliebige Content-Mengen erlaubt. Für den Default-Generator in Phase 1 reichen die zwei Regeln, weil die Menge fix aus Property-Daten kommt.

**Referenz-Spec:** `docs/superpowers/specs/2026-04-23-adaptive-expose-design.md`

---

## File Structure

### Neu zu erstellen

**Migrations (3):**
- `database/migrations/2026_04_23_140000_create_property_expose_versions_table.php`
- `database/migrations/2026_04_23_140100_add_expose_claim_to_properties.php`
- `database/migrations/2026_04_23_140200_add_expose_version_id_to_property_link_documents.php`

**Model:**
- `app/Models/PropertyExposeVersion.php`

**Services:**
- `app/Services/Expose/ExposeConfigBuilder.php` — Default-Konfiguration aus Property-Daten
- `app/Services/Expose/ExposePaginationService.php` — Umbruchregeln (60–92 % Füllgrad, keine Waisen, Spaltenzahl)
- `app/Services/Expose/ExposePdfService.php` — Puppeteer-Wrapper für PDF-Export
- `app/Services/Expose/ExposeRenderContext.php` — Struct-artige Value-Klasse mit allen für Templates nötigen Daten

**Controllers:**
- `app/Http/Controllers/Admin/ExposeController.php` — Admin-Routen (preview, save)

**Blade-Templates (alle unter `resources/views/expose/`):**
- `layout.blade.php` — Master-Template (A4-Bogen, Fonts, Page-Wrapper)
- `styles.blade.php` — Gemeinsames CSS als Partial
- `pages/cover.blade.php`
- `pages/details.blade.php`
- `pages/haus.blade.php`
- `pages/lage.blade.php`
- `pages/impressionen.blade.php`
- `pages/kontakt.blade.php`

**Vue-Component:**
- `resources/js/Components/Admin/property-detail/ExposeTab.vue`

**Tests:**
- `tests/Unit/Expose/ExposeConfigBuilderTest.php`
- `tests/Unit/Expose/ExposePaginationServiceTest.php`
- `tests/Feature/Admin/ExposeControllerTest.php`
- `tests/Feature/Public/ExposePublicViewTest.php`
- `tests/Feature/Public/ExposePdfDownloadTest.php`

### Zu modifizieren

- `app/Models/Property.php` — `expose_claim` in `$fillable`
- `app/Models/PropertyLink.php` — neue Relation `exposeVersion()`
- `app/Http/Controllers/PublicDocumentController.php` — zwei neue Methoden `expose()` + `exposePdf()`
- `routes/web.php` — neue Public-Routes `/docs/{token}/expose` und `/docs/{token}/expose.pdf`, neue Admin-Routes `/admin/properties/{property}/expose/*`
- `resources/js/Components/Admin/PropertyDetailPage.vue` — neuer Tab „Exposé"
- `resources/views/docs/partials/_unlocked.blade.php` — Exposé als Hero-Item oben in File-Liste
- `app/Http/Controllers/Admin/PropertyLinkController.php` — Exposé als auswählbares "virtuelles File" im Datei-Picker

---

## Phase A · Datenbank & Models

### Task 1 · Migration `property_expose_versions`

**Files:**
- Create: `database/migrations/2026_04_23_140000_create_property_expose_versions_table.php`

- [ ] **Step 1: Migration-Datei anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_expose_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200)->nullable();
            $table->longText('config_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['property_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_expose_versions');
    }
};
```

- [ ] **Step 2: Migration ausführen**

```bash
php artisan migrate
```

Expected: `2026_04_23_140000_create_property_expose_versions_table .............. DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_23_140000_create_property_expose_versions_table.php
git commit -m "feat: migration property_expose_versions"
```

### Task 2 · Migration `expose_claim` auf `properties`

**Files:**
- Create: `database/migrations/2026_04_23_140100_add_expose_claim_to_properties.php`
- Modify: `app/Models/Property.php` (fillable-Liste)

- [ ] **Step 1: Migration anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('expose_claim', 200)->nullable()->after('highlights');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('expose_claim');
        });
    }
};
```

- [ ] **Step 2: Zu `fillable` in `app/Models/Property.php` hinzufügen**

Im `$fillable`-Array bei den anderen Marketing-Feldern (in der Nähe von `highlights`) einfügen:

```php
'highlights', 'expose_claim',
```

- [ ] **Step 3: Migration ausführen + Test**

```bash
php artisan migrate
php artisan tinker --execute="echo \App\Models\Property::find(1)?->expose_claim === null ? 'ok' : 'fail';"
```

Expected: `ok`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_23_140100_add_expose_claim_to_properties.php app/Models/Property.php
git commit -m "feat: expose_claim column on properties"
```

### Task 3 · Migration `expose_version_id` auf `property_link_documents`

**Files:**
- Create: `database/migrations/2026_04_23_140200_add_expose_version_id_to_property_link_documents.php`

- [ ] **Step 1: Migration anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_link_documents', function (Blueprint $table) {
            $table->foreignId('expose_version_id')
                ->nullable()
                ->after('property_file_id')
                ->constrained('property_expose_versions')
                ->nullOnDelete();
            $table->unsignedBigInteger('property_file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('property_link_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expose_version_id');
        });
    }
};
```

- [ ] **Step 2: Ausführen**

```bash
php artisan migrate
```

Expected: `2026_04_23_140200_add_expose_version_id_to_property_link_documents .... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_23_140200_add_expose_version_id_to_property_link_documents.php
git commit -m "feat: expose_version_id pivot on property_link_documents"
```

### Task 4 · Model `PropertyExposeVersion`

**Files:**
- Create: `app/Models/PropertyExposeVersion.php`
- Modify: `app/Models/PropertyLink.php`
- Test: `tests/Unit/Expose/PropertyExposeVersionModelTest.php`

- [ ] **Step 1: Failing Test**

`tests/Unit/Expose/PropertyExposeVersionModelTest.php`:

```php
<?php

namespace Tests\Unit\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyExposeVersionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_json_is_cast_to_array(): void
    {
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [['type' => 'cover']]],
        ]);

        $fresh = PropertyExposeVersion::find($version->id);
        $this->assertIsArray($fresh->config_json);
        $this->assertEquals('cover', $fresh->config_json['pages'][0]['type']);
    }

    public function test_belongs_to_property(): void
    {
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => [],
        ]);

        $this->assertEquals($property->id, $version->property->id);
    }
}
```

- [ ] **Step 2: Test läuft ohne Model → FAIL**

```bash
php artisan test --filter=PropertyExposeVersionModelTest
```

Expected: `Class "App\Models\PropertyExposeVersion" not found`

- [ ] **Step 3: Model schreiben**

`app/Models/PropertyExposeVersion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyExposeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'created_by', 'name', 'config_json', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'is_active'   => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 4: Relation auf `PropertyLink` ergänzen**

In `app/Models/PropertyLink.php` als zusätzliche Methode (nach `documentIds()`):

```php
public function exposeVersionIds(): \Illuminate\Support\Collection
{
    return \DB::table('property_link_documents')
        ->where('property_link_id', $this->id)
        ->whereNotNull('expose_version_id')
        ->pluck('expose_version_id');
}
```

- [ ] **Step 5: Test läuft → PASS**

```bash
php artisan test --filter=PropertyExposeVersionModelTest
```

Expected: `OK (2 tests, 4 assertions)`

- [ ] **Step 6: Commit**

```bash
git add app/Models/PropertyExposeVersion.php app/Models/PropertyLink.php tests/Unit/Expose/
git commit -m "feat: PropertyExposeVersion model + link relation"
```

---

## Phase B · Services

### Task 5 · `ExposeConfigBuilder` (Default-Konfiguration)

**Files:**
- Create: `app/Services/Expose/ExposeConfigBuilder.php`
- Test: `tests/Unit/Expose/ExposeConfigBuilderTest.php`

Die Rolle: Eine Property wird reingegeben, eine vollständige Exposé-Config kommt raus. Entscheidet welches Bild Cover ist (falls `is_title_image=true`, sonst erstes per `sort_order`), verteilt übrige Bilder auf Impressionen-Seiten anhand Layout-Tabelle aus Spec Sektion 3.6.

- [ ] **Step 1: Failing Test**

`tests/Unit/Expose/ExposeConfigBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Expose;

use App\Models\Property;
use App\Models\PropertyImage;
use App\Services\Expose\ExposeConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposeConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function mkProperty(array $imageSpecs = []): Property
    {
        $p = Property::factory()->create([
            'realty_description' => 'Ein schönes Haus.',
        ]);
        foreach ($imageSpecs as $i => $spec) {
            PropertyImage::create(array_merge([
                'property_id'     => $p->id,
                'filename'        => "img{$i}.jpg",
                'path'            => "property_images/{$p->id}/img{$i}.jpg",
                'sort_order'      => $i,
                'is_title_image'  => false,
                'is_floorplan'    => false,
                'is_public'       => true,
                'category'        => 'sonstiges',
            ], $spec));
        }
        return $p->fresh();
    }

    public function test_minimal_property_produces_five_fixed_pages(): void
    {
        $p = $this->mkProperty();
        $config = (new ExposeConfigBuilder())->build($p);

        $types = array_column($config['pages'], 'type');
        $this->assertEquals(['cover', 'details', 'haus', 'lage', 'kontakt'], $types);
    }

    public function test_title_image_is_selected_as_cover(): void
    {
        $p = $this->mkProperty([
            ['is_title_image' => false],
            ['is_title_image' => true],
            ['is_title_image' => false],
        ]);

        $config = (new ExposeConfigBuilder())->build($p);
        $coverPage = collect($config['pages'])->firstWhere('type', 'cover');

        $titleImage = $p->images()->where('is_title_image', true)->first();
        $this->assertEquals($titleImage->id, $coverPage['image_id']);
    }

    public function test_four_images_produce_one_L4_impressionen_page(): void
    {
        $p = $this->mkProperty([[], [], [], [], ['is_title_image' => true]]);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($p) => $p['type'] === 'impressionen'
        ));

        $this->assertCount(1, $impressionen);
        $this->assertEquals('L4', $impressionen[0]['layout']);
        $this->assertCount(4, $impressionen[0]['image_ids']);
    }

    public function test_seven_images_produce_two_impressionen_pages(): void
    {
        $specs = array_fill(0, 8, []); // index 0 wird Title
        $specs[0]['is_title_image'] = true;
        $p = $this->mkProperty($specs);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($p) => $p['type'] === 'impressionen'
        ));

        // 7 Nicht-Cover-Bilder → L4 (4) + L3 (3)
        $this->assertCount(2, $impressionen);
        $this->assertEquals('L4', $impressionen[0]['layout']);
        $this->assertCount(4, $impressionen[0]['image_ids']);
        $this->assertEquals('L3', $impressionen[1]['layout']);
        $this->assertCount(3, $impressionen[1]['image_ids']);
    }
}
```

- [ ] **Step 2: Test läuft → FAIL**

```bash
php artisan test --filter=ExposeConfigBuilderTest
```

Expected: `Class "App\Services\Expose\ExposeConfigBuilder" not found`

- [ ] **Step 3: Service implementieren**

`app/Services/Expose/ExposeConfigBuilder.php`:

```php
<?php

namespace App\Services\Expose;

use App\Models\Property;

class ExposeConfigBuilder
{
    /**
     * Baut eine Default-Exposé-Konfiguration aus einer Property.
     * Seitenreihenfolge: cover → details → haus → lage → impressionen × n → kontakt.
     */
    public function build(Property $property): array
    {
        $images = $property->images()
            ->where('is_public', true)
            ->where('is_floorplan', false)
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $coverImage = $images->first();
        $rest = $images->slice(1)->values();

        $pages = [];
        $pages[] = ['type' => 'cover', 'image_id' => $coverImage?->id];
        $pages[] = ['type' => 'details'];
        $pages[] = [
            'type'     => 'haus',
            'image_id' => $rest->first()?->id,
        ];
        $pages[] = ['type' => 'lage'];

        // Bild, das bei "haus" verwendet wurde, nicht nochmal in Impressionen.
        $forImpressionen = $rest->slice(1)->values();
        foreach ($this->chunkForImpressionen($forImpressionen->pluck('id')->all()) as $chunk) {
            $pages[] = [
                'type'      => 'impressionen',
                'layout'    => $chunk['layout'],
                'image_ids' => $chunk['ids'],
            ];
        }

        $pages[] = ['type' => 'kontakt'];

        return [
            'claim_text' => null,
            'pages'      => $pages,
        ];
    }

    /**
     * Verteilt Bilder auf Impressionen-Seiten anhand Tabelle aus Spec 3.6.
     * Gibt Liste von ['layout' => 'L1-L5', 'ids' => [...]] zurück.
     */
    private function chunkForImpressionen(array $imageIds): array
    {
        $chunks = [];
        $i = 0;
        $n = count($imageIds);

        while ($i < $n) {
            $remaining = $n - $i;
            [$layout, $count] = match (true) {
                $remaining === 1       => ['L1', 1],
                $remaining === 2       => ['L2', 2],
                $remaining === 3       => ['L3', 3],
                $remaining === 5       => ['L5', 5],  // Mosaik 1+3 beschönigt auf 4, Rest 1 → besser: L4 + L1
                $remaining >= 4        => ['L4', 4],
                default                => ['L1', 1],
            };
            $chunks[] = [
                'layout' => $layout,
                'ids'    => array_slice($imageIds, $i, $count),
            ];
            $i += $count;
        }
        return $chunks;
    }
}
```

- [ ] **Step 4: Test läuft → PASS**

```bash
php artisan test --filter=ExposeConfigBuilderTest
```

Expected: `OK (4 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Expose/ExposeConfigBuilder.php tests/Unit/Expose/ExposeConfigBuilderTest.php
git commit -m "feat: ExposeConfigBuilder default config from property"
```

### Task 6 · `ExposePaginationService` (Text-Flow-Regeln)

**Files:**
- Create: `app/Services/Expose/ExposePaginationService.php`
- Test: `tests/Unit/Expose/ExposePaginationServiceTest.php`

Rolle: Gibt ein Wörter-Count für einen Text zurück und entscheidet den Layout-Mode (`short`, `medium`, `long`). Diese einfache Funktion reicht für Phase 1. Die Gruppen-Balancing-Regel (Sektion 5) wird CSS-nativ durch `break-inside: avoid` gelöst — kein PHP-Service dafür nötig.

- [ ] **Step 1: Failing Test**

`tests/Unit/Expose/ExposePaginationServiceTest.php`:

```php
<?php

namespace Tests\Unit\Expose;

use App\Services\Expose\ExposePaginationService;
use Tests\TestCase;

class ExposePaginationServiceTest extends TestCase
{
    public function test_short_text_below_80_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('short', $svc->textFlowMode(str_repeat('wort ', 50)));
    }

    public function test_medium_text_80_to_400_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('medium', $svc->textFlowMode(str_repeat('wort ', 200)));
    }

    public function test_long_text_above_400_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('long', $svc->textFlowMode(str_repeat('wort ', 500)));
    }

    public function test_empty_text_is_short(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('short', $svc->textFlowMode(''));
        $this->assertEquals('short', $svc->textFlowMode(null));
    }
}
```

- [ ] **Step 2: Test läuft → FAIL**

```bash
php artisan test --filter=ExposePaginationServiceTest
```

Expected: `Class "App\Services\Expose\ExposePaginationService" not found`

- [ ] **Step 3: Service implementieren**

`app/Services/Expose/ExposePaginationService.php`:

```php
<?php

namespace App\Services\Expose;

class ExposePaginationService
{
    public const SHORT_MAX = 80;
    public const MEDIUM_MAX = 400;

    /**
     * Ermittelt den Text-Flow-Modus (short/medium/long) anhand Wörteranzahl.
     * Entscheidet: kurzer Text → 1 Spalte + Bild, mittlerer → 2-spaltig,
     * langer → 3-spaltig mit Umbruch auf Folgeseite.
     */
    public function textFlowMode(?string $text): string
    {
        $count = $this->wordCount($text);
        if ($count <= self::SHORT_MAX)  return 'short';
        if ($count <= self::MEDIUM_MAX) return 'medium';
        return 'long';
    }

    public function wordCount(?string $text): int
    {
        if ($text === null || trim($text) === '') return 0;
        return str_word_count(strip_tags($text), 0, 'ÄÖÜäöüß');
    }
}
```

- [ ] **Step 4: Test läuft → PASS**

```bash
php artisan test --filter=ExposePaginationServiceTest
```

Expected: `OK (4 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Expose/ExposePaginationService.php tests/Unit/Expose/ExposePaginationServiceTest.php
git commit -m "feat: ExposePaginationService text flow mode"
```

### Task 7 · `ExposeRenderContext` (Value-Struct)

**Files:**
- Create: `app/Services/Expose/ExposeRenderContext.php`

Eine read-only Value-Klasse, die alle für Blade-Templates benötigten Daten vorbereitet. Entkoppelt Templates vom Eloquent-Model, macht Testing einfacher.

- [ ] **Step 1: Klasse schreiben**

`app/Services/Expose/ExposeRenderContext.php`:

```php
<?php

namespace App\Services\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\User;

class ExposeRenderContext
{
    public function __construct(
        public readonly Property $property,
        public readonly PropertyExposeVersion $version,
        public readonly ?User $broker,
        public readonly array $pages,
        public readonly string $hausTextMode,
        public readonly string $lageTextMode,
        public readonly ?string $claimText,
    ) {}

    public static function build(
        PropertyExposeVersion $version,
        ExposePaginationService $pagination,
    ): self {
        $property = $version->property;
        $config = $version->config_json;

        return new self(
            property: $property,
            version: $version,
            broker: $property->broker_id ? User::find($property->broker_id) : null,
            pages: $config['pages'] ?? [],
            hausTextMode: $pagination->textFlowMode($property->realty_description ?? ''),
            lageTextMode: $pagination->textFlowMode($property->location_description ?? ''),
            claimText: $property->expose_claim ?: ($config['claim_text'] ?? null),
        );
    }

    /** Findet Bild-Datensatz anhand der image_id aus der Config. */
    public function image(?int $imageId): ?\App\Models\PropertyImage
    {
        if (!$imageId) return null;
        return $this->property->images()->find($imageId);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Expose/ExposeRenderContext.php
git commit -m "feat: ExposeRenderContext value struct"
```

---

## Phase C · Blade-Templates

Alle Templates leben unter `resources/views/expose/`. Gemeinsames Format: A4 Querformat (297×210 mm).

### Task 8 · Master-Layout + gemeinsames CSS

**Files:**
- Create: `resources/views/expose/layout.blade.php`
- Create: `resources/views/expose/styles.blade.php`

- [ ] **Step 1: CSS-Partial schreiben**

`resources/views/expose/styles.blade.php`:

```blade
<style>
  @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,300;0,500;0,700;1,300;1,500;1,700&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;1,9..144,300&display=swap');

  :root {
    --accent: #ee7600;
    --text-primary: #1a1a1a;
    --text-secondary: #666;
    --border: #e5e7eb;
    --bg-cream: #fdfcfa;
    --font-serif: Georgia, serif;
    --font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--font-sans);
    color: var(--text-primary);
    background: #f3f4f6;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .page {
    width: 297mm; height: 210mm;
    background: #fff;
    position: relative;
    overflow: hidden;
    page-break-after: always;
    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
    margin: 24px auto;
  }

  .page:last-child { page-break-after: auto; }

  @media print {
    body { background: #fff; }
    .page { box-shadow: none; margin: 0; }
  }

  .page .pn {
    position: absolute; top: 30px; right: 42px;
    font-size: 12px; color: #bbb; letter-spacing: 2.5px; font-weight: 500;
  }
  .page .title-s {
    position: absolute; top: 38px; left: 48px;
    font-family: var(--font-serif); font-size: 36px; font-weight: 400;
    color: var(--text-primary); letter-spacing: 0.5px; line-height: 1;
  }
  .page .aline {
    position: absolute; top: 92px; left: 48px;
    width: 48px; height: 3px; background: var(--accent);
  }

  /* Gruppen (Details-Seite) */
  .grp { break-inside: avoid; margin-bottom: 14px; }
  .grp:last-child { margin-bottom: 0; }
  .grp .gh {
    font-size: 11px; color: var(--accent); letter-spacing: 2.5px;
    text-transform: uppercase; font-weight: 700;
    padding-bottom: 5px; margin-bottom: 6px;
    border-bottom: 1px solid var(--border);
  }
  .grp .r {
    display: flex; justify-content: space-between;
    padding: 3.5px 0; border-bottom: 1px dotted #f0f0f0; gap: 14px;
  }
  .grp .r:last-child { border-bottom: none; }
  .grp .r .k { color: var(--text-secondary); font-size: 12px; flex-shrink: 0; }
  .grp .r .v { font-family: var(--font-serif); color: var(--text-primary); font-size: 13px; text-align: right; }
  .grp .r .v.total { color: var(--accent); font-weight: 700; }
</style>
```

- [ ] **Step 2: Master-Layout schreiben**

`resources/views/expose/layout.blade.php`:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=1200">
    <title>Exposé · {{ $ctx->property->title ?? $ctx->property->address }}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @include('expose.styles')
</head>
<body>
    @foreach ($ctx->pages as $i => $page)
        @php($pageNum = sprintf('%02d / %02d', $i + 1, count($ctx->pages)))
        @include("expose.pages.{$page['type']}", ['page' => $page, 'pageNum' => $pageNum, 'ctx' => $ctx])
    @endforeach

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @stack('scripts')
</body>
</html>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/expose/layout.blade.php resources/views/expose/styles.blade.php
git commit -m "feat: expose master layout + shared CSS"
```

### Task 9 · Cover-Template

**Files:**
- Create: `resources/views/expose/pages/cover.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/cover.blade.php`:

```blade
@php
    $img = $ctx->image($page['image_id'] ?? null);
    $imgUrl = $img ? asset('storage/' . $img->path) : null;
    $address = trim(($ctx->property->address ?? '') . ' ' . ($ctx->property->house_number ?? ''));
    $zipCity = trim(($ctx->property->zip ?? '') . ' ' . ($ctx->property->city ?? ''));
    $living = $ctx->property->living_area ? number_format($ctx->property->living_area, 0, ',', '.') . ' m²' : null;
    $rooms = $ctx->property->rooms_amount ? rtrim(rtrim(number_format($ctx->property->rooms_amount, 1, ',', ''), '0'), ',') . ' Zimmer' : null;
    $year = $ctx->property->construction_year ? 'Baujahr ' . $ctx->property->construction_year : null;
    $price = $ctx->property->purchase_price ? '€ ' . number_format($ctx->property->purchase_price, 0, ',', '.') : null;
    $badges = array_filter([$living, $rooms, $year]);
    $propertyType = $ctx->property->object_type ?: 'Immobilie';
@endphp

<style>
  .cover-page {
    position: relative;
  }
  .cover-page .bg { position: absolute; inset: 0; }
  .cover-page .bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cover-page::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.1) 25%, rgba(0,0,0,0.2) 55%, rgba(0,0,0,0.55) 100%);
  }
  .cover-page .logo {
    position: absolute; top: 36px; left: 48px;
    height: 30px; width: auto;
    filter: brightness(0) invert(1);
    z-index: 2;
  }
  .cover-page .kicker {
    position: absolute; top: 210px; left: 0; right: 0; text-align: center;
    font-size: 12px; color: rgba(255,255,255,0.85); letter-spacing: 6px;
    text-transform: uppercase; font-weight: 600; z-index: 2;
  }
  .cover-page .title {
    position: absolute; top: 238px; left: 0; right: 0; text-align: center;
    font-family: 'Playfair Display', serif; font-size: 52px; font-weight: 400;
    color: #fff; letter-spacing: 8px; text-transform: uppercase;
    text-shadow: 0 3px 12px rgba(0,0,0,0.4); z-index: 2; line-height: 1;
  }
  .cover-page .address {
    position: absolute; top: 320px; left: 0; right: 0; text-align: center;
    font-family: Georgia, serif; font-size: 18px; color: rgba(255,255,255,0.95);
    letter-spacing: 2.5px; font-style: italic; z-index: 2;
  }
  .cover-page .address::before, .cover-page .address::after {
    content: ''; display: inline-block; width: 34px; height: 1px;
    background: rgba(255,255,255,0.5); vertical-align: middle; margin: 0 18px;
  }
  .cover-page .badges {
    position: absolute; bottom: 52px; left: 0; right: 0;
    display: flex; justify-content: center; gap: 14px; z-index: 2;
  }
  .cover-page .badge {
    background: rgba(255,255,255,0.96); color: #222;
    padding: 12px 24px; border-radius: 22px;
    font-size: 15px; font-weight: 600;
    box-shadow: 0 3px 12px rgba(0,0,0,0.25);
  }
  .cover-page .badge.accent { background: var(--accent); color: #fff; }
</style>

<div class="page cover-page">
    @if ($imgUrl)
        <div class="bg"><img src="{{ $imgUrl }}" alt=""></div>
    @else
        <div class="bg" style="background:linear-gradient(135deg,#5d4e37,#1a1a1a)"></div>
    @endif

    <img class="logo" src="{{ asset('assets/logo-full-white.svg') }}" alt="SR Homes">
    <div class="kicker">{{ strtoupper($propertyType) }}</div>
    <div class="title">{{ $ctx->property->city ?: 'Immobilie' }}</div>
    @if ($address)
        <div class="address">{{ $address }}@if ($zipCity) · {{ $zipCity }}@endif</div>
    @endif

    <div class="badges">
        @foreach ($badges as $b)
            <div class="badge">{{ $b }}</div>
        @endforeach
        @if ($price)
            <div class="badge accent">{{ $price }}</div>
        @endif
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/cover.blade.php
git commit -m "feat: expose cover page template"
```

### Task 10 · Details-Template

**Files:**
- Create: `resources/views/expose/pages/details.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/details.blade.php`:

```blade
@php
    $p = $ctx->property;

    $fmtArea = fn($v) => $v ? number_format($v, 0, ',', '.') . ' m²' : null;
    $fmtMoney = fn($v) => $v !== null && $v !== '' ? '€ ' . number_format($v, 0, ',', '.') : null;
    $rooms = $p->rooms_amount ? rtrim(rtrim(number_format($p->rooms_amount, 1, ',', ''), '0'), ',') : null;

    // Nebenkosten-Summe
    $sum = 0;
    $costs = array_filter([
        'Betriebskosten' => $p->operating_costs,
        'Heizkosten'     => $p->heating_costs,
        'Warmwasser'     => $p->warm_water_costs,
        'Rücklagen'      => $p->maintenance_reserves,
    ], fn($v) => $v !== null);
    foreach ($costs as $v) $sum += (float) $v;

    // Parking-Text
    $parking = null;
    $garageSpaces = (int) ($p->garage_spaces ?? 0);
    $parkingSpaces = (int) ($p->parking_spaces ?? 0);
    if ($garageSpaces + $parkingSpaces > 0) {
        $parts = [];
        if ($garageSpaces > 0) $parts[] = $garageSpaces . ' Garage' . ($garageSpaces > 1 ? 'n' : '');
        if ($parkingSpaces > 0) $parts[] = $parkingSpaces . ' Stellpl.';
        $parking = implode(' · ', $parts);
    }

    // Helper zum Erstellen einer Row nur wenn Wert vorhanden
    $row = function ($k, $v, $total = false) {
        if ($v === null || $v === '') return '';
        $cls = $total ? ' total' : '';
        return '<div class="r"><span class="k">' . e($k) . '</span><span class="v' . $cls . '">' . e($v) . '</span></div>';
    };
@endphp

<style>
  .details-page .grid {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1fr 1fr; column-gap: 56px;
  }
</style>

<div class="page details-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Details</div>
    <div class="aline"></div>
    <div class="grid">
        {{-- Linke Spalte: Objekt + Nebenkosten --}}
        <div>
            <div class="grp">
                <div class="gh">Objekt</div>
                {!! $row('Objektart', $p->object_type) !!}
                {!! $row('Zimmer', $rooms) !!}
                {!! $row('Baujahr', $p->construction_year) !!}
                {!! $row('Wohnfläche', $fmtArea($p->living_area)) !!}
                {!! $row('Grundstück', $fmtArea($p->realty_area)) !!}
                {!! $row('Verfügbar ab', $p->available_from?->format('d.m.Y') ?: $p->available_text ?: null) !!}
            </div>

            @if ($costs)
                <div class="grp">
                    <div class="gh">Nebenkosten (monatlich)</div>
                    @foreach ($costs as $k => $v)
                        {!! $row($k, $fmtMoney($v)) !!}
                    @endforeach
                    @if ($sum > 0)
                        {!! $row('Summe', $fmtMoney($sum), true) !!}
                    @endif
                </div>
            @endif
        </div>

        {{-- Rechte Spalte: Flächen + Ausstattung + Energie --}}
        <div>
            <div class="grp">
                <div class="gh">Flächen &amp; Räume</div>
                {!! $row('Balkon', $fmtArea($p->area_balcony)) !!}
                {!! $row('Terrasse', $fmtArea($p->area_terrace)) !!}
                {!! $row('Garten', $fmtArea($p->area_garden)) !!}
                {!! $row('Keller', $fmtArea($p->area_basement)) !!}
                {!! $row('Stellplatz', $parking) !!}
            </div>

            <div class="grp">
                <div class="gh">Ausstattung</div>
                {!! $row('Bodenbelag', $p->flooring) !!}
                {!! $row('Bad', $p->bathroom_equipment) !!}
                {!! $row('Küche', $p->has_fitted_kitchen ? 'inkl. Einbauküche' : null) !!}
                {!! $row('Ausrichtung', $p->orientation) !!}
            </div>

            <div class="grp">
                <div class="gh">Energie</div>
                {!! $row('Heizung', $p->heating) !!}
                {!! $row('HWB', $p->heating_demand_value ? $p->heating_demand_value . ' kWh/m²a' : null) !!}
                {!! $row('Energieklasse', $p->heating_demand_class) !!}
                {!! $row('Photovoltaik', $p->has_photovoltaik ? 'ja' : null) !!}
                {!! $row('Wohnraumlüftung', $p->has_wohnraumlueftung ? 'ja' : null) !!}
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/details.blade.php
git commit -m "feat: expose details page template"
```

### Task 11 · Haus-Template (adaptiv)

**Files:**
- Create: `resources/views/expose/pages/haus.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/haus.blade.php`:

```blade
@php
    $img = $ctx->image($page['image_id'] ?? null);
    $imgUrl = $img ? asset('storage/' . $img->path) : null;
    $text = $ctx->property->realty_description ?? '';
    $mode = $ctx->hausTextMode;
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n+/', $text))));
    $leadSentence = '';
    $rest = $paragraphs;
    if (!empty($paragraphs)) {
        // Ersten Satz als Lead extrahieren
        $first = $paragraphs[0];
        if (preg_match('/^(.+?[.!?])(\s|$)(.*)/s', $first, $m)) {
            $leadSentence = trim($m[1]);
            $rest = array_merge([trim($m[3])], array_slice($paragraphs, 1));
            $rest = array_values(array_filter($rest));
        } else {
            $leadSentence = $first;
            $rest = array_slice($paragraphs, 1);
        }
    }
@endphp

<style>
  .haus-page .layout {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: flex; gap: 32px;
  }
  .haus-page .txt { flex: 1.2; }
  .haus-page .img-wrap { flex: 1; border-radius: 3px; overflow: hidden; }
  .haus-page .img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .haus-page .lead {
    font-family: Georgia, serif; font-style: italic; font-size: 18px; line-height: 1.4;
    color: var(--text-primary); margin-bottom: 14px;
  }
  .haus-page .p { font-size: 13px; line-height: 1.6; color: #333; margin-bottom: 8px; }
  .haus-page .cols-2 { column-count: 2; column-gap: 24px; column-rule: 1px solid var(--border); }
  .haus-page .cols-3 { column-count: 3; column-gap: 18px; column-rule: 1px solid var(--border); }
  .haus-page .cont-hint {
    position: absolute; bottom: 14px; right: 48px;
    font-size: 10px; color: var(--accent); letter-spacing: 2px;
    text-transform: uppercase; font-weight: 700;
  }
</style>

<div class="page haus-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Das Haus</div>
    <div class="aline"></div>

    @if ($mode === 'short')
        <div class="layout">
            <div class="txt">
                @if ($leadSentence)
                    <div class="lead">{{ $leadSentence }}</div>
                @endif
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
            @if ($imgUrl)
                <div class="img-wrap"><img src="{{ $imgUrl }}" alt=""></div>
            @endif
        </div>
    @elseif ($mode === 'medium')
        <div class="layout" style="display:block">
            @if ($leadSentence)
                <div class="lead">{{ $leadSentence }}</div>
            @endif
            <div class="cols-2">
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
        </div>
    @else
        <div class="layout" style="display:block">
            @if ($leadSentence)
                <div class="lead">{{ $leadSentence }}</div>
            @endif
            <div class="cols-3">
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/haus.blade.php
git commit -m "feat: expose haus page template (adaptive)"
```

### Task 12 · Lage-Template

**Files:**
- Create: `resources/views/expose/pages/lage.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/lage.blade.php`:

```blade
@php
    $p = $ctx->property;
    $lat = (float) ($p->latitude ?? 47.7529);
    $lng = (float) ($p->longitude ?? 13.0260);
    $mapId = 'map-' . substr(md5($p->id . '-' . microtime()), 0, 8);
    $text = $p->location_description ?? '';
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n+/', $text))));
    $lead = '';
    $rest = [];
    if (!empty($paragraphs)) {
        $first = $paragraphs[0];
        if (preg_match('/^(.+?[.!?])(\s|$)(.*)/s', $first, $m)) {
            $lead = trim($m[1]);
            $rest = array_merge([trim($m[3])], array_slice($paragraphs, 1));
            $rest = array_values(array_filter($rest));
        } else {
            $lead = $first;
            $rest = array_slice($paragraphs, 1);
        }
    }
@endphp

<style>
  .lage-page .grid {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1.1fr 1fr; gap: 32px;
  }
  .lage-page .map-container {
    border-radius: 3px; overflow: hidden; position: relative;
    border: 1px solid var(--border);
  }
  .lage-page .map-container .mapbox { width: 100%; height: 100%; }
  .lage-page .map-container .leaflet-tile-pane { filter: grayscale(1) contrast(1.05); }
  .lage-page .map-badge {
    position: absolute; bottom: 14px; left: 16px; z-index: 400;
    font-family: Georgia, serif; font-size: 15px; color: var(--text-primary);
    background: rgba(255,255,255,0.94); padding: 6px 14px; border-radius: 2px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  }
  .lage-page .txt .lead { font-family: Georgia, serif; font-style: italic; font-size: 16px; line-height: 1.4; color: var(--text-primary); margin-bottom: 12px; }
  .lage-page .txt .p { font-size: 12px; line-height: 1.55; color: #333; margin-bottom: 6px; }
</style>

<div class="page lage-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Lage</div>
    <div class="aline"></div>
    <div class="grid">
        <div class="map-container">
            <div id="{{ $mapId }}" class="mapbox"></div>
            @if ($p->city)
                <div class="map-badge">{{ strtoupper($p->city) }}</div>
            @endif
        </div>
        <div class="txt">
            @if ($lead)
                <div class="lead">{{ $lead }}</div>
            @endif
            @foreach ($rest as $para)
                <div class="p">{{ $para }}</div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function() {
        function init() {
            if (!window.L) { setTimeout(init, 80); return; }
            var el = document.getElementById({!! json_encode($mapId) !!});
            if (!el) return;
            var map = L.map(el, { scrollWheelZoom: false, zoomControl: false, attributionControl: false })
                       .setView([{{ $lat }}, {{ $lng }}], 14);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                subdomains: 'abcd', maxZoom: 19
            }).addTo(map);
            L.circle([{{ $lat }}, {{ $lng }}], {
                radius: 400, color: '#ee7600', weight: 2.5,
                fillColor: '#ee7600', fillOpacity: 0.22
            }).addTo(map);
        }
        init();
    })();
</script>
@endpush
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/lage.blade.php
git commit -m "feat: expose lage page template (leaflet)"
```

### Task 13 · Impressionen-Template

**Files:**
- Create: `resources/views/expose/pages/impressionen.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/impressionen.blade.php`:

```blade
@php
    $layout = $page['layout'] ?? 'L4';
    $ids = $page['image_ids'] ?? [];
    $imgs = collect($ids)->map(fn($id) => $ctx->image($id))->filter()->values();
    $url = fn($img) => asset('storage/' . $img->path);
@endphp

<style>
  .impr-page .box {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; gap: 8px;
  }
  .impr-page .box.L1 { grid-template-columns: 1fr; }
  .impr-page .box.L2 { grid-template-columns: 1fr 1fr; }
  .impr-page .box.L3 { grid-template-columns: 1.6fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L3 > :first-child { grid-row: 1 / 3; }
  .impr-page .box.L4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L5 { grid-template-columns: 1.5fr 1fr; grid-template-rows: 1fr 1fr 1fr; }
  .impr-page .box.L5 > :first-child { grid-row: 1 / 4; }
  .impr-page .cell {
    border-radius: 3px; overflow: hidden;
  }
  .impr-page .cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
</style>

<div class="page impr-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Impressionen</div>
    <div class="aline"></div>
    <div class="box {{ $layout }}">
        @foreach ($imgs as $img)
            <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
        @endforeach
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/impressionen.blade.php
git commit -m "feat: expose impressionen page template (L1-L5)"
```

### Task 14 · Kontakt-Template

**Files:**
- Create: `resources/views/expose/pages/kontakt.blade.php`

- [ ] **Step 1: Template schreiben**

`resources/views/expose/pages/kontakt.blade.php`:

```blade
@php
    $b = $ctx->broker;
    $initials = $b ? collect(preg_split('/\s+/', $b->name))->map(fn($x) => mb_substr($x, 0, 1))->take(2)->implode('') : 'SR';
    $disclaimer = 'Dieses Exposé wurde mit größter Sorgfalt erstellt und dient ausschließlich der unverbindlichen Information. Alle Angaben zu Flächen, Maßen, Preisen, Erträgen sowie sonstigen Daten beruhen auf den Informationen und Unterlagen des Eigentümers bzw. Dritter. Für deren Richtigkeit, Vollständigkeit und Aktualität wird keine Haftung übernommen. Das Exposé stellt kein verbindliches Angebot dar. Änderungen, Irrtümer und Zwischenverkauf bleiben ausdrücklich vorbehalten. Maßgeblich sind ausschließlich die im Kaufvertrag vereinbarten Inhalte. Dieses Dokument ist vertraulich zu behandeln und darf ohne unsere ausdrückliche Zustimmung weder vervielfältigt noch an Dritte weitergegeben werden.';
@endphp

<style>
  .kontakt-page .grid {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 40px;
  }
  .kontakt-page .gh {
    font-size: 12px; color: var(--accent); letter-spacing: 2.5px; text-transform: uppercase;
    font-weight: 700; padding-bottom: 6px; margin-bottom: 10px;
    border-bottom: 1px solid var(--border);
  }
  .kontakt-page .contact-box {
    display: flex; gap: 16px; padding: 16px 18px;
    background: #fafafa; border-radius: 4px; margin-bottom: 18px;
  }
  .kontakt-page .avatar {
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #ee7600, #c95b00);
    color: #fff; font-family: Georgia, serif; font-size: 22px;
    display: flex; align-items: center; justify-content: center; font-weight: 600;
    flex-shrink: 0;
  }
  .kontakt-page .info .name { font-family: Georgia, serif; font-size: 18px; color: var(--text-primary); margin-bottom: 2px; }
  .kontakt-page .info .role { font-size: 11px; color: #999; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 10px; }
  .kontakt-page .info .line { font-size: 13px; color: #333; padding: 2px 0; }
  .kontakt-page .info .line .k { color: #999; display: inline-block; width: 68px; }
  .kontakt-page .over { font-size: 13px; line-height: 1.55; color: #444; margin-top: 6px; }
  .kontakt-page .r {
    display: flex; justify-content: space-between;
    padding: 5px 0; border-bottom: 1px dotted #f0f0f0; font-size: 13px; gap: 12px;
  }
  .kontakt-page .r:last-child { border-bottom: none; }
  .kontakt-page .r .k { color: var(--text-secondary); }
  .kontakt-page .r .v { font-family: Georgia, serif; color: var(--text-primary); font-size: 14px; }
  .kontakt-page .r .v.accent { color: var(--accent); font-weight: 700; }
  .kontakt-page .disclaimer {
    margin-top: 16px; padding: 14px 16px;
    background: #fafafa; border-left: 3px solid var(--accent); border-radius: 2px;
    font-size: 9.5px; line-height: 1.55; color: #555;
  }
  .kontakt-page .disclaimer .dh {
    font-size: 10px; color: var(--accent); letter-spacing: 2px;
    text-transform: uppercase; font-weight: 700; margin-bottom: 5px;
  }
</style>

<div class="page kontakt-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Kontakt</div>
    <div class="aline"></div>
    <div class="grid">
        <div>
            <div class="gh">Ihr Ansprechpartner</div>
            <div class="contact-box">
                <div class="avatar">{{ $initials }}</div>
                <div class="info">
                    <div class="name">{{ $b?->name ?: 'SR Homes' }}</div>
                    <div class="role">Immobilienmakler</div>
                    @if ($b?->phone ?? null)
                        <div class="line"><span class="k">Telefon</span>{{ $b->phone }}</div>
                    @endif
                    @if ($b?->email)
                        <div class="line"><span class="k">E-Mail</span>{{ $b->email }}</div>
                    @endif
                    <div class="line"><span class="k">Web</span>www.sr-homes.at</div>
                </div>
            </div>
            <div class="gh" style="margin-top: 4px;">Über SR Homes</div>
            <p class="over">SR Homes begleitet Sie mit Erfahrung und regionaler Expertise durch den gesamten Verkaufs- und Kaufprozess — von der Erstbesichtigung bis zur Schlüsselübergabe.</p>
        </div>
        <div>
            <div class="gh">Kaufnebenkosten</div>
            <div class="r"><span class="k">Grunderwerbsteuer</span><span class="v">3,5 %</span></div>
            <div class="r"><span class="k">Grundbucheintragung</span><span class="v">1,1 %</span></div>
            <div class="r"><span class="k">Vertragserrichtung</span><span class="v">1,5 %</span></div>
            <div class="r"><span class="k">Pfandrechtseintrag</span><span class="v">1,2 %</span></div>
            <div class="r"><span class="k">Käuferprovision</span><span class="v accent">3,0 % + USt</span></div>

            <div class="disclaimer">
                <div class="dh">Haftungsausschluss</div>
                {{ $disclaimer }}
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/expose/pages/kontakt.blade.php
git commit -m "feat: expose kontakt page template with disclaimer"
```

---

## Phase D · Admin-Controller + PDF-Export

### Task 15 · `ExposeController` (Admin)

**Files:**
- Create: `app/Http/Controllers/Admin/ExposeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/ExposeControllerTest.php`

- [ ] **Step 1: Failing Test**

`tests/Feature/Admin/ExposeControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_version_with_default_config(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create();
        PropertyImage::create([
            'property_id'   => $property->id,
            'filename'      => 'img.jpg',
            'path'          => "property_images/{$property->id}/img.jpg",
            'is_title_image' => true,
            'is_public'     => true,
        ]);

        $this->actingAs($user)
             ->postJson("/admin/properties/{$property->id}/expose")
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        $version = PropertyExposeVersion::where('property_id', $property->id)->first();
        $this->assertNotNull($version);
        $this->assertTrue($version->is_active);
        $this->assertNotEmpty($version->config_json['pages']);
    }

    public function test_preview_renders_html_with_page_count(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create(['realty_description' => 'Kurze Beschreibung.']);
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => [
                'pages' => [
                    ['type' => 'cover'],
                    ['type' => 'details'],
                    ['type' => 'haus'],
                    ['type' => 'lage'],
                    ['type' => 'kontakt'],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
                         ->get("/admin/properties/{$property->id}/expose/preview");

        $response->assertStatus(200);
        $response->assertSee('class="page cover-page"', false);
        $response->assertSee('class="page details-page"', false);
        $response->assertSee('class="page kontakt-page"', false);
    }
}
```

- [ ] **Step 2: Controller + Routes schreiben**

`app/Http/Controllers/Admin/ExposeController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Services\Expose\ExposeConfigBuilder;
use App\Services\Expose\ExposePaginationService;
use App\Services\Expose\ExposeRenderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExposeController extends Controller
{
    public function __construct(
        protected ExposeConfigBuilder $builder,
        protected ExposePaginationService $pagination,
    ) {}

    /** Generiert + speichert ein Default-Exposé für die Property. */
    public function store(Request $request, Property $property): JsonResponse
    {
        $config = $this->builder->build($property);

        // Bisherige aktive Version deaktivieren.
        PropertyExposeVersion::where('property_id', $property->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'created_by'  => $request->user()?->id,
            'name'        => 'Exposé ' . now()->format('d.m.Y H:i'),
            'config_json' => $config,
            'is_active'   => true,
        ]);

        return response()->json([
            'success'    => true,
            'version_id' => $version->id,
            'page_count' => count($config['pages']),
        ]);
    }

    /** HTML-Preview der aktiven Version (oder einer bestimmten). */
    public function preview(Request $request, Property $property): Response
    {
        $versionId = $request->query('version_id');
        $version = $versionId
            ? PropertyExposeVersion::where('property_id', $property->id)->find($versionId)
            : PropertyExposeVersion::where('property_id', $property->id)->where('is_active', true)->first();

        if (!$version) {
            // On-the-fly Default bauen, nicht speichern.
            $version = new PropertyExposeVersion([
                'property_id' => $property->id,
                'config_json' => $this->builder->build($property),
            ]);
            $version->setRelation('property', $property);
        }

        $ctx = ExposeRenderContext::build($version, $this->pagination);
        return response()->view('expose.layout', ['ctx' => $ctx]);
    }
}
```

- [ ] **Step 3: Routes in `routes/web.php` hinzufügen**

Nach den bestehenden `admin/properties/{property}/links`-Routes:

```php
// Exposé generieren + Preview
Route::middleware(['auth'])->group(function () {
    Route::post('/admin/properties/{property}/expose',
        [\App\Http\Controllers\Admin\ExposeController::class, 'store']);
    Route::get('/admin/properties/{property}/expose/preview',
        [\App\Http\Controllers\Admin\ExposeController::class, 'preview']);
});
```

- [ ] **Step 4: Tests laufen → PASS**

```bash
php artisan test --filter=ExposeControllerTest
```

Expected: `OK (2 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/ExposeController.php routes/web.php tests/Feature/Admin/
git commit -m "feat: admin expose controller (store + preview)"
```

### Task 16 · `ExposePdfService` (Puppeteer-Wrapper)

**Files:**
- Create: `app/Services/Expose/ExposePdfService.php`
- Create: `resources/scripts/expose-pdf.cjs` (Node-Script für Puppeteer)

Das bestehende `package.json` hat Puppeteer. Wir rufen Node via `exec` auf, weil kein Spatie/Browsershot installiert ist und das Node-Script schnell fertig ist.

- [ ] **Step 1: Node-Script schreiben**

`resources/scripts/expose-pdf.cjs`:

```javascript
#!/usr/bin/env node
/**
 * Rendert eine URL zu einem A4-Querformat-PDF.
 * Usage: node expose-pdf.cjs <url> <outPath>
 */
const puppeteer = require('puppeteer');

(async () => {
    const [,, url, outPath] = process.argv;
    if (!url || !outPath) {
        console.error('Usage: expose-pdf.cjs <url> <outPath>');
        process.exit(2);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    try {
        const page = await browser.newPage();
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });

        await page.pdf({
            path: outPath,
            format: 'A4',
            landscape: true,
            printBackground: true,
            margin: { top: 0, right: 0, bottom: 0, left: 0 },
            preferCSSPageSize: false,
        });
    } finally {
        await browser.close();
    }
})().catch((err) => {
    console.error(err.message);
    process.exit(1);
});
```

- [ ] **Step 2: Script ausführbar + Test-Failing**

```bash
chmod +x resources/scripts/expose-pdf.cjs
```

- [ ] **Step 3: Service schreiben**

`app/Services/Expose/ExposePdfService.php`:

```php
<?php

namespace App\Services\Expose;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExposePdfService
{
    /**
     * Rendert eine HTML-Preview-URL zu einem A4-Querformat-PDF.
     * Gibt den PDF-Binary zurück (nicht auf Disk).
     */
    public function renderFromUrl(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'expose_') . '.pdf';
        $script = base_path('resources/scripts/expose-pdf.cjs');

        $process = new Process(['node', $script, $url, $tmp]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($tmp);
            throw new ProcessFailedException($process);
        }

        if (!file_exists($tmp)) {
            throw new \RuntimeException('PDF file not created at ' . $tmp);
        }

        $binary = file_get_contents($tmp);
        @unlink($tmp);
        return $binary;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Expose/ExposePdfService.php resources/scripts/expose-pdf.cjs
git commit -m "feat: ExposePdfService via puppeteer node script"
```

---

## Phase E · Public-Integration (Freigabelink)

### Task 17 · Public-Routes für Exposé im `/docs/{token}`-Flow

**Files:**
- Modify: `app/Http/Controllers/PublicDocumentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Public/ExposePublicViewTest.php`
- Test: `tests/Feature/Public/ExposePdfDownloadTest.php`

- [ ] **Step 1: Failing Tests**

`tests/Feature/Public/ExposePublicViewTest.php`:

```php
<?php

namespace Tests\Feature\Public;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposePublicViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlocked_link_with_attached_expose_returns_html(): void
    {
        $property = Property::factory()->create(['realty_description' => 'Haus-Beschreibung.']);
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [['type' => 'cover'], ['type' => 'details'], ['type' => 'kontakt']]],
            'is_active'   => true,
        ]);
        $link = PropertyLink::create([
            'property_id' => $property->id,
            'name'        => 'Test',
            'token'       => 'testtoken1234',
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id'   => $link->id,
            'property_file_id'   => null,
            'expose_version_id'  => $version->id,
            'sort_order'         => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        $session = PropertyLinkSession::create([
            'property_link_id' => $link->id,
            'visitor_email'    => 'test@example.com',
            'unlocked_at'      => now(),
            'last_seen_at'     => now(),
        ]);

        $this->withCookie('plink_session_' . $link->token, (string) $session->id)
             ->get('/docs/testtoken1234/expose')
             ->assertStatus(200)
             ->assertSee('class="page cover-page"', false);
    }

    public function test_expose_blocked_on_locked_link(): void
    {
        $property = Property::factory()->create();
        $link = PropertyLink::create([
            'property_id' => $property->id,
            'name' => 'Test', 'token' => 'lockedtoken12',
        ]);

        $this->get('/docs/lockedtoken12/expose')->assertStatus(403);
    }

    public function test_expose_404_if_not_attached_to_link(): void
    {
        $property = Property::factory()->create();
        $link = PropertyLink::create([
            'property_id' => $property->id,
            'name' => 'Test', 'token' => 'emptytoken12',
        ]);
        $session = PropertyLinkSession::create([
            'property_link_id' => $link->id,
            'visitor_email' => 'x@y.at',
            'unlocked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->withCookie('plink_session_' . $link->token, (string) $session->id)
             ->get('/docs/emptytoken12/expose')
             ->assertStatus(404);
    }
}
```

- [ ] **Step 2: PublicDocumentController erweitern**

In `app/Http/Controllers/PublicDocumentController.php` am Ende der Klasse zwei neue Methoden einfügen:

```php
    public function expose(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();
        if (!$link) return response()->view('docs.error', ['reason' => 'not_found'], 404);
        if ($link->revoked_at) return response()->view('docs.error', ['reason' => 'revoked', 'link' => $link], 410);
        if ($link->expires_at?->isPast()) return response()->view('docs.error', ['reason' => 'expired', 'link' => $link], 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        if (!$session) return response('Forbidden', 403);

        // Exposé-Version aus der Pivot-Tabelle auflösen
        $versionId = \DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->whereNotNull('expose_version_id')
            ->value('expose_version_id');

        if (!$versionId) return response('Not found', 404);

        $version = \App\Models\PropertyExposeVersion::find($versionId);
        if (!$version) return response('Not found', 404);

        $this->logger->log($session, 'expose_view', ['expose_version_id' => $version->id]);

        $pagination = app(\App\Services\Expose\ExposePaginationService::class);
        $ctx = \App\Services\Expose\ExposeRenderContext::build($version, $pagination);
        return response()->view('expose.layout', ['ctx' => $ctx]);
    }

    public function exposePdf(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();
        if (!$link) return response('Not found', 404);
        if ($link->revoked_at || $link->expires_at?->isPast()) return response('Gone', 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        if (!$session) return response('Forbidden', 403);

        $versionId = \DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->whereNotNull('expose_version_id')
            ->value('expose_version_id');

        if (!$versionId) return response('Not found', 404);

        $pdfService = app(\App\Services\Expose\ExposePdfService::class);
        $url = url("/docs/{$token}/expose") . '?from_pdf=1';
        // Für PDF-Render braucht Puppeteer auch Cookie-Zugriff; einfachstes Mittel ist
        // ein signed URL-Parameter, den der expose()-Handler als Bypass akzeptiert:
        $bypass = hash_hmac('sha256', $versionId . '|' . $token, config('app.key'));
        $url = url("/docs/{$token}/expose") . '?pdf_bypass=' . $bypass;

        $binary = $pdfService->renderFromUrl($url);
        $this->logger->log($session, 'expose_pdf_download', ['expose_version_id' => $versionId]);

        $filename = 'Expose-' . \Illuminate\Support\Str::slug($link->property->title ?? 'immobilie') . '.pdf';
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($binary),
        ]);
    }
```

Und in der `expose()`-Methode oben den PDF-Bypass akzeptieren: Ersetze den `$session`-Check durch:

```php
        $pdfBypass = $request->query('pdf_bypass');
        $session = $this->resolveSessionFromCookie($request, $link);

        if (!$session && !$pdfBypass) return response('Forbidden', 403);
        // Für PDF-Bypass wird unten beim Version-Lookup das HMAC verifiziert.
```

Und bei Version-Lookup:

```php
        if ($pdfBypass) {
            $expected = hash_hmac('sha256', $versionId . '|' . $token, config('app.key'));
            if (!hash_equals($expected, $pdfBypass)) return response('Forbidden', 403);
        }
```

- [ ] **Step 3: Routes in `routes/web.php` ergänzen**

Innerhalb der bestehenden `/docs`-Route-Gruppe (bei anderen Public-Routes):

```php
    Route::get('{token}/expose', [\App\Http\Controllers\PublicDocumentController::class, 'expose'])->name('docs.expose');
    Route::get('{token}/expose.pdf', [\App\Http\Controllers\PublicDocumentController::class, 'exposePdf'])->name('docs.expose.pdf');
```

- [ ] **Step 4: Tests laufen → PASS**

```bash
php artisan test --filter=ExposePublicViewTest
```

Expected: `OK (3 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php routes/web.php tests/Feature/Public/
git commit -m "feat: public expose routes (/docs/{token}/expose + expose.pdf)"
```

### Task 18 · Links-Editor: Exposé als wählbares File im Admin-UI

**Files:**
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php`

Der Admin wählt bei Link-Erstellung Files aus. Wir fügen das aktive Exposé als „virtuelles File" in die Response ein, damit es in der Picker-UI auftaucht. Die Pivot-Tabelle kann `expose_version_id` setzen statt `property_file_id`.

- [ ] **Step 1: Method in PropertyLinkController finden/anpassen**

```bash
grep -n "property_file_id\|documents" /Users/max/srhomes/app/Http/Controllers/Admin/PropertyLinkController.php | head
```

Identifiziere die Method, die zum Speichern der Dokumente aufgerufen wird (z. B. `store`, `update`, oder ein Helper `syncDocuments`). Beispielsweise wenn die Controller-Input die Form `['document_ids' => [...]]` hat, muss sie erweitert werden um `['expose_version_id' => ...]`.

Ändere die Pivot-Einfügelogik so, dass sie `property_file_id` ODER `expose_version_id` akzeptiert:

```php
// Vorher (Pseudocode):
// foreach ($documentIds as $i => $fileId) {
//     DB::table('property_link_documents')->insert([
//         'property_link_id' => $link->id,
//         'property_file_id' => $fileId,
//         'sort_order'       => $i,
//     ]);
// }

// Nachher:
$attachments = $request->input('attachments', []); // [{type:'file', id:5}, {type:'expose', id:12}]
foreach ($attachments as $i => $att) {
    DB::table('property_link_documents')->insert([
        'property_link_id'  => $link->id,
        'property_file_id'  => $att['type'] === 'file'   ? $att['id'] : null,
        'expose_version_id' => $att['type'] === 'expose' ? $att['id'] : null,
        'sort_order'        => $i,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}
```

- [ ] **Step 2: Response um aktives Exposé ergänzen**

In der Method, die die verfügbaren Dateien für den Link-Editor zurückgibt (meist `availableDocuments` oder im `show`-Response), ergänze:

```php
$activeExpose = \App\Models\PropertyExposeVersion::where('property_id', $property->id)
    ->where('is_active', true)
    ->first();

return response()->json([
    'files'  => $files,
    'expose' => $activeExpose ? [
        'version_id'   => $activeExpose->id,
        'name'         => $activeExpose->name,
        'page_count'   => count($activeExpose->config_json['pages'] ?? []),
        'updated_at'   => $activeExpose->updated_at->toIso8601String(),
    ] : null,
]);
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php
git commit -m "feat: links editor accepts expose_version_id attachments"
```

### Task 19 · Public Landing zeigt Exposé als Hero-Item

**Files:**
- Modify: `resources/views/docs/partials/_unlocked.blade.php`
- Modify: `app/Http/Controllers/PublicDocumentController.php` (show-method mit expose-info füttern)

- [ ] **Step 1: Show-Method erweitern**

In `PublicDocumentController::show()` dort, wo die Files für das Template zusammengestellt werden, Exposé-Info dazulegen:

```php
$exposeVersionId = \DB::table('property_link_documents')
    ->where('property_link_id', $link->id)
    ->whereNotNull('expose_version_id')
    ->value('expose_version_id');

$exposeInfo = null;
if ($exposeVersionId) {
    $exposeInfo = [
        'view_url'     => route('docs.expose', $link->token),
        'download_url' => route('docs.expose.pdf', $link->token),
    ];
}

return response()->view('docs.landing', array_merge($commonProps, [
    'state'   => 'unlocked',
    'files'   => $files,
    'session' => $session,
    'expose'  => $exposeInfo,
]));
```

- [ ] **Step 2: Landing-Template erweitern**

In `resources/views/docs/landing.blade.php` bei dem `@include('docs.partials._unlocked')` die neue Variable mitgeben:

```blade
@include('docs.partials._unlocked', [
    'link'    => $link,
    'files'   => $files,
    'session' => $session,
    'expose'  => $expose ?? null,
])
```

- [ ] **Step 3: Partial `_unlocked.blade.php` erweitern**

Am Anfang der File-Liste (in `resources/views/docs/partials/_unlocked.blade.php`):

```blade
@if ($expose ?? null)
    <div class="cv-item hero" style="background: linear-gradient(90deg, #fff7ed 0%, #fff 50%); padding: 18px 20px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 14px;">
        <div style="width:40px;height:40px;border-radius:6px;background:linear-gradient(135deg,#ee7600,#c95b00);color:#fff;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-weight:600;">SR</div>
        <div style="flex:1">
            <div style="font-size:13px;color:#1a1a1a;font-weight:600">Exposé ansehen</div>
            <div style="font-size:11px;color:#888;margin-top:2px">Das vollständige Objektexposé im Browser</div>
        </div>
        <a href="{{ $expose['view_url'] }}" target="_blank" class="btn-primary" style="background:#ee7600;color:#fff;padding:6px 11px;border-radius:3px;font-size:11px;font-weight:600;text-decoration:none">Öffnen</a>
        <a href="{{ $expose['download_url'] }}" class="btn-secondary" style="padding:6px 11px;border:1px solid #e5e7eb;border-radius:3px;font-size:11px;font-weight:600;color:#333;text-decoration:none">PDF</a>
    </div>
@endif
```

- [ ] **Step 4: Manueller Test (nur wenn Test-Infrastruktur reicht)**

```bash
php artisan test --filter=ExposePublicViewTest
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php resources/views/docs/
git commit -m "feat: public landing shows expose as hero item"
```

---

## Phase F · Admin-UI (minimal)

### Task 20 · `ExposeTab.vue` (MVP: Preview + Generate-Button)

**Files:**
- Create: `resources/js/Components/Admin/property-detail/ExposeTab.vue`
- Modify: `resources/js/Components/Admin/PropertyDetailPage.vue`

- [ ] **Step 1: Vue-Component schreiben**

`resources/js/Components/Admin/property-detail/ExposeTab.vue`:

```vue
<script setup>
import { ref, onMounted, inject, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, RefreshCw, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
  property: { type: Object, required: true },
});

const toast = inject('toast');
const generating = ref(false);
const error = ref('');
const info = ref(null); // { page_count, version_id, updated_at }

const previewUrl = computed(() => `/admin/properties/${props.property.id}/expose/preview?ts=${Date.now()}`);

async function generate() {
  generating.value = true;
  error.value = '';
  try {
    const res = await fetch(`/admin/properties/${props.property.id}/expose`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
        'Accept': 'application/json',
      },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Generate failed');
    }
    info.value = data;
    toast('Exposé gespeichert · ' + data.page_count + ' Seiten');
  } catch (e) {
    error.value = e.message;
  } finally {
    generating.value = false;
  }
}

onMounted(() => {
  // Kein auto-generate. Vorschau läuft immer (zeigt on-the-fly Default, falls keine Version existiert).
});
</script>

<template>
  <div class="p-6 space-y-4">
    <div class="flex items-start justify-between">
      <div>
        <h2 class="text-lg font-semibold">Exposé</h2>
        <p class="text-sm text-muted-foreground mt-1">
          Das adaptive Exposé wird automatisch aus den Objektdaten und hochgeladenen Bildern erzeugt.
          Änderungen an der Property sind sofort in der Vorschau sichtbar.
        </p>
      </div>
      <div class="flex gap-2">
        <Button @click="generate" :disabled="generating" variant="default" size="sm">
          <Loader2 v-if="generating" class="w-4 h-4 mr-2 animate-spin" />
          <RefreshCw v-else class="w-4 h-4 mr-2" />
          {{ info ? 'Neu speichern' : 'Exposé speichern' }}
        </Button>
        <a :href="previewUrl" target="_blank">
          <Button variant="outline" size="sm">
            <ExternalLink class="w-4 h-4 mr-2" />
            Vollbild öffnen
          </Button>
        </a>
      </div>
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="info" class="text-xs text-muted-foreground">
      Aktive Version: {{ info.page_count }} Seiten · gespeichert soeben
    </div>

    <div class="border border-border rounded-md overflow-hidden bg-zinc-50" style="aspect-ratio: 297/210;">
      <iframe :src="previewUrl" class="w-full h-full border-0" />
    </div>
  </div>
</template>
```

- [ ] **Step 2: Tab in `PropertyDetailPage.vue` einbinden**

Das Tab-System in `PropertyDetailPage.vue` nutzt `Tabs`/`TabsList`/`TabsTrigger`/`TabsContent` (shadcn-vue). Drei Änderungen:

**2a) Import ergänzen** (neben den anderen Tab-Imports, nach Zeile 17):

```javascript
import ExposeTab from '@/Components/Admin/property-detail/ExposeTab.vue';
```

**2b) In den `tabs`-Computed** (Zeile ~88) einen neuen Eintrag vor `aktivitaeten` einfügen:

```javascript
t.push({ value: 'expose', label: 'Exposé' });
t.push({ value: 'aktivitaeten', label: 'Aktivitäten' });
```

**2c) In der Template-Sektion** den neuen `<TabsContent value="expose">` zwischen die bestehenden TabsContent-Blöcke einfügen (nach `TabsContent` für `dateien` oder `links`):

```vue
<TabsContent value="expose">
  <ExposeTab :property="property" />
</TabsContent>
```

- [ ] **Step 3: Build + Sichtprüfung**

```bash
npm run build
```

Expected: Build ohne Fehler. Dann in Property-Detail den neuen Tab öffnen — iframe mit Exposé-Preview muss erscheinen.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/property-detail/ExposeTab.vue resources/js/Components/Admin/PropertyDetailPage.vue
git commit -m "feat: admin expose tab (minimal UI - generate + preview)"
```

---

## Phase G · End-to-End-Smoke-Test

### Task 21 · Smoke-Test: Komplette Pipeline

**Files:**
- Test: `tests/Feature/Expose/ExposeEndToEndTest.php`

- [ ] **Step 1: E2E-Test schreiben**

`tests/Feature/Expose/ExposeEndToEndTest.php`:

```php
<?php

namespace Tests\Feature\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyImage;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_broker_generates_attaches_customer_views(): void
    {
        // 1) Setup: Property mit Bildern + Broker
        $broker = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create([
            'title' => 'Testhaus', 'realty_description' => 'Ein Haus.',
            'location_description' => 'Salzburg.', 'broker_id' => $broker->id,
        ]);
        for ($i = 0; $i < 4; $i++) {
            PropertyImage::create([
                'property_id' => $property->id,
                'filename'    => "img{$i}.jpg",
                'path'        => "property_images/{$property->id}/img{$i}.jpg",
                'sort_order'  => $i,
                'is_title_image' => $i === 0,
                'is_floorplan'   => false,
                'is_public'      => true,
                'category'       => 'sonstiges',
            ]);
        }

        // 2) Makler generiert Exposé
        $this->actingAs($broker)
             ->postJson("/admin/properties/{$property->id}/expose")
             ->assertStatus(200);

        $version = PropertyExposeVersion::where('property_id', $property->id)->first();
        $this->assertNotNull($version);

        // 3) Makler erstellt Freigabelink und hängt Exposé an
        $link = PropertyLink::create([
            'property_id' => $property->id,
            'name' => 'Testlink', 'token' => 'e2etest123456',
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id'  => $link->id,
            'expose_version_id' => $version->id,
            'sort_order'        => 0,
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        // 4) Kunde entsperrt Link (Session erstellt)
        $session = PropertyLinkSession::create([
            'property_link_id' => $link->id,
            'visitor_email'    => 'kunde@test.at',
            'unlocked_at'      => now(),
            'last_seen_at'     => now(),
        ]);

        // 5) Kunde sieht Exposé als HTML
        $this->withCookie('plink_session_' . $link->token, (string) $session->id)
             ->get('/docs/e2etest123456/expose')
             ->assertStatus(200)
             ->assertSee('class="page cover-page"', false);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

```bash
php artisan test --filter=ExposeEndToEndTest
```

Expected: `OK (1 test)`

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Expose/
git commit -m "test: expose end-to-end flow (broker → link → customer)"
```

---

## Self-Review-Checkliste

Nach Abschluss aller Tasks — bevor Deploy:

- [ ] Alle Tests grün: `php artisan test` mit 0 failures
- [ ] Migration erfolgreich auf Stage/Prod: `property_expose_versions`, `properties.expose_claim`, `property_link_documents.expose_version_id`
- [ ] Manuelles Ausprobieren auf Grödig (Property 5):
  - Exposé generieren → Preview zeigt 7 Seiten
  - PDF-Download funktioniert → Datei öffnet sauber
  - Link mit Exposé erstellen → Kunde sieht Hero-Item „Exposé ansehen" + kann Öffnen und PDF
- [ ] Node + Puppeteer läuft auf dem VPS (`node --version`, `ls node_modules/puppeteer`)
- [ ] Default-Bilder aus Property werden korrekt geladen (Storage-Symlinks intakt)

---

## Phase 2 (separater Plan, folgt später)

Nicht Teil dieses Plans, als Hinweis für später:

- **Editor-UI mit Bild-Drag&Drop + Layout-Wahl pro Seite + Claim-Textfeld** (Standard-Ausbaustufe laut Spec)
- **Editorial-Spreads** (M1/M2/M3/M4 Mixed-Layouts mit Playfair / Cormorant / Fraunces)
- **Claim-Vorschlagsliste** zum Durchklicken
- **Mehrere Exposé-Versionen pro Property** mit Versionsverlauf
- **POI-Auto-Discovery** über Maps-API (statt manueller Text-Einträge)
- **Makler-Porträt im Avatar** statt Initialen (falls Property-Team Porträtfoto-Feature bekommt)
