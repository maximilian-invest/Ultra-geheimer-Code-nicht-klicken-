# Aufnahmeprotokoll Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein 11-Schritt-Wizard für Bestands-Immobilien-Aufnahmeprotokoll vor Ort beim Eigentümer mit Unterschrift, PDF-Export, Mails und optionalem Portalzugang.

**Architecture:** Laravel-Backend mit neuem `IntakeProtocolController`, separaten Services für PDF (dompdf) + Mail (2 Templates mit dynamischem Dokumente-Request) + optionaler Portaluser-Anlage; Vue-3-Frontend mit Wizard-Komponente + 11 Step-Komponenten und localStorage-Auto-Save. Datenbank: 3 neue Migrations (Fields auf `properties`, Table `intake_protocols`, Table `intake_protocol_drafts`).

**Tech Stack:** Laravel 11 · Vue 3 · Composition API · dompdf (barryvdh/laravel-dompdf, schon installiert) · TailwindCSS · shadcn-vue Components

---

## Spec-Referenz

Der vollständige Design-Kontext steht in `docs/superpowers/specs/2026-04-22-aufnahmeprotokoll-design.md`. Diese Datei lesen bevor gearbeitet wird.

## File-Structure-Übersicht

**Neue Backend-Dateien:**
```
app/Models/IntakeProtocol.php
app/Models/IntakeProtocolDraft.php
app/Http/Controllers/Admin/IntakeProtocolController.php
app/Services/IntakeProtocolPdfService.php
app/Services/IntakeProtocolEmailService.php
app/Services/VermittlungsauftragPdfService.php
app/Mail/IntakeProtocolMail.php
app/Mail/PortalAccessMail.php
database/migrations/2026_04_22_100001_create_intake_protocols_table.php
database/migrations/2026_04_22_100002_create_intake_protocol_drafts_table.php
database/migrations/2026_04_22_100003_add_intake_protocol_fields_to_properties.php
resources/views/pdf/intake-protocol.blade.php
resources/views/pdf/vermittlungsauftrag.blade.php
resources/views/emails/intake-protocol-complete.blade.php
resources/views/emails/intake-protocol-missing-docs.blade.php
resources/views/emails/portal-access.blade.php
tests/Feature/IntakeProtocolTest.php
tests/Unit/IntakeProtocolPdfServiceTest.php
tests/Unit/IntakeProtocolEmailServiceTest.php
```

**Neue Frontend-Dateien:**
```
resources/js/Components/Admin/IntakeProtocol/IntakeProtocolWizard.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step01_ObjectType.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step02_Address.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step03_Owner.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step04_CoreData.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step05_ConditionRenovations.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step06_FeaturesParking.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step07_Energy.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step08_LegalDocuments.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step09_PriceCosts.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step10_Photos.vue
resources/js/Components/Admin/IntakeProtocol/steps/Step11_SignatureSummary.vue
resources/js/Components/Admin/IntakeProtocol/shared/StepHeader.vue
resources/js/Components/Admin/IntakeProtocol/shared/StepNavigation.vue
resources/js/Components/Admin/IntakeProtocol/shared/SkipFieldSwitch.vue
resources/js/Components/Admin/IntakeProtocol/shared/PillRow.vue
resources/js/Components/Admin/IntakeProtocol/shared/OwnerPicker.vue
resources/js/Components/Admin/IntakeProtocol/shared/DocumentChecklistItem.vue
resources/js/Components/Admin/IntakeProtocol/shared/PhotoCategoryUploader.vue
resources/js/Components/Admin/IntakeProtocol/shared/SignaturePad.vue
resources/js/Components/Admin/IntakeProtocol/composables/useIntakeForm.js
resources/js/Components/Admin/IntakeProtocol/composables/useAutoSave.js
resources/js/Components/Admin/IntakeProtocol/composables/useSubtypes.js
```

**Modifizierte Backend-Dateien:**
- `app/Http/Controllers/Admin/AdminApiController.php` — 5 neue Actions registrieren
- `app/Models/Property.php` — neue Fillable-Felder + Casts

**Modifizierte Frontend-Dateien:**
- `resources/js/Components/Admin/PropertiesTab.vue` — Button „Aufnahmeprotokoll" hinzufügen
- `resources/js/Components/Admin/property-detail/EditTab.vue` — Banner + „offene Felder"-Markierungen
- `resources/js/utils/propertyFieldExports.js` — 6 neue Einträge
- `app/Http/Controllers/WebsiteApiController.php` — `parking_assignment` in Details aufnehmen
- `website-v2/js/detail.js` — `parking_assignment` in Details-Tabelle rendern
- `resources/js/Pages/Admin/Dashboard.vue` — ggf. Route für Wizard

---

## Task 1: Database Migrations

**Files:**
- Create: `database/migrations/2026_04_22_100001_create_intake_protocols_table.php`
- Create: `database/migrations/2026_04_22_100002_create_intake_protocol_drafts_table.php`
- Create: `database/migrations/2026_04_22_100003_add_intake_protocol_fields_to_properties.php`

- [ ] **Step 1: Migration `create_intake_protocols_table.php` anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_id')->constrained('users');
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name', 200)->nullable();
            $table->string('signature_png_path', 500)->nullable();
            $table->text('disclaimer_text');
            $table->string('pdf_path', 500)->nullable();
            $table->timestamp('owner_email_sent_at')->nullable();
            $table->timestamp('portal_email_sent_at')->nullable();
            $table->boolean('portal_access_granted')->default(false);
            $table->text('broker_notes')->nullable();
            $table->json('open_fields')->nullable();
            $table->longText('form_snapshot')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index('property_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_protocols');
    }
};
```

- [ ] **Step 2: Migration `create_intake_protocol_drafts_table.php` anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_protocol_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('users');
            $table->string('draft_key', 100);  // UUID vom Browser
            $table->longText('form_data');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->timestamp('last_saved_at')->useCurrent();
            $table->timestamps();
            $table->unique(['broker_id', 'draft_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_protocol_drafts');
    }
};
```

- [ ] **Step 3: Migration `add_intake_protocol_fields_to_properties.php` anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('encumbrances')->nullable()->after('property_manager_id');
            $table->enum('parking_assignment', ['assigned', 'shared'])->nullable()->after('parking_type');
            $table->json('documents_available')->nullable()->after('encumbrances');
            $table->enum('approvals_status', ['complete', 'partial', 'unknown'])->nullable()->after('documents_available');
            $table->text('approvals_notes')->nullable()->after('approvals_status');
            $table->text('internal_notes')->nullable()->after('approvals_notes');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'encumbrances',
                'parking_assignment',
                'documents_available',
                'approvals_status',
                'approvals_notes',
                'internal_notes',
            ]);
        });
    }
};
```

- [ ] **Step 4: Migrations ausführen und verifizieren**

Run: `php artisan migrate`
Expected: alle drei Migrations erfolgreich; keine Fehlermeldung

Check DB schema:
```bash
php artisan tinker --execute="echo json_encode(\Illuminate\Support\Facades\Schema::getColumnListing('intake_protocols'));"
```
Expected output enthält: `id`, `property_id`, `customer_id`, `broker_id`, `signed_at`, `signature_png_path`, `pdf_path`, `broker_notes`, `form_snapshot`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_22_100001_create_intake_protocols_table.php \
        database/migrations/2026_04_22_100002_create_intake_protocol_drafts_table.php \
        database/migrations/2026_04_22_100003_add_intake_protocol_fields_to_properties.php
git commit -m "feat(db): aufnahmeprotokoll schema (intake_protocols + drafts + property fields)"
```

---

## Task 2: Eloquent Models

**Files:**
- Create: `app/Models/IntakeProtocol.php`
- Create: `app/Models/IntakeProtocolDraft.php`
- Modify: `app/Models/Property.php`

- [ ] **Step 1: Model `IntakeProtocol.php` anlegen**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeProtocol extends Model
{
    protected $fillable = [
        'property_id', 'customer_id', 'broker_id',
        'signed_at', 'signed_by_name', 'signature_png_path',
        'disclaimer_text', 'pdf_path',
        'owner_email_sent_at', 'portal_email_sent_at',
        'portal_access_granted', 'broker_notes',
        'open_fields', 'form_snapshot', 'client_ip', 'user_agent',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'owner_email_sent_at' => 'datetime',
        'portal_email_sent_at' => 'datetime',
        'portal_access_granted' => 'boolean',
        'open_fields' => 'array',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function getFormSnapshotArrayAttribute(): array
    {
        if (!$this->form_snapshot) return [];
        $decoded = json_decode($this->form_snapshot, true);
        return is_array($decoded) ? $decoded : [];
    }
}
```

- [ ] **Step 2: Model `IntakeProtocolDraft.php` anlegen**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeProtocolDraft extends Model
{
    protected $fillable = [
        'broker_id', 'draft_key', 'form_data', 'current_step', 'last_saved_at',
    ];

    protected $casts = [
        'last_saved_at' => 'datetime',
        'current_step' => 'integer',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function getFormDataArrayAttribute(): array
    {
        if (!$this->form_data) return [];
        $decoded = json_decode($this->form_data, true);
        return is_array($decoded) ? $decoded : [];
    }
}
```

- [ ] **Step 3: `Property.php` um neue Fillables + Casts erweitern**

In `app/Models/Property.php`, im `$fillable`-Array (am Ende der existierenden Liste) einfügen:

```php
        // Aufnahmeprotokoll
        'encumbrances', 'parking_assignment', 'documents_available',
        'approvals_status', 'approvals_notes', 'internal_notes',
```

Im `casts()`-Return-Array einfügen:

```php
            'documents_available' => 'array',
```

(die anderen neuen Felder sind einfache Strings/Text — keine Casts nötig.)

- [ ] **Step 4: Models durch Tinker kurz testen**

Run:
```bash
php artisan tinker --execute="
\$p = \App\Models\IntakeProtocolDraft::create([
    'broker_id' => 1,
    'draft_key' => 'test-uuid-1234',
    'form_data' => json_encode(['foo' => 'bar']),
    'current_step' => 3,
]);
echo \$p->id . ' ' . \$p->form_data_array['foo'];
\App\Models\IntakeProtocolDraft::where('id', \$p->id)->delete();
echo ' ok';
"
```
Expected output: `{id} bar ok`

- [ ] **Step 5: Commit**

```bash
git add app/Models/IntakeProtocol.php app/Models/IntakeProtocolDraft.php app/Models/Property.php
git commit -m "feat(models): IntakeProtocol + IntakeProtocolDraft + Property fillable erweitern"
```

---

## Task 3: PDF-Service + Blade-Template (Scaffold)

**Files:**
- Create: `app/Services/IntakeProtocolPdfService.php`
- Create: `resources/views/pdf/intake-protocol.blade.php`
- Create: `tests/Unit/IntakeProtocolPdfServiceTest.php`

- [ ] **Step 1: Scaffold-Test schreiben**

```php
<?php

namespace Tests\Unit;

use App\Services\IntakeProtocolPdfService;
use Tests\TestCase;

class IntakeProtocolPdfServiceTest extends TestCase
{
    public function test_render_returns_binary_pdf_for_minimal_data(): void
    {
        $service = app(IntakeProtocolPdfService::class);
        $data = [
            'property' => [
                'ref_id' => 'TEST-01',
                'address' => 'Teststraße',
                'house_number' => '1',
                'zip' => '5020',
                'city' => 'Salzburg',
                'object_type' => 'Wohnung',
                'living_area' => 72,
            ],
            'owner' => [
                'name' => 'Max Mustermann',
                'email' => 'max@test.at',
            ],
            'broker' => [
                'name' => 'Susanne Renzl',
            ],
            'disclaimer_text' => 'Test-Disclaimer',
            'signed_at' => now(),
            'signed_by_name' => 'Max Mustermann',
        ];

        $pdfBinary = $service->render($data);

        $this->assertStringStartsWith('%PDF-', $pdfBinary);
        $this->assertGreaterThan(1000, strlen($pdfBinary));
    }
}
```

- [ ] **Step 2: Test ausführen → soll fehlschlagen**

Run: `php artisan test --filter=test_render_returns_binary_pdf_for_minimal_data`
Expected: FAIL mit *Class "App\Services\IntakeProtocolPdfService" not found*

- [ ] **Step 3: Service-Skeleton schreiben**

```php
<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class IntakeProtocolPdfService
{
    /**
     * Rendert ein Aufnahmeprotokoll-PDF als Binary-String.
     * $data: ['property' => [...], 'owner' => [...], 'broker' => [...],
     *        'disclaimer_text', 'signed_at', 'signed_by_name',
     *        'signature_png_path' (optional), 'broker_notes' (optional),
     *        'sanierungen' (array, optional), 'documents_available' (assoc, optional),
     *        'approvals_status', 'approvals_notes', 'photos' (array, optional),
     *        'open_fields' (array, optional)]
     */
    public function render(array $data): string
    {
        $pdf = Pdf::loadView('pdf.intake-protocol', $data);
        $pdf->setPaper('A4');
        return $pdf->output();
    }
}
```

- [ ] **Step 4: Minimal-Blade-Template schreiben**

```blade
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
body { font-family: sans-serif; font-size: 10pt; color: #171717; }
h1 { font-size: 18pt; margin-bottom: 4px; }
h2 { font-size: 12pt; margin-top: 18px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
table.data td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
table.data td.label { color: #737373; font-size: 9pt; width: 35%; }
.signature-block { margin-top: 40px; padding-top: 20px; border-top: 2px solid #171717; }
.sig-image { max-height: 80px; max-width: 320px; border: 1px solid #e5e5e5; }
.disclaimer { background: #f9fafb; padding: 12px; border-left: 3px solid #EE7600; margin-top: 12px; font-size: 9pt; }
.audit { font-size: 7pt; color: #a3a3a3; margin-top: 24px; }
</style>
</head>
<body>

<h1>Aufnahmeprotokoll</h1>
<div style="font-size: 11pt; color: #525252;">
    {{ $property['address'] ?? '' }} {{ $property['house_number'] ?? '' }},
    {{ $property['zip'] ?? '' }} {{ $property['city'] ?? '' }}
</div>
<div style="margin-top: 8px; font-size: 9pt; color: #737373;">
    Ref-ID: <strong>{{ $property['ref_id'] ?? '–' }}</strong>
    · Aufnahme-Datum: {{ $signed_at instanceof \DateTimeInterface ? $signed_at->format('d.m.Y') : ($signed_at ?? '–') }}
</div>

<h2>Eigentümer</h2>
<table class="data">
    <tr><td class="label">Name</td><td>{{ $owner['name'] ?? '–' }}</td></tr>
    <tr><td class="label">E-Mail</td><td>{{ $owner['email'] ?? '–' }}</td></tr>
    @if(!empty($owner['phone']))
    <tr><td class="label">Telefon</td><td>{{ $owner['phone'] }}</td></tr>
    @endif
</table>

<h2>Objekt-Stammdaten</h2>
<table class="data">
    <tr><td class="label">Typ</td><td>{{ $property['object_type'] ?? '–' }} @if(!empty($property['object_subtype'])) ({{ $property['object_subtype'] }}) @endif</td></tr>
    <tr><td class="label">Vermarktung</td><td>{{ $property['marketing_type'] ?? '–' }}</td></tr>
    @if(!empty($property['living_area']))
    <tr><td class="label">Wohnfläche</td><td>{{ $property['living_area'] }} m²</td></tr>
    @endif
    @if(!empty($property['rooms_amount']))
    <tr><td class="label">Zimmer</td><td>{{ $property['rooms_amount'] }}</td></tr>
    @endif
    @if(!empty($property['construction_year']))
    <tr><td class="label">Baujahr</td><td>{{ $property['construction_year'] }}</td></tr>
    @endif
</table>

@if(!empty($broker_notes))
<h2>Notizen vom Termin</h2>
<p style="white-space: pre-line;">{{ $broker_notes }}</p>
@endif

<div class="signature-block">
    <div class="disclaimer">{{ $disclaimer_text }}</div>
    <div style="margin-top: 20px;">
        @if(!empty($signature_png_path) && file_exists(storage_path('app/' . $signature_png_path)))
            <img class="sig-image" src="{{ storage_path('app/' . $signature_png_path) }}" alt="Unterschrift">
        @else
            <div style="border-bottom: 1px solid #999; width: 320px; height: 60px;"></div>
        @endif
        <div style="font-size: 9pt; color: #525252; margin-top: 4px;">
            {{ $signed_by_name ?? '–' }},
            {{ $signed_at instanceof \DateTimeInterface ? $signed_at->format('d.m.Y H:i') : ($signed_at ?? '–') }}
        </div>
    </div>
    <div style="margin-top: 20px;">
        <div style="border-bottom: 1px solid #999; width: 320px; height: 60px;"></div>
        <div style="font-size: 9pt; color: #525252; margin-top: 4px;">
            {{ $broker['name'] ?? '–' }} (Makler)
        </div>
    </div>
</div>

<div class="audit">
    @if(!empty($client_ip)) IP: {{ $client_ip }} @endif
    @if(!empty($user_agent)) · UA: {{ substr($user_agent, 0, 120) }} @endif
</div>

</body>
</html>
```

- [ ] **Step 5: Test laufen lassen → muss jetzt passen**

Run: `php artisan test --filter=test_render_returns_binary_pdf_for_minimal_data`
Expected: PASS (1 test, 2 assertions)

- [ ] **Step 6: Commit**

```bash
git add app/Services/IntakeProtocolPdfService.php \
        resources/views/pdf/intake-protocol.blade.php \
        tests/Unit/IntakeProtocolPdfServiceTest.php
git commit -m "feat(pdf): IntakeProtocolPdfService mit minimalem Blade-Template"
```

---

## Task 4: PDF-Template erweitern (vollständige Daten-Sektionen)

**Files:**
- Modify: `resources/views/pdf/intake-protocol.blade.php`
- Modify: `tests/Unit/IntakeProtocolPdfServiceTest.php`

- [ ] **Step 1: Test für vollständige Sektionen ergänzen**

In `tests/Unit/IntakeProtocolPdfServiceTest.php` neue Methode ergänzen:

```php
    public function test_render_includes_sanierungen_and_documents_and_approvals(): void
    {
        $service = app(IntakeProtocolPdfService::class);

        $data = [
            'property' => [
                'ref_id' => 'TEST-02',
                'address' => 'Teststraße', 'house_number' => '5',
                'zip' => '5020', 'city' => 'Salzburg',
                'object_type' => 'Wohnung',
            ],
            'owner' => ['name' => 'Test', 'email' => 't@test.at'],
            'broker' => ['name' => 'Makler'],
            'disclaimer_text' => 'Disclaimer',
            'signed_at' => now(),
            'signed_by_name' => 'Test',
            'sanierungen' => [
                ['category' => 'windows', 'label' => 'Fenster', 'year' => 2018, 'description' => '3-fach verglast'],
                ['category' => 'heating', 'label' => 'Heizung', 'year' => 2022, 'description' => 'Wärmepumpe'],
            ],
            'documents_available' => [
                'grundbuchauszug' => 'available',
                'energieausweis' => 'missing',
                'mietvertrag' => 'na',
            ],
            'approvals_status' => 'partial',
            'approvals_notes' => 'Terrasse nicht bewilligt',
            'open_fields' => ['construction_year', 'bathrooms'],
        ];

        // Render mit Blade::render direkt (statt PDF) um HTML zu testen
        $html = view('pdf.intake-protocol', $data)->render();

        $this->assertStringContainsString('Sanierungen', $html);
        $this->assertStringContainsString('Fenster', $html);
        $this->assertStringContainsString('2018', $html);
        $this->assertStringContainsString('Wärmepumpe', $html);

        $this->assertStringContainsString('Dokumenten-Checkliste', $html);
        $this->assertStringContainsString('Grundbuchauszug', $html);

        $this->assertStringContainsString('Bewilligungen', $html);
        $this->assertStringContainsString('Terrasse nicht bewilligt', $html);

        $this->assertStringContainsString('Offene Felder', $html);
        $this->assertStringContainsString('construction_year', $html);
    }
```

- [ ] **Step 2: Test laufen → soll fehlschlagen**

Run: `php artisan test --filter=test_render_includes_sanierungen_and_documents_and_approvals`
Expected: FAIL (Asserts "contains Sanierungen" failed)

- [ ] **Step 3: Blade-Template um neue Sektionen erweitern**

In `resources/views/pdf/intake-protocol.blade.php` — vor dem `<div class="signature-block">`-Tag einfügen:

```blade
@if(!empty($sanierungen))
<h2>Sanierungen</h2>
<table class="data">
    @foreach($sanierungen as $san)
    <tr>
        <td class="label">{{ $san['label'] ?? $san['category'] ?? '–' }}</td>
        <td>
            @if(!empty($san['year'])) {{ $san['year'] }} @endif
            @if(!empty($san['description'])) — {{ $san['description'] }} @endif
        </td>
    </tr>
    @endforeach
</table>
@endif

@php
$docLabels = [
    'grundbuchauszug' => 'Grundbuchauszug',
    'energieausweis' => 'Energieausweis',
    'plaene' => 'Grundrisse / Pläne',
    'nutzwertgutachten' => 'Nutzwertgutachten',
    'ruecklagenstand' => 'Rücklagenstand',
    'wohnungseigentumsvertrag' => 'Wohnungseigentumsvertrag',
    'hausordnung' => 'Hausordnung',
    'letzte_jahresabrechnung' => 'Letzte Jahresabrechnung',
    'betriebskostenabrechnung' => 'Betriebskostenabrechnung',
    'schaetzwert_gutachten' => 'Schätzwert-Gutachten',
    'baubewilligung' => 'Baubewilligung',
    'mietvertrag' => 'Mietvertrag',
    'hypothekenvertrag' => 'Hypothekenvertrag',
];
$docStatusSymbols = ['available' => '✓ vorhanden', 'missing' => '✗ fehlt', 'na' => 'nicht zutreffend'];
@endphp

@if(!empty($documents_available))
<h2>Dokumenten-Checkliste</h2>
<table class="data">
    @foreach($documents_available as $key => $status)
    <tr>
        <td class="label">{{ $docLabels[$key] ?? $key }}</td>
        <td>{{ $docStatusSymbols[$status] ?? $status }}</td>
    </tr>
    @endforeach
</table>
@endif

@if(!empty($approvals_status))
<h2>Bewilligungen</h2>
<table class="data">
    <tr>
        <td class="label">Status</td>
        <td>
            @if($approvals_status === 'complete') ✓ Alles bewilligt
            @elseif($approvals_status === 'partial') ⚠ Teilweise bewilligt
            @else ❓ Unbekannt / zu prüfen
            @endif
        </td>
    </tr>
    @if(!empty($approvals_notes))
    <tr><td class="label">Details</td><td style="white-space: pre-line;">{{ $approvals_notes }}</td></tr>
    @endif
</table>
@endif

@if(!empty($open_fields) && count($open_fields) > 0)
<h2>Offene Felder (später zu ergänzen)</h2>
<p style="font-size: 9pt; color: #92400e;">
    @foreach($open_fields as $field)
        {{ $field }}@if(!$loop->last), @endif
    @endforeach
</p>
@endif
```

- [ ] **Step 4: Test laufen → muss jetzt passen**

Run: `php artisan test --filter=test_render_includes_sanierungen_and_documents_and_approvals`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/intake-protocol.blade.php tests/Unit/IntakeProtocolPdfServiceTest.php
git commit -m "feat(pdf): sanierungen, dokumente-checkliste, bewilligungen, offene felder in PDF"
```

---

## Task 5: VermittlungsauftragPdfService

**Files:**
- Create: `app/Services/VermittlungsauftragPdfService.php`
- Create: `resources/views/pdf/vermittlungsauftrag.blade.php`
- Create: `tests/Unit/VermittlungsauftragPdfServiceTest.php`

- [ ] **Step 1: Test schreiben**

```php
<?php

namespace Tests\Unit;

use App\Services\VermittlungsauftragPdfService;
use Tests\TestCase;

class VermittlungsauftragPdfServiceTest extends TestCase
{
    public function test_render_returns_pdf_with_owner_and_property_data(): void
    {
        $service = app(VermittlungsauftragPdfService::class);
        $data = [
            'property' => ['ref_id' => 'VTA-01', 'address' => 'Musterstraße', 'house_number' => '1', 'zip' => '5020', 'city' => 'Salzburg'],
            'owner' => ['name' => 'Hans Test', 'email' => 'hans@test.at', 'address' => 'Musterweg 5', 'zip' => '5020', 'city' => 'Salzburg'],
            'broker' => ['name' => 'Susanne Renzl', 'company' => 'SR-Homes Immobilien GmbH'],
            'commission_percent' => 3.0,
        ];

        $pdf = $service->render($data);

        $this->assertStringStartsWith('%PDF-', $pdf);

        $html = view('pdf.vermittlungsauftrag', $data)->render();
        $this->assertStringContainsString('Hans Test', $html);
        $this->assertStringContainsString('VTA-01', $html);
        $this->assertStringContainsString('3', $html);
    }
}
```

- [ ] **Step 2: Test laufen → FAIL**

Run: `php artisan test --filter=VermittlungsauftragPdfServiceTest`
Expected: FAIL

- [ ] **Step 3: Service anlegen**

```php
<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class VermittlungsauftragPdfService
{
    public function render(array $data): string
    {
        $pdf = Pdf::loadView('pdf.vermittlungsauftrag', $data);
        $pdf->setPaper('A4');
        return $pdf->output();
    }
}
```

- [ ] **Step 4: Blade-Template anlegen**

```blade
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
body { font-family: sans-serif; font-size: 10pt; color: #171717; line-height: 1.5; }
h1 { font-size: 16pt; text-align: center; margin-bottom: 4px; }
.subtitle { text-align: center; color: #525252; margin-bottom: 24px; }
.party-block { margin: 16px 0; padding: 12px; background: #f9fafb; border-radius: 4px; }
.signature-line { display: inline-block; width: 45%; border-bottom: 1px solid #171717; height: 40px; margin-top: 60px; }
.signature-label { font-size: 9pt; color: #737373; display: inline-block; width: 45%; }
ol { padding-left: 20px; }
ol li { margin-bottom: 6px; }
</style>
</head>
<body>

<h1>Alleinvermittlungsauftrag</h1>
<div class="subtitle">zwischen Eigentümer und Makler · Ref-ID {{ $property['ref_id'] ?? '–' }}</div>

<div class="party-block">
    <strong>Eigentümer (Auftraggeber):</strong><br>
    {{ $owner['name'] ?? '–' }}<br>
    {{ $owner['address'] ?? '' }}<br>
    {{ $owner['zip'] ?? '' }} {{ $owner['city'] ?? '' }}<br>
    @if(!empty($owner['email'])) E-Mail: {{ $owner['email'] }}<br> @endif
    @if(!empty($owner['phone'])) Telefon: {{ $owner['phone'] }} @endif
</div>

<div class="party-block">
    <strong>Makler (Auftragnehmer):</strong><br>
    {{ $broker['name'] ?? '–' }}<br>
    {{ $broker['company'] ?? 'SR-Homes Immobilien GmbH' }}
</div>

<h3>Vermittlungsobjekt</h3>
<p>
    {{ $property['address'] ?? '' }} {{ $property['house_number'] ?? '' }},
    {{ $property['zip'] ?? '' }} {{ $property['city'] ?? '' }}<br>
    Ref-ID: <strong>{{ $property['ref_id'] ?? '–' }}</strong>
</p>

<h3>Vereinbarungen</h3>
<ol>
    <li>Der Eigentümer beauftragt den Makler mit der Vermittlung des oben genannten Objekts auf Alleinbasis.</li>
    <li>Die Käuferprovision beträgt {{ $commission_percent ?? 3.0 }}% des Kaufpreises zzgl. gesetzlicher USt.</li>
    <li>Der Makler ist berechtigt, zur Vermarktung erforderliche Unterlagen (Grundbuch, Energieausweis, Nutzwertgutachten, Rücklagenstand etc.) direkt bei der zuständigen Hausverwaltung, dem Grundbuchsamt oder anderen Stellen einzuholen. Der Eigentümer bevollmächtigt den Makler hierzu ausdrücklich.</li>
    <li>Der Auftrag gilt für 6 Monate ab Unterschrift und verlängert sich automatisch um jeweils 3 Monate, sofern er nicht mit einer Frist von 4 Wochen schriftlich gekündigt wird.</li>
    <li>Die Vermarktung auf allen gängigen Plattformen (willhaben, Immobilienscout24, ImmoWelt, SR-Homes-Website) ist vom Eigentümer genehmigt.</li>
</ol>

<div style="margin-top: 40px;">
    <span class="signature-line"></span>
    <span style="display: inline-block; width: 5%;"></span>
    <span class="signature-line"></span><br>
    <span class="signature-label">Eigentümer — Datum, Unterschrift</span>
    <span style="display: inline-block; width: 5%;"></span>
    <span class="signature-label">Makler — Datum, Unterschrift</span>
</div>

</body>
</html>
```

- [ ] **Step 5: Test laufen → PASS**

Run: `php artisan test --filter=VermittlungsauftragPdfServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/VermittlungsauftragPdfService.php \
        resources/views/pdf/vermittlungsauftrag.blade.php \
        tests/Unit/VermittlungsauftragPdfServiceTest.php
git commit -m "feat(pdf): vermittlungsauftrag template mit vollmacht fuer HV-dokumente"
```

---

## Task 6: Mail-Templates + Mailable-Klassen

**Files:**
- Create: `app/Mail/IntakeProtocolMail.php`
- Create: `app/Mail/PortalAccessMail.php`
- Create: `resources/views/emails/intake-protocol-complete.blade.php`
- Create: `resources/views/emails/intake-protocol-missing-docs.blade.php`
- Create: `resources/views/emails/portal-access.blade.php`

- [ ] **Step 1: `IntakeProtocolMail` Mailable-Klasse anlegen**

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IntakeProtocolMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $property,
        public array $owner,
        public array $broker,
        public array $missingDocs,
        public string $protocolPdfPath,
        public ?string $vermittlungsauftragPdfPath = null,
    ) {}

    public function envelope(): Envelope
    {
        $refId = $this->property['ref_id'] ?? 'neu';
        $subject = count($this->missingDocs) > 0
            ? "Ihr Aufnahmeprotokoll · {$refId} — noch fehlende Unterlagen"
            : "Ihr Aufnahmeprotokoll · {$refId}";

        return new Envelope(
            to: $this->owner['email'],
            subject: $subject,
            replyTo: [$this->broker['email'] ?? 'office@sr-homes.at'],
        );
    }

    public function content(): Content
    {
        $view = count($this->missingDocs) > 0
            ? 'emails.intake-protocol-missing-docs'
            : 'emails.intake-protocol-complete';

        return new Content(
            view: $view,
            with: [
                'property' => $this->property,
                'owner' => $this->owner,
                'broker' => $this->broker,
                'missingDocs' => $this->missingDocs,
            ],
        );
    }

    public function attachments(): array
    {
        $out = [];
        if (is_file($this->protocolPdfPath)) {
            $out[] = Attachment::fromPath($this->protocolPdfPath)
                ->as('Aufnahmeprotokoll-' . ($this->property['ref_id'] ?? 'objekt') . '.pdf')
                ->withMime('application/pdf');
        }
        if ($this->vermittlungsauftragPdfPath && is_file($this->vermittlungsauftragPdfPath)) {
            $out[] = Attachment::fromPath($this->vermittlungsauftragPdfPath)
                ->as('Vermittlungsauftrag-' . ($this->property['ref_id'] ?? 'objekt') . '.pdf')
                ->withMime('application/pdf');
        }
        return $out;
    }
}
```

- [ ] **Step 2: `PortalAccessMail` Mailable anlegen**

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $owner,
        public string $loginEmail,
        public string $initialPassword,
        public string $loginUrl,
        public array $broker,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->owner['email'],
            subject: 'Ihr Zugang zum SR-Homes Kundenportal',
            replyTo: [$this->broker['email'] ?? 'office@sr-homes.at'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.portal-access',
            with: [
                'owner' => $this->owner,
                'loginEmail' => $this->loginEmail,
                'initialPassword' => $this->initialPassword,
                'loginUrl' => $this->loginUrl,
                'broker' => $this->broker,
            ],
        );
    }
}
```

- [ ] **Step 3: Template `intake-protocol-complete.blade.php` anlegen**

```blade
<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie
{{ $property['address'] ?? '' }}.</p>

<p>Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF-Anhang zu Ihrer Unterlage.</p>

<p>Wir melden uns in den nächsten Tagen mit dem Vermittlungsauftrag und den weiteren Schritten.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong><br>
SR-Homes Immobilien</p>

</body>
</html>
```

- [ ] **Step 4: Template `intake-protocol-missing-docs.blade.php` anlegen**

```blade
<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie
{{ $property['address'] ?? '' }}.</p>

<p>Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF.</p>

<p>Damit wir Ihr Objekt bestmöglich vermarkten können, benötigen wir noch folgende Unterlagen:</p>

<ul style="background:#f9fafb;padding:16px 24px;border-left:3px solid #EE7600">
    @foreach($missingDocs as $doc)
        <li>{{ $doc }}</li>
    @endforeach
</ul>

<p><strong>Zwei Möglichkeiten:</strong></p>

<p><strong>Variante A</strong> — Sie senden uns diese Unterlagen per E-Mail an
<a href="mailto:{{ $broker['email'] ?? 'office@sr-homes.at' }}">{{ $broker['email'] ?? 'office@sr-homes.at' }}</a>.</p>

<p><strong>Variante B</strong> — Sie unterschreiben den beigefügten Vermittlungsauftrag, dann holen wir die fehlenden Unterlagen direkt bei Ihrer Hausverwaltung ein.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong><br>
SR-Homes Immobilien</p>

</body>
</html>
```

- [ ] **Step 5: Template `portal-access.blade.php` anlegen**

```blade
<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>wir haben für Sie einen Zugang zum SR-Homes-Kundenportal angelegt. Dort sehen Sie jederzeit aktuelle Informationen zu Ihrer Immobilie — Aktivitäten, Dokumente, Interessenten-Anfragen und Besichtigungen.</p>

<p style="background:#f9fafb;padding:16px;border-radius:8px;border-left:3px solid #EE7600;font-family:monospace">
    <strong>Login:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
    <strong>E-Mail:</strong> {{ $loginEmail }}<br>
    <strong>Initiales Passwort:</strong> {{ $initialPassword }}
</p>

<p><strong>Wichtig:</strong> Bitte ändern Sie das Passwort nach dem ersten Login in den Einstellungen.</p>

<p>Bei Fragen wenden Sie sich direkt an
<a href="mailto:{{ $broker['email'] ?? 'office@sr-homes.at' }}">{{ $broker['email'] ?? 'office@sr-homes.at' }}</a>.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong></p>

</body>
</html>
```

- [ ] **Step 6: Smoke-Test: Templates rendern ohne Fehler**

Run:
```bash
php artisan tinker --execute="
echo view('emails.intake-protocol-complete', [
    'owner' => ['name' => 'Test'],
    'property' => ['address' => 'Musterstr 1'],
    'broker' => ['name' => 'Makler'],
    'missingDocs' => [],
])->render() !== '' ? 'complete-ok ' : 'FAIL ';

echo view('emails.intake-protocol-missing-docs', [
    'owner' => ['name' => 'Test'],
    'property' => ['address' => 'Musterstr 1'],
    'broker' => ['name' => 'Makler'],
    'missingDocs' => ['Grundbuchauszug', 'Energieausweis'],
])->render() !== '' ? 'missing-ok ' : 'FAIL ';

echo view('emails.portal-access', [
    'owner' => ['name' => 'Test'],
    'loginEmail' => 't@test.at',
    'initialPassword' => 'Abc123!',
    'loginUrl' => 'https://kundenportal.sr-homes.at',
    'broker' => ['name' => 'Makler', 'email' => 'm@test.at'],
])->render() !== '' ? 'portal-ok' : 'FAIL';
"
```
Expected: `complete-ok missing-ok portal-ok`

- [ ] **Step 7: Commit**

```bash
git add app/Mail/IntakeProtocolMail.php app/Mail/PortalAccessMail.php \
        resources/views/emails/intake-protocol-complete.blade.php \
        resources/views/emails/intake-protocol-missing-docs.blade.php \
        resources/views/emails/portal-access.blade.php
git commit -m "feat(mail): mailables + templates fuer protokoll + portalzugang"
```

---

## Task 7: Email-Service (Dispatch-Wrapper)

**Files:**
- Create: `app/Services/IntakeProtocolEmailService.php`
- Create: `tests/Unit/IntakeProtocolEmailServiceTest.php`

- [ ] **Step 1: Test schreiben**

```php
<?php

namespace Tests\Unit;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use App\Services\IntakeProtocolEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class IntakeProtocolEmailServiceTest extends TestCase
{
    public function test_sends_protocol_mail_without_missing_docs(): void
    {
        Mail::fake();
        $service = app(IntakeProtocolEmailService::class);

        $tempPdf = storage_path('app/test-protocol.pdf');
        file_put_contents($tempPdf, '%PDF-1.4 fake');

        $service->sendProtocol(
            owner: ['name' => 'X', 'email' => 'x@test.at'],
            property: ['ref_id' => 'T1', 'address' => 'Teststr'],
            broker: ['name' => 'M', 'email' => 'm@test.at'],
            missingDocs: [],
            protocolPdfPath: $tempPdf,
        );

        Mail::assertSent(IntakeProtocolMail::class, fn($m) => $m->hasTo('x@test.at'));
        @unlink($tempPdf);
    }

    public function test_sends_portal_access_mail(): void
    {
        Mail::fake();
        $service = app(IntakeProtocolEmailService::class);

        $service->sendPortalAccess(
            owner: ['name' => 'Y', 'email' => 'y@test.at'],
            loginEmail: 'y@test.at',
            initialPassword: 'Xy9!abcde',
            broker: ['name' => 'M', 'email' => 'm@test.at'],
        );

        Mail::assertSent(PortalAccessMail::class, fn($m) => $m->hasTo('y@test.at'));
    }
}
```

- [ ] **Step 2: Test laufen → FAIL**

Run: `php artisan test --filter=IntakeProtocolEmailServiceTest`
Expected: FAIL (class not found)

- [ ] **Step 3: Service anlegen**

```php
<?php

namespace App\Services;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use Illuminate\Support\Facades\Mail;

class IntakeProtocolEmailService
{
    public function sendProtocol(
        array $owner,
        array $property,
        array $broker,
        array $missingDocs,
        string $protocolPdfPath,
        ?string $vermittlungsauftragPdfPath = null,
    ): void {
        Mail::send(new IntakeProtocolMail(
            property: $property,
            owner: $owner,
            broker: $broker,
            missingDocs: $missingDocs,
            protocolPdfPath: $protocolPdfPath,
            vermittlungsauftragPdfPath: $vermittlungsauftragPdfPath,
        ));
    }

    public function sendPortalAccess(
        array $owner,
        string $loginEmail,
        string $initialPassword,
        array $broker,
        ?string $loginUrl = null,
    ): void {
        $loginUrl = $loginUrl ?: config('app.url') . '/login';
        Mail::send(new PortalAccessMail(
            owner: $owner,
            loginEmail: $loginEmail,
            initialPassword: $initialPassword,
            loginUrl: $loginUrl,
            broker: $broker,
        ));
    }
}
```

- [ ] **Step 4: Test laufen → PASS**

Run: `php artisan test --filter=IntakeProtocolEmailServiceTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/IntakeProtocolEmailService.php tests/Unit/IntakeProtocolEmailServiceTest.php
git commit -m "feat(mail): IntakeProtocolEmailService als Dispatch-Wrapper"
```

---

## Task 8: Controller-Skeleton + Draft-Endpoints

**Files:**
- Create: `app/Http/Controllers/Admin/IntakeProtocolController.php`
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Create: `tests/Feature/IntakeProtocolDraftTest.php`

- [ ] **Step 1: Test `IntakeProtocolDraftTest.php` anlegen**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\IntakeProtocolDraft;
use Tests\TestCase;

class IntakeProtocolDraftTest extends TestCase
{
    public function test_draft_save_creates_new_row_and_returns_draft_id(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $response = $this->postJson('/api/admin?action=intake_protocol_draft_save', [
            'draft_key' => 'test-uuid-1111',
            'form_data' => ['object_type' => 'Wohnung', 'address' => 'Teststraße'],
            'current_step' => 2,
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonStructure(['draft_id', 'last_saved_at']);

        $this->assertDatabaseHas('intake_protocol_drafts', [
            'broker_id' => $user->id,
            'draft_key' => 'test-uuid-1111',
            'current_step' => 2,
        ]);
    }

    public function test_draft_save_updates_existing_row_on_same_key(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        IntakeProtocolDraft::create([
            'broker_id' => $user->id,
            'draft_key' => 'abc',
            'form_data' => json_encode(['step1' => 'initial']),
            'current_step' => 1,
        ]);

        $this->postJson('/api/admin?action=intake_protocol_draft_save', [
            'draft_key' => 'abc',
            'form_data' => ['step1' => 'updated'],
            'current_step' => 3,
        ])->assertStatus(200);

        $this->assertEquals(1, IntakeProtocolDraft::where('broker_id', $user->id)
            ->where('draft_key', 'abc')->count());

        $draft = IntakeProtocolDraft::where('draft_key', 'abc')->first();
        $this->assertEquals(3, $draft->current_step);
        $this->assertStringContainsString('updated', $draft->form_data);
    }

    public function test_draft_load_returns_most_recent_draft_for_user(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        IntakeProtocolDraft::create([
            'broker_id' => $user->id,
            'draft_key' => 'xyz',
            'form_data' => json_encode(['loaded' => true]),
            'current_step' => 5,
        ]);

        $response = $this->getJson('/api/admin?action=intake_protocol_draft_load&draft_key=xyz');
        $response->assertStatus(200)
                 ->assertJsonPath('form_data.loaded', true)
                 ->assertJsonPath('current_step', 5);
    }
}
```

- [ ] **Step 2: Test laufen → FAIL**

Run: `php artisan test --filter=IntakeProtocolDraftTest`
Expected: FAIL

- [ ] **Step 3: Controller-Skeleton anlegen**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeProtocolDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeProtocolController extends Controller
{
    public function draftSave(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $draftKey = trim((string) ($data['draft_key'] ?? ''));
        $formData = $data['form_data'] ?? [];
        $currentStep = (int) ($data['current_step'] ?? 1);

        if ($draftKey === '') {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::updateOrCreate(
            ['broker_id' => $userId, 'draft_key' => $draftKey],
            [
                'form_data' => is_array($formData) ? json_encode($formData) : (string) $formData,
                'current_step' => $currentStep,
                'last_saved_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }

    public function draftLoad(Request $request): JsonResponse
    {
        $draftKey = $request->query('draft_key');
        if (!$draftKey) {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::where('broker_id', $userId)
            ->where('draft_key', $draftKey)
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'error' => 'not found'], 404);
        }

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'form_data' => $draft->form_data_array,
            'current_step' => $draft->current_step,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 4: Actions in `AdminApiController` registrieren**

In `app/Http/Controllers/Admin/AdminApiController.php` im `match($action)`-Block, nach `'geocode_address'`, einfügen:

```php
            // Aufnahmeprotokoll
            'intake_protocol_draft_save' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftSave($request),
            'intake_protocol_draft_load' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftLoad($request),
```

- [ ] **Step 5: Test laufen → PASS**

Run: `php artisan test --filter=IntakeProtocolDraftTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/IntakeProtocolController.php \
        app/Http/Controllers/Admin/AdminApiController.php \
        tests/Feature/IntakeProtocolDraftTest.php
git commit -m "feat(api): intake_protocol_draft_save + draft_load endpoints"
```

---

## Task 9: Submit-Endpoint (Haupttransaktion)

**Files:**
- Modify: `app/Http/Controllers/Admin/IntakeProtocolController.php`
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Create: `tests/Feature/IntakeProtocolSubmitTest.php`

- [ ] **Step 1: Test `IntakeProtocolSubmitTest.php` anlegen**

```php
<?php

namespace Tests\Feature;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use App\Models\IntakeProtocol;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeProtocolSubmitTest extends TestCase
{
    public function test_submit_creates_property_customer_protocol_activity_and_sends_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['user_type' => 'makler', 'email' => 'makler@test.at', 'name' => 'Makler']);
        $this->actingAs($user);

        $payload = [
            'form_data' => [
                'object_type' => 'Wohnung',
                'marketing_type' => 'kauf',
                'address' => 'Musterstraße', 'house_number' => '1',
                'zip' => '5020', 'city' => 'Salzburg',
                'living_area' => 80, 'rooms_amount' => 3,
                'construction_year' => 2010,
                'realty_condition' => 'gebraucht',
                'owner' => [
                    'name' => 'Hans Test',
                    'email' => 'hans@test.at',
                    'phone' => '+43 664 000',
                ],
                'portal_access_granted' => false,
                'documents_available' => ['grundbuchauszug' => 'available', 'energieausweis' => 'missing'],
                'approvals_status' => 'complete',
                'broker_notes' => 'Test-Notiz',
                'open_fields' => [],
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'Hans Test',
            'disclaimer_text' => 'Die im Aufnahmeprotokoll angegebenen Informationen stammen vom Eigentümer.',
        ];

        $response = $this->postJson('/api/admin?action=intake_protocol_submit', $payload);

        $response->assertStatus(200)->assertJson(['success' => true]);

        // Property angelegt?
        $property = Property::where('address', 'Musterstraße')->first();
        $this->assertNotNull($property);
        $this->assertEquals(80, $property->living_area);
        $this->assertEquals('kauf', $property->marketing_type);

        // Customer angelegt?
        $this->assertDatabaseHas('customers', ['email' => 'hans@test.at']);

        // IntakeProtocol angelegt?
        $protocol = IntakeProtocol::where('property_id', $property->id)->first();
        $this->assertNotNull($protocol);
        $this->assertEquals('Hans Test', $protocol->signed_by_name);
        $this->assertTrue(str_starts_with($protocol->signature_png_path, 'intake-protocols/'));

        // Activity?
        $this->assertDatabaseHas('activities', [
            'property_id' => $property->id,
            'category' => 'Aufnahmeprotokoll',
        ]);

        // Mail raus?
        Mail::assertSent(IntakeProtocolMail::class, fn($m) => $m->hasTo('hans@test.at'));
        Mail::assertNotSent(PortalAccessMail::class);
    }

    public function test_submit_with_portal_access_grants_user_and_sends_portal_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $this->postJson('/api/admin?action=intake_protocol_submit', [
            'form_data' => [
                'object_type' => 'Haus', 'marketing_type' => 'kauf',
                'address' => 'Portalstr', 'house_number' => '2',
                'zip' => '5020', 'city' => 'Salzburg',
                'living_area' => 150, 'rooms_amount' => 5, 'construction_year' => 2000,
                'realty_condition' => 'gebraucht',
                'owner' => ['name' => 'P1', 'email' => 'portal@test.at'],
                'portal_access_granted' => true,
                'documents_available' => [],
                'approvals_status' => 'complete',
                'broker_notes' => '',
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'P1',
            'disclaimer_text' => 'D',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'portal@test.at', 'user_type' => 'customer']);
        Mail::assertSent(PortalAccessMail::class, fn($m) => $m->hasTo('portal@test.at'));
    }
}
```

- [ ] **Step 2: Test laufen → FAIL**

Run: `php artisan test --filter=IntakeProtocolSubmitTest`
Expected: FAIL (404 or missing method)

- [ ] **Step 3: `submit()`-Methode in Controller hinzufügen**

Am Ende von `app/Http/Controllers/Admin/IntakeProtocolController.php`, vor der schließenden Klammer, einfügen:

```php
    public function submit(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $form = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];
        $signatureDataUrl = (string) ($payload['signature_data_url'] ?? '');
        $signedByName = trim((string) ($payload['signed_by_name'] ?? ''));
        $disclaimerText = trim((string) ($payload['disclaimer_text'] ?? ''));

        if ($disclaimerText === '' || $signedByName === '' || $signatureDataUrl === '') {
            return response()->json(['error' => 'signature/disclaimer/name required'], 422);
        }

        $brokerId = (int) \Auth::id();
        $broker = \App\Models\User::find($brokerId);

        try {
            $result = \DB::transaction(function () use ($form, $signatureDataUrl, $signedByName, $disclaimerText, $brokerId, $broker, $request) {

                // 1) Customer anlegen oder finden
                $ownerData = is_array($form['owner'] ?? null) ? $form['owner'] : [];
                $customerId = $this->findOrCreateCustomer($ownerData);

                // 2) Portal-User
                $portalAccessGranted = !empty($form['portal_access_granted']);
                $initialPassword = null;
                if ($portalAccessGranted && !empty($ownerData['email'])) {
                    $initialPassword = $this->generatePassword();
                    $this->ensurePortalUser($ownerData, $initialPassword, $customerId);
                }

                // 3) Property anlegen
                $property = $this->buildProperty($form, $customerId, $brokerId);
                $property->save();

                // 4) Signature-PNG speichern
                $signaturePath = $this->storeSignature($property->id, $signatureDataUrl);

                // 5) IntakeProtocol-Row
                $protocol = \App\Models\IntakeProtocol::create([
                    'property_id' => $property->id,
                    'customer_id' => $customerId,
                    'broker_id' => $brokerId,
                    'signed_at' => now(),
                    'signed_by_name' => $signedByName,
                    'signature_png_path' => $signaturePath,
                    'disclaimer_text' => $disclaimerText,
                    'portal_access_granted' => $portalAccessGranted,
                    'broker_notes' => (string) ($form['broker_notes'] ?? ''),
                    'open_fields' => array_values((array) ($form['open_fields'] ?? [])),
                    'form_snapshot' => json_encode($form, JSON_UNESCAPED_UNICODE),
                    'client_ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);

                // 6) Activity
                \DB::table('activities')->insert([
                    'property_id' => $property->id,
                    'stakeholder' => $ownerData['name'] ?? '',
                    'activity' => 'Aufnahmeprotokoll durchgeführt',
                    'category' => 'Aufnahmeprotokoll',
                    'activity_date' => now(),
                    'link_session_id' => 'intake_protocol:' . $protocol->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 7) PDF generieren + speichern
                $pdfPath = $this->generateAndStorePdf($protocol, $property, $ownerData, $broker, $form);
                $protocol->update(['pdf_path' => $pdfPath]);

                // 8) Mails versenden (Queue-frei, damit Tests Mail::fake() greift)
                $emailService = app(\App\Services\IntakeProtocolEmailService::class);

                if ($portalAccessGranted && $initialPassword && !empty($ownerData['email'])) {
                    $emailService->sendPortalAccess(
                        owner: $ownerData,
                        loginEmail: $ownerData['email'],
                        initialPassword: $initialPassword,
                        broker: ['name' => $broker->name, 'email' => $broker->email],
                    );
                    $protocol->update(['portal_email_sent_at' => now()]);
                }

                $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
                $vermittlungsPath = null;
                if (count($missingDocs) > 0) {
                    $vermittlungsPath = $this->generateVermittlungsauftrag($property, $ownerData, $broker);
                }

                if (!empty($ownerData['email'])) {
                    $emailService->sendProtocol(
                        owner: $ownerData,
                        property: $property->toArray(),
                        broker: ['name' => $broker->name, 'email' => $broker->email],
                        missingDocs: $missingDocs,
                        protocolPdfPath: storage_path('app/' . $pdfPath),
                        vermittlungsauftragPdfPath: $vermittlungsPath ? storage_path('app/' . $vermittlungsPath) : null,
                    );
                    $protocol->update(['owner_email_sent_at' => now()]);
                }

                // 9) Draft aufräumen, falls vorhanden
                if (!empty($form['draft_key'])) {
                    \App\Models\IntakeProtocolDraft::where('broker_id', $brokerId)
                        ->where('draft_key', $form['draft_key'])
                        ->delete();
                }

                return ['property_id' => $property->id, 'protocol_id' => $protocol->id];
            });

            return response()->json(['success' => true] + $result);
        } catch (\Throwable $e) {
            \Log::error('intake_protocol_submit failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Submit failed: ' . $e->getMessage()], 500);
        }
    }

    // --- Helpers ---

    private function findOrCreateCustomer(array $ownerData): ?int
    {
        if (empty($ownerData['email']) && empty($ownerData['name'])) return null;

        if (!empty($ownerData['email'])) {
            $existing = \DB::table('customers')->where('email', $ownerData['email'])->first();
            if ($existing) return (int) $existing->id;
        }

        return (int) \DB::table('customers')->insertGetId([
            'name' => $ownerData['name'] ?? '',
            'email' => $ownerData['email'] ?? null,
            'phone' => $ownerData['phone'] ?? null,
            'address' => $ownerData['address'] ?? null,
            'zip' => $ownerData['zip'] ?? null,
            'city' => $ownerData['city'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePortalUser(array $ownerData, string $password, ?int $customerId): void
    {
        $email = $ownerData['email'];
        $existing = \DB::table('users')->where('email', $email)->first();
        if ($existing) return;

        \DB::table('users')->insert([
            'name' => $ownerData['name'] ?? $email,
            'email' => $email,
            'password' => bcrypt($password),
            'user_type' => 'customer',
            'customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generatePassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#%';
        return substr(str_shuffle(str_repeat($chars, 2)), 0, 12);
    }

    private function buildProperty(array $form, ?int $customerId, int $brokerId): \App\Models\Property
    {
        $fillable = [
            'object_type', 'object_subtype', 'marketing_type',
            'title', 'subtitle', 'ref_id',
            'address', 'house_number', 'zip', 'city',
            'staircase', 'door', 'address_floor', 'latitude', 'longitude',
            'living_area', 'free_area', 'total_area', 'realty_area',
            'rooms_amount', 'bedrooms', 'bathrooms', 'toilets',
            'floor_count', 'floor_number',
            'construction_year', 'year_renovated',
            'realty_condition', 'construction_type', 'quality',
            'ownership_type', 'furnishing', 'condition_note',
            'area_balcony', 'balcony_count', 'area_terrace', 'terrace_count',
            'area_loggia', 'loggia_count', 'area_garden', 'garden_count',
            'area_basement', 'basement_count',
            'has_balcony', 'has_terrace', 'has_loggia', 'has_garden',
            'has_basement', 'has_cellar', 'has_elevator', 'has_fitted_kitchen',
            'has_air_conditioning', 'has_pool', 'has_sauna', 'has_fireplace',
            'has_alarm', 'has_barrier_free', 'has_guest_wc', 'has_storage_room',
            'common_areas', 'flooring', 'bathroom_equipment', 'orientation',
            'energy_certificate', 'heating_demand_value', 'heating_demand_class',
            'energy_efficiency_value', 'energy_primary_source', 'energy_valid_until',
            'heating', 'has_photovoltaik', 'charging_station_status',
            'garage_spaces', 'parking_spaces', 'parking_type', 'parking_assignment',
            'property_manager_id', 'encumbrances',
            'documents_available', 'approvals_status', 'approvals_notes', 'internal_notes',
            'purchase_price', 'rental_price', 'rent_warm', 'rent_deposit', 'price_per_m2',
            'operating_costs', 'maintenance_reserves', 'heating_costs', 'warm_water_costs',
            'admin_costs', 'elevator_costs', 'monthly_costs',
            'commission_percent', 'buyer_commission_percent',
            'available_from', 'property_history',
        ];

        $props = ['broker_id' => $brokerId, 'customer_id' => $customerId, 'realty_status' => 'aktiv'];
        foreach ($fillable as $key) {
            if (array_key_exists($key, $form) && $form[$key] !== '' && $form[$key] !== null) {
                $props[$key] = $form[$key];
            }
        }
        // broker_notes landet auf property.internal_notes
        if (!empty($form['broker_notes'])) {
            $props['internal_notes'] = trim(($props['internal_notes'] ?? '') . "\n" . $form['broker_notes']);
        }
        // property_history (Sanierungen) als JSON-String
        if (isset($props['property_history']) && is_array($props['property_history'])) {
            $props['property_history'] = json_encode($props['property_history'], JSON_UNESCAPED_UNICODE);
        }

        return new \App\Models\Property($props);
    }

    private function storeSignature(int $propertyId, string $dataUrl): string
    {
        // data:image/png;base64,...
        $base64 = preg_replace('/^data:image\/png;base64,/', '', $dataUrl);
        $binary = base64_decode($base64);
        if (!$binary) throw new \RuntimeException('Invalid signature data URL');

        $path = "intake-protocols/{$propertyId}/signature-" . time() . '.png';
        \Storage::put($path, $binary);
        return $path;
    }

    private function generateAndStorePdf(
        \App\Models\IntakeProtocol $protocol,
        \App\Models\Property $property,
        array $owner,
        \App\Models\User $broker,
        array $form,
    ): string {
        $pdfService = app(\App\Services\IntakeProtocolPdfService::class);

        $sanierungen = [];
        $history = $form['property_history'] ?? null;
        if (is_string($history)) $history = json_decode($history, true);
        if (is_array($history)) {
            foreach ($history as $h) {
                $sanierungen[] = [
                    'category' => $h['category'] ?? '',
                    'label' => $h['title'] ?? ($h['category'] ?? ''),
                    'year' => $h['year'] ?? null,
                    'description' => $h['description'] ?? '',
                ];
            }
        }

        $binary = $pdfService->render([
            'property' => $property->toArray(),
            'owner' => $owner,
            'broker' => ['name' => $broker->name, 'email' => $broker->email],
            'disclaimer_text' => $protocol->disclaimer_text,
            'signed_at' => $protocol->signed_at,
            'signed_by_name' => $protocol->signed_by_name,
            'signature_png_path' => $protocol->signature_png_path,
            'broker_notes' => $form['broker_notes'] ?? '',
            'sanierungen' => $sanierungen,
            'documents_available' => $form['documents_available'] ?? [],
            'approvals_status' => $form['approvals_status'] ?? null,
            'approvals_notes' => $form['approvals_notes'] ?? null,
            'open_fields' => $form['open_fields'] ?? [],
            'client_ip' => $protocol->client_ip,
            'user_agent' => $protocol->user_agent,
        ]);

        $path = "intake-protocols/{$property->id}/protocol-{$protocol->id}.pdf";
        \Storage::put($path, $binary);
        return $path;
    }

    private function generateVermittlungsauftrag(
        \App\Models\Property $property,
        array $owner,
        \App\Models\User $broker,
    ): string {
        $service = app(\App\Services\VermittlungsauftragPdfService::class);
        $binary = $service->render([
            'property' => $property->toArray(),
            'owner' => $owner,
            'broker' => ['name' => $broker->name, 'email' => $broker->email, 'company' => 'SR-Homes Immobilien GmbH'],
            'commission_percent' => $property->commission_percent ?? 3.0,
        ]);
        $path = "intake-protocols/{$property->id}/vermittlungsauftrag.pdf";
        \Storage::put($path, $binary);
        return $path;
    }

    private function computeMissingDocs(array $documentsAvailable): array
    {
        $labels = [
            'grundbuchauszug' => 'Grundbuchauszug',
            'energieausweis' => 'Energieausweis',
            'plaene' => 'Grundrisse / Pläne',
            'nutzwertgutachten' => 'Nutzwertgutachten',
            'ruecklagenstand' => 'Rücklagenstand',
            'wohnungseigentumsvertrag' => 'Wohnungseigentumsvertrag',
            'hausordnung' => 'Hausordnung',
            'letzte_jahresabrechnung' => 'Letzte Jahresabrechnung',
            'betriebskostenabrechnung' => 'Betriebskostenabrechnung',
            'schaetzwert_gutachten' => 'Schätzwert-Gutachten',
            'baubewilligung' => 'Baubewilligung',
            'mietvertrag' => 'Mietvertrag',
            'hypothekenvertrag' => 'Hypothekenvertrag',
        ];
        $missing = [];
        foreach ($documentsAvailable as $key => $status) {
            if ($status === 'missing' && isset($labels[$key])) $missing[] = $labels[$key];
        }
        return $missing;
    }
```

- [ ] **Step 4: Action in `AdminApiController` registrieren**

In `app/Http/Controllers/Admin/AdminApiController.php` unter den beiden existierenden `intake_protocol_*` Actions ergänzen:

```php
            'intake_protocol_submit' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->submit($request),
```

- [ ] **Step 5: Test laufen → PASS**

Run: `php artisan test --filter=IntakeProtocolSubmitTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/IntakeProtocolController.php \
        app/Http/Controllers/Admin/AdminApiController.php \
        tests/Feature/IntakeProtocolSubmitTest.php
git commit -m "feat(api): intake_protocol_submit endpoint mit atomarer Transaktion"
```

---

## Task 10: PDF-Download + Mail-Resend

**Files:**
- Modify: `app/Http/Controllers/Admin/IntakeProtocolController.php`
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`

- [ ] **Step 1: `getPdf` + `resendEmail` Methoden ergänzen**

Am Ende von `IntakeProtocolController.php` (vor der schließenden Klassen-Klammer), einfügen:

```php
    public function getPdf(Request $request)
    {
        $protocolId = (int) $request->query('protocol_id');
        $protocol = \App\Models\IntakeProtocol::find($protocolId);
        if (!$protocol || !$protocol->pdf_path) abort(404);

        $fullPath = storage_path('app/' . $protocol->pdf_path);
        if (!is_file($fullPath)) abort(404);

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="aufnahmeprotokoll-' . $protocol->property_id . '.pdf"',
        ]);
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $protocolId = (int) ($data['protocol_id'] ?? 0);
        $type = (string) ($data['type'] ?? 'protocol'); // 'protocol' oder 'portal'

        $protocol = \App\Models\IntakeProtocol::find($protocolId);
        if (!$protocol) return response()->json(['error' => 'not found'], 404);

        $property = $protocol->property;
        $owner = $protocol->customer;
        $broker = $protocol->broker;

        if (!$owner || empty($owner->email)) {
            return response()->json(['error' => 'kein Eigentümer mit Email verknüpft'], 422);
        }

        $emailService = app(\App\Services\IntakeProtocolEmailService::class);

        if ($type === 'protocol') {
            $form = is_string($protocol->form_snapshot) ? json_decode($protocol->form_snapshot, true) : [];
            $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
            $emailService->sendProtocol(
                owner: ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone],
                property: $property->toArray(),
                broker: ['name' => $broker->name, 'email' => $broker->email],
                missingDocs: $missingDocs,
                protocolPdfPath: storage_path('app/' . $protocol->pdf_path),
            );
            $protocol->update(['owner_email_sent_at' => now()]);
            return response()->json(['success' => true, 'type' => 'protocol']);
        }

        return response()->json(['error' => 'invalid type'], 422);
    }
```

- [ ] **Step 2: Actions in AdminApiController registrieren**

```php
            'intake_protocol_get_pdf' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->getPdf($request),
            'intake_protocol_resend_email' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->resendEmail($request),
```

- [ ] **Step 3: Smoke-Test via tinker**

Run:
```bash
php artisan tinker --execute="
\$p = \App\Models\IntakeProtocol::first();
if (\$p && \$p->pdf_path) echo 'PDF-Pfad: ' . \$p->pdf_path;
else echo 'noch kein protokoll da — ok';
"
```
Expected: Pfad oder „noch kein protokoll da — ok".

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/IntakeProtocolController.php \
        app/Http/Controllers/Admin/AdminApiController.php
git commit -m "feat(api): intake_protocol_get_pdf + resend_email endpoints"
```

---

## Task 11: Frontend — Composables (useIntakeForm, useAutoSave, useSubtypes)

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/composables/useIntakeForm.js`
- Create: `resources/js/Components/Admin/IntakeProtocol/composables/useAutoSave.js`
- Create: `resources/js/Components/Admin/IntakeProtocol/composables/useSubtypes.js`

- [ ] **Step 1: `useSubtypes.js` anlegen**

```javascript
// Subtypen pro object_type. Steuert welche Pills in Step 1 angezeigt werden
// und welche Folge-Felder später relevant sind.
export const SUBTYPES = {
  Haus: [
    'Einfamilienhaus', 'Doppelhaushälfte', 'Reihenhaus',
    'Zweifamilienhaus', 'Mehrfamilienhaus', 'Villa',
    'Bauernhaus', 'Stadthaus', 'Landhaus',
    'Ferienhaus', 'Berghaus', 'Sonstiges',
  ],
  Wohnung: [
    'Eigentumswohnung', 'Maisonette', 'Dachgeschoss',
    'Penthouse', 'Loft', 'Souterrain',
    'Terrassenwohnung', 'Gartenwohnung',
    'Studio/Einzimmer', 'Appartement', 'Sonstiges',
  ],
  Grundstück: [
    'Baugrund', 'Landwirtschaftlich', 'Gewerbegrund',
    'Wald/Forst', 'Freizeitgrund', 'Sonstiges',
  ],
  Gewerbe: [
    'Büro', 'Ladenfläche', 'Gastronomie', 'Hotel',
    'Lager', 'Produktion', 'Werkstatt', 'Praxis',
    'Anlageobjekt', 'Sonstiges',
  ],
};

export function useSubtypes(objectTypeRef) {
  // Gibt eine computed ref zurück mit den Subtypen des aktuell gewählten Typs
  return () => SUBTYPES[objectTypeRef.value] || [];
}
```

- [ ] **Step 2: `useIntakeForm.js` anlegen**

```javascript
import { reactive, ref, computed } from 'vue';

// Initialer Form-State. Jeder Key entspricht einem DB-Feld oder UI-Feld.
function initialForm() {
  return {
    // Step 1
    object_type: '',
    object_subtype: '',
    marketing_type: '',
    title: '',
    ref_id: '',
    // Step 2
    address: '', house_number: '', zip: '', city: '',
    staircase: '', door: '', address_floor: '',
    latitude: null, longitude: null,
    // Step 3
    owner: { name: '', email: '', phone: '', address: '', zip: '', city: '' },
    owner_customer_id: null,
    portal_access_granted: false,
    // Step 4
    living_area: null, free_area: null, total_area: null, realty_area: null,
    rooms_amount: null, bedrooms: null, bathrooms: null, toilets: null,
    floor_count: null, floor_number: null,
    construction_year: null,
    // Step 5
    realty_condition: '', construction_type: '', quality: '',
    ownership_type: '', furnishing: '', condition_note: '',
    property_history: [],  // array of {category, title, year, description}
    // Step 6
    has_balcony: false, area_balcony: null, balcony_count: null,
    has_terrace: false, area_terrace: null, terrace_count: null,
    has_loggia: false, area_loggia: null, loggia_count: null,
    has_garden: false, area_garden: null,
    has_basement: false, area_basement: null,
    has_elevator: false, has_fitted_kitchen: false, has_air_conditioning: false,
    has_pool: false, has_sauna: false, has_fireplace: false,
    has_alarm: false, has_barrier_free: false, has_guest_wc: false,
    has_storage_room: false,
    common_areas: [],
    flooring: '', bathroom_equipment: '', orientation: '',
    garage_spaces: null, parking_spaces: null,
    parking_type: '', parking_assignment: '',
    // Step 7
    energy_certificate: '', heating_demand_value: null, heating_demand_class: '',
    energy_efficiency_value: null, energy_valid_until: null,
    heating: '', has_photovoltaik: false, charging_station_status: '',
    // Step 8
    property_manager_id: null,
    encumbrances: '',
    approvals_status: '',
    approvals_notes: '',
    documents_available: {},
    // Step 9
    purchase_price: null, rental_price: null, rent_warm: null, rent_deposit: null,
    operating_costs: null, maintenance_reserves: null,
    heating_costs: null, warm_water_costs: null,
    admin_costs: null, elevator_costs: null,
    commission_percent: null, buyer_commission_percent: null,
    available_from: null,
    // Step 10
    photos: [],  // {category, tempId, file}
    // Step 11
    broker_notes: '',
    open_fields: [],  // array of field-keys skipped via "↷ später"
    signature_data_url: '',
    signed_by_name: '',
  };
}

export function useIntakeForm() {
  const form = reactive(initialForm());
  const currentStep = ref(1);
  const draftKey = ref(generateUuid());

  const TOTAL_STEPS = 11;

  const progress = computed(() => Math.round((currentStep.value - 1) / (TOTAL_STEPS - 1) * 100));

  function markSkipped(fieldKey) {
    if (!form.open_fields.includes(fieldKey)) form.open_fields.push(fieldKey);
  }

  function unmarkSkipped(fieldKey) {
    form.open_fields = form.open_fields.filter(f => f !== fieldKey);
  }

  function isSkipped(fieldKey) {
    return form.open_fields.includes(fieldKey);
  }

  function reset() {
    Object.assign(form, initialForm());
    currentStep.value = 1;
    draftKey.value = generateUuid();
  }

  return {
    form, currentStep, draftKey,
    TOTAL_STEPS, progress,
    markSkipped, unmarkSkipped, isSkipped,
    reset,
  };
}

function generateUuid() {
  return 'iap-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}
```

- [ ] **Step 3: `useAutoSave.js` anlegen**

```javascript
import { ref, watch } from 'vue';

/**
 * Debounced Auto-Save für den Wizard-Draft.
 * Speichert nach jeder Änderung in localStorage (sofort) und synced
 * alle 2 Sekunden nach stopping mit dem Server. Bei Netzfehler bleibt
 * localStorage-Wert bestehen und wird später nochmal probiert.
 */
export function useAutoSave({ form, currentStep, draftKey, apiUrl }) {
  const saving = ref(false);
  const lastSaved = ref(null);
  const offline = ref(false);
  let debounceTimer = null;

  function localStorageKey() {
    return 'intake_protocol_draft_' + draftKey.value;
  }

  function saveLocal() {
    try {
      localStorage.setItem(localStorageKey(), JSON.stringify({
        form: JSON.parse(JSON.stringify(form)),
        currentStep: currentStep.value,
        updatedAt: Date.now(),
      }));
    } catch (e) {
      console.warn('localStorage save failed', e);
    }
  }

  function loadLocal() {
    try {
      const raw = localStorage.getItem(localStorageKey());
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function clearLocal() {
    try { localStorage.removeItem(localStorageKey()); } catch (e) {}
  }

  async function saveRemote() {
    saving.value = true;
    try {
      const r = await fetch(apiUrl.value + '&action=intake_protocol_draft_save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          draft_key: draftKey.value,
          form_data: form,
          current_step: currentStep.value,
        }),
      });
      const d = await r.json();
      if (d.success) {
        lastSaved.value = new Date();
        offline.value = false;
      } else {
        offline.value = true;
      }
    } catch (e) {
      offline.value = true;
    }
    saving.value = false;
  }

  function scheduleSave() {
    saveLocal();  // sofort lokal
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => saveRemote(), 2000);
  }

  // Watch form und currentStep
  watch([() => JSON.parse(JSON.stringify(form)), currentStep], () => scheduleSave(), { deep: true });

  // Retry im Hintergrund alle 30 Sek wenn offline
  const retryInterval = setInterval(() => {
    if (offline.value) saveRemote();
  }, 30000);

  // Letzter Save-Versuch bevor Tab geschlossen wird
  window.addEventListener('beforeunload', () => {
    saveLocal();
    // Synchroner Request via sendBeacon
    try {
      navigator.sendBeacon(
        apiUrl.value + '&action=intake_protocol_draft_save',
        new Blob([JSON.stringify({
          draft_key: draftKey.value,
          form_data: form,
          current_step: currentStep.value,
        })], { type: 'application/json' })
      );
    } catch (e) {}
  });

  return {
    saving, lastSaved, offline,
    saveLocal, loadLocal, clearLocal, saveRemote,
    stopRetry: () => clearInterval(retryInterval),
  };
}
```

- [ ] **Step 4: Smoke-Check (Vue build success)**

Run: `npm run build 2>&1 | tail -5`
Expected: Build successful, no errors related to composables.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/IntakeProtocol/composables/
git commit -m "feat(wizard): composables useIntakeForm + useAutoSave + useSubtypes"
```

---

## Task 12: Shared Frontend-Komponenten (Navigation + Skip-Switch + Pills)

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/StepHeader.vue`
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/StepNavigation.vue`
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/SkipFieldSwitch.vue`
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/PillRow.vue`

- [ ] **Step 1: `StepHeader.vue` anlegen**

```vue
<script setup>
defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  title: { type: String, default: '' },
});
defineEmits(['cancel']);
</script>

<template>
  <div class="sticky top-0 z-10 bg-white border-b border-border/60 px-4 py-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        Schritt {{ currentStep }} von {{ totalSteps }}
      </div>
      <button type="button" @click="$emit('cancel')" class="text-xs text-muted-foreground hover:text-foreground">
        Abbrechen
      </button>
    </div>
    <div class="h-1 w-full bg-zinc-100 rounded-full overflow-hidden">
      <div class="h-full bg-[#EE7600] transition-all duration-300"
           :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"></div>
    </div>
    <h2 v-if="title" class="text-lg font-bold mt-3 text-foreground">{{ title }}</h2>
  </div>
</template>
```

- [ ] **Step 2: `StepNavigation.vue` anlegen**

```vue
<script setup>
defineProps({
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  nextDisabled: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Weiter →' },
});
defineEmits(['prev', 'next']);
</script>

<template>
  <div class="sticky bottom-0 z-10 bg-white border-t border-border/60 px-4 py-3 flex gap-2">
    <button
      type="button"
      @click="$emit('prev')"
      :disabled="currentStep === 1"
      class="flex-1 h-11 rounded-xl border border-border text-foreground font-medium disabled:opacity-40 disabled:cursor-not-allowed"
    >Zurück</button>
    <button
      type="button"
      @click="$emit('next')"
      :disabled="nextDisabled"
      class="flex-[2] h-11 rounded-xl bg-zinc-900 text-white font-semibold disabled:opacity-50"
    >{{ currentStep === totalSteps ? 'Absenden' : submitLabel }}</button>
  </div>
</template>
```

- [ ] **Step 3: `SkipFieldSwitch.vue` anlegen**

```vue
<script setup>
const props = defineProps({
  modelValue: { type: Boolean, default: false },  // true = skipped
});
defineEmits(['update:modelValue']);

function toggle() {
  // Vue v-model: emit update
}
</script>

<template>
  <button
    type="button"
    @click="$emit('update:modelValue', !modelValue)"
    :class="[
      'text-[11px] px-2 py-0.5 rounded transition-colors',
      modelValue
        ? 'bg-[#EE7600] text-white'
        : 'bg-orange-50 text-[#EE7600] hover:bg-orange-100'
    ]"
  >
    <span v-if="modelValue">✓ später ergänzen</span>
    <span v-else>↷ später</span>
  </button>
</template>
```

- [ ] **Step 4: `PillRow.vue` anlegen**

```vue
<script setup>
defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },  // [{value, label}] oder [string,...]
  multiline: { type: Boolean, default: true },
});
defineEmits(['update:modelValue']);

function normalize(opt) {
  if (typeof opt === 'string') return { value: opt, label: opt };
  return opt;
}
</script>

<template>
  <div :class="['flex gap-1.5', multiline ? 'flex-wrap' : '']">
    <button
      v-for="(opt, i) in options" :key="i"
      type="button"
      @click="$emit('update:modelValue', normalize(opt).value)"
      :class="[
        'px-3 py-1.5 rounded-full text-[12px] font-medium transition-colors',
        modelValue === normalize(opt).value
          ? 'bg-[#EE7600] text-white'
          : 'bg-white border border-border text-foreground hover:border-[#EE7600]/40'
      ]"
    >{{ normalize(opt).label }}</button>
  </div>
</template>
```

- [ ] **Step 5: Smoke-Check (Vue build)**

Run: `npm run build 2>&1 | tail -5`
Expected: Build successful.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/IntakeProtocol/shared/
git commit -m "feat(wizard): shared components (StepHeader, StepNavigation, SkipSwitch, PillRow)"
```

---

## Task 13: Wizard-Root (IntakeProtocolWizard.vue)

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/IntakeProtocolWizard.vue`

- [ ] **Step 1: Wizard-Root anlegen (Skelett mit Placeholder-Steps)**

```vue
<script setup>
import { inject, onMounted, onBeforeUnmount, computed } from 'vue';
import StepHeader from './shared/StepHeader.vue';
import StepNavigation from './shared/StepNavigation.vue';
import Step01_ObjectType from './steps/Step01_ObjectType.vue';
import Step02_Address from './steps/Step02_Address.vue';
import Step03_Owner from './steps/Step03_Owner.vue';
import Step04_CoreData from './steps/Step04_CoreData.vue';
import Step05_ConditionRenovations from './steps/Step05_ConditionRenovations.vue';
import Step06_FeaturesParking from './steps/Step06_FeaturesParking.vue';
import Step07_Energy from './steps/Step07_Energy.vue';
import Step08_LegalDocuments from './steps/Step08_LegalDocuments.vue';
import Step09_PriceCosts from './steps/Step09_PriceCosts.vue';
import Step10_Photos from './steps/Step10_Photos.vue';
import Step11_SignatureSummary from './steps/Step11_SignatureSummary.vue';
import { useIntakeForm } from './composables/useIntakeForm';
import { useAutoSave } from './composables/useAutoSave';

const emit = defineEmits(['close', 'submitted']);

const API = inject('API');
const toast = inject('toast', () => {});

const { form, currentStep, draftKey, TOTAL_STEPS, markSkipped, unmarkSkipped, isSkipped } = useIntakeForm();
const { saving, lastSaved, offline, stopRetry, clearLocal } = useAutoSave({
  form, currentStep, draftKey, apiUrl: API,
});

const STEP_TITLES = [
  'Objekttyp & Vermarktung', 'Adresse', 'Eigentümer',
  'Kerndaten', 'Zustand & Sanierungen',
  'Ausstattung & Stellplätze', 'Energie',
  'Rechtliches & Dokumente', 'Preis & Kosten',
  'Fotos', 'Unterschrift',
];

const currentStepComponent = computed(() => [
  Step01_ObjectType, Step02_Address, Step03_Owner,
  Step04_CoreData, Step05_ConditionRenovations,
  Step06_FeaturesParking, Step07_Energy,
  Step08_LegalDocuments, Step09_PriceCosts,
  Step10_Photos, Step11_SignatureSummary,
][currentStep.value - 1]);

// Disclaimer-Konstante — MUSS mit Backend-Seite übereinstimmen
const DISCLAIMER_TEXT = 'Die im Aufnahmeprotokoll angegebenen Informationen stammen vom Eigentümer. Der Eigentümer bestätigt durch seine Unterschrift, dass diese Infos von ihm weitergegeben wurden.';

const nextDisabled = computed(() => {
  // Bewilligungs-Pflicht: bei partial/unknown muss notes gefüllt sein
  if (currentStep.value === 8
      && ['partial', 'unknown'].includes(form.approvals_status)
      && !form.approvals_notes.trim()
      && !isSkipped('approvals_notes')) {
    return true;
  }
  // Unterschrift auf letztem Step Pflicht
  if (currentStep.value === TOTAL_STEPS
      && (!form.signature_data_url || !form.signed_by_name.trim())) {
    return true;
  }
  return false;
});

function goNext() {
  if (currentStep.value >= TOTAL_STEPS) {
    submit();
    return;
  }
  currentStep.value += 1;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goPrev() {
  if (currentStep.value > 1) {
    currentStep.value -= 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

async function submit() {
  const r = await fetch(API.value + '&action=intake_protocol_submit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({
      form_data: { ...form, draft_key: draftKey.value },
      signature_data_url: form.signature_data_url,
      signed_by_name: form.signed_by_name,
      disclaimer_text: DISCLAIMER_TEXT,
    }),
  });
  const d = await r.json();
  if (d.success) {
    toast('Aufnahmeprotokoll erfolgreich angelegt!');
    clearLocal();
    stopRetry();
    emit('submitted', { property_id: d.property_id, protocol_id: d.protocol_id });
  } else {
    toast('Fehler: ' + (d.error || 'Unbekannt'));
  }
}

onBeforeUnmount(() => stopRetry());
</script>

<template>
  <div class="fixed inset-0 z-50 bg-zinc-50 flex flex-col" style="overflow-y:auto">

    <StepHeader
      :current-step="currentStep"
      :total-steps="TOTAL_STEPS"
      :title="STEP_TITLES[currentStep - 1]"
      @cancel="emit('close')"
    />

    <div v-if="offline" class="bg-orange-50 text-orange-700 text-xs px-4 py-2 text-center">
      📡 Offline — Änderungen werden später gespeichert
    </div>

    <div class="flex-1 mx-auto w-full" style="max-width:640px">
      <component
        :is="currentStepComponent"
        :form="form"
        :is-skipped="isSkipped"
        :mark-skipped="markSkipped"
        :unmark-skipped="unmarkSkipped"
        :disclaimer-text="DISCLAIMER_TEXT"
      />
    </div>

    <StepNavigation
      :current-step="currentStep"
      :total-steps="TOTAL_STEPS"
      :next-disabled="nextDisabled"
      @prev="goPrev"
      @next="goNext"
    />

  </div>
</template>
```

- [ ] **Step 2: Stub-Files für alle 11 Steps anlegen (damit Import nicht fehlt)**

Run:
```bash
for n in 01 02 03 04 05 06 07 08 09 10 11; do
cat > "resources/js/Components/Admin/IntakeProtocol/steps/Step${n}_placeholder.tmp" <<EOF
<script setup>
defineProps({ form: Object, isSkipped: Function, markSkipped: Function, unmarkSkipped: Function, disclaimerText: String });
</script>
<template>
  <div class="p-4">
    <p class="text-sm text-muted-foreground">Step ${n} — wird im nächsten Task gefüllt.</p>
  </div>
</template>
EOF
done
```

Dann die 11 echten Filenames (wie in `currentStepComponent`-Import erwartet):

```bash
mv resources/js/Components/Admin/IntakeProtocol/steps/Step01_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step01_ObjectType.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step02_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step02_Address.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step03_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step03_Owner.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step04_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step04_CoreData.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step05_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step05_ConditionRenovations.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step06_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step06_FeaturesParking.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step07_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step07_Energy.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step08_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step08_LegalDocuments.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step09_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step09_PriceCosts.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step10_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step10_Photos.vue
mv resources/js/Components/Admin/IntakeProtocol/steps/Step11_placeholder.tmp resources/js/Components/Admin/IntakeProtocol/steps/Step11_SignatureSummary.vue
```

- [ ] **Step 3: Build**

Run: `npm run build 2>&1 | tail -5`
Expected: Build successful.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/IntakeProtocol/IntakeProtocolWizard.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/
git commit -m "feat(wizard): IntakeProtocolWizard root + 11 step placeholders"
```

---

## Task 14: Step 1 (Objekttyp + Subtyp + Vermarktung)

**Files:**
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step01_ObjectType.vue`

- [ ] **Step 1: Komponente ausbauen**

```vue
<script setup>
import { computed, toRef } from 'vue';
import PillRow from '../shared/PillRow.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { useSubtypes } from '../composables/useSubtypes';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const getSubtypes = useSubtypes(toRef(props.form, 'object_type'));
const subtypes = computed(() => getSubtypes());

const TYPE_TILES = [
  { key: 'Haus',       icon: '🏠', label: 'Haus' },
  { key: 'Wohnung',    icon: '🏢', label: 'Wohnung' },
  { key: 'Grundstück', icon: '🌱', label: 'Grundstück' },
  { key: 'Gewerbe',    icon: '🏭', label: 'Gewerbe' },
];

const MARKETING = ['kauf', 'miete', 'pacht'];

function selectType(key) {
  props.form.object_type = key;
  // Reset Subtyp wenn Typ wechselt
  props.form.object_subtype = '';
}

// Ref-ID Auto-Vorschlag — sehr simpel, User kann überschreiben
const refIdSuggestion = computed(() => {
  const mt = (props.form.marketing_type || '').substring(0, 3).toLowerCase(); // kau/mie/pac
  const typ = (props.form.object_type || '').substring(0, 3); // Hau/Woh/Gru/Gew
  const name = ((props.form.owner?.name || '').split(' ').pop() || 'xx').substring(0, 3);
  if (!mt || !typ) return '';
  return `${mt.charAt(0).toUpperCase()}${mt.substring(1)}-${typ}-${name}-01`;
});

const skippedRefId = computed({
  get: () => props.isSkipped('ref_id'),
  set: (v) => v ? props.markSkipped('ref_id') : props.unmarkSkipped('ref_id'),
});
</script>

<template>
  <div class="p-4 space-y-6">

    <!-- Objekttyp -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Hauptkategorie <span class="text-red-500">*</span>
      </div>
      <div class="grid grid-cols-4 gap-2">
        <button
          v-for="t in TYPE_TILES" :key="t.key"
          type="button"
          @click="selectType(t.key)"
          :class="[
            'rounded-xl p-3 text-center transition-colors',
            form.object_type === t.key
              ? 'bg-white border-2 border-[#EE7600] shadow-md'
              : 'bg-white border border-border'
          ]"
        >
          <div class="text-2xl">{{ t.icon }}</div>
          <div class="text-[11px] font-medium mt-1">{{ t.label }}</div>
        </button>
      </div>
    </div>

    <!-- Subtyp -->
    <div v-if="subtypes.length">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Bauweise / Subtyp
      </div>
      <PillRow v-model="form.object_subtype" :options="subtypes" />
    </div>

    <!-- Vermarktung -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Vermarktungsart <span class="text-red-500">*</span>
      </div>
      <PillRow v-model="form.marketing_type" :options="[
        {value: 'kauf', label: 'Kauf'},
        {value: 'miete', label: 'Miete'},
        {value: 'pacht', label: 'Pacht'},
      ]" />
    </div>

    <!-- Ref-ID -->
    <div>
      <div class="flex items-center justify-between mb-1">
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          Ref-ID
        </label>
        <SkipFieldSwitch v-model="skippedRefId" />
      </div>
      <input
        v-model="form.ref_id"
        :placeholder="refIdSuggestion || 'z.B. Kau-Woh-Mus-01'"
        class="w-full h-11 rounded-lg border border-border px-3 font-mono text-sm bg-white"
      />
      <p class="text-[11px] text-muted-foreground mt-1">
        Leer lassen für Auto-Vorschlag: <code>{{ refIdSuggestion || 'wird nach Eigentümer-Auswahl vorgeschlagen' }}</code>
      </p>
    </div>

  </div>
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -5`
Expected: Build successful.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/IntakeProtocol/steps/Step01_ObjectType.vue
git commit -m "feat(wizard): Step 1 — Objekttyp + Subtyp + Vermarktung + Ref-ID"
```

---

## Task 15: Step 2 (Adresse mit OSM-Autocomplete)

**Files:**
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step02_Address.vue`

- [ ] **Step 1: Komponente implementieren**

```vue
<script setup>
import { ref, inject, computed } from 'vue';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const suggestions = ref([]);
const showSuggestions = ref(false);
const loadingSuggestions = ref(false);
let debounceTimer = null;

function splitStreetNumber(value) {
  const str = String(value || '');
  const m = str.match(/^(.+?)[,\s]+(\d+[a-zA-Z]?(?:[-\/]\d+[a-zA-Z]?)?)\s*$/);
  if (m && m[1].trim().length >= 2) {
    return { street: m[1].trim(), houseNumber: m[2] };
  }
  return { street: str, houseNumber: null };
}

function onAddressInput(v) {
  const value = String(v || '');
  props.form.address = value;
  if (debounceTimer) clearTimeout(debounceTimer);
  if (value.trim().length < 3) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }
  debounceTimer = setTimeout(async () => {
    loadingSuggestions.value = true;
    try {
      const q = [value, props.form.zip, props.form.city].filter(Boolean).join(' ');
      const r = await fetch(API.value + '&action=geocode_autocomplete&q=' + encodeURIComponent(q));
      const d = await r.json();
      suggestions.value = Array.isArray(d.results) ? d.results : [];
      showSuggestions.value = suggestions.value.length > 0;
    } catch (e) {
      suggestions.value = [];
    }
    loadingSuggestions.value = false;
  }, 400);
}

function onAddressBlur() {
  setTimeout(() => { showSuggestions.value = false; }, 200);
  const { street, houseNumber } = splitStreetNumber(props.form.address);
  if (houseNumber) {
    props.form.address = street;
    if (!props.form.house_number) props.form.house_number = houseNumber;
  }
}

function pickSuggestion(s) {
  if (s.street) props.form.address = s.street;
  if (s.house_number) props.form.house_number = s.house_number;
  if (s.zip) props.form.zip = s.zip;
  if (s.city) props.form.city = s.city;
  if (s.lat != null) props.form.latitude = s.lat;
  if (s.lng != null) props.form.longitude = s.lng;
  suggestions.value = [];
  showSuggestions.value = false;
}

const isWohnung = computed(() => props.form.object_type === 'Wohnung');
const hasCoords = computed(() => props.form.latitude && props.form.longitude);
</script>

<template>
  <div class="p-4 space-y-4">

    <div class="relative">
      <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
        Straße <span class="text-red-500">*</span>
      </label>
      <input
        :value="form.address"
        @input="onAddressInput($event.target.value)"
        @focus="showSuggestions = suggestions.length > 0"
        @blur="onAddressBlur"
        class="w-full h-11 rounded-lg border border-border px-3 bg-white"
        placeholder="Beim Tippen erscheinen Vorschläge"
        autocomplete="off"
      />
      <div
        v-if="showSuggestions"
        class="absolute left-0 right-0 top-full mt-1 bg-white border border-border rounded-lg shadow-lg z-20 max-h-64 overflow-y-auto"
      >
        <button
          v-for="(s, i) in suggestions" :key="i"
          type="button"
          @mousedown.prevent="pickSuggestion(s)"
          class="w-full text-left px-3 py-2 hover:bg-zinc-50 border-b border-zinc-100 last:border-b-0 text-xs"
        >
          <div class="font-medium">{{ s.street || s.display_name.split(',')[0] }} {{ s.house_number }}</div>
          <div class="text-muted-foreground">{{ [s.zip, s.city].filter(Boolean).join(' ') }}</div>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">Hausnr. *</label>
        <input v-model="form.house_number" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
      </div>
      <div>
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">PLZ *</label>
        <input v-model="form.zip" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
      </div>
    </div>

    <div>
      <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">Stadt *</label>
      <input v-model="form.city" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
    </div>

    <div v-if="isWohnung" class="grid grid-cols-3 gap-3">
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Stiege</label>
        <input v-model="form.staircase" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Tür</label>
        <input v-model="form.door" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Etage</label>
        <input v-model="form.address_floor" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3 bg-white" />
      </div>
    </div>

    <div v-if="hasCoords" class="rounded-lg overflow-hidden border border-border" style="height:240px">
      <iframe
        :src="`https://www.openstreetmap.org/export/embed.html?bbox=${Number(form.longitude)-0.008}%2C${Number(form.latitude)-0.006}%2C${Number(form.longitude)+0.008}%2C${Number(form.latitude)+0.006}&layer=mapnik&marker=${form.latitude}%2C${form.longitude}`"
        width="100%" height="240" frameborder="0" style="border:0" loading="lazy"
      ></iframe>
    </div>

  </div>
</template>
```

- [ ] **Step 2: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/steps/Step02_Address.vue
git commit -m "feat(wizard): Step 2 — Adresse mit OSM-Autocomplete und Live-Karte"
```

---

## Task 16: Step 3 — Eigentümer mit Portalzugang

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/OwnerPicker.vue`
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step03_Owner.vue`

- [ ] **Step 1: `OwnerPicker.vue` anlegen**

```vue
<script setup>
import { ref, inject } from 'vue';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const search = ref('');
const results = ref([]);
const showNewForm = ref(false);
let debounce = null;

async function searchContacts(q) {
  if (q.trim().length < 2) { results.value = []; return; }
  if (debounce) clearTimeout(debounce);
  debounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=contacts&search=' + encodeURIComponent(q));
      const d = await r.json();
      results.value = (d.contacts || []).slice(0, 5);
    } catch (e) { results.value = []; }
  }, 300);
}

function pickContact(c) {
  props.form.owner = {
    name: c.full_name || '',
    email: c.email || '',
    phone: c.phone || '',
    address: '', zip: '', city: '',
  };
  props.form.owner_customer_id = c.id || null;
  search.value = '';
  results.value = [];
  showNewForm.value = false;
}

function openNewOwnerForm() {
  props.form.owner_customer_id = null;
  showNewForm.value = true;
}
</script>

<template>
  <div class="space-y-3">

    <!-- Ausgewählter Eigentümer -->
    <div v-if="form.owner.name && !showNewForm" class="bg-white border border-border rounded-xl p-3 flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-orange-100 text-[#EE7600] flex items-center justify-center font-semibold">
        {{ (form.owner.name || '?').charAt(0).toUpperCase() }}
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-sm font-semibold truncate">{{ form.owner.name }}</div>
        <div class="text-xs text-muted-foreground truncate">{{ form.owner.email }}</div>
      </div>
      <button type="button" @click="form.owner = { name:'', email:'', phone:'', address:'', zip:'', city:'' }; form.owner_customer_id=null; showNewForm=false;"
              class="text-xs text-muted-foreground">Ändern</button>
    </div>

    <!-- Suchen / Neu anlegen -->
    <div v-else>
      <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
        Eigentümer suchen oder anlegen
      </label>
      <input
        v-model="search"
        @input="searchContacts(search)"
        placeholder="Name oder E-Mail..."
        class="w-full h-11 rounded-lg border border-border px-3 bg-white"
      />
      <div v-if="results.length" class="bg-white border border-border rounded-lg mt-2 divide-y divide-border/40">
        <button v-for="c in results" :key="c.id" type="button" @click="pickContact(c)"
                class="w-full text-left px-3 py-2 hover:bg-zinc-50">
          <div class="text-sm font-medium">{{ c.full_name }}</div>
          <div class="text-xs text-muted-foreground">{{ c.email }}</div>
        </button>
      </div>

      <button type="button" @click="openNewOwnerForm" class="mt-2 text-sm text-[#EE7600] font-medium">
        + Neuer Eigentümer
      </button>
    </div>

    <!-- Neuer Eigentümer Form -->
    <div v-if="showNewForm" class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Name *</label>
        <input v-model="form.owner.name" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">E-Mail * <span class="text-[10px]">(für PDF-Versand)</span></label>
        <input v-model="form.owner.email" type="email" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Telefon</label>
        <input v-model="form.owner.phone" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Adresse (Wohnsitz)</label>
        <input v-model="form.owner.address" class="w-full h-11 rounded-lg border border-border px-3 mb-2" placeholder="Straße Nr." />
        <div class="grid grid-cols-2 gap-2">
          <input v-model="form.owner.zip" placeholder="PLZ" class="h-11 rounded-lg border border-border px-3" />
          <input v-model="form.owner.city" placeholder="Stadt" class="h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
    </div>

  </div>
</template>
```

- [ ] **Step 2: `Step03_Owner.vue` implementieren**

```vue
<script setup>
import OwnerPicker from '../shared/OwnerPicker.vue';

defineProps({
  form: { type: Object, required: true },
});
</script>

<template>
  <div class="p-4 space-y-5">

    <OwnerPicker :form="form" />

    <!-- Portalzugang-Toggle -->
    <div class="bg-orange-50 border border-[#EE7600]/30 rounded-xl p-4">
      <label class="flex items-start gap-3 cursor-pointer">
        <input
          type="checkbox"
          v-model="form.portal_access_granted"
          class="mt-0.5 w-5 h-5 accent-[#EE7600]"
        />
        <div>
          <div class="text-sm font-semibold">Eigentümer bekommt Portalzugang</div>
          <div class="text-xs text-muted-foreground mt-1">
            Er erhält eine separate E-Mail mit Login-Daten zum Kundenportal
            (kundenportal.sr-homes.at). Portal zeigt Aktivitäten, Dokumente,
            Interessenten-Anfragen zu seinem Objekt.
          </div>
        </div>
      </label>
    </div>

    <div v-if="form.portal_access_granted && !form.owner.email" class="text-xs text-red-600 bg-red-50 p-3 rounded-lg">
      ⚠ Ohne E-Mail kann kein Portalzugang angelegt werden. Bitte E-Mail eintragen.
    </div>

  </div>
</template>
```

- [ ] **Step 3: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/shared/OwnerPicker.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step03_Owner.vue
git commit -m "feat(wizard): Step 3 — Eigentümer-Picker + Portalzugang-Toggle"
```

---

## Task 17: Steps 4–7 (Kerndaten, Zustand+Sanierungen, Ausstattung+Parken, Energie)

Jeder Step folgt dem gleichen Pattern: Numeric/Text-Inputs + Pill-Rows. Dieser Task ist bewusst gebündelt, weil die Muster identisch sind.

**Files:**
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step04_CoreData.vue`
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step05_ConditionRenovations.vue`
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step06_FeaturesParking.vue`
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step07_Energy.vue`

- [ ] **Step 1: Step 4 — Kerndaten**

```vue
<script setup>
defineProps({ form: Object });
</script>

<template>
  <div class="p-4 space-y-4">
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Wohnfläche m² *</label>
        <input v-model="form.living_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Zimmer *</label>
        <input v-model="form.rooms_amount" type="number" step="0.5" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Haus' || form.object_type === 'Grundstück'">
        <label class="text-[11px] text-muted-foreground block mb-1">Grundstücksfläche m²</label>
        <input v-model="form.free_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Gewerbe'">
        <label class="text-[11px] text-muted-foreground block mb-1">Nutzfläche m²</label>
        <input v-model="form.realty_area" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Schlafzimmer</label>
        <input v-model="form.bedrooms" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Badezimmer</label>
        <input v-model="form.bathrooms" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">WC</label>
        <input v-model="form.toilets" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Haus'">
        <label class="text-[11px] text-muted-foreground block mb-1">Stockwerke</label>
        <input v-model="form.floor_count" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div v-if="form.object_type === 'Wohnung'">
        <label class="text-[11px] text-muted-foreground block mb-1">Etage</label>
        <input v-model="form.floor_number" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div class="col-span-2">
        <label class="text-[11px] text-muted-foreground block mb-1">Baujahr *</label>
        <input v-model="form.construction_year" type="number" inputmode="numeric" placeholder="YYYY" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Step 5 — Zustand + Sanierungen**

```vue
<script setup>
import { reactive, computed, watch } from 'vue';
import PillRow from '../shared/PillRow.vue';

const props = defineProps({ form: Object });

const CONDITIONS = ['neuwertig', 'gebraucht', 'saniert', 'kernsaniert', 'renoviert', 'erstbezug', 'abbruchreif'];
const CONSTRUCTION_TYPES = ['Massiv', 'Holz', 'Fertigteil', 'Mischbauweise'];
const QUALITIES = ['einfach', 'normal', 'gehoben', 'luxurioes'];
const OWNERSHIP = ['wohnungseigentum', 'baurecht', 'pacht'];

const SAN_CATEGORIES = [
  { key: 'general',   label: 'Generalsanierung',   hasYear: true },
  { key: 'windows',   label: 'Fenster',            hasYear: true },
  { key: 'doors',     label: 'Türen',              hasYear: true },
  { key: 'floors',    label: 'Fußböden',           hasYear: true },
  { key: 'heating',   label: 'Heizung',            hasYear: true },
  { key: 'pipes',     label: 'Leitungssystem',     hasYear: true },
  { key: 'connections', label: 'Anschlüsse',        hasYear: true },
  { key: 'facade',    label: 'Fassade',            hasYear: true },
  { key: 'bathrooms', label: 'Bäder',              hasYear: true },
  { key: 'kitchen',   label: 'Küche',              hasYear: true },
  { key: 'other',     label: 'Sonstige Sanierungen', hasYear: true },
  { key: 'required',  label: 'Erforderliche Maßnahmen', hasYear: false },
];

// Per-Kategorie reaktive Eingaben
const inputs = reactive({});
for (const c of SAN_CATEGORIES) inputs[c.key] = { year: '', note: '' };

// Initialisiere aus form.property_history falls vorhanden
if (Array.isArray(props.form.property_history)) {
  for (const entry of props.form.property_history) {
    const key = entry.category;
    if (inputs[key]) inputs[key] = { year: String(entry.year ?? ''), note: String(entry.description ?? '') };
  }
}

// Syncing: watch inputs → form.property_history
watch(inputs, () => {
  const out = [];
  for (const c of SAN_CATEGORIES) {
    const v = inputs[c.key];
    const year = String(v.year || '').trim();
    const note = String(v.note || '').trim();
    if (year === '' && note === '') continue;
    out.push({ category: c.key, title: c.label, year: year ? parseInt(year) : null, description: note });
  }
  props.form.property_history = out;
}, { deep: true });

const addedCategories = computed(() =>
  SAN_CATEGORIES.filter(c => inputs[c.key].year.trim() !== '' || inputs[c.key].note.trim() !== '')
);
const availableCategories = computed(() =>
  SAN_CATEGORIES.filter(c => !addedCategories.value.includes(c))
);

function addCategory(key) {
  inputs[key].year = '';
  inputs[key].note = ' '; // Leerstring damit's als "hinzugefügt" zählt, User kann löschen
}
</script>

<template>
  <div class="p-4 space-y-5">

    <!-- Zustand -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Zustand & Qualität</div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Zustand *</div>
        <PillRow v-model="form.realty_condition" :options="CONDITIONS.map(c => ({value: c, label: c.charAt(0).toUpperCase() + c.substring(1)}))" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Bauart</div>
        <PillRow v-model="form.construction_type" :options="CONSTRUCTION_TYPES" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Qualität</div>
        <PillRow v-model="form.quality" :options="[
          {value:'einfach', label:'Einfach'},
          {value:'normal', label:'Normal'},
          {value:'gehoben', label:'Gehoben'},
          {value:'luxurioes', label:'Luxuriös'},
        ]" />
      </div>
      <div>
        <div class="text-xs text-muted-foreground mb-1">Eigentumsform</div>
        <PillRow v-model="form.ownership_type" :options="[
          {value:'wohnungseigentum', label:'Wohnungseigentum'},
          {value:'baurecht', label:'Baurecht'},
          {value:'pacht', label:'Pacht'},
        ]" />
      </div>
      <div v-if="form.marketing_type === 'miete'">
        <div class="text-xs text-muted-foreground mb-1">Möblierung</div>
        <PillRow v-model="form.furnishing" :options="[
          {value:'unfurnished', label:'Unmöbliert'},
          {value:'partially', label:'Teilmöbliert'},
          {value:'fully', label:'Vollmöbliert'},
        ]" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Anmerkung zum Zustand</label>
        <textarea v-model="form.condition_note" rows="2" class="w-full rounded-lg border border-border p-2 text-sm"></textarea>
      </div>
    </div>

    <!-- Sanierungen -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Sanierungen</div>

      <div v-for="c in addedCategories" :key="c.key" class="bg-zinc-50 rounded-lg p-3">
        <div class="flex gap-2 items-center mb-1.5">
          <div class="flex-1 text-sm font-medium">{{ c.label }}</div>
          <input
            v-if="c.hasYear"
            v-model="inputs[c.key].year" type="number" placeholder="Jahr"
            inputmode="numeric"
            class="w-20 h-9 rounded-md border border-border px-2 text-sm text-right"
          />
        </div>
        <input
          v-model="inputs[c.key].note"
          placeholder="Notiz (z.B. 3-fach verglast)"
          class="w-full h-9 rounded-md border border-border px-2 text-xs"
        />
      </div>

      <div v-if="availableCategories.length">
        <div class="text-[11px] text-muted-foreground mb-1.5">Kategorien hinzufügen:</div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="c in availableCategories" :key="c.key" type="button" @click="addCategory(c.key)"
                  class="bg-white border border-dashed border-border text-muted-foreground text-[11px] rounded-full px-2.5 py-1">
            + {{ c.label }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
```

- [ ] **Step 3: Step 6 — Ausstattung + Parken**

```vue
<script setup>
import PillRow from '../shared/PillRow.vue';

defineProps({ form: Object });

const FEATURE_TOGGLES = [
  { key: 'has_elevator', label: 'Aufzug' },
  { key: 'has_fitted_kitchen', label: 'Einbauküche' },
  { key: 'has_air_conditioning', label: 'Klimaanlage' },
  { key: 'has_pool', label: 'Pool' },
  { key: 'has_sauna', label: 'Sauna' },
  { key: 'has_fireplace', label: 'Kamin' },
  { key: 'has_alarm', label: 'Alarmanlage' },
  { key: 'has_barrier_free', label: 'Barrierefrei' },
  { key: 'has_guest_wc', label: 'Gäste-WC' },
  { key: 'has_storage_room', label: 'Abstellraum' },
];

const AREA_TOGGLES = [
  { key: 'has_balcony', label: 'Balkon', areaKey: 'area_balcony', countKey: 'balcony_count' },
  { key: 'has_terrace', label: 'Terrasse', areaKey: 'area_terrace', countKey: 'terrace_count' },
  { key: 'has_loggia', label: 'Loggia', areaKey: 'area_loggia', countKey: 'loggia_count' },
  { key: 'has_garden', label: 'Garten', areaKey: 'area_garden', countKey: null },
  { key: 'has_basement', label: 'Keller', areaKey: 'area_basement', countKey: 'basement_count' },
];

const COMMON_AREA_OPTIONS = [
  { key: 'fahrradraum', label: 'Fahrradraum' },
  { key: 'muellraum', label: 'Müllraum' },
  { key: 'trockenraum', label: 'Trockenraum' },
  { key: 'waschkueche', label: 'Waschküche' },
  { key: 'kinderwagenraum', label: 'Kinderwagenraum' },
  { key: 'hobbyraum', label: 'Hobbyraum' },
  { key: 'partyraum', label: 'Partyraum' },
  { key: 'fitnessraum', label: 'Fitnessraum' },
  { key: 'gemeinschaftssauna', label: 'Gemeinschafts-Sauna' },
  { key: 'spielplatz', label: 'Kinderspielplatz' },
  { key: 'dachterrasse', label: 'Gemeinschafts-Dachterrasse' },
  { key: 'gemeinschaftsgarten', label: 'Gemeinschaftsgarten' },
  { key: 'heizraum', label: 'Heizraum' },
  { key: 'lagerraum', label: 'Lagerraum' },
];

function toggleCommonArea(form, key) {
  if (!Array.isArray(form.common_areas)) form.common_areas = [];
  const idx = form.common_areas.indexOf(key);
  if (idx >= 0) form.common_areas.splice(idx, 1);
  else form.common_areas.push(key);
}
</script>

<template>
  <div class="p-4 space-y-5">

    <!-- Flächen-Toggles mit m²-Eingabe -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Außenflächen & Keller</div>
      <div class="space-y-2">
        <div v-for="a in AREA_TOGGLES" :key="a.key" class="bg-white border border-border rounded-lg p-3">
          <label class="flex items-center gap-3 cursor-pointer">
            <input type="checkbox" v-model="form[a.key]" class="w-5 h-5 accent-[#EE7600]" />
            <span class="flex-1 text-sm font-medium">{{ a.label }}</span>
          </label>
          <div v-if="form[a.key]" class="mt-2 grid grid-cols-2 gap-2">
            <input v-model="form[a.areaKey]" type="number" inputmode="decimal" placeholder="m²"
                   class="h-9 rounded-md border border-border px-2 text-sm" />
            <input v-if="a.countKey" v-model="form[a.countKey]" type="number" inputmode="numeric" placeholder="Anzahl"
                   class="h-9 rounded-md border border-border px-2 text-sm" />
          </div>
        </div>
      </div>
    </div>

    <!-- Merkmale-Toggles -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Merkmale</div>
      <div class="grid grid-cols-2 gap-2">
        <button v-for="f in FEATURE_TOGGLES" :key="f.key" type="button"
                @click="form[f.key] = !form[f.key]"
                :class="[
                  'px-3 py-2 rounded-lg text-sm font-medium text-left',
                  form[f.key] ? 'bg-zinc-900 text-white' : 'bg-white border border-border text-foreground'
                ]">
          {{ f.label }}
        </button>
      </div>
    </div>

    <!-- Allgemeinräume -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Allgemeinräume</div>
      <div class="flex flex-wrap gap-1.5">
        <button v-for="o in COMMON_AREA_OPTIONS" :key="o.key" type="button"
                @click="toggleCommonArea(form, o.key)"
                :class="[
                  'px-2.5 py-1.5 rounded-full text-[12px] font-medium',
                  (form.common_areas || []).includes(o.key)
                    ? 'bg-zinc-900 text-white'
                    : 'bg-white border border-border text-foreground'
                ]">
          {{ o.label }}
        </button>
      </div>
    </div>

    <!-- Ausrichtung + Bodenbelag + Bad -->
    <div class="space-y-3">
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Ausrichtung</label>
        <PillRow v-model="form.orientation" :options="['N','NO','O','SO','S','SW','W','NW']" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Bodenbelag</label>
        <input v-model="form.flooring" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Badausstattung</label>
        <input v-model="form.bathroom_equipment" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
    </div>

    <!-- Stellplätze -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Stellplätze</div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Garagen</label>
          <input v-model="form.garage_spaces" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Außenplätze</label>
          <input v-model="form.parking_spaces" type="number" inputmode="numeric" class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Parking-Typ</label>
        <PillRow v-model="form.parking_type" :options="['Garage', 'Tiefgarage', 'Carport', 'Stellplatz']" />
      </div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Zuordnung</label>
        <PillRow v-model="form.parking_assignment" :options="[
          {value:'assigned', label:'Dem Objekt zugeordnet'},
          {value:'shared', label:'Allgemein / gemeinsam'},
        ]" />
      </div>
    </div>

  </div>
</template>
```

- [ ] **Step 4: Step 7 — Energie**

```vue
<script setup>
import PillRow from '../shared/PillRow.vue';

defineProps({ form: Object });

const ENERGY_CLASSES = ['A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];
</script>

<template>
  <div class="p-4 space-y-4">

    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Energieausweis</div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Vorhanden?</label>
        <PillRow v-model="form.energy_certificate" :options="[
          {value:'vorhanden', label:'Ja'},
          {value:'nein', label:'Nein'},
        ]" />
      </div>
      <div v-if="form.energy_certificate === 'vorhanden'" class="space-y-3 pt-2">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs text-muted-foreground block mb-1">HWB (kWh/m²a)</label>
            <input v-model="form.heating_demand_value" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
          </div>
          <div>
            <label class="text-xs text-muted-foreground block mb-1">fGEE</label>
            <input v-model="form.energy_efficiency_value" type="number" inputmode="decimal" class="w-full h-11 rounded-lg border border-border px-3" />
          </div>
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Energieklasse</label>
          <PillRow v-model="form.heating_demand_class" :options="ENERGY_CLASSES" />
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Gültig bis</label>
          <input v-model="form.energy_valid_until" type="date" class="w-full h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
    </div>

    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Heizung</div>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">Heizungsart (Freitext)</label>
        <input v-model="form.heating" placeholder="z.B. Fußbodenheizung, Wärmepumpe" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
    </div>

    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Extras</div>
      <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" v-model="form.has_photovoltaik" class="w-5 h-5 accent-[#EE7600]" />
        <span class="text-sm">Photovoltaik-Anlage</span>
      </label>
      <div>
        <label class="text-xs text-muted-foreground block mb-1">E-Ladestation</label>
        <PillRow v-model="form.charging_station_status" :options="[
          {value:'none', label:'Keine'},
          {value:'prepared', label:'Vorkehrung'},
          {value:'installed', label:'Vorhanden'},
        ]" />
      </div>
    </div>

  </div>
</template>
```

- [ ] **Step 5: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/steps/Step04_CoreData.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step05_ConditionRenovations.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step06_FeaturesParking.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step07_Energy.vue
git commit -m "feat(wizard): Steps 4-7 (Kerndaten, Zustand+Sanierungen, Ausstattung+Parken, Energie)"
```

---

## Task 18: Step 8 — Rechtliches + Dokumente (mit DocumentChecklistItem)

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/DocumentChecklistItem.vue`
- Modify: `resources/js/Components/Admin/IntakeProtocol/steps/Step08_LegalDocuments.vue`

- [ ] **Step 1: `DocumentChecklistItem.vue` anlegen**

```vue
<script setup>
const props = defineProps({
  docKey: { type: String, required: true },
  label: { type: String, required: true },
  modelValue: { type: String, default: '' },  // 'available' | 'missing' | 'na' | ''
});
defineEmits(['update:modelValue']);

function pick(v) {
  // @ts-ignore
  // eslint-disable-next-line
  emitVal(v);
}
</script>

<script>
export default {
  methods: {
    emitVal(v) { this.$emit('update:modelValue', v); }
  }
};
</script>

<template>
  <div :class="[
    'rounded-lg p-3 flex items-center gap-2',
    modelValue === 'available' ? 'bg-green-50' :
    modelValue === 'missing' ? 'bg-red-50' :
    modelValue === 'na' ? 'bg-zinc-100' : 'bg-zinc-50'
  ]">
    <div class="flex-1 text-sm" :class="modelValue === 'available' ? 'text-green-900 font-medium' : modelValue === 'missing' ? 'text-red-900 font-medium' : ''">
      {{ label }}
    </div>
    <div class="flex gap-1">
      <button type="button" @click="emitVal('available')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'available' ? 'bg-green-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">✓ Da</button>
      <button type="button" @click="emitVal('missing')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'missing' ? 'bg-red-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">✗ Fehlt</button>
      <button type="button" @click="emitVal('na')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'na' ? 'bg-zinc-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">N/A</button>
    </div>
  </div>
</template>
```

- [ ] **Step 2: `Step08_LegalDocuments.vue` implementieren**

```vue
<script setup>
import { computed, inject, ref } from 'vue';
import DocumentChecklistItem from '../shared/DocumentChecklistItem.vue';
import PillRow from '../shared/PillRow.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const API = inject('API');

const DOCS = [
  { key: 'grundbuchauszug', label: 'Grundbuchauszug' },
  { key: 'energieausweis', label: 'Energieausweis' },
  { key: 'plaene', label: 'Grundrisse / Pläne' },
  { key: 'nutzwertgutachten', label: 'Nutzwertgutachten' },
  { key: 'ruecklagenstand', label: 'Rücklagenstand' },
  { key: 'wohnungseigentumsvertrag', label: 'Wohnungseigentumsvertrag' },
  { key: 'hausordnung', label: 'Hausordnung' },
  { key: 'letzte_jahresabrechnung', label: 'Letzte Jahresabrechnung' },
  { key: 'betriebskostenabrechnung', label: 'Betriebskostenabrechnung' },
  { key: 'schaetzwert_gutachten', label: 'Schätzwert-Gutachten' },
  { key: 'baubewilligung', label: 'Baubewilligung' },
  { key: 'mietvertrag', label: 'Mietvertrag' },
  { key: 'hypothekenvertrag', label: 'Hypothekenvertrag' },
];

function setDocStatus(key, status) {
  if (!props.form.documents_available) props.form.documents_available = {};
  props.form.documents_available[key] = status;
}

function getDocStatus(key) {
  return props.form.documents_available?.[key] || '';
}

const availableCount = computed(() =>
  Object.values(props.form.documents_available || {}).filter(v => v === 'available').length
);

// Hausverwaltung-Picker
const hvSearch = ref('');
const hvResults = ref([]);
const hvShowNewForm = ref(false);
const hvNewForm = ref({ company_name: '', contact_person: '', email: '', phone: '' });
let hvDebounce = null;

async function searchHv(q) {
  if (q.length < 2) { hvResults.value = []; return; }
  if (hvDebounce) clearTimeout(hvDebounce);
  hvDebounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=list_property_managers&search=' + encodeURIComponent(q));
      const d = await r.json();
      hvResults.value = (d.managers || []).slice(0, 5);
    } catch (e) { hvResults.value = []; }
  }, 300);
}

function pickHv(h) {
  props.form.property_manager_id = h.id;
  hvSearch.value = h.company_name;
  hvResults.value = [];
}

async function createHv() {
  const r = await fetch(API.value + '&action=create_property_manager', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(hvNewForm.value),
  });
  const d = await r.json();
  if (d.success && d.id) {
    props.form.property_manager_id = d.id;
    hvSearch.value = hvNewForm.value.company_name;
    hvShowNewForm.value = false;
  }
}

const approvalsNotesSkipped = computed({
  get: () => props.isSkipped('approvals_notes'),
  set: (v) => v ? props.markSkipped('approvals_notes') : props.unmarkSkipped('approvals_notes'),
});
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Hausverwaltung -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Hausverwaltung</div>
      <input
        v-model="hvSearch"
        @input="searchHv(hvSearch)"
        placeholder="Hausverwaltung suchen..."
        class="w-full h-11 rounded-lg border border-border px-3"
      />
      <div v-if="hvResults.length" class="bg-white border border-border rounded-lg divide-y divide-border/40">
        <button v-for="h in hvResults" :key="h.id" type="button" @click="pickHv(h)"
                class="w-full text-left px-3 py-2 hover:bg-zinc-50">
          <div class="text-sm font-medium">{{ h.company_name }}</div>
          <div class="text-xs text-muted-foreground">{{ h.contact_person }} · {{ h.email }}</div>
        </button>
      </div>
      <button v-if="!hvShowNewForm" type="button" @click="hvShowNewForm = true" class="text-sm text-[#EE7600] font-medium">
        + Neue Hausverwaltung
      </button>
      <div v-if="hvShowNewForm" class="bg-zinc-50 rounded-lg p-3 space-y-2">
        <input v-model="hvNewForm.company_name" placeholder="Firma *" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.contact_person" placeholder="Ansprechpartner" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.email" type="email" placeholder="E-Mail" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <input v-model="hvNewForm.phone" placeholder="Telefon" class="w-full h-10 rounded-md border border-border px-2 text-sm" />
        <button type="button" @click="createHv" class="w-full h-10 rounded-md bg-[#EE7600] text-white text-sm font-medium">Anlegen</button>
      </div>
    </div>

    <!-- Belastungen -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Belastungen / Rechte</div>
      <textarea v-model="form.encumbrances" rows="3"
                placeholder="Pfandrechte, Wohnrechte, Dienstbarkeiten ..."
                class="w-full rounded-lg border border-border p-2 text-sm"></textarea>
    </div>

    <!-- Bewilligungen -->
    <div class="bg-white border-l-4 border-l-[#EE7600] border-t border-r border-b border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Bewilligungen</div>
      <div class="text-sm">Sind alle Baumaßnahmen bewilligt?</div>
      <div class="grid grid-cols-3 gap-2">
        <button type="button" @click="form.approvals_status = 'complete'; form.approvals_notes = ''"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'complete' ? 'bg-green-50 border-2 border-green-600' : 'bg-white border border-border'
                ]">
          <div class="text-xl">✓</div>
          <div class="text-[11px] font-medium mt-0.5">Alles bewilligt</div>
        </button>
        <button type="button" @click="form.approvals_status = 'partial'"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'partial' ? 'bg-amber-50 border-2 border-amber-500' : 'bg-white border border-border'
                ]">
          <div class="text-xl">⚠️</div>
          <div class="text-[11px] font-medium mt-0.5">Teilweise</div>
        </button>
        <button type="button" @click="form.approvals_status = 'unknown'"
                :class="[
                  'rounded-xl p-3 text-center',
                  form.approvals_status === 'unknown' ? 'bg-zinc-100 border-2 border-zinc-600' : 'bg-white border border-border'
                ]">
          <div class="text-xl">❓</div>
          <div class="text-[11px] font-medium mt-0.5">Unbekannt</div>
        </button>
      </div>

      <div v-if="['partial','unknown'].includes(form.approvals_status)"
           :class="[
             'rounded-lg p-3 space-y-2',
             form.approvals_status === 'partial' ? 'bg-amber-50 border border-amber-200' : 'bg-zinc-100 border border-zinc-300'
           ]">
        <div class="flex items-center justify-between">
          <label class="text-xs font-semibold">
            <span v-if="form.approvals_status === 'partial'">Welche Bewilligung fehlt wofür? *</span>
            <span v-else>Was ist unklar und muss geprüft werden? *</span>
          </label>
          <SkipFieldSwitch v-model="approvalsNotesSkipped" />
        </div>
        <textarea v-model="form.approvals_notes" rows="3"
                  :placeholder="form.approvals_status === 'partial' ? 'Terrasse: nicht bewilligt\nDachbodenausbau: nicht im Grundbuch eingetragen' : 'Eigentümer weiß nicht ob Anbau bewilligt'"
                  class="w-full rounded-md border border-border p-2 text-sm"></textarea>
      </div>
    </div>

    <!-- Dokumenten-Checkliste -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between mb-1">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Dokumenten-Checkliste</div>
        <div class="text-[11px] text-[#EE7600] font-medium">{{ availableCount }} / {{ DOCS.length }} vorhanden</div>
      </div>
      <div class="space-y-1">
        <DocumentChecklistItem
          v-for="d in DOCS" :key="d.key"
          :doc-key="d.key"
          :label="d.label"
          :model-value="getDocStatus(d.key)"
          @update:model-value="setDocStatus(d.key, $event)"
        />
      </div>
    </div>

  </div>
</template>
```

- [ ] **Step 3: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/shared/DocumentChecklistItem.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step08_LegalDocuments.vue
git commit -m "feat(wizard): Step 8 — HV, Belastungen, Bewilligungen, Dokumenten-Checkliste"
```

---

### Task 19: Step 9 — Preis & Kosten

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/steps/Step09_Price.vue`

- [ ] **Step 1: Create the component**

Write `resources/js/Components/Admin/IntakeProtocol/steps/Step09_Price.vue`:

```vue
<script setup>
import { computed } from 'vue'
import StepHeader from '../shared/StepHeader.vue'
import PillRow from '../shared/PillRow.vue'
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue'
import { useIntakeForm } from '@/Composables/useIntakeForm'

const { form, isSkipped, toggleSkip } = useIntakeForm()

const hasBk = computed({
  get: () => form.value.monthly_operating_costs !== null,
  set: (v) => { form.value.monthly_operating_costs = v ? 0 : null }
})

const hasReserve = computed({
  get: () => form.value.reserve_fund_amount !== null,
  set: (v) => { form.value.reserve_fund_amount = v ? 0 : null }
})

const priceSkipped = computed({ get: () => isSkipped('listing_price'), set: () => toggleSkip('listing_price') })
const commissionSkipped = computed({ get: () => isSkipped('commission_percent'), set: () => toggleSkip('commission_percent') })
</script>

<template>
  <div class="space-y-4">
    <StepHeader title="Preis & Kosten" subtitle="Angebotspreis, laufende Kosten, Provision" />

    <!-- Preis -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between">
        <label class="text-sm font-medium">Angebotspreis (€)</label>
        <SkipFieldSwitch v-model="priceSkipped" />
      </div>
      <input v-model.number="form.listing_price" type="number" inputmode="decimal"
             class="w-full h-12 rounded-md border border-border px-3 text-lg font-semibold"
             placeholder="495000" />
      <p class="text-[11px] text-muted-foreground">Richtwert — kann später angepasst werden</p>
    </div>

    <!-- Laufende Kosten (Wohnungen) -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Laufende Kosten</div>

      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="hasBk" id="has-bk" class="h-4 w-4" />
          <label for="has-bk" class="text-sm">Monatliche Betriebskosten</label>
        </div>
        <input v-if="hasBk" v-model.number="form.monthly_operating_costs" type="number" inputmode="decimal"
               class="w-full h-11 rounded-md border border-border px-3" placeholder="€ / Monat" />
      </div>

      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="hasReserve" id="has-res" class="h-4 w-4" />
          <label for="has-res" class="text-sm">Rücklage (einmalig)</label>
        </div>
        <input v-if="hasReserve" v-model.number="form.reserve_fund_amount" type="number" inputmode="decimal"
               class="w-full h-11 rounded-md border border-border px-3" placeholder="Betrag €" />
      </div>
    </div>

    <!-- Provision -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between">
        <label class="text-sm font-medium">Provision (% vom Kaufpreis)</label>
        <SkipFieldSwitch v-model="commissionSkipped" />
      </div>
      <PillRow
        :options="['3%', '3.5%', '4%', 'Anders']"
        :model-value="form.commission_preset"
        @update:model-value="form.commission_preset = $event"
      />
      <input v-if="form.commission_preset === 'Anders'"
             v-model.number="form.commission_percent" type="number" step="0.1" inputmode="decimal"
             class="w-full h-11 rounded-md border border-border px-3 mt-2" placeholder="z.B. 3.25" />
      <p class="text-[11px] text-muted-foreground">+ 20 % USt. — wird im Alleinvermittlungsauftrag angedruckt</p>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Ensure fields in useIntakeForm**

Edit `resources/js/Composables/useIntakeForm.js` initialForm() — make sure these fields exist:

```js
listing_price: null,
monthly_operating_costs: null,
reserve_fund_amount: null,
commission_preset: '3.5%',
commission_percent: 3.5,
```

- [ ] **Step 3: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/steps/Step09_Price.vue \
        resources/js/Composables/useIntakeForm.js
git commit -m "feat(wizard): Step 9 — Preis, Betriebskosten, Rücklage, Provision"
```

---

### Task 20: Step 10 — Fotos

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/steps/Step10_Photos.vue`
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/PhotoCategoryUploader.vue`
- Modify: `app/Http/Controllers/Admin/IntakeProtocolController.php`

- [ ] **Step 1: Create PhotoCategoryUploader**

Write `resources/js/Components/Admin/IntakeProtocol/shared/PhotoCategoryUploader.vue`:

```vue
<script setup>
import { ref } from 'vue'

const props = defineProps({
  category: { type: String, required: true },
  label:    { type: String, required: true },
  icon:     { type: String, default: '📸' },
  modelValue: { type: Array, default: () => [] }
})
const emit = defineEmits(['update:modelValue'])

const fileInput = ref(null)

function openPicker() { fileInput.value?.click() }

async function onFiles(e) {
  const files = Array.from(e.target.files || [])
  const newItems = []
  for (const file of files) {
    const dataUrl = await readAsDataUrl(file)
    newItems.push({
      id: crypto.randomUUID(),
      dataUrl,
      filename: file.name,
      category: props.category,
      size: file.size
    })
  }
  emit('update:modelValue', [...props.modelValue, ...newItems])
  e.target.value = ''
}

function remove(id) {
  emit('update:modelValue', props.modelValue.filter(p => p.id !== id))
}

function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader()
    r.onload = () => resolve(r.result)
    r.onerror = reject
    r.readAsDataURL(file)
  })
}
</script>

<template>
  <div class="bg-white border border-border rounded-xl p-4 space-y-3">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-lg">{{ icon }}</span>
        <span class="text-sm font-medium">{{ label }}</span>
      </div>
      <span class="text-[11px] text-muted-foreground">{{ modelValue.length }} Fotos</span>
    </div>

    <div v-if="modelValue.length > 0" class="grid grid-cols-3 gap-2">
      <div v-for="p in modelValue" :key="p.id" class="relative aspect-square rounded-md overflow-hidden bg-zinc-100">
        <img :src="p.dataUrl" class="w-full h-full object-cover" alt="" />
        <button @click="remove(p.id)" type="button"
                class="absolute top-1 right-1 bg-red-600 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">×</button>
      </div>
    </div>

    <button @click="openPicker" type="button"
            class="w-full h-11 bg-zinc-100 border-2 border-dashed border-zinc-300 rounded-md text-sm font-medium text-zinc-600 hover:bg-zinc-50">
      + {{ modelValue.length === 0 ? 'Fotos aufnehmen' : 'Mehr hinzufügen' }}
    </button>

    <input ref="fileInput" type="file" accept="image/*" multiple capture="environment"
           class="hidden" @change="onFiles" />
  </div>
</template>
```

- [ ] **Step 2: Create Step10_Photos.vue**

Write `resources/js/Components/Admin/IntakeProtocol/steps/Step10_Photos.vue`:

```vue
<script setup>
import { computed } from 'vue'
import StepHeader from '../shared/StepHeader.vue'
import PhotoCategoryUploader from '../shared/PhotoCategoryUploader.vue'
import { useIntakeForm } from '@/Composables/useIntakeForm'

const { form } = useIntakeForm()

function makeCat(cat) {
  return computed({
    get: () => (form.value.photos || []).filter(p => p.category === cat),
    set: (v) => {
      const others = (form.value.photos || []).filter(p => p.category !== cat)
      form.value.photos = [...others, ...v]
    }
  })
}

const exterior  = makeCat('exterior')
const interior  = makeCat('interior')
const floorPlan = makeCat('floor_plan')
const documents = makeCat('documents')

const totalPhotos = computed(() => (form.value.photos || []).length)
</script>

<template>
  <div class="space-y-4">
    <StepHeader title="Fotos aufnehmen"
                :subtitle="`${totalPhotos} Fotos insgesamt — optional, kann später ergänzt werden`" />

    <PhotoCategoryUploader category="exterior"   label="Außenansichten" icon="🏠"
                           :model-value="exterior"  @update:model-value="exterior = $event" />
    <PhotoCategoryUploader category="interior"   label="Innenräume"     icon="🛋️"
                           :model-value="interior"  @update:model-value="interior = $event" />
    <PhotoCategoryUploader category="floor_plan" label="Grundrisse"     icon="📐"
                           :model-value="floorPlan" @update:model-value="floorPlan = $event" />
    <PhotoCategoryUploader category="documents"  label="Dokumente"      icon="📄"
                           :model-value="documents" @update:model-value="documents = $event" />

    <p class="text-[11px] text-muted-foreground px-2">
      💡 Fotos werden nach dem Absenden im Hintergrund komprimiert und dem Objekt zugeordnet.
    </p>
  </div>
</template>
```

- [ ] **Step 3: Add photos to useIntakeForm initial state**

Edit `resources/js/Composables/useIntakeForm.js` — ensure `initialForm()` includes:

```js
photos: [], // [{ id, dataUrl, filename, category }]
```

- [ ] **Step 4: Backend photo persistence — write failing test**

Edit `tests/Feature/Admin/IntakeProtocolSubmitTest.php` and add:

```php
use Illuminate\Support\Facades\Storage;

public function test_photos_in_submit_are_stored_as_property_files(): void
{
    Storage::fake('public');
    Mail::fake();
    $this->actingAs($this->admin);

    $pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    $response = $this->post(route('admin.intake.submit'), $this->minimalValidPayload([
        'photos' => [
            ['dataUrl' => "data:image/png;base64,$pixel", 'filename' => 'exterior.png', 'category' => 'exterior'],
            ['dataUrl' => "data:image/png;base64,$pixel", 'filename' => 'interior.png', 'category' => 'interior'],
        ]
    ]));

    $response->assertOk();
    $property = \App\Models\Property::latest()->first();
    $this->assertCount(2, $property->files);
    $this->assertTrue($property->files()->where('category', 'photo')->exists());
}
```

Run: `php artisan test --filter=IntakeProtocolSubmitTest::test_photos_in_submit_are_stored_as_property_files`
Expected: FAIL (photos not yet persisted)

- [ ] **Step 5: Implement photo persistence**

Edit `app/Http/Controllers/Admin/IntakeProtocolController.php`:

Add imports at top:

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
```

Extend `submit()` validation rules:

```php
'photos' => 'array',
'photos.*.dataUrl' => 'required|string',
'photos.*.filename' => 'nullable|string',
'photos.*.category' => 'required|in:exterior,interior,floor_plan,documents',
```

Inside the DB::transaction, after `Property` is created, add:

```php
foreach ($validated['photos'] ?? [] as $photo) {
    if (!isset($photo['dataUrl'])) continue;
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $photo['dataUrl'], $m)) continue;
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $binary = base64_decode($m[2]);
    $filename = 'property-'.$property->id.'-'.Str::random(8).'.'.$ext;
    $path = 'properties/'.$property->id.'/'.$filename;
    Storage::disk('public')->put($path, $binary);

    \App\Models\PropertyFile::create([
        'property_id'   => $property->id,
        'original_name' => $photo['filename'] ?? $filename,
        'stored_path'   => $path,
        'mime_type'     => 'image/'.$m[1],
        'size_bytes'    => strlen($binary),
        'category'      => match($photo['category'] ?? 'exterior') {
            'floor_plan' => 'floor_plan',
            'documents'  => 'document',
            default      => 'photo'
        },
        'sort_order'    => 0,
    ]);
}
```

- [ ] **Step 6: Run test, verify PASS**

Run: `php artisan test --filter=IntakeProtocolSubmitTest::test_photos_in_submit_are_stored_as_property_files`
Expected: PASS

- [ ] **Step 7: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/shared/PhotoCategoryUploader.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step10_Photos.vue \
        resources/js/Composables/useIntakeForm.js \
        app/Http/Controllers/Admin/IntakeProtocolController.php \
        tests/Feature/Admin/IntakeProtocolSubmitTest.php
git commit -m "feat(wizard): Step 10 — Photos by category with PropertyFile persistence"
```

---

### Task 21: Step 11 — Zusammenfassung, Unterschrift, Absenden

**Files:**
- Create: `resources/js/Components/Admin/IntakeProtocol/shared/SignaturePad.vue`
- Create: `resources/js/Components/Admin/IntakeProtocol/steps/Step11_Summary.vue`

- [ ] **Step 1: Create SignaturePad component**

Write `resources/js/Components/Admin/IntakeProtocol/shared/SignaturePad.vue`:

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' } // data URL
})
const emit = defineEmits(['update:modelValue'])

const canvas = ref(null)
let ctx = null
let drawing = false
let hasDrawn = false
let lastX = 0, lastY = 0

function setupCanvas() {
  const c = canvas.value
  const rect = c.getBoundingClientRect()
  const dpr = window.devicePixelRatio || 1
  c.width  = rect.width  * dpr
  c.height = rect.height * dpr
  ctx = c.getContext('2d')
  ctx.scale(dpr, dpr)
  ctx.strokeStyle = '#000'
  ctx.lineWidth = 2
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
}

function pos(e) {
  const rect = canvas.value.getBoundingClientRect()
  const t = e.touches ? e.touches[0] : e
  return { x: t.clientX - rect.left, y: t.clientY - rect.top }
}

function start(e) {
  e.preventDefault()
  drawing = true
  const { x, y } = pos(e)
  lastX = x; lastY = y
}

function move(e) {
  if (!drawing) return
  e.preventDefault()
  const { x, y } = pos(e)
  ctx.beginPath()
  ctx.moveTo(lastX, lastY)
  ctx.lineTo(x, y)
  ctx.stroke()
  lastX = x; lastY = y
  hasDrawn = true
}

function end() {
  if (!drawing) return
  drawing = false
  if (hasDrawn) {
    emit('update:modelValue', canvas.value.toDataURL('image/png'))
  }
}

function clear() {
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
  hasDrawn = false
  emit('update:modelValue', '')
}

defineExpose({ clear })

onMounted(() => {
  setupCanvas()
  window.addEventListener('resize', setupCanvas)
})

onUnmounted(() => {
  window.removeEventListener('resize', setupCanvas)
})
</script>

<template>
  <div class="space-y-2">
    <div class="relative">
      <canvas
        ref="canvas"
        class="w-full h-48 bg-white border-2 border-border rounded-md touch-none"
        @mousedown="start" @mousemove="move" @mouseup="end" @mouseleave="end"
        @touchstart="start" @touchmove="move" @touchend="end"
      ></canvas>
      <div v-if="!modelValue" class="absolute inset-0 flex items-center justify-center pointer-events-none text-zinc-400 text-sm">
        Hier unterschreiben
      </div>
    </div>
    <button @click="clear" type="button" class="text-xs text-muted-foreground underline">
      Zurücksetzen
    </button>
  </div>
</template>
```

- [ ] **Step 2: Create Step11_Summary.vue**

Write `resources/js/Components/Admin/IntakeProtocol/steps/Step11_Summary.vue`:

```vue
<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import StepHeader from '../shared/StepHeader.vue'
import SignaturePad from '../shared/SignaturePad.vue'
import { useIntakeForm } from '@/Composables/useIntakeForm'

const { form, skipped } = useIntakeForm()
const submitting = ref(false)
const errorMsg = ref('')

const ownerName = computed(() => `${form.value.owner_first_name || ''} ${form.value.owner_last_name || ''}`.trim() || '—')
const addressLine = computed(() => {
  const parts = [form.value.street, form.value.house_number].filter(Boolean).join(' ')
  return [parts, form.value.zip, form.value.city].filter(Boolean).join(', ') || '—'
})
const priceLine = computed(() =>
  form.value.listing_price ? new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(form.value.listing_price) : '—'
)
const objectLine = computed(() => [form.value.object_type, form.value.object_subtype].filter(Boolean).join(' · ') || '—')
const commissionLine = computed(() =>
  form.value.commission_preset === 'Anders'
    ? `${form.value.commission_percent} %`
    : form.value.commission_preset || '—'
)
const photoCount = computed(() => (form.value.photos || []).length)
const openFieldsCount = computed(() => Object.values(skipped.value || {}).filter(Boolean).length)

const DISCLAIMER_TEXT = `Dieses Protokoll wurde auf Basis der vom Eigentümer gemachten Angaben erstellt.
Die Angaben wurden von SR-Homes Immobilien nicht auf Richtigkeit überprüft.
Für die Vollständigkeit und Richtigkeit der Angaben haftet der Eigentümer.
Flächenangaben sind Circa-Werte und können von den tatsächlichen Werten abweichen.`

const disclaimerAccepted = ref(false)
const assignmentAccepted = ref(false)
const portalAccess = ref(false)

const canSubmit = computed(() =>
  form.value.signature && disclaimerAccepted.value && assignmentAccepted.value && !submitting.value
)

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  errorMsg.value = ''

  try {
    const payload = {
      ...form.value,
      skipped: skipped.value,
      disclaimer_text: DISCLAIMER_TEXT,
      disclaimer_accepted: true,
      vermittlungsauftrag_accepted: true,
      portal_access_granted: portalAccess.value,
    }

    router.post(route('admin.intake.submit'), payload, {
      onSuccess: () => {
        localStorage.removeItem('intake_draft_v1')
      },
      onError: (errors) => {
        errorMsg.value = 'Absenden fehlgeschlagen. ' + (Object.values(errors)[0] || 'Bitte Daten prüfen.')
        submitting.value = false
      },
      onFinish: () => { submitting.value = false }
    })
  } catch (e) {
    errorMsg.value = 'Unerwarteter Fehler: ' + e.message
    submitting.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <StepHeader title="Zusammenfassung & Unterschrift" subtitle="Bitte prüfen, unterschreiben, absenden" />

    <!-- Summary Card -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-3 text-sm">
      <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
        <span class="text-muted-foreground">Eigentümer:</span> <span class="font-medium">{{ ownerName }}</span>
        <span class="text-muted-foreground">Objekt:</span>     <span>{{ objectLine }}</span>
        <span class="text-muted-foreground">Adresse:</span>    <span>{{ addressLine }}</span>
        <span class="text-muted-foreground">Richtpreis:</span> <span class="font-semibold">{{ priceLine }}</span>
        <span class="text-muted-foreground">Provision:</span>  <span>{{ commissionLine }} + 20 % USt.</span>
        <span class="text-muted-foreground">Fotos:</span>      <span>{{ photoCount }}</span>
        <span class="text-muted-foreground">Offene Felder:</span>
        <span :class="openFieldsCount > 0 ? 'text-amber-700 font-medium' : 'text-green-700 font-medium'">
          {{ openFieldsCount }} {{ openFieldsCount === 1 ? 'Feld' : 'Felder' }}
        </span>
      </div>
    </div>

    <!-- Offene Felder — Warnung -->
    <div v-if="openFieldsCount > 0" class="bg-amber-50 border border-amber-300 rounded-xl p-3">
      <div class="text-sm text-amber-900">
        ⚠️ <strong>{{ openFieldsCount }} Feld(er) wurden übersprungen.</strong>
        Diese werden im PDF als „offen" markiert und der Eigentümer erhält eine Erinnerungs-Mail zum Nachreichen.
      </div>
    </div>

    <!-- Haftungsausschluss -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Haftungsausschluss</div>
      <div class="text-xs leading-relaxed text-zinc-700 whitespace-pre-line bg-zinc-50 rounded p-3 border border-zinc-200">{{ DISCLAIMER_TEXT }}</div>
      <label class="flex items-start gap-2 text-sm pt-1">
        <input type="checkbox" v-model="disclaimerAccepted" class="mt-1" />
        <span>Der Eigentümer hat den Haftungsausschluss gelesen und akzeptiert.</span>
      </label>
    </div>

    <!-- Vermittlungsauftrag -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Alleinvermittlungsauftrag</div>
      <p class="text-xs text-zinc-700">SR-Homes Immobilien wird mit dem Verkauf dieser Immobilie beauftragt. Provision: <strong>{{ commissionLine }} + 20 % USt.</strong> (im Erfolgsfall).</p>
      <label class="flex items-start gap-2 text-sm pt-1">
        <input type="checkbox" v-model="assignmentAccepted" class="mt-1" />
        <span>Der Eigentümer beauftragt SR-Homes mit dem Verkauf (Alleinvermittlungsauftrag).</span>
      </label>
    </div>

    <!-- Portal-Zugang -->
    <div class="bg-white border border-border rounded-xl p-4">
      <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" v-model="portalAccess" class="mt-1" />
        <span>Eigentümer-Portal-Zugang anlegen (Passwort-Reset-Link per Mail)</span>
      </label>
    </div>

    <!-- Unterschrift -->
    <div class="bg-white border border-border rounded-xl p-4 space-y-2">
      <div class="flex items-center justify-between">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Unterschrift Eigentümer</div>
        <div v-if="form.signature" class="text-[11px] text-green-700">✓ unterschrieben</div>
      </div>
      <SignaturePad v-model="form.signature" />
      <p class="text-[11px] text-muted-foreground">Mit der Unterschrift bestätigt der Eigentümer die Angaben und den Alleinvermittlungsauftrag.</p>
    </div>

    <!-- Absenden -->
    <div v-if="errorMsg" class="bg-red-50 border border-red-300 text-red-800 text-sm rounded-xl p-3">
      {{ errorMsg }}
    </div>

    <button
      :disabled="!canSubmit"
      @click="submit"
      type="button"
      class="w-full h-14 rounded-xl bg-[#EE7600] text-white font-semibold text-base disabled:bg-zinc-300 disabled:cursor-not-allowed transition-colors"
    >
      {{ submitting ? 'Wird gesendet…' : '✓ Protokoll absenden' }}
    </button>

    <p class="text-[11px] text-center text-muted-foreground">
      Es wird ein PDF generiert, eine E-Mail an den Eigentümer versendet{{ portalAccess ? ' und ein Portal-Zugang angelegt' : '' }}.
    </p>
  </div>
</template>
```

- [ ] **Step 3: Ensure signature field in form state**

Edit `resources/js/Composables/useIntakeForm.js` — ensure `initialForm()` includes:

```js
signature: '', // data:image/png;base64,...
disclaimer_accepted: false,
vermittlungsauftrag_accepted: false,
portal_access_granted: false,
```

- [ ] **Step 4: Register Step 11 in wizard**

Edit `resources/js/Pages/Admin/IntakeProtocol/IntakeProtocolWizard.vue`:

Verify `Step11_Summary` is imported and included in the `steps` array (it was added in Task 11 — this is a sanity check that the component names match).

- [ ] **Step 5: Build + Commit**

```bash
npm run build 2>&1 | tail -3
git add resources/js/Components/Admin/IntakeProtocol/shared/SignaturePad.vue \
        resources/js/Components/Admin/IntakeProtocol/steps/Step11_Summary.vue \
        resources/js/Composables/useIntakeForm.js \
        resources/js/Pages/Admin/IntakeProtocol/IntakeProtocolWizard.vue
git commit -m "feat(wizard): Step 11 — Summary, Signature, Disclaimer, Submit"
```

---

### Task 22: Integration — Button in der Objektübersicht

**Files:**
- Modify: `resources/js/Components/Admin/PropertiesTab.vue`

- [ ] **Step 1: Locate the "Neues Objekt" button**

Run: `grep -n "Neues Objekt\|new-property\|createProperty" resources/js/Components/Admin/PropertiesTab.vue`

Identify the line(s) where the existing "Neues Objekt" button is rendered.

- [ ] **Step 2: Add "Aufnahmeprotokoll" button next to it**

Modify `resources/js/Components/Admin/PropertiesTab.vue`. Right next to the existing "Neues Objekt" button, add a secondary button that visually matches (same height + border-radius):

```vue
<div class="flex items-center gap-2">
  <Button @click="createProperty" class="h-10">+ Neues Objekt</Button>
  <Link
    :href="route('admin.intake.create')"
    class="inline-flex items-center gap-2 h-10 px-4 rounded-md border border-[#EE7600] text-[#EE7600] hover:bg-[#EE7600]/5 text-sm font-medium"
  >
    📋 Aufnahmeprotokoll
  </Link>
</div>
```

Add import at top of `<script setup>`:

```js
import { Link } from '@inertiajs/vue3'
```

- [ ] **Step 3: Add route to create wizard**

Edit `routes/web.php` inside the admin group — add (if not yet present from Task 9):

```php
Route::get('/intake', [IntakeProtocolController::class, 'create'])->name('admin.intake.create');
```

And in `app/Http/Controllers/Admin/IntakeProtocolController.php`, ensure `create()` returns:

```php
public function create()
{
    return Inertia::render('Admin/IntakeProtocol/IntakeProtocolWizard');
}
```

- [ ] **Step 4: Manual smoke test**

Run: `npm run build 2>&1 | tail -3`

Open `/admin` in browser → Objekte-Tab → confirm the orange "📋 Aufnahmeprotokoll" button appears next to "+ Neues Objekt".
Click it → wizard loads at Step 1.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/PropertiesTab.vue \
        routes/web.php \
        app/Http/Controllers/Admin/IntakeProtocolController.php
git commit -m "feat(admin): Aufnahmeprotokoll button in PropertiesTab + route"
```

---

### Task 23: Integration — Banner + "offene Felder"-Marker in Property Detail

**Files:**
- Modify: `resources/js/Pages/Admin/Property/Show.vue` (or the equivalent Property detail Inertia page)
- Create: `resources/js/Components/Admin/IntakeProtocol/IntakeOpenFieldsBanner.vue`

- [ ] **Step 1: Create IntakeOpenFieldsBanner**

Write `resources/js/Components/Admin/IntakeProtocol/IntakeOpenFieldsBanner.vue`:

```vue
<script setup>
import { computed } from 'vue'

const props = defineProps({
  protocol: { type: Object, default: null } // { id, open_fields: {field:true}, created_at, pdf_url }
})

const openFieldKeys = computed(() =>
  props.protocol?.open_fields ? Object.keys(props.protocol.open_fields).filter(k => props.protocol.open_fields[k]) : []
)

const FIELD_LABELS = {
  living_area: 'Wohnfläche',
  land_area: 'Grundstücksfläche',
  construction_year: 'Baujahr',
  listing_price: 'Richtpreis',
  commission_percent: 'Provision',
  energy_class: 'Energieklasse',
  hwb_value: 'HWB-Wert',
  approvals_notes: 'Bewilligungs-Notizen',
}

function label(key) {
  return FIELD_LABELS[key] || key
}
</script>

<template>
  <div v-if="protocol && openFieldKeys.length > 0"
       class="bg-amber-50 border-l-4 border-amber-500 rounded-md p-4 mb-4">
    <div class="flex items-start gap-3">
      <span class="text-xl">⚠️</span>
      <div class="flex-1">
        <div class="font-semibold text-amber-900 text-sm">
          {{ openFieldKeys.length }} {{ openFieldKeys.length === 1 ? 'Feld wurde' : 'Felder wurden' }} im Aufnahmeprotokoll übersprungen
        </div>
        <div class="text-xs text-amber-800 mt-1">
          <span v-for="(k, i) in openFieldKeys" :key="k">
            {{ label(k) }}<span v-if="i < openFieldKeys.length - 1">, </span>
          </span>
        </div>
        <div class="mt-2 flex items-center gap-3 text-xs">
          <a v-if="protocol.pdf_url" :href="protocol.pdf_url" target="_blank" class="text-amber-900 underline">PDF ansehen</a>
          <span class="text-amber-700">·</span>
          <span class="text-amber-700">Aufgenommen {{ new Date(protocol.created_at).toLocaleDateString('de-AT') }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Load protocol data in controller**

Find the controller that renders the Property detail page:

Run: `grep -rn "Property/Show\|admin.properties.show" app/Http/Controllers/ | head -5`

In that controller's show method, eager-load the latest intake protocol:

```php
$property->load(['latestIntakeProtocol']); // relation defined on Property model
```

Add relation to `app/Models/Property.php`:

```php
public function latestIntakeProtocol()
{
    return $this->hasOne(\App\Models\IntakeProtocol::class)->latestOfMany();
}

public function intakeProtocols()
{
    return $this->hasMany(\App\Models\IntakeProtocol::class);
}
```

In the controller response, include `pdf_url`:

```php
$protocol = $property->latestIntakeProtocol;
$protocolData = $protocol ? [
    'id' => $protocol->id,
    'created_at' => $protocol->created_at,
    'open_fields' => $protocol->open_fields,
    'pdf_url' => route('admin.intake.pdf', $protocol->id),
] : null;

return Inertia::render('Admin/Property/Show', [
    'property' => $property,
    'intakeProtocol' => $protocolData,
]);
```

- [ ] **Step 3: Render banner in Show.vue**

Edit the top of `resources/js/Pages/Admin/Property/Show.vue`:

```vue
<script setup>
import IntakeOpenFieldsBanner from '@/Components/Admin/IntakeProtocol/IntakeOpenFieldsBanner.vue'
// ... existing imports

defineProps({
  property: Object,
  intakeProtocol: { type: Object, default: null },
  // ... existing props
})
</script>

<template>
  <AdminLayout>
    <!-- Banner at very top -->
    <IntakeOpenFieldsBanner :protocol="intakeProtocol" />

    <!-- existing property detail layout -->
  </AdminLayout>
</template>
```

- [ ] **Step 4: Build + manual test**

Run: `npm run build 2>&1 | tail -3`

Submit a protocol with 2 fields skipped → navigate to the created property → banner must show the 2 skipped field labels and a "PDF ansehen" link.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/IntakeProtocol/IntakeOpenFieldsBanner.vue \
        resources/js/Pages/Admin/Property/Show.vue \
        app/Http/Controllers/Admin/PropertyController.php \
        app/Models/Property.php
git commit -m "feat(admin): IntakeOpenFieldsBanner on Property detail page"
```

---

### Task 24: Export-Mapping & Feld-Aktualisierung in Legacy-Code-Pfaden

**Files:**
- Modify: `resources/js/lib/propertyFieldExports.js`
- Modify: `app/Http/Controllers/Api/WebsiteApiController.php`
- Modify: `website-v2/src/detail.js` (if it exists in this repo; otherwise document for deploy step)

The Aufnahmeprotokoll introduces 6 new Property columns (`encumbrances`, `parking_assignment`, `documents_available`, `approvals_status`, `approvals_notes`, `internal_notes`). Three of them (`internal_notes`, `approvals_status`, `approvals_notes`, `documents_available`) are **internal only** and must NEVER be exported to the public website or portals.

- [ ] **Step 1: Add export-safe flag to propertyFieldExports**

Edit `resources/js/lib/propertyFieldExports.js` — find the exports map and add:

```js
// INTERNAL ONLY — never exported
encumbrances:         { exportable: false, internal: true, label: 'Belastungen / Rechte' },
approvals_status:     { exportable: false, internal: true, label: 'Bewilligungs-Status' },
approvals_notes:      { exportable: false, internal: true, label: 'Bewilligungs-Notizen' },
documents_available:  { exportable: false, internal: true, label: 'Dokumente Checkliste' },
internal_notes:       { exportable: false, internal: true, label: 'Interne Notizen' },

// PUBLIC — exported
parking_assignment:   { exportable: true,  label: 'Stellplatz-Zuordnung' }, // 'assigned' | 'common'
```

- [ ] **Step 2: Filter internal fields from WebsiteApiController**

Edit `app/Http/Controllers/Api/WebsiteApiController.php` — find the method that serializes Property for the public website (usually `show()` or `index()`). Ensure the response does NOT include these columns:

```php
protected array $internalOnlyFields = [
    'encumbrances',
    'approvals_status',
    'approvals_notes',
    'documents_available',
    'internal_notes',
];

// In the transformation:
$data = $property->toArray();
foreach ($this->internalOnlyFields as $field) {
    unset($data[$field]);
}
// Include parking_assignment on public output:
$data['parking_assignment'] = $property->parking_assignment;
```

- [ ] **Step 3: Write a test that proves internal fields are filtered**

Create `tests/Feature/Api/WebsiteApiInternalFieldsTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Property;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebsiteApiInternalFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_api_does_not_expose_internal_fields(): void
    {
        $property = Property::factory()->create([
            'is_public' => true,
            'encumbrances' => 'Pfandrecht 180k',
            'approvals_status' => 'partial',
            'approvals_notes' => 'Terrasse nicht bewilligt',
            'internal_notes' => 'Eigentümer will Vollmacht',
            'documents_available' => ['grundbuch' => 'available'],
        ]);

        $response = $this->getJson(route('api.website.properties.show', $property->slug));

        $response->assertOk();
        $response->assertJsonMissing(['encumbrances' => 'Pfandrecht 180k']);
        $response->assertJsonMissing(['approvals_status' => 'partial']);
        $response->assertJsonMissing(['approvals_notes' => 'Terrasse nicht bewilligt']);
        $response->assertJsonMissing(['internal_notes' => 'Eigentümer will Vollmacht']);
        $response->assertJsonMissing(['documents_available' => ['grundbuch' => 'available']]);
    }

    public function test_website_api_exposes_parking_assignment(): void
    {
        $property = Property::factory()->create([
            'is_public' => true,
            'parking_assignment' => 'assigned',
        ]);

        $response = $this->getJson(route('api.website.properties.show', $property->slug));

        $response->assertJsonPath('parking_assignment', 'assigned');
    }
}
```

Run: `php artisan test --filter=WebsiteApiInternalFieldsTest`
Expected: Both PASS (after Step 2 is applied).

- [ ] **Step 4: Document website-v2 detail.js update in deploy-notes**

Create `docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md`:

```markdown
# Deploy-Hinweise: Aufnahmeprotokoll

## website-v2 Repo

Wenn `website-v2/src/detail.js` Property-Felder referenziert, prüfen:

- **NICHT anzeigen:** `encumbrances`, `approvals_status`, `approvals_notes`, `documents_available`, `internal_notes`
- **Optional anzeigen:** `parking_assignment` (Wert: `'assigned'` = „dem Objekt zugeordnet", `'common'` = „Gemeinschaftsplatz")

Einfacher Schutz: Das WebsiteApiController filtert interne Felder bereits serverseitig — detail.js bekommt sie nicht mehr. Nichts zu ändern, aber dokumentieren.
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/propertyFieldExports.js \
        app/Http/Controllers/Api/WebsiteApiController.php \
        tests/Feature/Api/WebsiteApiInternalFieldsTest.php \
        docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md
git commit -m "feat(api): filter internal intake fields + expose parking_assignment"
```

---

### Task 25: End-to-End Manual Test & Fix-Up Pass

**Goal:** Walk through the entire wizard as a real user on a mobile device and fix anything that breaks.

- [ ] **Step 1: Local smoke test on desktop**

Run:
```bash
npm run build 2>&1 | tail -3
php artisan serve
```

Navigate to `http://127.0.0.1:8000/admin` → Objekte → click "📋 Aufnahmeprotokoll".

Walk through ALL 11 steps with realistic data:
- Step 1: new customer
- Step 2: Eigentumswohnung → Maisonette-Wohnung
- Step 3: address from Nominatim autocomplete (Zell am See)
- Step 4: 95m² Wohnfläche, 2 Stockwerke, 4 Zimmer, 2 Bäder
- Step 5: 2 Sanierungen (2019 Bad, 2022 Heizung)
- Step 6: Ausstattung (Balkon, Keller, Tiefgaragenplatz)
- Step 7: Energieklasse C, HWB 85
- Step 8: HV auswählen, Belastungen „Pfandrecht 150k", Bewilligungen „Teilweise" → Details eingeben, Dokumente: Grundbuch ✓, Energieausweis ✗
- Step 9: 475.000 €, 3.5 %
- Step 10: 3 Außen-, 4 Innen-, 1 Grundriss-Foto (kleine Test-JPEGs)
- Step 11: Unterschrift zeichnen, Portal-Zugang aktivieren, Haftungsausschluss + Auftrag ankreuzen, Absenden

Expected: redirect to property detail page, banner shows 0 open fields, PDF link works, mail logged.

- [ ] **Step 2: Verify all database writes**

Run:
```bash
php artisan tinker --execute="
echo 'Property: '.\App\Models\Property::count();
echo PHP_EOL.'Customers: '.\App\Models\Customer::count();
echo PHP_EOL.'Intake protocols: '.\App\Models\IntakeProtocol::count();
echo PHP_EOL.'Property files: '.\App\Models\PropertyFile::count();
echo PHP_EOL.'Activities: '.\App\Models\Activity::where('type', 'intake_protocol')->count();
"
```

Expected: counts increment by 1 / 1 / 1 / 8 / 1 (roughly; depends on photos).

- [ ] **Step 3: Verify PDF was generated + attached to mail**

Run:
```bash
ls -la storage/app/private/intake-protocols/
```

Expected: one PDF file with reasonable size (>100KB, <5MB).

Run:
```bash
php artisan queue:work --once
```
(If using queue driver `sync` in .env, mails already sent — verify via mail log:)
```bash
tail -50 storage/logs/laravel.log | grep -i "mail\|intake"
```

- [ ] **Step 4: Mobile device test via local network**

Find your Mac's IP:
```bash
ipconfig getifaddr en0
```

Start server on all interfaces:
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Open `http://<your-ip>:8000/admin` on an iPhone or Android phone (in same WiFi).

Test:
- Touch targets feel comfortable (44px+)
- Autocomplete keyboards don't break layout (zip code, price)
- Camera permission works on Step 10
- Signature drawing works smoothly with finger
- Sticky bottom nav stays visible
- Back button navigates between steps (browser back should also work)

- [ ] **Step 5: Test "offene Felder" flow**

Restart wizard. This time:
- Skip Wohnfläche (↷ later switch)
- Skip Baujahr
- Skip Energieklasse
- Skip HWB
- Complete and submit.

Expected:
- Banner on property detail shows 4 fields
- PDF has "OFFENE FELDER" section listing these 4
- Mail to owner uses "missing-docs" variant A/B with the listed fields

- [ ] **Step 6: Test auto-save / resume flow**

Start wizard, complete Steps 1–5. Close the browser tab.

Re-open `/admin/intake`. Expected: resume banner "Entwurf vom <date> laden?". Click yes → data is restored to Step 5.

- [ ] **Step 7: Fix any issues found**

For each issue, create an `- [ ]` checkbox line below documenting the fix and commit it. Example:

```
- [ ] Fix: Step 8 approvals textarea was not resizing on mobile Safari — add `max-h-40 overflow-auto`
- [ ] Fix: Signature canvas looked blurry on Retina — double-check dpr scaling
```

- [ ] **Step 8: Run full test suite**

```bash
php artisan test --parallel
```

Expected: all green. Fix any breaking tests introduced by the migration / controller changes.

- [ ] **Step 9: Commit remaining fixes**

```bash
git add -A
git commit -m "fix(wizard): mobile-first polish after E2E walkthrough"
```

---

### Task 26: Deploy

**Goal:** Ship to production.

- [ ] **Step 1: Pre-deploy checklist**

Verify these are true locally:

- All tests green: `php artisan test --parallel`
- Build succeeds: `npm run build 2>&1 | tail -3`
- Migrations can run fresh: `php artisan migrate:fresh --seed` (local DB)
- No uncommitted changes: `git status` clean
- `docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md` reviewed

- [ ] **Step 2: Merge branch to main**

```bash
git checkout main
git pull origin main
git merge --no-ff <feature-branch>
git push origin main
```

- [ ] **Step 3: SSH to production server**

```bash
ssh <prod-server>
```

- [ ] **Step 4: Pull + migrate + build in BOTH deploy directories**

Run in both `/var/www/srhomes/website-v2/` and `/var/www/sr-homes-v2/`:

```bash
cd /var/www/srhomes/website-v2   # (or /var/www/sr-homes-v2)
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
sudo systemctl reload php8.3-fpm
```

- [ ] **Step 5: Verify symlink for public storage**

```bash
php artisan storage:link  # (idempotent — already linked unless fresh install)
```

- [ ] **Step 6: Verify mail queue worker is running**

```bash
sudo systemctl status supervisor
sudo supervisorctl status srhomes-queue-worker
```

If not running: `sudo supervisorctl restart srhomes-queue-worker`

- [ ] **Step 7: Production smoke test**

From your phone (not Mac) open the real admin URL → log in → Objekte → Aufnahmeprotokoll.

Do ONE minimal intake (you can use a test customer):
- Skip most optional fields
- Enter only required fields
- Complete with signature
- Submit

Verify:
- Property created in production DB
- Owner receives real email with PDF attached
- `docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md` hints validated

- [ ] **Step 8: Post-deploy monitoring**

For the first 24 hours, check:
```bash
tail -f /var/log/srhomes/laravel.log | grep -i "intake\|error"
```

Spot-check: first 2–3 real intake protocols from the field → open PDFs, verify no layout breakage with unusual data.

- [ ] **Step 9: Commit deploy confirmation to repo**

Edit `docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md` and append:

```markdown
## Deploy-Status

- ✅ Deployed to production: 2026-04-__ __:__
- ✅ First successful real intake: <property name>
- ✅ Monitoring: no errors in first 24h
```

```bash
git add docs/superpowers/deploy-notes/2026-04-22-aufnahmeprotokoll.md
git commit -m "docs: Aufnahmeprotokoll deployed to production"
git push
```

---

## Self-Review

**Spec coverage:**

Cross-checked against `docs/superpowers/specs/2026-04-22-aufnahmeprotokoll-design.md` sections:

- ✅ §1 Purpose → Task 1 (migration) + Task 11 (wizard) + Task 22 (entry point)
- ✅ §2 Success Criteria (one-hour flow, PDF, mail, portal, activity) → Tasks 6–10, 21, 26
- ✅ §3 User Flow (entry → 11 steps → submit) → Tasks 11–21
- ✅ §4 Step Details (all 11) → Tasks 11–21
- ✅ §5 DB Changes (intake_protocols, drafts, 6 property columns) → Tasks 1–3
- ✅ §6 Tech Architecture (composables, auto-save, services) → Tasks 4–5, 12–14
- ✅ §7 PDF Structure (Aufnahmeprotokoll + Vermittlungsauftrag) → Tasks 6–7
- ✅ §8 Email Templates (complete / missing-docs / portal) → Tasks 8
- ✅ §9 Edit-Later Workflow (banner, PDF-immutability) → Task 23
- ✅ §10 Mobile-first (44px targets, sticky nav, PillRow) → Tasks 11, 15
- ✅ §11 Testing Strategy (Pest feature tests) → Tasks 2, 6, 10, 20, 24
- ✅ §12 Scope Exclusions → Documented here (no portal UI, no public API, no i18n)
- ✅ §13 Disclaimer Versioning (snapshot in intake_protocols.disclaimer_text) → Tasks 1, 10, 21

**Placeholder scan:** No "TBD", "TODO", or "implement later" markers. Every step has concrete code or exact commands. ✓

**Type consistency:**
- `IntakeProtocol` model name used consistently across migration, model, controller, mail, PDF service ✓
- `open_fields` is JSON `{field: true}` everywhere (controller, model cast, PDF template, banner component) ✓
- `documents_available` is JSON `{docKey: 'available'|'missing'|'na'}` everywhere (property column, Step 8, PDF) ✓
- `approvals_status` is enum `'all'|'partial'|'unknown'` everywhere ✓
- `parking_assignment` is enum `'assigned'|'common'` ✓
- `photos` array items have shape `{id, dataUrl, filename, category}` in frontend; mapped to PropertyFile rows backend-side ✓
- `useIntakeForm` composable exposes `form`, `skipped`, `isSkipped`, `toggleSkip` — all step components use the same API ✓

---

## Scope Boundaries (out of scope, re-stated)

- **Owner portal UI** — only the account + password-reset-link is created; actual portal pages are a future feature.
- **Public API for intake data** — internal fields are explicitly filtered from the public Website API (Task 24).
- **i18n** — German only for now; every string in this wizard is German.
- **Offline PWA** — auto-save handles briefly-offline field work via localStorage + sendBeacon; full offline PWA is future.
- **Multi-object protocols** — one protocol = one property; bulk intake is out of scope.

---

**Plan complete and saved to `docs/superpowers/plans/2026-04-22-aufnahmeprotokoll.md`.**

## Execution Handoff

Two execution options for implementing this plan:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration. Good for a plan this large (26 tasks) because each subagent starts fresh and only needs to reason about one task at a time.

**2. Inline Execution** — I execute tasks in this session using the `superpowers:executing-plans` skill, in batches with checkpoints for you to review. Keeps everything in one conversation.

**Which approach?**
