# Hausverwaltung Phase 2 — Kontakt-Flows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HV kontaktieren aus dem Objekt + Mieter-Mails an HV weiterleiten aus der Inbox. Template-basierte Entwürfe (Unterlagen anfordern mit AVA, Mieter-Meldung KI-zusammengefasst, Freitext) + Missing-HV + Missing-AVA Popups. Baut auf Phase 1 auf (HV-Tabelle, Picker, is_ava-Flag sind schon live).

**Architecture:** Neuer Service `PropertyManagerContactService` kapselt die Template-Logik + KI-Calls (AnthropicService). Zwei neue Endpoints (`contact_property_manager` für Entwurf, `send_to_manager` für Versand als neuer HV-Thread). Fünf neue Vue-Komponenten: Sheet mit Template-Auswahl, Missing-Popups, Inbox-Forward-Button.

**Tech Stack:** Laravel 11 + PHP 8.2, AnthropicService (claude-haiku-4-5 via chatJson), Vue 3 + shadcn-vue (Dialog, Sheet), bestehende Compose-Pane Pipeline.

**Spec:** [`docs/superpowers/specs/2026-04-21-hausverwaltung-design.md`](../specs/2026-04-21-hausverwaltung-design.md)

**Voraussetzung:** Phase 1 ist deployed — `property_managers` Tabelle existiert, `properties.property_manager_id` + `property_files.is_ava` sind gesetzt, CRUD-API funktioniert.

---

## File Structure

**Backend (create):**
- `database/migrations/2026_04_21_170000_add_hausverwaltung_to_activity_category.php`
- `app/Services/PropertyManagerContactService.php` — Template-Builder + KI-Call
- `tests/Unit/Services/PropertyManagerContactServiceTest.php`

**Backend (modify):**
- `app/Http/Controllers/Admin/AdminApiController.php` — `contact_property_manager` + `send_to_manager` actions
- `app/Http/Controllers/Admin/ConversationController.php` — conv_detail Response um `property_manager_id` + `property_manager_email` ergänzen

**Frontend (create):**
- `resources/js/Components/Admin/property-detail/ContactManagerSheet.vue` — Sheet mit 3 Template-Karten
- `resources/js/Components/Admin/property-detail/MissingAvaDialog.vue` — AVA-Upload-Popup
- `resources/js/Components/Admin/inbox/MissingManagerDialog.vue` — HV-anlegen-Popup (wraps HausverwaltungFormDialog, adds Property-Kontext-Header)
- `resources/js/Components/Admin/inbox/ForwardToManagerButton.vue` — oranger Button + Flow-Orchestrator

**Frontend (modify):**
- `resources/js/Components/Admin/property-detail/OverviewTab.vue` — „Hausverwaltung kontaktieren"-Button
- `resources/js/Components/Admin/inbox/InboxChatView.vue` — ForwardToManagerButton im Thread-Footer

---

## Task 1: Activities-Category ENUM erweitern

**Files:**
- Create: `database/migrations/2026_04_21_170000_add_hausverwaltung_to_activity_category.php`

- [ ] **Step 1: Migration anlegen**

Create `database/migrations/2026_04_21_170000_add_hausverwaltung_to_activity_category.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: Enum-Wert 'hausverwaltung' hinzufuegen. Zweistufig via raw SQL
        // damit bestehende Werte erhalten bleiben.
        DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM(
            'email-in','email-out','expose','besichtigung','kaufanbot','update',
            'absage','sonstiges','anfrage','eigentuemer','partner','bounce',
            'intern','makler','feedback_positiv','feedback_negativ',
            'feedback_besichtigung','nachfassen','link_opened','objekt_edit',
            'hausverwaltung'
        ) NULL DEFAULT 'sonstiges'");
    }

    public function down(): void
    {
        // Rueckwaerts: 'hausverwaltung'-Rows auf 'sonstiges' ruecksetzen bevor Enum gekuerzt wird
        DB::statement("UPDATE activities SET category = 'sonstiges' WHERE category = 'hausverwaltung'");
        DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM(
            'email-in','email-out','expose','besichtigung','kaufanbot','update',
            'absage','sonstiges','anfrage','eigentuemer','partner','bounce',
            'intern','makler','feedback_positiv','feedback_negativ',
            'feedback_besichtigung','nachfassen','link_opened','objekt_edit'
        ) NULL DEFAULT 'sonstiges'");
    }
};
```

- [ ] **Step 2: Migration laufen lassen**

Run: `php artisan migrate`
Expected: `Migrated` für `2026_04_21_170000_add_hausverwaltung_to_activity_category`

- [ ] **Step 3: Verify enum**

Run: `mysql --no-defaults -u root srhomes_portal -e "SHOW COLUMNS FROM activities LIKE 'category';" 2>&1 | grep -c hausverwaltung`
(Oder lokal via: `php artisan db:show`)
Expected: `1` (oder die Zeile enthält `hausverwaltung`)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_21_170000_add_hausverwaltung_to_activity_category.php
git commit -m "feat(hv): add 'hausverwaltung' to activities.category enum"
```

---

## Task 2: PropertyManagerContactService — Template Builder

**Files:**
- Create: `app/Services/PropertyManagerContactService.php`
- Create: `tests/Unit/Services/PropertyManagerContactServiceTest.php`

- [ ] **Step 1: Service-Gerüst anlegen**

Create `app/Services/PropertyManagerContactService.php`:

```php
<?php

namespace App\Services;

use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyManagerContactService
{
    public function __construct(
        private AnthropicService $anthropic,
    ) {}

    /**
     * Baut den HV-Kontakt-Entwurf fuer ein Template.
     * Returns: ['subject' => string, 'body' => string, 'attachments' => int[]|array[], 'ava_missing' => bool]
     */
    public function buildDraft(Property $property, PropertyManager $manager, string $templateKind, ?PortalEmail $sourceEmail, ?User $maklerUser): array
    {
        return match ($templateKind) {
            'unterlagen' => $this->buildUnterlagenDraft($property, $manager, $maklerUser),
            'mieter_meldung' => $this->buildMieterMeldungDraft($property, $manager, $sourceEmail, $maklerUser),
            'freitext' => $this->buildFreitextDraft($property, $manager, $maklerUser),
            default => throw new \InvalidArgumentException("Unknown template_kind: {$templateKind}"),
        };
    }

    private function buildUnterlagenDraft(Property $property, PropertyManager $manager, ?User $maklerUser): array
    {
        $address = trim(($property->address ?? '') . ' ' . ($property->zip ?? '') . ' ' . ($property->city ?? ''));
        $refId = $property->ref_id ?? '';
        $maklerName = $maklerUser?->name ?? 'Ihr SR-Homes Team';

        $body = "Sehr geehrte Damen und Herren,\n\n"
            . "ich bin mit dem Verkauf des Objekts" . ($address ? " {$address}" : '') . " beauftragt und darf\n"
            . "Sie in diesem Zusammenhang höflich um Zusendung folgender Unterlagen bitten:\n\n"
            . "- Aktuelle Betriebskostenabrechnung\n"
            . "- Nutzwertgutachten\n"
            . "- Pläne des Objekts\n"
            . "- Energieausweis\n"
            . "- Rücklagenstand\n"
            . "- Hausordnung\n"
            . "- Wohnungseigentumsvertrag\n"
            . "- Protokolle der letzten Eigentümerversammlungen\n\n"
            . "Im Anhang finden Sie den Alleinvermittlungsauftrag als Nachweis meiner\n"
            . "Beauftragung.\n\n"
            . "Vielen Dank im Voraus und mit freundlichen Grüßen\n"
            . $maklerName;

        $subject = trim("Verkauf " . ($refId ? $refId . ' ' : '') . ($address ?: '(Objekt)') . " – Bitte um Unterlagen");

        // AVA suchen
        $ava = DB::table('property_files')
            ->where('property_id', $property->id)
            ->where('is_ava', 1)
            ->first();

        return [
            'subject' => $subject,
            'body' => $body,
            'attachments' => $ava ? [(int) $ava->id] : [],
            'ava_missing' => !$ava,
        ];
    }

    private function buildMieterMeldungDraft(Property $property, PropertyManager $manager, ?PortalEmail $sourceEmail, ?User $maklerUser): array
    {
        if (!$sourceEmail) {
            throw new \InvalidArgumentException("mieter_meldung template requires source_email_id");
        }

        $address = trim(($property->address ?? '') . ' ' . ($property->zip ?? '') . ' ' . ($property->city ?? ''));
        $maklerName = $maklerUser?->name ?? 'Ihr SR-Homes Team';
        $origBody = trim(strip_tags((string) ($sourceEmail->body_text ?? '')));
        $origSubject = trim((string) ($sourceEmail->subject ?? ''));
        $origFrom = trim((string) ($sourceEmail->from_email ?? ''));

        // KI zusammenfassen
        $summary = $this->summarizeIssueViaAi($origSubject, $origBody);

        $schlagwort = $summary['schlagwort'] ?? 'Mieter-Meldung';
        $issueText = $summary['issue'] ?? $origSubject;

        $body = "Sehr geehrte Damen und Herren,\n\n"
            . "wir haben heute von den Mietern" . ($address ? " der Wohnung {$address}" : '') . " folgende Meldung erhalten:\n\n"
            . $issueText . "\n\n"
            . "Wir bitten Sie, zeitnah mit den Mietern Kontakt aufzunehmen und sich der\n"
            . "Angelegenheit anzunehmen.\n\n"
            . "Mit freundlichen Grüßen\n"
            . $maklerName;

        $subject = trim($schlagwort . ' – Wohnung ' . ($address ?: ($property->ref_id ?? '')));

        return [
            'subject' => $subject,
            'body' => $body,
            'attachments' => [],
            'ava_missing' => false,
        ];
    }

    private function buildFreitextDraft(Property $property, PropertyManager $manager, ?User $maklerUser): array
    {
        // Leerer Entwurf — User schreibt selbst.
        return [
            'subject' => '',
            'body' => '',
            'attachments' => [],
            'ava_missing' => false,
        ];
    }

    /**
     * KI-Call: fasst Original-Mieter-Mail in 1-2 Saetze zusammen
     * und liefert ein kurzes Schlagwort fuer den Betreff.
     * Returns: ['issue' => '…', 'schlagwort' => 'Heizungsstörung']
     */
    private function summarizeIssueViaAi(string $subject, string $body): array
    {
        $systemPrompt = 'Du bekommst eine Mieter-Mail. Erkenne das konkrete Problem und fasse es in 1-2 saalchlichen deutschen Saetzen zusammen. '
            . 'Erfinde keine Details die nicht drinstehen. '
            . 'Extrahiere zusaetzlich ein 1-2-Wort-Schlagwort fuer den Betreff einer Weiterleitung an die Hausverwaltung '
            . '(z.B. "Heizungsstörung", "Wasserschaden", "Lärmbelästigung"). '
            . 'Antworte NUR als JSON: {"issue":"...","schlagwort":"..."}';

        $userMessage = "Betreff: {$subject}\n\nText:\n" . mb_substr($body, 0, 2500);

        try {
            $result = $this->anthropic->chatJson($systemPrompt, $userMessage, 400);
            if (is_array($result) && isset($result['issue'], $result['schlagwort'])) {
                return [
                    'issue' => mb_substr((string) $result['issue'], 0, 400),
                    'schlagwort' => mb_substr((string) $result['schlagwort'], 0, 40),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('PropertyManagerContactService::summarizeIssueViaAi failed', ['error' => $e->getMessage()]);
        }

        // Fallback: Original-Subject als Schlagwort, erste 200 Zeichen des Bodies als Issue.
        return [
            'issue' => mb_substr($body, 0, 200) . (mb_strlen($body) > 200 ? '…' : ''),
            'schlagwort' => $subject ?: 'Mieter-Meldung',
        ];
    }
}
```

- [ ] **Step 2: Test schreiben**

Create `tests/Unit/Services/PropertyManagerContactServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\User;
use App\Services\AnthropicService;
use App\Services\PropertyManagerContactService;
use Tests\TestCase;

class PropertyManagerContactServiceTest extends TestCase
{
    public function test_freitext_returns_empty(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['id' => 1, 'ref_id' => 'REF-1', 'address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $out = $svc->buildDraft($prop, $mgr, 'freitext', null, $user);

        $this->assertSame('', $out['subject']);
        $this->assertSame('', $out['body']);
        $this->assertEmpty($out['attachments']);
        $this->assertFalse($out['ava_missing']);
    }

    public function test_unterlagen_body_contains_required_items(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['id' => 1, 'ref_id' => 'REF-1', 'address' => 'Teststraße 1', 'city' => 'Salzburg', 'zip' => '5020']);
        $prop->id = 1;
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $out = $svc->buildDraft($prop, $mgr, 'unterlagen', null, $user);

        $this->assertStringContainsString('Betriebskostenabrechnung', $out['body']);
        $this->assertStringContainsString('Nutzwertgutachten', $out['body']);
        $this->assertStringContainsString('Energieausweis', $out['body']);
        $this->assertStringContainsString('Rücklagenstand', $out['body']);
        $this->assertStringContainsString('Alleinvermittlungsauftrag', $out['body']);
        $this->assertStringContainsString('REF-1', $out['subject']);
        $this->assertTrue($out['ava_missing'], 'Keine AVA-Datei in DB → missing=true');
    }

    public function test_mieter_meldung_requires_source_email(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['id' => 1, 'address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $this->expectException(\InvalidArgumentException::class);
        $svc->buildDraft($prop, $mgr, 'mieter_meldung', null, $user);
    }

    public function test_mieter_meldung_uses_ai_summary_with_fallback(): void
    {
        // AnthropicService liefert einen fehler → service fällt auf fallback zurück
        $mock = $this->createMock(AnthropicService::class);
        $mock->method('chatJson')->willReturn(null);
        $this->app->instance(AnthropicService::class, $mock);

        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['id' => 1, 'address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);
        $email = new PortalEmail(['subject' => 'Heizung kaputt', 'body_text' => 'Hallo, unsere Heizung heizt nicht mehr']);

        $out = $svc->buildDraft($prop, $mgr, 'mieter_meldung', $email, $user);

        $this->assertNotEmpty($out['body']);
        $this->assertStringContainsString('Mieter', $out['body']);
        $this->assertStringContainsString('Heizung kaputt', $out['subject']);
    }

    public function test_mieter_meldung_uses_ai_when_ai_returns_json(): void
    {
        $mock = $this->createMock(AnthropicService::class);
        $mock->method('chatJson')->willReturn([
            'issue' => 'Die Heizung im Wohnzimmer ist seit gestern ausgefallen.',
            'schlagwort' => 'Heizungsstörung',
        ]);
        $this->app->instance(AnthropicService::class, $mock);

        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['id' => 1, 'address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);
        $email = new PortalEmail(['subject' => 'Heizung', 'body_text' => 'Bla']);

        $out = $svc->buildDraft($prop, $mgr, 'mieter_meldung', $email, $user);

        $this->assertStringContainsString('Die Heizung im Wohnzimmer ist seit gestern ausgefallen', $out['body']);
        $this->assertStringContainsString('Heizungsstörung', $out['subject']);
    }
}
```

- [ ] **Step 3: Tests laufen lassen**

Run: `php artisan test tests/Unit/Services/PropertyManagerContactServiceTest.php`
Expected: Alle 5 Tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/PropertyManagerContactService.php tests/Unit/Services/PropertyManagerContactServiceTest.php
git commit -m "feat(hv): PropertyManagerContactService with templates + KI-summary"
```

---

## Task 3: API — contact_property_manager (Draft-Generation)

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`

- [ ] **Step 1: Action im Dispatcher registrieren**

In `app/Http/Controllers/Admin/AdminApiController.php`, suche den Phase-1-Block und ergänze:

```php
            // Hausverwaltung (Phase 1 — Core CRUD + Assignment)
            'list_property_managers'    => $this->listPropertyManagers($request),
            // ... (bestehende Phase-1-Aktionen) ...
            'upload_ava'                => $this->uploadAva($request),

            // Hausverwaltung (Phase 2 — Contact Flows)
            'contact_property_manager'  => $this->contactPropertyManager($request),
            'send_to_manager'           => $this->sendToManager($request),
```

- [ ] **Step 2: Methode implementieren**

Am Ende der Klasse (vor `}`) anhängen:

```php
    private function contactPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $templateKind = (string) ($data['template_kind'] ?? '');
        $sourceEmailId = isset($data['source_email_id']) && $data['source_email_id'] ? (int) $data['source_email_id'] : null;

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if (!in_array($templateKind, ['unterlagen', 'mieter_meldung', 'freitext'], true)) {
            return response()->json(['success' => false, 'error' => 'invalid template_kind'], 400);
        }

        // Ownership check
        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property) return response()->json(['success' => false, 'error' => 'property not found'], 404);
        if (!$property->property_manager_id) {
            return response()->json(['success' => false, 'error' => 'Keine Hausverwaltung zugeordnet', 'needs_manager' => true], 422);
        }

        $manager = \App\Models\PropertyManager::find($property->property_manager_id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'property_manager_id verweist auf nicht existierende HV'], 500);

        $sourceEmail = $sourceEmailId ? \App\Models\PortalEmail::find($sourceEmailId) : null;
        if ($templateKind === 'mieter_meldung' && !$sourceEmail) {
            return response()->json(['success' => false, 'error' => 'mieter_meldung template requires source_email_id'], 400);
        }

        $maklerUser = \Auth::user();

        try {
            $svc = app(\App\Services\PropertyManagerContactService::class);
            $draft = $svc->buildDraft($property, $manager, $templateKind, $sourceEmail, $maklerUser);
        } catch (\Throwable $e) {
            \Log::error('contactPropertyManager failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Entwurf-Generierung fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'draft' => [
                'to' => $manager->email,
                'to_name' => $manager->company_name,
                'subject' => $draft['subject'],
                'body' => $draft['body'],
                'attachments' => $draft['attachments'],
            ],
            'ava_missing' => $draft['ava_missing'],
            'manager' => [
                'id' => $manager->id,
                'company_name' => $manager->company_name,
                'email' => $manager->email,
            ],
        ]);
    }
```

- [ ] **Step 3: Lint + Smoketest**

Run: `php -l app/Http/Controllers/Admin/AdminApiController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php
git commit -m "feat(hv): contact_property_manager endpoint — draft generation"
```

---

## Task 4: API — send_to_manager (Actual Send)

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`

- [ ] **Step 1: Methode hinzufügen**

Am Ende von AdminApiController (vor `}`) anhängen:

```php
    private function sendToManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $attachmentFileIds = is_array($data['attachment_file_ids'] ?? null) ? $data['attachment_file_ids'] : [];
        $sourceEmailId = isset($data['source_email_id']) && $data['source_email_id'] ? (int) $data['source_email_id'] : null;

        if (!$propertyId || $subject === '' || $body === '') {
            return response()->json(['success' => false, 'error' => 'property_id, subject, body required'], 400);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property || !$property->property_manager_id) {
            return response()->json(['success' => false, 'error' => 'Property oder HV nicht gefunden'], 404);
        }
        $manager = \App\Models\PropertyManager::find($property->property_manager_id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'HV nicht gefunden'], 404);

        // Versand via EmailService (bestehende Pipeline wiederverwenden)
        try {
            $emailService = app(\App\Services\EmailService::class);

            // Attachments aufbereiten (file_path-Array)
            $attachmentPaths = [];
            if (!empty($attachmentFileIds)) {
                $files = \DB::table('property_files')
                    ->whereIn('id', $attachmentFileIds)
                    ->where('property_id', $propertyId)
                    ->get();
                foreach ($files as $f) {
                    $absPath = storage_path('app/public/' . $f->path);
                    if (is_file($absPath)) {
                        $attachmentPaths[] = [
                            'path' => $absPath,
                            'filename' => $f->filename,
                            'mime' => $f->mime_type ?? 'application/octet-stream',
                        ];
                    }
                }
            }

            // Account fuer Versand: erstes aktives Account des Users
            $accountId = \DB::table('email_accounts')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->value('id');
            if (!$accountId) {
                return response()->json(['success' => false, 'error' => 'Kein aktives E-Mail-Konto fuer diesen User'], 500);
            }

            $sentEmailId = $emailService->sendEmail(
                accountId: (int) $accountId,
                toEmail: $manager->email,
                subject: $subject,
                bodyText: $body,
                propertyId: $propertyId,
                stakeholder: $manager->company_name,
                attachments: $attachmentPaths,
                sourceEmailId: $sourceEmailId,
            );
        } catch (\Throwable $e) {
            \Log::error('sendToManager failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Versand fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        // Activity-Log als hausverwaltung-Eintrag
        try {
            \DB::table('activities')->insert([
                'property_id' => $propertyId,
                'activity_date' => now()->toDateString(),
                'stakeholder' => $manager->company_name,
                'activity' => 'An Hausverwaltung gesendet: ' . mb_substr($subject, 0, 200),
                'category' => 'hausverwaltung',
                'source_email_id' => $sentEmailId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('sendToManager activity log failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'email_id' => $sentEmailId]);
    }
```

- [ ] **Step 2: EmailService::sendEmail signature prüfen**

Run: `grep -n "function sendEmail" app/Services/EmailService.php`

Check dass `sendEmail` existiert und etwa diese Signatur hat:

```php
public function sendEmail(
    int $accountId, string $toEmail, string $subject, string $bodyText,
    ?int $propertyId = null, ?string $stakeholder = null,
    array $attachments = [], ?int $sourceEmailId = null
): ?int
```

Falls die Signatur in der Realität abweicht (unterschiedliche Parameter-Namen oder andere Defaults), passe den Aufruf entsprechend an. Die Named-Argument-Syntax (`accountId: ...`) nutzt PHP 8.0+ Features, einfach an die tatsächliche EmailService-API anpassen.

Wenn der Parameter `attachments` vom EmailService nur Paths als string erwartet, dann mappe:

```php
$pathsOnly = array_column($attachmentPaths, 'path');
```

und gib das statt des assoziativen Arrays.

- [ ] **Step 3: Lint**

Run: `php -l app/Http/Controllers/Admin/AdminApiController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php
git commit -m "feat(hv): send_to_manager endpoint + activity log"
```

---

## Task 5: conv_detail Response ergänzen um HV-Infos

**Files:**
- Modify: `app/Http/Controllers/Admin/ConversationController.php`

- [ ] **Step 1: Den Conv-Detail-Response lokalisieren**

Run: `grep -n "public function detail" app/Http/Controllers/Admin/ConversationController.php`

Suche die Methode `detail(Request $request)`. Darin werden die Conversation-Daten zusammengestellt und als JSON zurückgegeben.

- [ ] **Step 2: Im Response-Array zusätzliche Felder ergänzen**

Innerhalb der `detail`-Methode, wo die Response gebaut wird (typischerweise wird ein Array gebaut und am Ende `return response()->json(...)` gerufen), ergänze — wenn ein `$conv->property_id` existiert — folgendes:

```php
        // HV-Info direkt mitgeben damit InboxChatView ohne Extra-Call weiss
        // ob die Conversation's Property eine HV hat und welche E-Mail die HV hat.
        $propertyManagerId = null;
        $propertyManagerName = null;
        $propertyManagerEmail = null;
        if ($conv->property_id) {
            $propInfo = DB::table('properties as p')
                ->leftJoin('property_managers as pm', 'p.property_manager_id', '=', 'pm.id')
                ->where('p.id', $conv->property_id)
                ->select('p.property_manager_id', 'pm.company_name', 'pm.email')
                ->first();
            if ($propInfo) {
                $propertyManagerId = $propInfo->property_manager_id ? (int) $propInfo->property_manager_id : null;
                $propertyManagerName = $propInfo->company_name;
                $propertyManagerEmail = $propInfo->email;
            }
        }
```

Und im Return-Array:

```php
            'property_manager_id' => $propertyManagerId,
            'property_manager_name' => $propertyManagerName,
            'property_manager_email' => $propertyManagerEmail,
```

(Exakte Position hängt vom bestehenden Response-Shape ab — sie muss auf derselben Ebene landen wie `property_id`, `ref_id`, usw.)

- [ ] **Step 3: Lint**

Run: `php -l app/Http/Controllers/Admin/ConversationController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/ConversationController.php
git commit -m "feat(hv): include property_manager info in conv_detail response"
```

---

## Task 6: Vue — MissingAvaDialog

**Files:**
- Create: `resources/js/Components/Admin/property-detail/MissingAvaDialog.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/property-detail/MissingAvaDialog.vue`:

```vue
<script setup>
import { ref, watch } from 'vue'
import { Paperclip, X } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  uploading: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'upload', 'skip'])

const fileInput = ref(null)
const chosenFile = ref(null)

watch(() => props.open, (isOpen) => {
  if (isOpen) chosenFile.value = null
})

function onFileChange(e) {
  const file = e.target.files?.[0]
  if (file) chosenFile.value = file
}

function onUploadClick() {
  if (!chosenFile.value) {
    fileInput.value?.click()
    return
  }
  emit('upload', chosenFile.value)
}

function onSkipClick() {
  emit('skip')
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Alleinvermittlungsauftrag fehlt</DialogTitle>
        <DialogDescription>
          Für dieses Template brauchen wir den unterzeichneten Alleinvermittlungsauftrag als Anhang.
        </DialogDescription>
      </DialogHeader>

      <div class="py-4">
        <label for="ava-upload"
               class="flex flex-col items-center justify-center py-8 px-4 border-2 border-dashed border-[#fed7aa] bg-[#fff7ed] rounded-lg cursor-pointer hover:bg-[#ffedd5] transition-colors">
          <Paperclip class="w-8 h-8 text-[#EE7600] mb-2" />
          <div v-if="!chosenFile" class="text-sm font-semibold text-[#7c2d12]">PDF hier ablegen oder klicken</div>
          <div v-if="!chosenFile" class="text-xs text-[#a16207] mt-1">Max. 10 MB</div>
          <div v-else class="text-sm font-medium text-[#7c2d12] truncate max-w-full">{{ chosenFile.name }}</div>
          <div v-if="chosenFile" class="text-xs text-[#a16207] mt-1">
            {{ Math.round(chosenFile.size / 1024) }} KB — Klick zum Ändern
          </div>
          <input ref="fileInput" id="ava-upload" type="file" accept="application/pdf" class="hidden" @change="onFileChange" />
        </label>
      </div>

      <DialogFooter class="flex-col sm:flex-row gap-2">
        <Button variant="ghost" size="sm" @click="onSkipClick" :disabled="uploading">Ohne Anhang senden</Button>
        <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="onUploadClick" :disabled="uploading">
          <span v-if="uploading">Lädt hoch…</span>
          <span v-else-if="chosenFile">Hochladen &amp; senden</span>
          <span v-else>Datei wählen</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: Build ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/property-detail/MissingAvaDialog.vue
git commit -m "feat(hv): MissingAvaDialog for AVA-fehlt-flow"
```

---

## Task 7: Vue — ContactManagerSheet

**Files:**
- Create: `resources/js/Components/Admin/property-detail/ContactManagerSheet.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/property-detail/ContactManagerSheet.vue`:

```vue
<script setup>
import { ref, inject, computed } from 'vue'
import { FileText, AlertTriangle, Pencil, X, ArrowRight } from 'lucide-vue-next'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import MissingAvaDialog from './MissingAvaDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  // Ein Sheet für direkten Property-Detail-Aufruf (nur unterlagen + freitext)
  // ODER nach Mieter-Forward-Flow (nur mieter_meldung auto-triggered)
  availableTemplates: { type: Array, default: () => ['unterlagen', 'freitext'] },
  // Für mieter_meldung Modus nötig
  sourceEmailId: { type: [Number, String, null], default: null },
})

const emit = defineEmits(['update:open', 'draft-ready'])

const loading = ref(false)
const avaDialogOpen = ref(false)
const pendingDraft = ref(null) // merkt sich den Draft falls AVA-Upload nötig

async function pickTemplate(kind) {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=contact_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: props.propertyId,
        template_kind: kind,
        source_email_id: props.sourceEmailId,
      }),
    })
    const d = await r.json()
    if (!d.success) {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
      loading.value = false
      return
    }

    if (d.ava_missing) {
      pendingDraft.value = { ...d.draft, manager: d.manager }
      avaDialogOpen.value = true
      loading.value = false
      return
    }

    // Draft bereit, Sheet schließen und an Parent emiten
    emit('draft-ready', { ...d.draft, manager: d.manager })
    emit('update:open', false)
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}

async function onAvaUpload(file) {
  const fd = new FormData()
  fd.append('property_id', String(props.propertyId))
  fd.append('file', file)

  try {
    const r = await fetch(API.value + '&action=upload_ava', { method: 'POST', body: fd })
    const d = await r.json()
    if (!d.success) {
      toast('Upload fehlgeschlagen: ' + (d.error || 'Unbekannt'))
      return
    }
    // Nach erfolgreichem Upload: Template nochmal mit frischer AVA aufrufen
    avaDialogOpen.value = false
    await pickTemplate('unterlagen')
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

function onAvaSkip() {
  // Ohne Anhang senden: Draft wie er ist raushauen (attachments bleibt leer)
  if (pendingDraft.value) {
    emit('draft-ready', pendingDraft.value)
    emit('update:open', false)
    pendingDraft.value = null
  }
}
</script>

<template>
  <Sheet :open="open" @update:open="emit('update:open', $event)">
    <SheetContent side="right" class="w-full sm:max-w-md bg-white dark:bg-zinc-950">
      <SheetHeader>
        <SheetTitle>Hausverwaltung kontaktieren</SheetTitle>
        <SheetDescription>Vorgefertigtes Anschreiben oder leeren Entwurf wählen.</SheetDescription>
      </SheetHeader>

      <div class="mt-6 space-y-3">
        <button v-if="availableTemplates.includes('unterlagen')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading" @click="pickTemplate('unterlagen')">
          <div class="w-10 h-10 rounded-lg bg-[#fff7ed] flex items-center justify-center shrink-0">
            <FileText class="w-5 h-5 text-[#EE7600]" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Unterlagen anfordern</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Betriebskostenabrechnung, Nutzwertgutachten, Pläne, Energieausweis, Rücklagenstand u. a.
            </div>
            <div class="text-[10px] text-[#c2410c] mt-1.5 font-medium">Anhang: Alleinvermittlungsauftrag</div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button v-if="availableTemplates.includes('mieter_meldung')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading || !sourceEmailId" @click="pickTemplate('mieter_meldung')">
          <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
            <AlertTriangle class="w-5 h-5 text-amber-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Mieter-Meldung weiterleiten</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              KI fasst die Original-Mail zusammen und bereitet ein höfliches Anschreiben vor.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button v-if="availableTemplates.includes('freitext')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading" @click="pickTemplate('freitext')">
          <div class="w-10 h-10 rounded-lg bg-muted flex items-center justify-center shrink-0">
            <Pencil class="w-5 h-5 text-muted-foreground" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Freitext</div>
            <div class="text-xs text-muted-foreground mt-0.5">Leeren Entwurf starten — HV-Empfänger ist bereits eingesetzt.</div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>
      </div>

      <MissingAvaDialog
        v-model:open="avaDialogOpen"
        :property-id="propertyId"
        @upload="onAvaUpload"
        @skip="onAvaSkip"
      />
    </SheetContent>
  </Sheet>
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: Build ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/property-detail/ContactManagerSheet.vue
git commit -m "feat(hv): ContactManagerSheet with 3 template cards + AVA-check"
```

---

## Task 8: Integration — OverviewTab "HV kontaktieren" Button

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/OverviewTab.vue`

- [ ] **Step 1: Button + Sheet einbinden**

Zuerst grep wo es im OverviewTab sinnvoll passt (z.B. neben bestehenden Aktionen für das Property):

Run: `grep -n "<template>\|<button\|Eigentümer\|<Card" resources/js/Components/Admin/property-detail/OverviewTab.vue | head -15`

Finde eine passende Stelle (z.B. eine existierende Action-Leiste oder direkt nach dem Titel). Ergänze dort:

```vue
          <!-- HV kontaktieren -->
          <Button
            v-if="form && form.property_manager_id"
            size="sm"
            variant="outline"
            class="bg-[#fff7ed] text-[#c2410c] border-[#fed7aa] hover:bg-[#ffedd5]"
            @click="contactSheetOpen = true"
          >
            <Mail class="w-3.5 h-3.5 mr-1.5" />
            Hausverwaltung kontaktieren
          </Button>

          <ContactManagerSheet
            v-model:open="contactSheetOpen"
            :property-id="form.id"
            :available-templates="['unterlagen', 'freitext']"
            @draft-ready="onHvDraftReady"
          />
```

Im `<script setup>` ergänzen:

```javascript
import ContactManagerSheet from './ContactManagerSheet.vue'
import { Mail } from 'lucide-vue-next'
import { ref } from 'vue'

const contactSheetOpen = ref(false)

function onHvDraftReady(draft) {
  // Option 1: Neue Compose-View öffnen mit dem Draft
  // Option 2: Direkt per send_to_manager absenden (weniger Kontrolle für User)
  //
  // Wir wählen Option 1 via ein globales Event, das InboxTab oder der
  // Property-Detail-Page abfängt und einen Compose-Dialog öffnet.
  window.dispatchEvent(new CustomEvent('open-hv-compose', {
    detail: {
      property_id: form.value?.id ?? form.id,
      manager: draft.manager,
      subject: draft.subject,
      body: draft.body,
      attachments: draft.attachments || [],
    },
  }))
}
```

Achtung — `form` ist ggf. als prop oder ref verfügbar. Passe den Zugriff (`form.value?.id` vs. `form.id`) an den konkreten Context an, der in OverviewTab.vue vorliegt.

Die globale `open-hv-compose`-Event-Strategie vermeidet tiefe Verdrahtung. Das Event wird in Task 11 (HvComposeDialog) abgefangen.

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: Build ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/property-detail/OverviewTab.vue
git commit -m "feat(hv): add 'Hausverwaltung kontaktieren' button to property OverviewTab"
```

---

## Task 9: Vue — MissingManagerDialog

**Files:**
- Create: `resources/js/Components/Admin/inbox/MissingManagerDialog.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/inbox/MissingManagerDialog.vue`:

```vue
<script setup>
import { inject } from 'vue'
import HausverwaltungFormDialog from '../HausverwaltungFormDialog.vue'
import { ref } from 'vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  propertyLabel: { type: String, default: '' }, // z.B. "Kau-Hau-Ste-01 (Weiherweg 2, Grödig)"
})
const emit = defineEmits(['update:open', 'assigned'])

const saving = ref(false)

async function onSave(payload) {
  saving.value = true
  try {
    const r = await fetch(API.value + '&action=quick_create_and_assign_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, ...payload }),
    })
    const d = await r.json()
    if (d.success) {
      toast('Hausverwaltung angelegt')
      emit('assigned', {
        id: d.manager.id,
        company_name: d.manager.company_name,
        email: d.manager.email,
      })
      emit('update:open', false)
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <HausverwaltungFormDialog
    :open="open"
    :manager="null"
    :saving="saving"
    @update:open="emit('update:open', $event)"
    @save="onSave"
  />
</template>
```

Die Komponente wrappt den bestehenden `HausverwaltungFormDialog` — nutzt ihn für Create + schreibt zusätzlich das Ergebnis direkt als Assignment via `quick_create_and_assign_property_manager` Endpoint. `propertyLabel` ist nicht genutzt in diesem simplen Wrap, aber als Prop vorbereitet falls wir später den Header erweitern wollen.

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/inbox/MissingManagerDialog.vue
git commit -m "feat(hv): MissingManagerDialog (wraps HV-FormDialog with auto-assign)"
```

---

## Task 10: Vue — ForwardToManagerButton

**Files:**
- Create: `resources/js/Components/Admin/inbox/ForwardToManagerButton.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/inbox/ForwardToManagerButton.vue`:

```vue
<script setup>
import { ref, inject, computed } from 'vue'
import { Building2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import MissingManagerDialog from './MissingManagerDialog.vue'
import MissingAvaDialog from '../property-detail/MissingAvaDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  // Conversation item aus InboxChatView (enthält property_id, property_manager_id, etc.)
  item: { type: Object, required: true },
  // email_id der konkreten Mieter-Mail (letzte Inbound-Nachricht oder latest message)
  sourceEmailId: { type: [Number, String, null], default: null },
})

const missingMgrOpen = ref(false)
const missingAvaOpen = ref(false)
const loading = ref(false)

const propertyId = computed(() => Number(props.item?.property_id || 0))
const hasManager = computed(() => !!Number(props.item?.property_manager_id || 0))
const isVisible = computed(() => propertyId.value > 0)

async function onClick() {
  if (!propertyId.value) return

  if (!hasManager.value) {
    missingMgrOpen.value = true
    return
  }

  await triggerDraft()
}

async function onManagerAssigned(manager) {
  // Assignment ist durch (MissingManagerDialog speichert via Endpoint)
  // Lokal das Item aktualisieren damit hasManager true wird
  props.item.property_manager_id = manager.id
  missingMgrOpen.value = false
  await triggerDraft()
}

async function triggerDraft() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=contact_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: propertyId.value,
        template_kind: 'mieter_meldung',
        source_email_id: props.sourceEmailId,
      }),
    })
    const d = await r.json()
    if (!d.success) {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
      return
    }

    // mieter_meldung hat nie ava_missing, aber zur Sicherheit prüfen
    if (d.ava_missing) {
      toast('Unerwarteter ava_missing bei mieter_meldung — Entwurf trotzdem vorhanden')
    }

    // Draft bereit — an HvComposeDialog übergeben via globalem Event
    window.dispatchEvent(new CustomEvent('open-hv-compose', {
      detail: {
        property_id: propertyId.value,
        manager: d.manager,
        subject: d.draft.subject,
        body: d.draft.body,
        attachments: d.draft.attachments || [],
        source_email_id: props.sourceEmailId,
      },
    }))
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Button v-if="isVisible"
          variant="outline" size="sm"
          class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white border-0 font-semibold shadow-sm"
          :disabled="loading" @click="onClick" title="Mieter-Meldung an Hausverwaltung weiterleiten">
    <Building2 class="w-3.5 h-3.5 mr-1.5" />
    {{ loading ? 'Lädt…' : 'An HV weiterleiten' }}
  </Button>

  <MissingManagerDialog
    v-model:open="missingMgrOpen"
    :property-id="propertyId"
    @assigned="onManagerAssigned"
  />
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/inbox/ForwardToManagerButton.vue
git commit -m "feat(hv): ForwardToManagerButton (inbox → HV mit missing-HV-flow)"
```

---

## Task 11: Vue — HvComposeDialog (Review-Compose-Fenster)

**Files:**
- Create: `resources/js/Components/Admin/inbox/HvComposeDialog.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/inbox/HvComposeDialog.vue`:

```vue
<script setup>
import { ref, inject, onMounted, onBeforeUnmount } from 'vue'
import { Send, X, Paperclip } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const API = inject('API')
const toast = inject('toast', () => {})

const open = ref(false)
const sending = ref(false)
const draft = ref({
  property_id: null,
  manager: null,
  to: '',
  subject: '',
  body: '',
  attachments: [],
  source_email_id: null,
})

function handleOpenEvent(ev) {
  const d = ev.detail || {}
  draft.value = {
    property_id: d.property_id,
    manager: d.manager,
    to: d.manager?.email || '',
    subject: d.subject || '',
    body: d.body || '',
    attachments: d.attachments || [],
    source_email_id: d.source_email_id || null,
  }
  open.value = true
}

onMounted(() => window.addEventListener('open-hv-compose', handleOpenEvent))
onBeforeUnmount(() => window.removeEventListener('open-hv-compose', handleOpenEvent))

async function onSend() {
  if (sending.value) return
  if (!draft.value.subject.trim() || !draft.value.body.trim()) {
    toast('Betreff und Nachricht sind erforderlich')
    return
  }
  sending.value = true
  try {
    const r = await fetch(API.value + '&action=send_to_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: draft.value.property_id,
        subject: draft.value.subject,
        body: draft.value.body,
        attachment_file_ids: draft.value.attachments,
        source_email_id: draft.value.source_email_id,
      }),
    })
    const d = await r.json()
    if (d.success) {
      toast('An Hausverwaltung gesendet')
      open.value = false
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    sending.value = false
  }
}

function onCancel() { open.value = false }
</script>

<template>
  <Dialog :open="open" @update:open="open = $event">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>An Hausverwaltung senden</DialogTitle>
        <DialogDescription>
          Entwurf vor dem Senden prüfen. Der Versand erstellt einen neuen HV-Thread.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3 py-2">
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">An</label>
          <Input :model-value="draft.to + ' (' + (draft.manager?.company_name || '') + ')'" disabled />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Betreff</label>
          <Input v-model="draft.subject" placeholder="Betreff eintragen" />
        </div>
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Nachricht</label>
          <textarea v-model="draft.body" rows="12"
                    class="w-full text-sm rounded-md border border-input px-3 py-2 bg-background font-sans leading-relaxed"
                    placeholder="Nachricht"></textarea>
        </div>

        <div v-if="draft.attachments.length" class="text-xs text-muted-foreground flex items-center gap-2">
          <Paperclip class="w-3.5 h-3.5" />
          <span>{{ draft.attachments.length }} Anhang (Alleinvermittlungsauftrag)</span>
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="onCancel" :disabled="sending">Abbrechen</Button>
        <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white"
                @click="onSend" :disabled="sending">
          <Send class="w-3.5 h-3.5 mr-1.5" />
          <span v-if="sending">Sende…</span>
          <span v-else>Senden</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build 2>&1 | tail -3`
Expected: ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/inbox/HvComposeDialog.vue
git commit -m "feat(hv): HvComposeDialog listens for open-hv-compose event, sends via send_to_manager"
```

---

## Task 12: Integration in InboxChatView + InboxTab

**Files:**
- Modify: `resources/js/Components/Admin/inbox/InboxChatView.vue`
- Modify: `resources/js/Components/Admin/InboxTab.vue`

- [ ] **Step 1: ForwardToManagerButton in InboxChatView footer**

In `resources/js/Components/Admin/inbox/InboxChatView.vue`, imports:

```javascript
import ForwardToManagerButton from './ForwardToManagerButton.vue'
```

Im Thread-Footer-Bereich (dort wo aktuell schon `Antworten`, `Weiterleiten`, `Erledigt` stehen):

```vue
      <Button variant="outline" size="sm" @click="enterCompose('forward', false)" title="Weiterleiten">
        <Forward class="w-3.5 h-3.5 mr-1" />
        Weiterleiten
      </Button>
      <ForwardToManagerButton :item="item" :source-email-id="latestInbound()?.id || flatMessages[0]?.id" />
      <Button variant="ghost" size="sm" class="sr-done-btn" @click="emit('markHandled')">
```

Das `latestInbound()` liefert die neueste eingehende Mail in der Thread-Liste. Für Source-Email-ID verwenden — Fallback auf `flatMessages[0]` (erstes Element, das ist meist das neueste).

- [ ] **Step 2: HvComposeDialog global in InboxTab mounten**

In `resources/js/Components/Admin/InboxTab.vue`, imports:

```javascript
import HvComposeDialog from "@/Components/Admin/inbox/HvComposeDialog.vue";
```

Im Template am Ende (auf demselben Level wie andere Modal-Dialoge) einfügen:

```vue
    <HvComposeDialog />
```

Der Dialog hört selbst auf das globale `open-hv-compose`-Event, deshalb muss er nur einmal gemounted werden.

- [ ] **Step 3: Build + Smoketest lokal**

Run: `npm run build 2>&1 | tail -3`
Expected: ok.

Manual:
- [ ] Inbox öffnen, eine Mieter-Mail auswählen, Thread-Footer zeigt oranger „An HV weiterleiten"-Button
- [ ] Property mit HV: Klick → HvComposeDialog öffnet mit vorgefertigtem Entwurf
- [ ] Property ohne HV: Klick → MissingManagerDialog öffnet → Firma + E-Mail eintragen → Anlegen → HV wird angelegt + zugewiesen + HvComposeDialog öffnet
- [ ] Im HvComposeDialog: Entwurf bearbeiten → Senden → Toast „An Hausverwaltung gesendet"
- [ ] In Activities am Property erscheint neuer Eintrag mit category=hausverwaltung

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/inbox/InboxChatView.vue resources/js/Components/Admin/InboxTab.vue
git commit -m "feat(hv): integrate ForwardToManagerButton + HvComposeDialog in Inbox"
```

---

## Task 13: Deploy

- [ ] **Step 1: Lint**

Run: `php -l app/Http/Controllers/Admin/AdminApiController.php app/Http/Controllers/Admin/ConversationController.php app/Services/PropertyManagerContactService.php`
Expected: `No syntax errors detected` für alle.

- [ ] **Step 2: Tests**

Run: `php artisan test tests/Unit/Services/PropertyManagerContactServiceTest.php tests/Feature/PropertyManagerApiTest.php`
Expected: Alle PASS.

- [ ] **Step 3: Frontend Build**

Run: `npm run build`
Expected: built successfully.

- [ ] **Step 4: Push**

```bash
git push origin main
```

- [ ] **Step 5: Deploy Production**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"
```

- [ ] **Step 6: Production-Smoketest**

```bash
ssh root@187.124.166.153 "mysql --no-defaults -u srhomes -p'SRH_db_2026!portal' srhomes_portal -e \"SHOW COLUMNS FROM activities LIKE 'category';\" 2>&1 | grep hausverwaltung"
```
Expected: Zeile enthält `hausverwaltung` im ENUM.

- [ ] **Step 7: Browser-Smoketest**

- [ ] Login https://kundenportal.sr-homes.at/admin
- [ ] Property-Detail öffnen das eine HV hat → „Hausverwaltung kontaktieren"-Button klicken → Sheet öffnet → „Unterlagen anfordern" → Compose öffnet mit Template-Body + AVA-Anhang (oder MissingAva-Popup)
- [ ] Inbox → Mieter-Mail öffnen → oranger „An HV weiterleiten"-Button klicken → HvComposeDialog mit KI-Entwurf → Senden
- [ ] Gesendet-Ordner: Die Mail ist da
- [ ] Property-Detail → Aktivitäten: neuer Eintrag mit category=hausverwaltung

---

## Self-Review

**1. Spec coverage:**
- ✅ `PropertyManagerContactService` mit allen 3 Templates → Task 2
- ✅ Template 1 (Unterlagen): erweiterte Liste inkl. Nutzwertgutachten, Pläne, Energieausweis, Rücklagenstand → Task 2 buildUnterlagenDraft
- ✅ Template 2 (Mieter-Meldung): KI-Summary mit Schlagwort → Task 2 summarizeIssueViaAi
- ✅ Template 3 (Freitext): leerer Entwurf → Task 2
- ✅ `contact_property_manager` Endpoint mit `needs_manager` + `ava_missing` Flags → Task 3
- ✅ `send_to_manager` Endpoint mit EmailService + Activity-Log → Task 4
- ✅ Conv-Detail Response um property_manager_id/name/email ergänzt → Task 5
- ✅ `MissingAvaDialog` mit Upload + „ohne Anhang senden" → Task 6
- ✅ `ContactManagerSheet` mit 3 Template-Karten → Task 7
- ✅ „HV kontaktieren"-Button im OverviewTab → Task 8
- ✅ `MissingManagerDialog` als Wrapper von HausverwaltungFormDialog → Task 9
- ✅ `ForwardToManagerButton` orange im Inbox-Footer → Task 10
- ✅ `HvComposeDialog` Review-Compose-Fenster → Task 11
- ✅ Integration beider in InboxChatView + InboxTab → Task 12
- ✅ Activity-Category Enum `hausverwaltung` Migration → Task 1

**2. Placeholder scan:** Keine TBDs/TODOs, alle Code-Blöcke vollständig mit Snippets.

**3. Type consistency:**
- `property_manager_id`, `email`, `company_name` konsistent über Backend + Frontend
- `template_kind` Enum-Werte konsistent: `unterlagen`, `mieter_meldung`, `freitext`
- `source_email_id` Typ int|null konsistent
- Event `open-hv-compose` Detail-Shape konsistent (property_id, manager, subject, body, attachments, source_email_id) zwischen Sender (ContactManagerSheet, ForwardToManagerButton) und Listener (HvComposeDialog).
