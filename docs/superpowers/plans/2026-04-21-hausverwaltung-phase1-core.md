# Hausverwaltung Phase 1 — Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zentrale Hausverwaltungs-Verwaltung — Datenmodell, CRUD-APIs, Admin → Kontakte-Sub-Tab „Hausverwaltungen", Property-Edit mit HV-Picker statt String-Feld. Foundation für Phase 2 (Kontakt-Flows).

**Architecture:** Neue Laravel-Tabelle `property_managers` mit Eloquent-Model. Neue Actions im bestehenden `AdminApiController`-Dispatcher. Drei neue Vue-Komponenten (HausverwaltungenTab, HausverwaltungFormDialog, PropertyManagerPicker). String-Feld `properties.property_manager` bleibt aus Legacy-Gründen bestehen und wird bei Zuweisung synchronisiert.

**Tech Stack:** Laravel 11 + PHP 8.2, MySQL 8, Vue 3 + shadcn-vue Components (Dialog, Input, Button), Tailwind, Inertia.

**Spec:** [`docs/superpowers/specs/2026-04-21-hausverwaltung-design.md`](../specs/2026-04-21-hausverwaltung-design.md)

---

## File Structure

**Backend (create):**
- `database/migrations/2026_04_21_150000_create_property_managers_table.php`
- `database/migrations/2026_04_21_150100_add_property_manager_id_to_properties.php`
- `database/migrations/2026_04_21_150200_add_is_ava_to_property_files.php`
- `app/Models/PropertyManager.php`

**Backend (modify):**
- `app/Http/Controllers/Admin/AdminApiController.php` — neue Actions (list/create/update/delete/assign/quick_create_and_assign + upload_ava)

**Frontend (create):**
- `resources/js/Components/Admin/HausverwaltungFormDialog.vue` — wiederverwendbarer Dialog für Create/Edit/Quick-Create
- `resources/js/Components/Admin/HausverwaltungenTab.vue` — Sub-Tab mit Tabelle, Suche, CRUD
- `resources/js/Components/Admin/property-detail/PropertyManagerPicker.vue` — Autocomplete-Picker

**Frontend (modify):**
- `resources/js/Components/Admin/AdminTab.vue` — vierter Sub-Tab hinzufügen
- `resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue` — String-Input durch Picker ersetzen

**Tests:**
- `tests/Feature/PropertyManagerApiTest.php`
- `tests/Unit/Models/PropertyManagerTest.php` (optional, nur wenn Model-Logik entsteht)

---

## Task 1: DB Migration + PropertyManager Model

**Files:**
- Create: `database/migrations/2026_04_21_150000_create_property_managers_table.php`
- Create: `database/migrations/2026_04_21_150100_add_property_manager_id_to_properties.php`
- Create: `database/migrations/2026_04_21_150200_add_is_ava_to_property_files.php`
- Create: `app/Models/PropertyManager.php`

- [ ] **Step 1: property_managers Migration anlegen**

Create `database/migrations/2026_04_21_150000_create_property_managers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_managers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('address_street')->nullable();
            $table->string('address_zip', 20)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('email');
            $table->string('phone', 100)->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_name');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_managers');
    }
};
```

- [ ] **Step 2: properties.property_manager_id FK Migration**

Create `database/migrations/2026_04_21_150100_add_property_manager_id_to_properties.php`:

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
            $table->foreignId('property_manager_id')
                ->nullable()
                ->after('property_manager')
                ->constrained('property_managers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['property_manager_id']);
            $table->dropColumn('property_manager_id');
        });
    }
};
```

- [ ] **Step 3: property_files.is_ava Migration mit Backfill**

Create `database/migrations/2026_04_21_150200_add_is_ava_to_property_files.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            $table->boolean('is_ava')->default(false)->after('is_website_download');
            $table->index('is_ava');
        });

        // Backfill: existing AVA-labeled files
        DB::table('property_files')
            ->where(function ($q) {
                $q->where('label', 'like', '%Alleinvermittlungsauftrag%')
                  ->orWhere('label', 'like', '%AVA%')
                  ->orWhere('label', 'like', '%Alleinvermittler%');
            })
            ->update(['is_ava' => 1]);
    }

    public function down(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            $table->dropIndex(['is_ava']);
            $table->dropColumn('is_ava');
        });
    }
};
```

- [ ] **Step 4: Migrations laufen lassen**

Run: `php artisan migrate`
Expected: Drei neue Zeilen „Migrated" für die drei Migrations.

- [ ] **Step 5: PropertyManager Model anlegen**

Create `app/Models/PropertyManager.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyManager extends Model
{
    protected $fillable = [
        'company_name',
        'address_street',
        'address_zip',
        'address_city',
        'email',
        'phone',
        'contact_person',
        'notes',
        'created_by',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 6: Verify model loads**

Run: `php artisan tinker --execute="echo \App\Models\PropertyManager::count();"`
Expected: `0`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_21_150000_create_property_managers_table.php \
        database/migrations/2026_04_21_150100_add_property_manager_id_to_properties.php \
        database/migrations/2026_04_21_150200_add_is_ava_to_property_files.php \
        app/Models/PropertyManager.php
git commit -m "feat(hv): add property_managers table, FK, is_ava flag + backfill"
```

---

## Task 2: API — list_property_managers

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Create: `tests/Feature/PropertyManagerApiTest.php`

- [ ] **Step 1: Test schreiben**

Create `tests/Feature/PropertyManagerApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyManagerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_all_managers_with_property_count(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $manager = PropertyManager::create([
            'company_name' => 'ImmoFirst',
            'email' => 'office@immofirst.at',
        ]);

        Property::factory()->create(['property_manager_id' => $manager->id]);
        Property::factory()->create(['property_manager_id' => $manager->id]);

        $res = $this->postJson('/api/admin_api.php?action=list_property_managers&key=' . config('portal.api_key'));
        $res->assertOk();
        $res->assertJsonStructure(['success', 'managers' => [['id', 'company_name', 'email', 'property_count']]]);
        $data = $res->json('managers');
        $this->assertCount(1, $data);
        $this->assertSame(2, $data[0]['property_count']);
    }

    public function test_list_requires_auth(): void
    {
        $res = $this->postJson('/api/admin_api.php?action=list_property_managers&key=' . config('portal.api_key'));
        $res->assertStatus(401);
    }
}
```

- [ ] **Step 2: Test läuft → FAIL**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: FAIL „action not recognized" oder ähnlich.

- [ ] **Step 3: Action in AdminApiController dispatcher registrieren**

In `app/Http/Controllers/Admin/AdminApiController.php`, suche den `match ($action)`-Block (ca. Zeile 80). Füge im passenden Bereich hinzu (nach den snooze_followup-Zeilen, vor Tagesbriefing):

```php
            // Hausverwaltung (Phase 1)
            'list_property_managers'   => $this->listPropertyManagers($request),
            'create_property_manager'  => $this->createPropertyManager($request),
            'update_property_manager'  => $this->updatePropertyManager($request),
            'delete_property_manager'  => $this->deletePropertyManager($request),
            'assign_property_manager'  => $this->assignPropertyManager($request),
            'quick_create_and_assign_property_manager' => $this->quickCreateAndAssignPropertyManager($request),
            'upload_ava'               => $this->uploadAva($request),
```

- [ ] **Step 4: listPropertyManagers Methode implementieren**

Am Ende der Klasse `AdminApiController` (vor dem schließenden `}`) anhängen:

```php
    private function listPropertyManagers(Request $request): JsonResponse
    {
        $search = trim($request->query('search', ''));

        $q = \DB::table('property_managers as pm')
            ->leftJoin('properties as p', 'p.property_manager_id', '=', 'pm.id')
            ->select([
                'pm.id', 'pm.company_name', 'pm.address_street', 'pm.address_zip', 'pm.address_city',
                'pm.email', 'pm.phone', 'pm.contact_person', 'pm.notes', 'pm.created_at',
                \DB::raw('COUNT(p.id) as property_count'),
            ])
            ->groupBy('pm.id', 'pm.company_name', 'pm.address_street', 'pm.address_zip', 'pm.address_city',
                      'pm.email', 'pm.phone', 'pm.contact_person', 'pm.notes', 'pm.created_at')
            ->orderBy('pm.company_name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('pm.company_name', 'like', "%{$search}%")
                  ->orWhere('pm.email', 'like', "%{$search}%")
                  ->orWhere('pm.contact_person', 'like', "%{$search}%");
            });
        }

        $managers = $q->get()->map(fn($r) => [
            'id' => (int) $r->id,
            'company_name' => $r->company_name,
            'address_street' => $r->address_street,
            'address_zip' => $r->address_zip,
            'address_city' => $r->address_city,
            'email' => $r->email,
            'phone' => $r->phone,
            'contact_person' => $r->contact_person,
            'notes' => $r->notes,
            'property_count' => (int) $r->property_count,
            'created_at' => $r->created_at,
        ])->all();

        return response()->json(['success' => true, 'managers' => $managers]);
    }
```

- [ ] **Step 5: Test läuft → PASS**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: Beide Tests passing.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/PropertyManagerApiTest.php
git commit -m "feat(hv): list_property_managers endpoint with property_count + search"
```

---

## Task 3: API — create_property_manager

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Modify: `tests/Feature/PropertyManagerApiTest.php`

- [ ] **Step 1: Tests anhängen**

In `tests/Feature/PropertyManagerApiTest.php` innerhalb der Klasse:

```php
    public function test_create_validates_required_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->postJson('/api/admin_api.php?action=create_property_manager&key=' . config('portal.api_key'), [
            'company_name' => '',
            'email' => '',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('required', strtolower((string) $res->json('error')));
    }

    public function test_create_persists_manager(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->postJson('/api/admin_api.php?action=create_property_manager&key=' . config('portal.api_key'), [
            'company_name' => 'Wimmer & Partner',
            'email' => 'office@wimmer-partner.at',
            'address_street' => 'Linzer Straße 5',
            'address_city' => 'Linz',
            'phone' => '+43 732 987654',
        ]);
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $this->assertDatabaseHas('property_managers', [
            'company_name' => 'Wimmer & Partner',
            'email' => 'office@wimmer-partner.at',
            'created_by' => $user->id,
        ]);
    }
```

- [ ] **Step 2: Test läuft → FAIL**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php --filter=create`
Expected: FAIL „method not defined".

- [ ] **Step 3: createPropertyManager Methode**

Am Ende von AdminApiController anhängen:

```php
    private function createPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($companyName === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'company_name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'email format invalid'], 422);
        }

        $manager = \App\Models\PropertyManager::create([
            'company_name' => $companyName,
            'email' => $email,
            'address_street' => trim((string) ($data['address_street'] ?? '')) ?: null,
            'address_zip' => trim((string) ($data['address_zip'] ?? '')) ?: null,
            'address_city' => trim((string) ($data['address_city'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'contact_person' => trim((string) ($data['contact_person'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_by' => \Auth::id(),
        ]);

        return response()->json(['success' => true, 'manager_id' => $manager->id, 'manager' => $manager]);
    }
```

- [ ] **Step 4: Tests → PASS**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php --filter=create`
Expected: Beide PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/PropertyManagerApiTest.php
git commit -m "feat(hv): create_property_manager endpoint with validation"
```

---

## Task 4: API — update + delete_property_manager

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Modify: `tests/Feature/PropertyManagerApiTest.php`

- [ ] **Step 1: Tests anhängen**

In `tests/Feature/PropertyManagerApiTest.php`:

```php
    public function test_update_changes_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $m = PropertyManager::create(['company_name' => 'Old', 'email' => 'a@b.c']);

        $res = $this->postJson('/api/admin_api.php?action=update_property_manager&key=' . config('portal.api_key'), [
            'id' => $m->id,
            'company_name' => 'New Name',
            'email' => 'new@email.at',
            'phone' => '+43 1 234567',
        ]);
        $res->assertOk();
        $this->assertDatabaseHas('property_managers', ['id' => $m->id, 'company_name' => 'New Name', 'phone' => '+43 1 234567']);
    }

    public function test_delete_fails_when_assigned_to_properties(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $m = PropertyManager::create(['company_name' => 'InUse', 'email' => 'x@y.z']);
        Property::factory()->create(['property_manager_id' => $m->id]);

        $res = $this->postJson('/api/admin_api.php?action=delete_property_manager&key=' . config('portal.api_key'), [
            'id' => $m->id,
        ]);
        $res->assertStatus(409);
        $this->assertDatabaseHas('property_managers', ['id' => $m->id]);
    }

    public function test_delete_succeeds_when_unassigned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $m = PropertyManager::create(['company_name' => 'Lone', 'email' => 'x@y.z']);

        $res = $this->postJson('/api/admin_api.php?action=delete_property_manager&key=' . config('portal.api_key'), [
            'id' => $m->id,
        ]);
        $res->assertOk();
        $this->assertDatabaseMissing('property_managers', ['id' => $m->id]);
    }
```

- [ ] **Step 2: Test läuft → FAIL**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php --filter=update`
Expected: FAIL.

- [ ] **Step 3: Methoden implementieren**

Am Ende von AdminApiController anhängen:

```php
    private function updatePropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $id = (int) ($data['id'] ?? 0);
        if (!$id) return response()->json(['success' => false, 'error' => 'id required'], 400);

        $manager = \App\Models\PropertyManager::find($id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'not found'], 404);

        foreach (['company_name', 'email', 'address_street', 'address_zip', 'address_city', 'phone', 'contact_person', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $manager->$field = $value !== '' ? $value : null;
            }
        }
        if (!$manager->company_name || !$manager->email) {
            return response()->json(['success' => false, 'error' => 'company_name and email cannot be empty'], 422);
        }
        $manager->save();

        // Legacy-Sync: alle zugeordneten Properties bekommen den neuen Namen ins String-Feld
        \DB::table('properties')
            ->where('property_manager_id', $manager->id)
            ->update(['property_manager' => $manager->company_name]);

        return response()->json(['success' => true, 'manager' => $manager]);
    }

    private function deletePropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $id = (int) ($data['id'] ?? 0);
        if (!$id) return response()->json(['success' => false, 'error' => 'id required'], 400);

        $manager = \App\Models\PropertyManager::find($id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'not found'], 404);

        $assignedCount = \DB::table('properties')->where('property_manager_id', $id)->count();
        if ($assignedCount > 0) {
            return response()->json([
                'success' => false,
                'error' => "Hausverwaltung ist noch {$assignedCount} Objekt(en) zugewiesen. Zuerst umhängen oder entfernen.",
                'assigned_count' => $assignedCount,
            ], 409);
        }

        $manager->delete();
        return response()->json(['success' => true]);
    }
```

- [ ] **Step 4: Tests → PASS**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: Alle PASS (bisher 5 Tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/PropertyManagerApiTest.php
git commit -m "feat(hv): update + delete_property_manager endpoints"
```

---

## Task 5: API — assign_property_manager + quick_create_and_assign

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Modify: `tests/Feature/PropertyManagerApiTest.php`

- [ ] **Step 1: Tests anhängen**

In `tests/Feature/PropertyManagerApiTest.php`:

```php
    public function test_assign_sets_property_manager_id_and_syncs_string(): void
    {
        $user = User::factory()->create(['user_type' => 'admin']);
        $this->actingAs($user);

        $m = PropertyManager::create(['company_name' => 'New HV', 'email' => 'a@b.c']);
        $p = Property::factory()->create(['broker_id' => $user->id]);

        $res = $this->postJson('/api/admin_api.php?action=assign_property_manager&key=' . config('portal.api_key'), [
            'property_id' => $p->id,
            'property_manager_id' => $m->id,
        ]);
        $res->assertOk();

        $this->assertDatabaseHas('properties', [
            'id' => $p->id,
            'property_manager_id' => $m->id,
            'property_manager' => 'New HV',
        ]);
    }

    public function test_assign_null_clears_mapping(): void
    {
        $user = User::factory()->create(['user_type' => 'admin']);
        $this->actingAs($user);

        $m = PropertyManager::create(['company_name' => 'HV', 'email' => 'a@b.c']);
        $p = Property::factory()->create(['broker_id' => $user->id, 'property_manager_id' => $m->id, 'property_manager' => 'HV']);

        $res = $this->postJson('/api/admin_api.php?action=assign_property_manager&key=' . config('portal.api_key'), [
            'property_id' => $p->id,
            'property_manager_id' => null,
        ]);
        $res->assertOk();

        $this->assertDatabaseHas('properties', [
            'id' => $p->id,
            'property_manager_id' => null,
        ]);
    }

    public function test_quick_create_and_assign(): void
    {
        $user = User::factory()->create(['user_type' => 'admin']);
        $this->actingAs($user);
        $p = Property::factory()->create(['broker_id' => $user->id]);

        $res = $this->postJson('/api/admin_api.php?action=quick_create_and_assign_property_manager&key=' . config('portal.api_key'), [
            'property_id' => $p->id,
            'company_name' => 'Quick HV',
            'email' => 'q@hv.at',
            'phone' => '+43 1 1234',
        ]);
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $p->refresh();
        $this->assertNotNull($p->property_manager_id);
        $this->assertSame('Quick HV', $p->property_manager);
        $this->assertDatabaseHas('property_managers', ['company_name' => 'Quick HV']);
    }
```

- [ ] **Step 2: Methoden implementieren**

Am Ende von AdminApiController anhängen:

```php
    private function assignPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $managerId = isset($data['property_manager_id']) && $data['property_manager_id'] !== ''
            ? (int) $data['property_manager_id']
            : null;

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);

        // Broker-Scope: Makler nur eigene, Admin auch nur eigene (wie andere Property-Write-Actions)
        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung: Objekt gehört einem anderen Makler'], 403);
            }
        }

        $managerName = null;
        if ($managerId) {
            $mgr = \DB::table('property_managers')->where('id', $managerId)->first();
            if (!$mgr) return response()->json(['success' => false, 'error' => 'Hausverwaltung nicht gefunden'], 404);
            $managerName = $mgr->company_name;
        }

        \DB::table('properties')->where('id', $propertyId)->update([
            'property_manager_id' => $managerId,
            'property_manager' => $managerName,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'property_id' => $propertyId, 'property_manager_id' => $managerId]);
    }

    private function quickCreateAndAssignPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $companyName = trim((string) ($data['company_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if ($companyName === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'company_name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'email format invalid'], 422);
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

        $result = \DB::transaction(function () use ($data, $propertyId, $companyName, $email, $userId) {
            $manager = \App\Models\PropertyManager::create([
                'company_name' => $companyName,
                'email' => $email,
                'address_street' => trim((string) ($data['address_street'] ?? '')) ?: null,
                'address_zip' => trim((string) ($data['address_zip'] ?? '')) ?: null,
                'address_city' => trim((string) ($data['address_city'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'contact_person' => trim((string) ($data['contact_person'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $userId,
            ]);

            \DB::table('properties')->where('id', $propertyId)->update([
                'property_manager_id' => $manager->id,
                'property_manager' => $manager->company_name,
                'updated_at' => now(),
            ]);

            return $manager;
        });

        return response()->json(['success' => true, 'manager_id' => $result->id, 'manager' => $result, 'property_id' => $propertyId]);
    }
```

- [ ] **Step 3: Tests → PASS**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: Alle PASS (jetzt 8 Tests).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/PropertyManagerApiTest.php
git commit -m "feat(hv): assign + quick_create_and_assign with broker ownership checks"
```

---

## Task 6: API — upload_ava

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Modify: `tests/Feature/PropertyManagerApiTest.php`

- [ ] **Step 1: Test anhängen**

In `tests/Feature/PropertyManagerApiTest.php` am Anfang der Klasse `use` einfügen:

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Dann Tests anhängen:

```php
    public function test_upload_ava_creates_file_record_with_flag(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['user_type' => 'admin']);
        $this->actingAs($user);
        $p = Property::factory()->create(['broker_id' => $user->id]);

        $res = $this->post('/api/admin_api.php?action=upload_ava&key=' . config('portal.api_key'), [
            'property_id' => $p->id,
            'file' => UploadedFile::fake()->create('ava.pdf', 50, 'application/pdf'),
        ]);
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $this->assertDatabaseHas('property_files', [
            'property_id' => $p->id,
            'is_ava' => 1,
        ]);
    }

    public function test_upload_ava_unmarks_previous_ava(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['user_type' => 'admin']);
        $this->actingAs($user);
        $p = Property::factory()->create(['broker_id' => $user->id]);

        \DB::table('property_files')->insert([
            'property_id' => $p->id,
            'label' => 'Alter AVA',
            'filename' => 'old.pdf',
            'path' => 'files/old.pdf',
            'is_ava' => 1,
            'is_website_download' => 0,
        ]);

        $this->post('/api/admin_api.php?action=upload_ava&key=' . config('portal.api_key'), [
            'property_id' => $p->id,
            'file' => UploadedFile::fake()->create('new.pdf', 50, 'application/pdf'),
        ]);

        $avaCount = \DB::table('property_files')->where('property_id', $p->id)->where('is_ava', 1)->count();
        $this->assertSame(1, $avaCount, 'Nur der neueste AVA sollte als is_ava=1 markiert sein');
    }
```

- [ ] **Step 2: Methode implementieren**

Am Ende von AdminApiController:

```php
    private function uploadAva(Request $request): JsonResponse
    {
        $propertyId = (int) $request->input('property_id', 0);
        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'error' => 'file required'], 400);
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

        $file = $request->file('file');
        $dir = 'property_files/' . $propertyId;
        $filename = 'AVA_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $filename, 'public');

        \DB::transaction(function () use ($propertyId, $file, $filename, $path) {
            // Bestehende AVAs entmarkieren
            \DB::table('property_files')
                ->where('property_id', $propertyId)
                ->where('is_ava', 1)
                ->update(['is_ava' => 0]);

            // Neuen Eintrag anlegen
            \DB::table('property_files')->insert([
                'property_id' => $propertyId,
                'label' => 'Alleinvermittlungsauftrag',
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_ava' => 1,
                'is_website_download' => 0,
                'created_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'path' => $path]);
    }
```

- [ ] **Step 3: Tests → PASS**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: Alle 10 Tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/PropertyManagerApiTest.php
git commit -m "feat(hv): upload_ava endpoint with unmark-previous logic"
```

---

## Task 7: Vue — HausverwaltungFormDialog

**Files:**
- Create: `resources/js/Components/Admin/HausverwaltungFormDialog.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/HausverwaltungFormDialog.vue`:

```vue
<script setup>
import { ref, watch } from 'vue'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const props = defineProps({
  open: { type: Boolean, default: false },
  // null = neu anlegen, Object = bearbeiten
  manager: { type: Object, default: null },
  // Vorausgefüllter Firmenname (aus "+ Neue Hausverwaltung Suchtext anlegen"-Flow)
  prefillName: { type: String, default: '' },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'save', 'cancel'])

const form = ref({
  company_name: '', email: '', address_street: '', address_zip: '', address_city: '',
  phone: '', contact_person: '', notes: '',
})

const errorMessage = ref('')

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    errorMessage.value = ''
    if (props.manager) {
      form.value = { ...form.value, ...props.manager }
    } else {
      form.value = {
        company_name: props.prefillName || '',
        email: '', address_street: '', address_zip: '', address_city: '',
        phone: '', contact_person: '', notes: '',
      }
    }
  }
})

const isEditing = () => !!props.manager

function onSubmit() {
  errorMessage.value = ''
  const name = (form.value.company_name || '').trim()
  const email = (form.value.email || '').trim()
  if (!name) { errorMessage.value = 'Firmenname ist Pflicht.'; return }
  if (!email) { errorMessage.value = 'E-Mail ist Pflicht.'; return }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    errorMessage.value = 'E-Mail-Format ist ungültig.'; return
  }
  emit('save', { ...form.value })
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ isEditing() ? 'Hausverwaltung bearbeiten' : 'Neue Hausverwaltung' }}</DialogTitle>
        <DialogDescription>
          Felder mit <span class="text-red-600">*</span> sind Pflicht.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-2">
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">
            Firmenname <span class="text-red-600">*</span>
          </label>
          <Input v-model="form.company_name" placeholder="z. B. ImmoFirst Hausverwaltung GmbH" />
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">
            E-Mail <span class="text-red-600">*</span>
          </label>
          <Input v-model="form.email" type="email" placeholder="verwaltung@…" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Straße</label>
            <Input v-model="form.address_street" placeholder="z. B. Getreidegasse 18" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">PLZ</label>
            <Input v-model="form.address_zip" placeholder="5020" />
          </div>
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Ort</label>
          <Input v-model="form.address_city" placeholder="Salzburg" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Telefon</label>
            <Input v-model="form.phone" placeholder="+43 …" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Ansprechpartner</label>
            <Input v-model="form.contact_person" placeholder="z. B. Frau Meier" />
          </div>
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Notizen</label>
          <textarea v-model="form.notes" rows="2" class="w-full text-sm rounded-md border border-input px-3 py-2" placeholder="Interne Notizen (optional)"></textarea>
        </div>

        <div v-if="errorMessage" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-2">
          {{ errorMessage }}
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="emit('update:open', false)" :disabled="saving">Abbrechen</Button>
        <Button size="sm" @click="onSubmit" :disabled="saving">
          <span v-if="saving">Speichere…</span>
          <span v-else>{{ isEditing() ? 'Speichern' : 'Anlegen' }}</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/HausverwaltungFormDialog.vue
git commit -m "feat(hv): add HausverwaltungFormDialog (reusable Create/Edit)"
```

---

## Task 8: Vue — HausverwaltungenTab

**Files:**
- Create: `resources/js/Components/Admin/HausverwaltungenTab.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/HausverwaltungenTab.vue`:

```vue
<script setup>
import { ref, inject, onMounted, computed } from 'vue'
import { Search, Plus, Pencil, Trash2, Building2, Mail, Phone, MapPin } from 'lucide-vue-next'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import HausverwaltungFormDialog from './HausverwaltungFormDialog.vue'

const API = inject('API')
const toast = inject('toast')

const managers = ref([])
const loading = ref(false)
const search = ref('')
const dialogOpen = ref(false)
const editingManager = ref(null)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=list_property_managers' + (search.value ? '&search=' + encodeURIComponent(search.value) : ''))
    const d = await r.json()
    managers.value = d.managers || []
  } catch (e) {
    toast && toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingManager.value = null
  dialogOpen.value = true
}

function openEdit(m) {
  editingManager.value = { ...m }
  dialogOpen.value = true
}

async function onSave(payload) {
  saving.value = true
  try {
    const action = editingManager.value ? 'update_property_manager' : 'create_property_manager'
    const body = editingManager.value ? { id: editingManager.value.id, ...payload } : payload
    const r = await fetch(API.value + '&action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
    const d = await r.json()
    if (d.success) {
      toast && toast(editingManager.value ? 'Hausverwaltung aktualisiert' : 'Hausverwaltung angelegt')
      dialogOpen.value = false
      await load()
    } else {
      toast && toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast && toast('Fehler: ' + e.message)
  } finally {
    saving.value = false
  }
}

async function onDelete(m) {
  if (m.property_count > 0) {
    toast && toast(`Kann nicht gelöscht werden — noch ${m.property_count} Objekt(en) zugewiesen`)
    return
  }
  if (!confirm(`Hausverwaltung "${m.company_name}" wirklich löschen?`)) return
  try {
    const r = await fetch(API.value + '&action=delete_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: m.id }),
    })
    const d = await r.json()
    if (d.success) {
      toast && toast('Gelöscht')
      await load()
    } else {
      toast && toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast && toast('Fehler: ' + e.message)
  }
}

function addressLine(m) {
  const parts = [m.address_street, [m.address_zip, m.address_city].filter(Boolean).join(' ')].filter(Boolean)
  return parts.join(', ')
}

let debounce = null
function onSearchInput() {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => load(), 250)
}

onMounted(load)

defineExpose({ load })
</script>

<template>
  <div>
    <div class="flex items-center gap-2 mb-4">
      <div class="relative flex-1">
        <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
        <Input v-model="search" @input="onSearchInput" class="pl-9" placeholder="Hausverwaltung suchen…" />
      </div>
      <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="openCreate">
        <Plus class="w-4 h-4 mr-1" />
        Neue Hausverwaltung
      </Button>
    </div>

    <div v-if="loading" class="text-sm text-muted-foreground py-8 text-center">Lädt…</div>

    <div v-else-if="!managers.length" class="text-center py-12 text-sm text-muted-foreground">
      <Building2 class="w-10 h-10 mx-auto mb-2 text-muted-foreground/40" />
      <div>Noch keine Hausverwaltungen angelegt.</div>
      <div class="text-xs mt-1">Klick „Neue Hausverwaltung" um zu beginnen.</div>
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="m in managers" :key="m.id"
        class="rounded-xl border border-border/60 bg-card p-4 hover:border-border transition-colors"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-semibold text-sm">{{ m.company_name }}</span>
              <Badge v-if="m.property_count" variant="outline" class="text-[10px]">
                {{ m.property_count }} Objekt{{ m.property_count > 1 ? 'e' : '' }}
              </Badge>
            </div>
            <div v-if="m.contact_person" class="text-xs text-muted-foreground mt-0.5">
              Ansprechpartner: {{ m.contact_person }}
            </div>
            <div class="text-xs text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1">
              <span class="flex items-center gap-1"><Mail class="w-3 h-3" /> {{ m.email }}</span>
              <span v-if="m.phone" class="flex items-center gap-1"><Phone class="w-3 h-3" /> {{ m.phone }}</span>
              <span v-if="addressLine(m)" class="flex items-center gap-1"><MapPin class="w-3 h-3" /> {{ addressLine(m) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <Button variant="ghost" size="icon" class="h-8 w-8" @click="openEdit(m)" title="Bearbeiten">
              <Pencil class="w-3.5 h-3.5" />
            </Button>
            <Button variant="ghost" size="icon" class="h-8 w-8 text-red-600 hover:text-red-700 hover:bg-red-50"
                    @click="onDelete(m)" title="Löschen" :disabled="m.property_count > 0">
              <Trash2 class="w-3.5 h-3.5" />
            </Button>
          </div>
        </div>
      </div>
    </div>

    <HausverwaltungFormDialog
      v-model:open="dialogOpen"
      :manager="editingManager"
      :saving="saving"
      @save="onSave"
    />
  </div>
</template>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -5`
Expected: Build ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/HausverwaltungenTab.vue
git commit -m "feat(hv): add HausverwaltungenTab with list, search, CRUD"
```

---

## Task 9: Integrate HausverwaltungenTab into AdminTab

**Files:**
- Modify: `resources/js/Components/Admin/AdminTab.vue`

- [ ] **Step 1: Import + State**

In `resources/js/Components/Admin/AdminTab.vue`, imports erweitern (Script-Block):

```javascript
import HausverwaltungenTab from "@/Components/Admin/HausverwaltungenTab.vue";
import { Building2 } from "lucide-vue-next";
```

(Building2 nur wenn noch nicht drin — prüfe den `lucide-vue-next`-Import und ergänze.)

- [ ] **Step 2: Switch-Sub um "managers" erweitern**

Suche die Funktion `switchSub` (ca. Zeile 73) und füge in den `if`-Block ein:

```javascript
    if (sub === 'managers') { /* nichts extra laden — Komponente lädt selbst */ }
```

Auch in `onMounted` keine Zeile nötig (Tab lädt onMounted selbst).

- [ ] **Step 3: Sub-Tab Button im Template**

Suche den Block mit `switchSub('team')` (ca. Zeile 498). Füge einen vierten Button hinzu:

```vue
            <button @click="switchSub('managers')" class="btn btn-sm" :class="adminSubTab === 'managers' ? 'btn-primary' : 'btn-ghost'">
                <Building2 class="w-3.5 h-3.5" /> Hausverwaltungen
            </button>
```

- [ ] **Step 4: Content-Bereich**

Nach dem existierenden `<div v-if="adminSubTab === 'team'">…</div>`-Block und dem `<div v-if="adminSubTab === 'owners'">…</div>`-Block einen neuen Bereich hinzufügen:

```vue
        <!-- HAUSVERWALTUNGEN -->
        <div v-if="adminSubTab === 'managers'">
            <HausverwaltungenTab />
        </div>
```

- [ ] **Step 5: Build prüfen + Smoketest**

Run: `npm run build 2>&1 | tail -5`
Expected: Build ok.

Manual:
- [ ] Admin → Kontakte öffnen, vierten Tab „Hausverwaltungen" sehen
- [ ] Klick → leere Liste
- [ ] „Neue Hausverwaltung" → Dialog öffnet
- [ ] Firma + Email eintragen → Anlegen → erscheint in Liste

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/AdminTab.vue
git commit -m "feat(hv): integrate Hausverwaltungen sub-tab into Kontakte"
```

---

## Task 10: Vue — PropertyManagerPicker

**Files:**
- Create: `resources/js/Components/Admin/property-detail/PropertyManagerPicker.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/property-detail/PropertyManagerPicker.vue`:

```vue
<script setup>
import { ref, computed, inject, onMounted, watch, nextTick } from 'vue'
import { Search, Building2, ChevronDown, X, Plus } from 'lucide-vue-next'
import HausverwaltungFormDialog from '../HausverwaltungFormDialog.vue'

const API = inject('API')
const toast = inject('toast')

const props = defineProps({
  propertyId: { type: [Number, String], required: true },
  // Aktuell zugewiesener Manager (oder null)
  managerId: { type: [Number, String, null], default: null },
  managerName: { type: String, default: '' },
})

const emit = defineEmits(['assigned'])

const open = ref(false)
const managers = ref([])
const loading = ref(false)
const search = ref('')
const selectedManager = ref(null)
const dialogOpen = ref(false)
const dialogSaving = ref(false)

const triggerRef = ref(null)

async function loadManagers() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=list_property_managers')
    const d = await r.json()
    managers.value = d.managers || []
  } catch (e) {
    toast && toast('Laden fehlgeschlagen')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return managers.value
  return managers.value.filter(m => {
    return [m.company_name, m.email, m.address_city, m.contact_person]
      .filter(Boolean).join(' ').toLowerCase().includes(q)
  })
})

const showCreateOption = computed(() => {
  const q = search.value.trim()
  if (!q) return false
  return !managers.value.some(m => m.company_name.toLowerCase() === q.toLowerCase())
})

function toggleOpen() {
  open.value = !open.value
  if (open.value && !managers.value.length) loadManagers()
  if (open.value) nextTick(() => search.value = '')
}

async function select(m) {
  try {
    const r = await fetch(API.value + '&action=assign_property_manager', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, property_manager_id: m.id }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = m
      open.value = false
      emit('assigned', { id: m.id, company_name: m.company_name })
      toast && toast('Hausverwaltung zugewiesen')
    } else {
      toast && toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast && toast('Fehler: ' + e.message)
  }
}

async function clearSelection() {
  if (!confirm('Hausverwaltung-Zuordnung wirklich entfernen?')) return
  try {
    const r = await fetch(API.value + '&action=assign_property_manager', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, property_manager_id: null }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = null
      open.value = false
      emit('assigned', null)
    }
  } catch {}
}

function openCreateDialog() {
  open.value = false
  dialogOpen.value = true
}

async function onSaveFromDialog(payload) {
  dialogSaving.value = true
  try {
    const r = await fetch(API.value + '&action=quick_create_and_assign_property_manager', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, ...payload }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = d.manager
      managers.value.unshift(d.manager)
      dialogOpen.value = false
      emit('assigned', { id: d.manager.id, company_name: d.manager.company_name })
      toast && toast('Hausverwaltung angelegt und zugewiesen')
    } else {
      toast && toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast && toast('Fehler: ' + e.message)
  } finally {
    dialogSaving.value = false
  }
}

watch(() => props.managerId, async (id) => {
  if (!id) { selectedManager.value = null; return }
  if (selectedManager.value?.id === Number(id)) return
  // Try find locally
  if (managers.value.length) {
    const m = managers.value.find(x => x.id === Number(id))
    if (m) { selectedManager.value = m; return }
  }
  // Otherwise load
  if (!managers.value.length) await loadManagers()
  selectedManager.value = managers.value.find(x => x.id === Number(id)) || { id: Number(id), company_name: props.managerName || 'Hausverwaltung' }
}, { immediate: true })

// Close dropdown on outside click
function onDocClick(e) {
  if (triggerRef.value && !triggerRef.value.contains(e.target)) open.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
})
</script>

<template>
  <div class="relative" ref="triggerRef">
    <button type="button" class="w-full flex items-center justify-between border border-input rounded-md px-3 py-2 text-sm bg-background hover:bg-accent/40"
            @click.stop="toggleOpen">
      <div v-if="selectedManager" class="flex items-center gap-2 min-w-0">
        <div class="w-7 h-7 rounded-md bg-[#fff7ed] flex items-center justify-center shrink-0">
          <Building2 class="w-3.5 h-3.5 text-[#EE7600]" />
        </div>
        <div class="text-left min-w-0">
          <div class="font-medium truncate">{{ selectedManager.company_name }}</div>
          <div v-if="selectedManager.email || selectedManager.address_city" class="text-xs text-muted-foreground truncate">
            {{ [selectedManager.email, selectedManager.address_city].filter(Boolean).join(' · ') }}
          </div>
        </div>
      </div>
      <span v-else class="text-muted-foreground">Hausverwaltung wählen oder neu anlegen…</span>
      <ChevronDown class="w-4 h-4 text-muted-foreground shrink-0" />
    </button>

    <!-- Dropdown -->
    <div v-if="open" class="absolute z-20 left-0 right-0 mt-1 bg-popover border border-border rounded-lg shadow-lg p-1 max-h-72 overflow-y-auto">
      <div class="relative mb-1">
        <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
        <input v-model="search" type="text" class="w-full border-0 bg-muted/50 rounded-md pl-7 pr-2 py-1.5 text-sm focus:outline-none" placeholder="Suchen…" @click.stop />
      </div>

      <div v-if="loading" class="text-xs text-muted-foreground py-3 text-center">Lädt…</div>

      <div v-else>
        <button v-for="m in filtered" :key="m.id" type="button"
                class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-accent/60 text-left">
          <Building2 class="w-3.5 h-3.5 text-[#EE7600] shrink-0" />
          <div class="flex-1 min-w-0" @click="select(m)">
            <div class="text-sm font-medium truncate">{{ m.company_name }}</div>
            <div class="text-[11px] text-muted-foreground truncate">
              {{ [m.email, m.address_city].filter(Boolean).join(' · ') }}
            </div>
          </div>
        </button>

        <div v-if="!filtered.length && !showCreateOption" class="text-xs text-muted-foreground py-3 text-center">
          Keine Treffer.
        </div>

        <button v-if="showCreateOption" type="button"
                class="w-full flex items-center gap-2 px-2 py-2 mt-1 border-t border-border rounded-md hover:bg-accent/60 text-left text-[#c2410c]"
                @click="openCreateDialog">
          <Plus class="w-4 h-4 shrink-0" />
          <span class="text-sm font-medium">Neue Hausverwaltung „{{ search }}" anlegen</span>
        </button>

        <button v-if="selectedManager" type="button"
                class="w-full flex items-center gap-2 px-2 py-2 mt-1 border-t border-border rounded-md hover:bg-accent/60 text-left text-red-600"
                @click="clearSelection">
          <X class="w-4 h-4 shrink-0" />
          <span class="text-sm">Zuordnung entfernen</span>
        </button>
      </div>
    </div>

    <HausverwaltungFormDialog
      v-model:open="dialogOpen"
      :prefill-name="search"
      :saving="dialogSaving"
      @save="onSaveFromDialog"
    />
  </div>
</template>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -5`
Expected: ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/property-detail/PropertyManagerPicker.vue
git commit -m "feat(hv): add PropertyManagerPicker (autocomplete + quick-create)"
```

---

## Task 11: Integrate Picker in Property-Edit

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue`

- [ ] **Step 1: Import ergänzen**

Am Anfang von `EditTabAllgemeines.vue` (Script-Block) hinzufügen:

```javascript
import PropertyManagerPicker from './PropertyManagerPicker.vue'
```

Wenn die Komponente die Property-ID und `property_manager_id` aus einem Form-Objekt bekommt: prüfen dass diese Properties im form-Objekt ankommen. Der Picker schreibt selbst über API, nicht über v-model.

- [ ] **Step 2: Template-Ersetzung**

Suche in `EditTabAllgemeines.vue` den Block:

```vue
        <div>
          <label :class="labelCls">Hausverwaltung</label>
          <Input v-model="form.property_manager" :class="inputCls" />
        </div>
```

Ersetze durch:

```vue
        <div>
          <label :class="labelCls">Hausverwaltung</label>
          <PropertyManagerPicker
            v-if="form.id"
            :property-id="form.id"
            :manager-id="form.property_manager_id"
            :manager-name="form.property_manager"
            @assigned="onManagerAssigned"
          />
          <Input v-else v-model="form.property_manager" :class="inputCls" placeholder="Objekt zuerst speichern, dann Hausverwaltung wählen" :disabled="true" />
        </div>
```

- [ ] **Step 3: Handler im Script**

Im Script-Block ergänzen (an passender Stelle bei anderen Handlern):

```javascript
function onManagerAssigned(manager) {
  if (manager) {
    form.value.property_manager_id = manager.id
    form.value.property_manager = manager.company_name
  } else {
    form.value.property_manager_id = null
    form.value.property_manager = ''
  }
}
```

(Falls `form` nicht als `ref` sondern als reaktives Objekt geführt wird, passen Sie die Syntax an — `form.property_manager_id = ...` statt `form.value.property_manager_id = ...`.)

- [ ] **Step 4: Backend-API um property_manager_id erweitern**

Dies liefert der `get_property`-Endpoint vermutlich bereits, da alle Spalten rausgehen. Verify:

Run: `grep -n "property_manager_id\|property_manager" app/Http/Controllers/Admin/AdminApiController.php | head -10`

Wenn der Spaltenname noch nicht in selektiven SELECTs erscheint, im `getProperty`- oder `updateProperty`-Handler in AdminApiController prüfen und `property_manager_id` in fillable/select aufnehmen.

In `app/Models/Property.php` prüfen dass `property_manager_id` im `$fillable`-Array ist:

```bash
grep "property_manager_id\|property_manager" app/Models/Property.php
```

Falls nicht drin, in `$fillable` ergänzen:

```php
'property_manager', 'property_manager_id', // ...
```

- [ ] **Step 5: Build prüfen + Smoketest**

Run: `npm run build 2>&1 | tail -5`
Expected: ok.

Manual:
- [ ] Existierendes Property öffnen → Bearbeiten → Allgemeines-Sektion
- [ ] „Hausverwaltung" zeigt den Picker (nicht mehr plain Input)
- [ ] Klick → Dropdown mit Liste
- [ ] Suche „ImmoFirst" → Ergebnis erscheint
- [ ] Suche „Neues" → „Neue Hausverwaltung „Neues" anlegen"-Option
- [ ] Anlegen → neue HV in DB → zugewiesen an das Property
- [ ] Seite neuladen → Zuordnung bleibt (weil `property_manager_id` korrekt aus DB kommt)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue app/Models/Property.php
git commit -m "feat(hv): replace property_manager text field with picker in property edit"
```

---

## Task 12: Deploy

- [ ] **Step 1: Lint-Check**

Run: `php -l app/Http/Controllers/Admin/AdminApiController.php app/Models/PropertyManager.php app/Models/Property.php`
Expected: `No syntax errors detected` für alle.

- [ ] **Step 2: Full test run**

Run: `php artisan test tests/Feature/PropertyManagerApiTest.php`
Expected: Alle 10+ Tests PASS.

- [ ] **Step 3: Frontend Build**

Run: `npm run build`
Expected: Built in XX.XXs

- [ ] **Step 4: Push**

```bash
git push origin main
```

- [ ] **Step 5: Deploy Production**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"
```

Expected: `DEPLOY COMPLETE`. Migration läuft automatisch im Deploy-Script.

- [ ] **Step 6: Production-Smoketest via Command**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan tinker --execute=\"echo \\App\\Models\\PropertyManager::count();\""
```
Expected: `0`

- [ ] **Step 7: Browser-Smoketest**

- [ ] Login https://kundenportal.sr-homes.at/admin
- [ ] Admin → Kontakte → Tab „Hausverwaltungen" sichtbar
- [ ] „Neue Hausverwaltung" klicken → Dialog → eintragen → speichern → erscheint in Liste
- [ ] In ein Objekt wechseln → Bearbeiten → Allgemeines → Picker statt Input
- [ ] HV zuweisen → Seite reload → bleibt zugewiesen

---

## Self-Review

**1. Spec coverage:**
- ✅ `property_managers` Tabelle → Task 1
- ✅ `properties.property_manager_id` FK → Task 1
- ✅ `property_files.is_ava` + Backfill → Task 1
- ✅ `PropertyManager` Model → Task 1
- ✅ `list_property_managers` Endpoint → Task 2
- ✅ `create_property_manager` Endpoint → Task 3
- ✅ `update_property_manager` Endpoint → Task 4
- ✅ `delete_property_manager` Endpoint mit Assigned-Check → Task 4
- ✅ `assign_property_manager` mit Broker-Scope → Task 5
- ✅ `quick_create_and_assign_property_manager` → Task 5
- ✅ `upload_ava` mit unmark-previous → Task 6
- ✅ `HausverwaltungFormDialog` → Task 7
- ✅ `HausverwaltungenTab` → Task 8
- ✅ Integration in AdminTab (vierter Sub-Tab) → Task 9
- ✅ `PropertyManagerPicker` → Task 10
- ✅ Integration in EditTabAllgemeines → Task 11
- ✅ Legacy-Sync von company_name zu property_manager-String → Task 4 (update) + Task 5 (assign + quick_create)

**2. Placeholder scan:** Keine TBDs, alle Code-Blöcke vollständig.

**3. Type consistency:**
- `property_manager_id` überall int oder null.
- `company_name` + `email` immer required.
- API-Actions konsistent benannt (snake_case), alle via `?action=` dispatcher.
- Component-Props konsistent: `managerId`, `propertyId`, `open` (v-model), `saving`.

**Out of scope für Phase 1** (kommt in Phase 2): Template-Auswahl-Sheet, Inbox-Forward-Button, ContactManagerSheet, MissingManagerDialog, MissingAvaDialog, KI-Prompts, `contact_property_manager` + `send_to_manager` Endpoints, Activity-Kategorie `hausverwaltung`.
