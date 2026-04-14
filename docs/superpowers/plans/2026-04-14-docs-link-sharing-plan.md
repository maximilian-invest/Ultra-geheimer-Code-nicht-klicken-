# Docs Link-Sharing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace PDF email attachments with trackable, revocable links per property — staged document release with email-gate, DSGVO consent, inline PDF viewer, and per-session activity tracking.

**Architecture:** New public Blade routes (`/docs/{token}`) + admin Inertia/Vue CRUD sit in the existing Laravel monolith. Four new tables (`property_links`, `property_link_documents`, `property_link_sessions`, `property_link_events`). Two services (`PropertyLinkService`, `LinkActivityLogger`) encapsulate token generation, access checks, and session/event writes.

**Tech Stack:** Laravel 12, Vue 3 + Inertia.js, MySQL (prod) / SQLite in-memory (tests), Blade + vanilla JS for the public pages, PDF.js for the inline viewer, Outfit font, sr-homes.at design tokens.

**Reference spec:** `docs/superpowers/specs/2026-04-14-docs-link-sharing-design.md` (committed `08f58f4`).

---

## File Structure

**Migrations (new, `database/migrations/`):**
- `2026_04_14_100000_create_legacy_tables_for_testing.php` — compat layer for SQLite (no-op on MySQL)
- `2026_04_14_100100_create_property_links_table.php`
- `2026_04_14_100200_create_property_link_documents_table.php`
- `2026_04_14_100300_create_property_link_sessions_table.php`
- `2026_04_14_100400_create_property_link_events_table.php`
- `2026_04_14_100500_add_default_link_expiry_days_to_users.php`
- `2026_04_14_100600_add_link_session_id_to_activities.php`

**Models (new, `app/Models/`):**
- `PropertyLink.php` — fillable, casts, relations, scopes
- `PropertyLinkSession.php` — fillable, relations
- `PropertyLinkEvent.php` — fillable, relations

**Models (modify):**
- `Property.php` — add `propertyLinks()` HasMany
- `Activity.php` — add `linkSession()` BelongsTo + `link_session_id` fillable
- `User.php` — add `default_link_expiry_days` fillable + cast

**Services (new, `app/Services/`):**
- `PropertyLinkService.php` — token gen, access check, markAsDefault transaction
- `LinkActivityLogger.php` — session upsert, event write, activity summary

**Controllers (new, `app/Http/Controllers/`):**
- `Admin/PropertyLinkController.php` — CRUD + revoke/reactivate
- `PublicDocumentController.php` — show, unlock, file, event
- `Admin/DsgvoLinkController.php` — export + delete

**Commands (new, `app/Console/Commands/`):**
- `PurgeOldLinkSessions.php` — scheduled daily

**Vue components (new, `resources/js/`):**
- `Pages/Admin/PropertyLinkDetail.vue` — full detail page
- `Components/Admin/Property/PropertyLinksTab.vue` — tab content in PropertyDetailPage
- `Components/Admin/Property/PropertyLinkForm.vue` — slide-over create/edit
- `Components/Admin/Inbox/LinkPickerPopover.vue` — composer popover

**Vue components (modify):**
- `Pages/Admin/PropertyDetailPage.vue` — wire up the Links tab
- `Components/Admin/Inbox/InboxChatView.vue` — wire up link picker button

**Blade templates (new, `resources/views/`):**
- `docs/landing.blade.php` — shell (email-gate or unlocked view)
- `docs/partials/_email_gate.blade.php`
- `docs/partials/_unlocked.blade.php`
- `docs/partials/_viewer.blade.php`
- `docs/error.blade.php` — 404/410 error page

**Public assets (new, `public/docs/`):**
- `docs.css` — landing design
- `docs.js` — email gate, viewer, heartbeat
- `pdf.worker.min.js` — PDF.js worker (vendored from npm)

**Routes (modify):**
- `routes/web.php` — add public `docs` group and admin `property-links` group

**Kernel (modify):**
- `routes/console.php` — register `PurgeOldLinkSessions` schedule

**Tests (new, `tests/`):**
- `tests/Unit/Services/PropertyLinkServiceTest.php`
- `tests/Unit/Services/LinkActivityLoggerTest.php`
- `tests/Feature/Admin/PropertyLinkControllerTest.php`
- `tests/Feature/PublicDocumentControllerTest.php`
- `tests/Feature/Admin/ConversationControllerAutoInsertTest.php`
- `tests/Feature/Admin/DsgvoLinkControllerTest.php`
- `tests/Feature/PurgeOldLinkSessionsTest.php`
- `database/factories/PropertyFactory.php`
- `database/factories/PropertyLinkFactory.php`
- `database/factories/PropertyLinkSessionFactory.php`

---

## Conventions & Gotchas

1. **Middleware role syntax:** Use `role:admin,makler,assistenz` (commas), NOT `role:admin|makler|assistenz`.
2. **SQLite test env:** `phpunit.xml` uses `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Existing profile tests already fail because `property_files` has an ALTER migration without a CREATE. Task 0.1 fixes this with a compat migration.
3. **No ENUM modifications on SQLite:** For the `activities.category` extension, wrap in `if (DB::connection()->getDriverName() === 'mysql')`. SQLite accepts any string in the column, so feature tests just work.
4. **Factories:** Only `UserFactory` exists. `PropertyFactory` and `PropertyLinkFactory` must be created — use `HasFactory` trait in models.
5. **property_files schema:** Raw-SQL managed table. Columns: `id`, `property_id (int unsigned)`, `label (varchar 100)`, `filename (varchar 500)`, `path (varchar 500)`, `mime_type`, `file_size`, `sort_order`, `is_website_download`, `created_at`. Treat `property_id` as `unsignedInteger`, not `unsignedBigInteger`.
6. **Design tokens:** Use `#FAF8F5` cream, `#0A0A08` dark, `#D4743B` accent, Outfit font — match sr-homes.at 1:1.
7. **Token storage:** `Str::random(43)` returns URL-safe 43-char string (256-bit entropy). Store plaintext in DB for lookup.
8. **Cookie name:** `sr_link_session_{first 8 chars of token}` so different tabs/links don't clobber each other.
9. **Commit after every task step unless a task explicitly bundles multiple steps.** TDD: failing test → implementation → passing test → commit.

---

## Task 0.1: Legacy Test Compat Migration

**Why:** Existing feature tests break because `property_files` has no CREATE migration — only an ALTER that assumes the table was created via raw SQL on prod. SQLite in-memory tests fail on migrate:fresh. This task adds a compat migration that creates missing legacy tables on SQLite only (no-op on MySQL via `hasTable` guard).

**Files:**
- Create: `database/migrations/2026_04_14_100000_create_legacy_tables_for_testing.php`

- [ ] **Step 1: Create the compat migration**

```php
<?php
// database/migrations/2026_04_14_100000_create_legacy_tables_for_testing.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('property_files')) {
            Schema::create('property_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('property_id');
                $table->string('label', 100)->default('');
                $table->string('filename', 500);
                $table->string('path', 500);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedInteger('file_size')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_website_download')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->index('property_id', 'idx_property_id');
            });
        }

        if (!Schema::hasTable('activities')) {
            Schema::create('activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained()->onDelete('cascade');
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->date('activity_date');
                $table->string('stakeholder');
                $table->text('activity');
                $table->text('result')->nullable();
                $table->integer('duration')->nullable();
                $table->string('category', 40)->default('sonstiges');
                $table->tinyInteger('followup_stage')->nullable();
                $table->integer('source_email_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->dateTime('snooze_until')->nullable();
                $table->boolean('viewing_alert_dismissed')->default(false);
                $table->string('kaufanbot_status', 30)->nullable();
                $table->index(['property_id', 'activity_date']);
                $table->index('stakeholder');
            });
        }
    }

    public function down(): void
    {
        // No-op — we never drop legacy tables.
    }
};
```

- [ ] **Step 2: Run existing profile tests to verify they no longer fail**

Run: `cd /Users/max/srhomes && php artisan test tests/Feature/ProfileTest.php`
Expected: `Tests:    5 passed`

- [ ] **Step 3: Commit**

```bash
cd /Users/max/srhomes
git add database/migrations/2026_04_14_100000_create_legacy_tables_for_testing.php
git commit -m "test: compat migration for legacy property_files and activities on sqlite"
```

---

## Task 1.1: Migration — `property_links` table

**Files:**
- Create: `database/migrations/2026_04_14_100100_create_property_links_table.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100100_create_property_links_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->string('name', 120);
            $table->char('token', 43)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('token', 'idx_property_links_token');
            $table->index(['property_id', 'revoked_at', 'expires_at'], 'idx_property_links_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_links');
    }
};
```

- [ ] **Step 2: Run the migration on the test DB**

Run: `cd /Users/max/srhomes && php artisan migrate --database=sqlite --env=testing` (or just `php artisan test --filter=nothing` which runs `migrate:fresh`)
Expected: Migration runs without errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_14_100100_create_property_links_table.php
git commit -m "feat: add property_links table migration"
```

---

## Task 1.2: Migration — `property_link_documents` pivot

**Files:**
- Create: `database/migrations/2026_04_14_100200_create_property_link_documents_table.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100200_create_property_link_documents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_documents', function (Blueprint $table) {
            $table->foreignId('property_link_id')->constrained('property_links')->onDelete('cascade');
            $table->unsignedBigInteger('property_file_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['property_link_id', 'property_file_id']);

            // NOTE: no FK to property_files because it's unsignedBigInteger here vs unsignedInteger there.
            // Integrity is enforced in PropertyLinkService via existence checks.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_documents');
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_14_100200_create_property_link_documents_table.php
git commit -m "feat: add property_link_documents pivot migration"
```

---

## Task 1.3: Migration — `property_link_sessions`

**Files:**
- Create: `database/migrations/2026_04_14_100300_create_property_link_sessions_table.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100300_create_property_link_sessions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_link_id')->constrained('property_links')->onDelete('cascade');
            $table->string('email');
            $table->timestamp('dsgvo_accepted_at');
            $table->char('ip_hash', 64);
            $table->char('user_agent_hash', 64);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['property_link_id', 'email'], 'idx_property_link_sessions_link_email');
            $table->index('last_seen_at', 'idx_property_link_sessions_last_seen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_sessions');
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_14_100300_create_property_link_sessions_table.php
git commit -m "feat: add property_link_sessions migration"
```

---

## Task 1.4: Migration — `property_link_events`

**Files:**
- Create: `database/migrations/2026_04_14_100400_create_property_link_events_table.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100400_create_property_link_events_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('property_link_sessions')->onDelete('cascade');
            $table->unsignedBigInteger('property_file_id')->nullable();
            $table->string('event_type', 20); // link_opened | doc_viewed | doc_downloaded
            $table->unsignedInteger('duration_s')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id', 'idx_property_link_events_session');
            $table->index('created_at', 'idx_property_link_events_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_events');
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_14_100400_create_property_link_events_table.php
git commit -m "feat: add property_link_events migration"
```

---

## Task 1.5: Migration — `users.default_link_expiry_days`

**Files:**
- Create: `database/migrations/2026_04_14_100500_add_default_link_expiry_days_to_users.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100500_add_default_link_expiry_days_to_users.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('default_link_expiry_days')->nullable()->default(30)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_link_expiry_days');
        });
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_14_100500_add_default_link_expiry_days_to_users.php
git commit -m "feat: add default_link_expiry_days to users"
```

---

## Task 1.6: Migration — `activities.link_session_id` + category extension

**Files:**
- Create: `database/migrations/2026_04_14_100600_add_link_session_id_to_activities.php`

- [ ] **Step 1: Write migration**

```php
<?php
// database/migrations/2026_04_14_100600_add_link_session_id_to_activities.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('link_session_id')->nullable()->after('source_email_id');
            $table->index('link_session_id', 'idx_activities_link_session');
        });

        // Extend the category enum only on MySQL. SQLite already stores any string.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen','link_opened'"
                . ") DEFAULT 'sonstiges'");
        }
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('idx_activities_link_session');
            $table->dropColumn('link_session_id');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen'"
                . ") DEFAULT 'sonstiges'");
        }
    }
};
```

- [ ] **Step 2: Run all new migrations against the in-memory SQLite test DB**

Run: `cd /Users/max/srhomes && php artisan test tests/Feature/ProfileTest.php`
Expected: Still `Tests: 5 passed` — migrations don't break anything.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_14_100600_add_link_session_id_to_activities.php
git commit -m "feat: add link_session_id to activities and extend category enum"
```

---

## Task 1.7: Eloquent models

**Files:**
- Create: `app/Models/PropertyLink.php`
- Create: `app/Models/PropertyLinkSession.php`
- Create: `app/Models/PropertyLinkEvent.php`

- [ ] **Step 1: Create `PropertyLink` model**

```php
<?php
// app/Models/PropertyLink.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'name', 'token', 'is_default',
        'expires_at', 'revoked_at', 'revoked_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PropertyLinkSession::class);
    }

    /**
     * Document IDs associated with this link (pivot is read-only by design).
     *
     * @return \Illuminate\Support\Collection<int,int>
     */
    public function documentIds(): \Illuminate\Support\Collection
    {
        return \DB::table('property_link_documents')
            ->where('property_link_id', $this->id)
            ->orderBy('sort_order')
            ->pluck('property_file_id');
    }

    public function isAccessible(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }
}
```

- [ ] **Step 2: Create `PropertyLinkSession` model**

```php
<?php
// app/Models/PropertyLinkSession.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyLinkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_link_id', 'email', 'dsgvo_accepted_at',
        'ip_hash', 'user_agent_hash', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'dsgvo_accepted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public $timestamps = false; // we manage first_seen_at / last_seen_at manually

    public function link(): BelongsTo
    {
        return $this->belongsTo(PropertyLink::class, 'property_link_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PropertyLinkEvent::class, 'session_id');
    }
}
```

- [ ] **Step 3: Create `PropertyLinkEvent` model**

```php
<?php
// app/Models/PropertyLinkEvent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyLinkEvent extends Model
{
    use HasFactory;

    public const TYPE_LINK_OPENED = 'link_opened';
    public const TYPE_DOC_VIEWED = 'doc_viewed';
    public const TYPE_DOC_DOWNLOADED = 'doc_downloaded';

    protected $fillable = [
        'session_id', 'property_file_id', 'event_type', 'duration_s',
    ];

    public $timestamps = false;

    protected $dates = ['created_at'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PropertyLinkSession::class, 'session_id');
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/PropertyLink.php app/Models/PropertyLinkSession.php app/Models/PropertyLinkEvent.php
git commit -m "feat: add PropertyLink, PropertyLinkSession, PropertyLinkEvent models"
```

---

## Task 1.8: Wire relations into existing models

**Files:**
- Modify: `app/Models/Property.php` — add `propertyLinks()`
- Modify: `app/Models/Activity.php` — add `link_session_id` fillable + `linkSession()` relation
- Modify: `app/Models/User.php` — add `default_link_expiry_days` fillable + cast

- [ ] **Step 1: Add `propertyLinks()` to Property**

In `app/Models/Property.php`, add this method after the existing `tasks()` method:

```php
    public function propertyLinks(): HasMany
    {
        return $this->hasMany(PropertyLink::class);
    }
```

- [ ] **Step 2: Update Activity model**

In `app/Models/Activity.php`, replace the `$fillable` array:

```php
    protected $fillable = [
        'property_id', 'activity_date', 'stakeholder', 'activity', 'result',
        'duration', 'category', 'source_email_id', 'followup_stage', 'link_session_id',
    ];
```

And add this method at the bottom:

```php
    public function linkSession(): BelongsTo
    {
        return $this->belongsTo(PropertyLinkSession::class, 'link_session_id');
    }
```

- [ ] **Step 3: Update User model**

In `app/Models/User.php`, add `'default_link_expiry_days'` to the `$fillable` array and add `'default_link_expiry_days' => 'integer'` to the `casts()` method return array.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Property.php app/Models/Activity.php app/Models/User.php
git commit -m "feat: wire property link relations into Property, Activity, User"
```

---

## Task 1.9: Factories for test scaffolding

**Files:**
- Create: `database/factories/PropertyFactory.php`
- Create: `database/factories/PropertyLinkFactory.php`
- Create: `database/factories/PropertyLinkSessionFactory.php`

- [ ] **Step 1: Create `PropertyFactory`**

```php
<?php
// database/factories/PropertyFactory.php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        // customers table has a FK constraint — ensure a customer exists.
        $customerId = DB::table('customers')->insertGetId([
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'customer_id' => $customerId,
            'ref_id' => 'TST-' . $this->faker->unique()->randomNumber(6),
            'project_name' => $this->faker->company() . ' Project',
            'title' => $this->faker->sentence(4),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'zip' => $this->faker->postcode(),
            'price' => $this->faker->numberBetween(200000, 900000),
            'living_area' => $this->faker->numberBetween(40, 200),
            'rooms_amount' => $this->faker->numberBetween(1, 6),
        ];
    }
}
```

Add `use HasFactory;` inside the `Property` class body in `app/Models/Property.php`, and add the import `use Illuminate\Database\Eloquent\Factories\HasFactory;` at the top if not already present.

- [ ] **Step 2: Create `PropertyLinkFactory`**

```php
<?php
// database/factories/PropertyLinkFactory.php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PropertyLinkFactory extends Factory
{
    protected $model = PropertyLink::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Erstanfrage',
            'token' => Str::random(43),
            'is_default' => false,
            'expires_at' => now()->addDays(30),
            'created_by' => User::factory(),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (array $attrs) => [
            'revoked_at' => now(),
            'revoked_by' => $attrs['created_by'] ?? User::factory(),
        ]);
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
```

- [ ] **Step 3: Create `PropertyLinkSessionFactory`**

```php
<?php
// database/factories/PropertyLinkSessionFactory.php

namespace Database\Factories;

use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyLinkSessionFactory extends Factory
{
    protected $model = PropertyLinkSession::class;

    public function definition(): array
    {
        return [
            'property_link_id' => PropertyLink::factory(),
            'email' => $this->faker->safeEmail(),
            'dsgvo_accepted_at' => now(),
            'ip_hash' => hash('sha256', '127.0.0.1' . 'test-salt'),
            'user_agent_hash' => hash('sha256', 'test-ua' . 'test-salt'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
        ];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/factories/PropertyFactory.php database/factories/PropertyLinkFactory.php database/factories/PropertyLinkSessionFactory.php app/Models/Property.php
git commit -m "feat: add factories for Property, PropertyLink, PropertyLinkSession"
```

---

## Task 2.1: `PropertyLinkService::generateUniqueToken` (TDD)

**Files:**
- Create: `app/Services/PropertyLinkService.php`
- Create: `tests/Unit/Services/PropertyLinkServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/Services/PropertyLinkServiceTest.php

namespace Tests\Unit\Services;

use App\Models\PropertyLink;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_unique_token_of_correct_length(): void
    {
        $svc = new PropertyLinkService();
        $token = $svc->generateUniqueToken();

        $this->assertSame(43, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);
    }

    public function test_generates_token_that_does_not_collide_with_existing(): void
    {
        // Factories auto-wire dependencies (user, property)
        PropertyLink::factory()->create(['token' => 'fixed-token-value-for-testing-collisions-XX']);

        $svc = new PropertyLinkService();
        $token = $svc->generateUniqueToken();

        $this->assertNotSame('fixed-token-value-for-testing-collisions-XX', $token);
    }
}
```

- [ ] **Step 2: Run test — expect FAIL (service does not exist)**

Run: `cd /Users/max/srhomes && php artisan test tests/Unit/Services/PropertyLinkServiceTest.php --filter=test_generates_unique_token_of_correct_length`
Expected: `Error: Class "App\Services\PropertyLinkService" not found`

- [ ] **Step 3: Create the service with minimal implementation**

```php
<?php
// app/Services/PropertyLinkService.php

namespace App\Services;

use App\Models\PropertyLink;
use Illuminate\Support\Str;

class PropertyLinkService
{
    public function generateUniqueToken(): string
    {
        do {
            $token = Str::random(43);
        } while (PropertyLink::where('token', $token)->exists());

        return $token;
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

Run: `cd /Users/max/srhomes && php artisan test tests/Unit/Services/PropertyLinkServiceTest.php`
Expected: `Tests: 2 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/PropertyLinkService.php tests/Unit/Services/PropertyLinkServiceTest.php
git commit -m "feat: PropertyLinkService with unique token generator"
```

---

## Task 2.2: `PropertyLinkService::markAsDefault` transaction (TDD)

**Files:**
- Modify: `app/Services/PropertyLinkService.php`
- Modify: `tests/Unit/Services/PropertyLinkServiceTest.php`

- [ ] **Step 1: Write failing test**

Append to `PropertyLinkServiceTest`:

```php
    public function test_mark_as_default_unsets_other_defaults_on_same_property(): void
    {
        $p1 = Property::factory()->create();
        $p2 = Property::factory()->create();

        $linkA = PropertyLink::factory()->create(['property_id' => $p1->id, 'is_default' => true]);
        $linkB = PropertyLink::factory()->create(['property_id' => $p1->id, 'is_default' => false]);
        $linkC = PropertyLink::factory()->create(['property_id' => $p2->id, 'is_default' => true]);

        $svc = new PropertyLinkService();
        $svc->markAsDefault($linkB);

        $this->assertFalse($linkA->fresh()->is_default, 'linkA default should be unset');
        $this->assertTrue($linkB->fresh()->is_default, 'linkB should now be default');
        $this->assertTrue($linkC->fresh()->is_default, 'linkC on a DIFFERENT property stays default');
    }
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Unit/Services/PropertyLinkServiceTest.php --filter=test_mark_as_default`
Expected: `Error: Call to undefined method`

- [ ] **Step 3: Implement `markAsDefault`**

Add to `PropertyLinkService`:

```php
    public function markAsDefault(\App\Models\PropertyLink $link): void
    {
        \DB::transaction(function () use ($link) {
            \App\Models\PropertyLink::where('property_id', $link->property_id)
                ->where('id', '!=', $link->id)
                ->update(['is_default' => false]);

            $link->is_default = true;
            $link->save();
        });
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Unit/Services/PropertyLinkServiceTest.php`
Expected: `Tests: 3 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/PropertyLinkService.php tests/Unit/Services/PropertyLinkServiceTest.php
git commit -m "feat: PropertyLinkService::markAsDefault enforces one-default-per-property"
```

---

## Task 2.3: `PropertyLinkService::isAccessible` (TDD)

**Files:**
- Modify: `app/Services/PropertyLinkService.php`
- Modify: `tests/Unit/Services/PropertyLinkServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append:

```php
    public function test_is_accessible_returns_false_for_expired_link(): void
    {
        $link = PropertyLink::factory()->expired()->create();

        $svc = new PropertyLinkService();
        $this->assertFalse($svc->isAccessible($link));
    }

    public function test_is_accessible_returns_false_for_revoked_link(): void
    {
        $link = PropertyLink::factory()->revoked()->create();

        $svc = new PropertyLinkService();
        $this->assertFalse($svc->isAccessible($link));
    }

    public function test_is_accessible_returns_true_for_active_link(): void
    {
        $link = PropertyLink::factory()->create();

        $svc = new PropertyLinkService();
        $this->assertTrue($svc->isAccessible($link));
    }

    public function test_is_accessible_returns_true_for_link_without_expiry(): void
    {
        $link = PropertyLink::factory()->create(['expires_at' => null]);

        $svc = new PropertyLinkService();
        $this->assertTrue($svc->isAccessible($link));
    }
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Unit/Services/PropertyLinkServiceTest.php --filter=test_is_accessible`
Expected: `Error: Call to undefined method`

- [ ] **Step 3: Implement `isAccessible`**

Add to `PropertyLinkService`:

```php
    public function isAccessible(\App\Models\PropertyLink $link): bool
    {
        return $link->isAccessible();
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Unit/Services/PropertyLinkServiceTest.php`
Expected: `Tests: 7 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/PropertyLinkService.php tests/Unit/Services/PropertyLinkServiceTest.php
git commit -m "feat: PropertyLinkService::isAccessible delegates to model"
```

---

## Task 2.4: `LinkActivityLogger::recordLinkOpened` (TDD)

**Files:**
- Create: `app/Services/LinkActivityLogger.php`
- Create: `tests/Unit/Services/LinkActivityLoggerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/Services/LinkActivityLoggerTest.php

namespace Tests\Unit\Services;

use App\Models\Activity;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use App\Services\LinkActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_link_opened_creates_activity_and_upserts_on_second_call(): void
    {
        $link = PropertyLink::factory()->create(['name' => 'Erstanfrage']);
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'lisa@example.com',
        ]);

        $logger = new LinkActivityLogger();
        $logger->recordLinkOpened($session);

        $this->assertDatabaseCount('activities', 1);
        $activity = Activity::first();
        $this->assertSame($link->property_id, $activity->property_id);
        $this->assertSame('lisa@example.com', $activity->stakeholder);
        $this->assertSame('link_opened', $activity->category);
        $this->assertStringContainsString('Erstanfrage', $activity->activity);
        $this->assertSame($session->id, $activity->link_session_id);

        // Second call with same session → still exactly 1 activity (upserted)
        $logger->recordLinkOpened($session);
        $this->assertDatabaseCount('activities', 1);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Unit/Services/LinkActivityLoggerTest.php`
Expected: `Error: Class "App\Services\LinkActivityLogger" not found`

- [ ] **Step 3: Create the logger**

```php
<?php
// app/Services/LinkActivityLogger.php

namespace App\Services;

use App\Models\Activity;
use App\Models\PropertyLinkEvent;
use App\Models\PropertyLinkSession;

class LinkActivityLogger
{
    public function recordLinkOpened(PropertyLinkSession $session): Activity
    {
        $session->loadMissing('link', 'events');

        return Activity::updateOrCreate(
            ['link_session_id' => $session->id],
            [
                'property_id'   => $session->link->property_id,
                'activity_date' => now()->toDateString(),
                'stakeholder'   => $session->email,
                'category'      => 'link_opened',
                'activity'      => $this->buildSummaryText($session),
            ]
        );
    }

    public function recordEvent(
        PropertyLinkSession $session,
        string $type,
        ?int $propertyFileId = null,
        ?int $durationS = null,
    ): PropertyLinkEvent {
        $event = PropertyLinkEvent::create([
            'session_id' => $session->id,
            'property_file_id' => $propertyFileId,
            'event_type' => $type,
            'duration_s' => $durationS,
        ]);

        $session->touch();
        $session->last_seen_at = now();
        $session->save();

        $this->refreshActivitySummary($session);

        return $event;
    }

    public function refreshActivitySummary(PropertyLinkSession $session): void
    {
        $session->loadMissing('link', 'events');

        Activity::where('link_session_id', $session->id)->update([
            'activity' => $this->buildSummaryText($session),
        ]);
    }

    protected function buildSummaryText(PropertyLinkSession $session): string
    {
        $events = $session->events;
        $viewed = $events->where('event_type', PropertyLinkEvent::TYPE_DOC_VIEWED)->count();
        $downloaded = $events->where('event_type', PropertyLinkEvent::TYPE_DOC_DOWNLOADED)->count();
        $durationMin = max(1, (int) ceil($events->sum('duration_s') / 60));

        return sprintf(
            "hat Link '%s' geoeffnet · %d Dokumente angesehen, %d heruntergeladen · ~%d Min",
            $session->link->name,
            $viewed,
            $downloaded,
            $durationMin,
        );
    }
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Unit/Services/LinkActivityLoggerTest.php`
Expected: `Tests: 1 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/LinkActivityLogger.php tests/Unit/Services/LinkActivityLoggerTest.php
git commit -m "feat: LinkActivityLogger with upserting recordLinkOpened"
```

---

## Task 2.5: `LinkActivityLogger::recordEvent` + summary refresh (TDD)

**Files:**
- Modify: `tests/Unit/Services/LinkActivityLoggerTest.php`

- [ ] **Step 1: Write failing test**

Append to `LinkActivityLoggerTest`:

```php
    public function test_record_event_updates_summary_text_with_counts(): void
    {
        $link = PropertyLink::factory()->create(['name' => 'Phase 2']);
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'bob@example.com',
        ]);

        $logger = new LinkActivityLogger();
        $logger->recordLinkOpened($session);

        $logger->recordEvent($session, \App\Models\PropertyLinkEvent::TYPE_DOC_VIEWED, 42, 60);
        $logger->recordEvent($session, \App\Models\PropertyLinkEvent::TYPE_DOC_VIEWED, 43, 90);
        $logger->recordEvent($session, \App\Models\PropertyLinkEvent::TYPE_DOC_DOWNLOADED, 42, null);

        $activity = \App\Models\Activity::where('link_session_id', $session->id)->first();
        $this->assertStringContainsString('2 Dokumente angesehen', $activity->activity);
        $this->assertStringContainsString('1 heruntergeladen', $activity->activity);
        $this->assertStringContainsString("Phase 2", $activity->activity);

        $this->assertDatabaseCount('property_link_events', 3);
    }
```

- [ ] **Step 2: Run — expect PASS (already implemented above)**

Run: `php artisan test tests/Unit/Services/LinkActivityLoggerTest.php`
Expected: `Tests: 2 passed`

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Services/LinkActivityLoggerTest.php
git commit -m "test: LinkActivityLogger summary text reflects event counts"
```

---

## Task 3.1: Admin controller skeleton + `index` action

**Files:**
- Create: `app/Http/Controllers/Admin/PropertyLinkController.php`
- Create: `tests/Feature/Admin/PropertyLinkControllerTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing feature test**

```php
<?php
// tests/Feature/Admin/PropertyLinkControllerTest.php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    public function test_index_lists_property_links_for_admin(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        PropertyLink::factory()->count(3)->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/admin/properties/{$property->id}/links");

        $response->assertOk()
            ->assertJsonCount(3, 'links');
    }

    public function test_index_rejects_non_admin(): void
    {
        // user_type enum values: admin, makler, backoffice, eigentuemer — 'eigentuemer' is a portal user
        $user = User::factory()->create(['user_type' => 'eigentuemer', 'email_verified_at' => now()]);
        $property = Property::factory()->create();

        $response = $this->actingAs($user)
            ->getJson("/admin/properties/{$property->id}/links");

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — expect FAIL (404, no route)**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php`
Expected: `404 Not Found`

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Admin/PropertyLinkController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Services\PropertyLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyLinkController extends Controller
{
    public function __construct(protected PropertyLinkService $service)
    {
    }

    public function index(Property $property): JsonResponse
    {
        $links = $property->propertyLinks()
            ->withCount(['sessions'])
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PropertyLink $link) => $this->serialize($link));

        return response()->json(['links' => $links]);
    }

    protected function serialize(PropertyLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'token' => $link->token,
            'is_default' => $link->is_default,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at->toIso8601String(),
            'sessions_count' => $link->sessions_count ?? 0,
            'document_ids' => $link->documentIds()->all(),
            'url' => url("/docs/{$link->token}"),
            'status' => $this->statusOf($link),
        ];
    }

    protected function statusOf(PropertyLink $link): string
    {
        if ($link->revoked_at) return 'revoked';
        if ($link->expires_at && $link->expires_at->isPast()) return 'expired';
        return 'active';
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add before the `require __DIR__.'/auth.php';` line:

```php
// Property Links (admin) — see docs/superpowers/specs/2026-04-14-docs-link-sharing-design.md
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])
    ->prefix('admin/properties/{property}/links')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'store']);
        Route::get('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'show']);
        Route::put('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'update']);
        Route::delete('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'destroy']);
        Route::post('/{link}/revoke', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'revoke']);
        Route::post('/{link}/reactivate', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'reactivate']);
    });
```

- [ ] **Step 5: Run — expect PASS**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php --filter=test_index`
Expected: `Tests: 2 passed`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php tests/Feature/Admin/PropertyLinkControllerTest.php routes/web.php
git commit -m "feat: Admin PropertyLinkController index action"
```

---

## Task 3.2: Admin controller — `store`

**Files:**
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php`
- Modify: `tests/Feature/Admin/PropertyLinkControllerTest.php`

- [ ] **Step 1: Write failing test**

Append to `PropertyLinkControllerTest`:

```php
    public function test_store_creates_link_with_documents_and_returns_url(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();

        // Seed two property_files rows
        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'Expose', 'filename' => 'expose.pdf', 'path' => 'p/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100000, 'sort_order' => 1, 'is_website_download' => 0],
            ['property_id' => $property->id, 'label' => 'Grundriss', 'filename' => 'gr.pdf', 'path' => 'p/gr.pdf', 'mime_type' => 'application/pdf', 'file_size' => 50000, 'sort_order' => 2, 'is_website_download' => 0],
        ]);
        $fileIds = \DB::table('property_files')->where('property_id', $property->id)->pluck('id')->all();

        $response = $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links", [
                'name' => 'Erstanfrage',
                'is_default' => true,
                'expires_at' => now()->addDays(14)->toIso8601String(),
                'file_ids' => $fileIds,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['link' => ['id', 'token', 'url', 'is_default', 'document_ids']]);

        $this->assertDatabaseCount('property_links', 1);
        $link = PropertyLink::first();
        $this->assertSame('Erstanfrage', $link->name);
        $this->assertTrue((bool) $link->is_default);
        $this->assertSame(43, strlen($link->token));

        $this->assertDatabaseCount('property_link_documents', 2);
    }

    public function test_store_enforces_single_default_per_property(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        PropertyLink::factory()->default()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);
        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'Expose', 'filename' => 'e.pdf', 'path' => 'e.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'sort_order' => 1, 'is_website_download' => 0],
        ]);
        $fileId = \DB::table('property_files')->where('property_id', $property->id)->value('id');

        $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links", [
                'name' => 'Phase 2',
                'is_default' => true,
                'expires_at' => now()->addDays(7)->toIso8601String(),
                'file_ids' => [$fileId],
            ])->assertOk();

        $defaults = PropertyLink::where('property_id', $property->id)->where('is_default', true)->count();
        $this->assertSame(1, $defaults);
    }
```

- [ ] **Step 2: Run — expect FAIL (store action does not exist)**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php --filter=test_store`
Expected: `405 Method Not Allowed` or similar.

- [ ] **Step 3: Add `store` method**

Append to `PropertyLinkController`:

```php
    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['integer'],
        ]);

        // Validate ownership of each file
        $validFileIds = \DB::table('property_files')
            ->where('property_id', $property->id)
            ->whereIn('id', $data['file_ids'])
            ->pluck('id')
            ->all();

        if (count($validFileIds) !== count($data['file_ids'])) {
            return response()->json(['error' => 'Ein oder mehrere Dokumente gehoeren nicht zu dieser Property'], 422);
        }

        $link = \DB::transaction(function () use ($data, $property, $validFileIds) {
            $link = PropertyLink::create([
                'property_id' => $property->id,
                'name' => $data['name'],
                'token' => $this->service->generateUniqueToken(),
                'is_default' => false, // set via markAsDefault to enforce single-default
                'expires_at' => $data['expires_at'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($validFileIds as $sort => $fileId) {
                \DB::table('property_link_documents')->insert([
                    'property_link_id' => $link->id,
                    'property_file_id' => $fileId,
                    'sort_order' => $sort,
                    'created_at' => now(),
                ]);
            }

            if (!empty($data['is_default'])) {
                $this->service->markAsDefault($link);
                $link->refresh();
            }

            return $link;
        });

        return response()->json(['link' => $this->serialize($link)]);
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php`
Expected: All store tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php tests/Feature/Admin/PropertyLinkControllerTest.php
git commit -m "feat: Admin PropertyLinkController store with file validation and default-lock"
```

---

## Task 3.3: Admin controller — `show` (detail view)

**Files:**
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php`
- Modify: `tests/Feature/Admin/PropertyLinkControllerTest.php`

- [ ] **Step 1: Write failing test**

Append:

```php
    public function test_show_returns_link_with_sessions_and_events(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);
        $session = \App\Models\PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'bob@example.com',
        ]);
        \App\Models\PropertyLinkEvent::create([
            'session_id' => $session->id,
            'event_type' => 'link_opened',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/admin/properties/{$property->id}/links/{$link->id}");

        $response->assertOk()
            ->assertJsonPath('link.id', $link->id)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.email', 'bob@example.com')
            ->assertJsonPath('sessions.0.events.0.event_type', 'link_opened');
    }
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php --filter=test_show`

- [ ] **Step 3: Add `show` method**

Append to `PropertyLinkController`:

```php
    public function show(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->load('sessions.events');

        $sessions = $link->sessions->map(fn ($s) => [
            'id' => $s->id,
            'email' => $s->email,
            'first_seen_at' => $s->first_seen_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'dsgvo_accepted_at' => $s->dsgvo_accepted_at?->toIso8601String(),
            'events' => $s->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'property_file_id' => $e->property_file_id,
                'duration_s' => $e->duration_s,
                'created_at' => $e->created_at,
            ])->values(),
        ])->values();

        return response()->json([
            'link' => $this->serialize($link),
            'sessions' => $sessions,
        ]);
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php --filter=test_show`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php tests/Feature/Admin/PropertyLinkControllerTest.php
git commit -m "feat: Admin PropertyLinkController show action with sessions and events"
```

---

## Task 3.4: Admin controller — `update`

**Files:**
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php`
- Modify: `tests/Feature/Admin/PropertyLinkControllerTest.php`

- [ ] **Step 1: Write failing test**

Append:

```php
    public function test_update_changes_name_expiry_and_documents(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
            'name' => 'Old',
        ]);
        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'A', 'filename' => 'a.pdf', 'path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'sort_order' => 1, 'is_website_download' => 0],
            ['property_id' => $property->id, 'label' => 'B', 'filename' => 'b.pdf', 'path' => 'b.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'sort_order' => 2, 'is_website_download' => 0],
        ]);
        $fileIds = \DB::table('property_files')->where('property_id', $property->id)->pluck('id')->all();

        $response = $this->actingAs($admin)
            ->putJson("/admin/properties/{$property->id}/links/{$link->id}", [
                'name' => 'New Name',
                'expires_at' => now()->addDays(60)->toIso8601String(),
                'file_ids' => $fileIds,
                'is_default' => false,
            ]);

        $response->assertOk();
        $this->assertSame('New Name', $link->fresh()->name);
        $this->assertDatabaseCount('property_link_documents', 2);
    }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Add `update` method**

Append:

```php
    public function update(Request $request, Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['integer'],
        ]);

        $validFileIds = \DB::table('property_files')
            ->where('property_id', $property->id)
            ->whereIn('id', $data['file_ids'])
            ->pluck('id')
            ->all();

        if (count($validFileIds) !== count($data['file_ids'])) {
            return response()->json(['error' => 'Ein oder mehrere Dokumente gehoeren nicht zu dieser Property'], 422);
        }

        \DB::transaction(function () use ($link, $data, $validFileIds) {
            $link->update([
                'name' => $data['name'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            \DB::table('property_link_documents')->where('property_link_id', $link->id)->delete();
            foreach ($validFileIds as $sort => $fileId) {
                \DB::table('property_link_documents')->insert([
                    'property_link_id' => $link->id,
                    'property_file_id' => $fileId,
                    'sort_order' => $sort,
                    'created_at' => now(),
                ]);
            }

            if (!empty($data['is_default'])) {
                $this->service->markAsDefault($link);
            }
        });

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }
```

- [ ] **Step 4: Run — expect PASS; Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php tests/Feature/Admin/PropertyLinkControllerTest.php
git commit -m "feat: Admin PropertyLinkController update action"
```

---

## Task 3.5: Admin controller — `destroy`, `revoke`, `reactivate`

**Files:**
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php`
- Modify: `tests/Feature/Admin/PropertyLinkControllerTest.php`

- [ ] **Step 1: Write failing tests**

Append:

```php
    public function test_destroy_removes_link(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/properties/{$property->id}/links/{$link->id}")
            ->assertOk();

        $this->assertDatabaseCount('property_links', 0);
    }

    public function test_revoke_sets_revoked_at_and_revoked_by(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links/{$link->id}/revoke")
            ->assertOk();

        $link->refresh();
        $this->assertNotNull($link->revoked_at);
        $this->assertSame($admin->id, $link->revoked_by);
    }

    public function test_reactivate_clears_revoked_state(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->revoked()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links/{$link->id}/reactivate")
            ->assertOk();

        $link->refresh();
        $this->assertNull($link->revoked_at);
        $this->assertNull($link->revoked_by);
    }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Add the three methods**

Append to `PropertyLinkController`:

```php
    public function destroy(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->delete();

        return response()->json(['ok' => true]);
    }

    public function revoke(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->update([
            'revoked_at' => now(),
            'revoked_by' => auth()->id(),
        ]);

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }

    public function reactivate(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->update([
            'revoked_at' => null,
            'revoked_by' => null,
        ]);

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }
```

- [ ] **Step 4: Run — expect all tests pass**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php tests/Feature/Admin/PropertyLinkControllerTest.php
git commit -m "feat: Admin PropertyLinkController destroy, revoke, reactivate"
```

---

## Task 4.1: `PropertyLinksTab.vue` — list view

**Files:**
- Create: `resources/js/Components/Admin/Property/PropertyLinksTab.vue`

- [ ] **Step 1: Create the component**

```vue
<!-- resources/js/Components/Admin/Property/PropertyLinksTab.vue -->
<template>
  <div class="property-links-tab">
    <header class="toolbar">
      <h3>Zugriffs-Links</h3>
      <button type="button" class="btn-primary" @click="openCreate">+ Neuer Link</button>
    </header>

    <div v-if="loading" class="skeleton">Lade Links …</div>

    <div v-else-if="links.length === 0" class="empty-state">
      <p>Noch keine Links fuer dieses Objekt. Erstelle den ersten Link fuer Erstanfragen.</p>
    </div>

    <ul v-else class="cards">
      <li
        v-for="link in sortedLinks"
        :key="link.id"
        class="card"
        :class="{ 'is-dimmed': link.status !== 'active', 'is-default': link.is_default }"
      >
        <div class="card-head">
          <h4>
            <span v-if="link.is_default" class="badge badge-default">Standard</span>
            {{ link.name }}
          </h4>
          <span class="status" :data-status="link.status">{{ statusLabel(link.status) }}</span>
        </div>
        <div class="card-meta">
          {{ link.document_ids.length }} Dokument(e) · {{ link.sessions_count }} Aufrufe
          · laeuft am {{ formatDate(link.expires_at) }}
        </div>
        <div class="card-actions">
          <button @click="copyUrl(link)">URL kopieren</button>
          <button @click="openEdit(link)">Bearbeiten</button>
          <button v-if="link.status === 'active'" @click="revoke(link)">Sperren</button>
          <button v-else-if="link.status === 'revoked'" @click="reactivate(link)">Reaktivieren</button>
          <a :href="`/admin/properties/${propertyId}/links/${link.id}`">Details →</a>
        </div>
      </li>
    </ul>

    <PropertyLinkForm
      v-if="formOpen"
      :property-id="propertyId"
      :link="editingLink"
      :available-files="availableFiles"
      @close="closeForm"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import PropertyLinkForm from './PropertyLinkForm.vue';

const props = defineProps({
  propertyId: { type: Number, required: true },
  availableFiles: { type: Array, default: () => [] },
});

const links = ref([]);
const loading = ref(true);
const formOpen = ref(false);
const editingLink = ref(null);

const sortedLinks = computed(() => {
  return [...links.value].sort((a, b) => {
    if (a.is_default !== b.is_default) return a.is_default ? -1 : 1;
    if (a.status !== b.status) return a.status === 'active' ? -1 : 1;
    return new Date(b.created_at) - new Date(a.created_at);
  });
});

async function fetchLinks() {
  loading.value = true;
  const { data } = await axios.get(`/admin/properties/${props.propertyId}/links`);
  links.value = data.links;
  loading.value = false;
}

function statusLabel(s) {
  return { active: 'AKTIV', expired: 'ABGELAUFEN', revoked: 'GESPERRT' }[s] || s;
}

function formatDate(iso) {
  if (!iso) return 'unbegrenzt';
  return new Date(iso).toLocaleDateString('de-AT');
}

async function copyUrl(link) {
  await navigator.clipboard.writeText(link.url);
  window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'Link kopiert' } }));
}

async function revoke(link) {
  if (!confirm(`Link "${link.name}" wirklich sperren?`)) return;
  await axios.post(`/admin/properties/${props.propertyId}/links/${link.id}/revoke`);
  await fetchLinks();
}

async function reactivate(link) {
  await axios.post(`/admin/properties/${props.propertyId}/links/${link.id}/reactivate`);
  await fetchLinks();
}

function openCreate() {
  editingLink.value = null;
  formOpen.value = true;
}

function openEdit(link) {
  editingLink.value = link;
  formOpen.value = true;
}

function closeForm() {
  formOpen.value = false;
  editingLink.value = null;
}

async function onSaved(link) {
  closeForm();
  if (link?.url) {
    await navigator.clipboard.writeText(link.url);
    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'Link erstellt & kopiert' } }));
  }
  await fetchLinks();
}

onMounted(fetchLinks);
</script>

<style scoped>
.property-links-tab { padding: 24px; }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.toolbar h3 { font-size: 20px; font-weight: 600; color: #0A0A08; }
.btn-primary { background: #D4743B; color: white; padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; font-weight: 500; transition: background 200ms; }
.btn-primary:hover { background: #C0551F; }
.cards { list-style: none; padding: 0; display: grid; gap: 14px; }
.card { border: 1px solid #E5E0D8; border-radius: 12px; padding: 18px; background: #FFFFFF; transition: all 250ms cubic-bezier(0.25,0.46,0.45,0.94); }
.card:hover { box-shadow: 0 4px 24px rgba(10,10,8,0.08); transform: translateY(-2px); }
.card.is-dimmed { opacity: 0.55; }
.card.is-default { border-color: #D4743B; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.card-head h4 { font-size: 16px; font-weight: 600; color: #0A0A08; display: flex; gap: 8px; align-items: center; }
.badge-default { background: #D4743B; color: white; font-size: 11px; font-weight: 500; padding: 2px 8px; border-radius: 4px; }
.status { font-size: 11px; font-weight: 600; color: #5A564E; }
.status[data-status="active"] { color: #15803d; }
.status[data-status="expired"] { color: #b45309; }
.status[data-status="revoked"] { color: #b91c1c; }
.card-meta { font-size: 13px; color: #5A564E; margin-bottom: 12px; }
.card-actions { display: flex; gap: 10px; font-size: 13px; }
.card-actions button, .card-actions a { background: transparent; border: 1px solid #E5E0D8; color: #0A0A08; padding: 6px 12px; border-radius: 6px; cursor: pointer; text-decoration: none; }
.card-actions button:hover, .card-actions a:hover { border-color: #D4743B; color: #D4743B; }
.empty-state { padding: 40px; text-align: center; color: #5A564E; background: #FAF8F5; border-radius: 12px; }
.skeleton { padding: 20px; color: #5A564E; }
</style>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/Property/PropertyLinksTab.vue
git commit -m "feat: PropertyLinksTab.vue with list, revoke, reactivate, copy"
```

---

## Task 4.2: `PropertyLinkForm.vue` — slide-over create/edit

**Files:**
- Create: `resources/js/Components/Admin/Property/PropertyLinkForm.vue`

- [ ] **Step 1: Create the component**

```vue
<!-- resources/js/Components/Admin/Property/PropertyLinkForm.vue -->
<template>
  <Teleport to="body">
    <div class="slideover-backdrop" @click.self="$emit('close')">
      <aside class="slideover">
        <header>
          <h3>{{ link ? 'Link bearbeiten' : 'Neuer Link' }}</h3>
          <button class="close-btn" @click="$emit('close')">×</button>
        </header>

        <div class="body">
          <label class="field">
            <span>Name</span>
            <input v-model="form.name" type="text" placeholder="z.B. Erstanfrage / Phase 2 / Besichtigung" maxlength="120" />
          </label>

          <label class="field field-toggle">
            <input v-model="form.is_default" type="checkbox" />
            <span>Als Standard-Link fuer Erstanfragen verwenden</span>
          </label>

          <label class="field">
            <span>Gueltig fuer</span>
            <select v-model="form.expiry_days">
              <option :value="7">7 Tage</option>
              <option :value="14">14 Tage</option>
              <option :value="30">30 Tage</option>
              <option :value="90">90 Tage</option>
              <option :value="null">Unbegrenzt</option>
            </select>
          </label>

          <div class="field">
            <span>Dokumente ({{ selectedIds.length }} / {{ availableFiles.length }})</span>
            <ul class="file-list">
              <li v-for="file in availableFiles" :key="file.id">
                <label>
                  <input
                    type="checkbox"
                    :value="file.id"
                    :checked="selectedIds.includes(file.id)"
                    @change="toggleFile(file.id)"
                  />
                  <span class="file-label">{{ file.label || file.filename }}</span>
                  <span class="file-size">{{ formatSize(file.file_size) }}</span>
                </label>
              </li>
            </ul>
          </div>
        </div>

        <footer>
          <button class="btn-secondary" @click="$emit('close')">Abbrechen</button>
          <button class="btn-primary" :disabled="!canSave || saving" @click="save">
            {{ saving ? 'Speichere …' : 'Speichern' }}
          </button>
        </footer>
      </aside>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  propertyId: { type: Number, required: true },
  link: { type: Object, default: null },
  availableFiles: { type: Array, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = ref({
  name: props.link?.name ?? '',
  is_default: props.link?.is_default ?? false,
  expiry_days: 30,
});
const selectedIds = ref(props.link?.document_ids ?? []);
const saving = ref(false);

const canSave = computed(() => form.value.name.trim() && selectedIds.value.length > 0);

function toggleFile(id) {
  const i = selectedIds.value.indexOf(id);
  if (i >= 0) selectedIds.value.splice(i, 1);
  else selectedIds.value.push(id);
}

function formatSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

async function save() {
  saving.value = true;
  const payload = {
    name: form.value.name.trim(),
    is_default: form.value.is_default,
    expires_at: form.value.expiry_days ? new Date(Date.now() + form.value.expiry_days * 86400000).toISOString() : null,
    file_ids: selectedIds.value,
  };

  try {
    const url = props.link
      ? `/admin/properties/${props.propertyId}/links/${props.link.id}`
      : `/admin/properties/${props.propertyId}/links`;
    const method = props.link ? 'put' : 'post';
    const { data } = await axios[method](url, payload);
    emit('saved', data.link);
  } catch (e) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: e.response?.data?.error || 'Fehler beim Speichern' } }));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  if (props.link) {
    const days = props.link.expires_at
      ? Math.round((new Date(props.link.expires_at) - Date.now()) / 86400000)
      : null;
    form.value.expiry_days = [7, 14, 30, 90].includes(days) ? days : null;
  }
});
</script>

<style scoped>
.slideover-backdrop { position: fixed; inset: 0; background: rgba(10,10,8,0.4); z-index: 1000; display: flex; justify-content: flex-end; }
.slideover { width: 480px; max-width: 100vw; background: white; height: 100vh; display: flex; flex-direction: column; animation: slide-in 300ms cubic-bezier(0.25,0.46,0.45,0.94); }
@keyframes slide-in { from { transform: translateX(100%); } to { transform: translateX(0); } }
header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #E5E0D8; }
header h3 { font-size: 18px; font-weight: 600; color: #0A0A08; }
.close-btn { background: transparent; border: none; font-size: 28px; color: #5A564E; cursor: pointer; }
.body { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 18px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field > span { font-size: 13px; font-weight: 500; color: #0A0A08; }
.field input[type="text"], .field select { padding: 10px 12px; border: 1px solid #E5E0D8; border-radius: 8px; font-size: 14px; font-family: inherit; }
.field input:focus, .field select:focus { outline: none; border-color: #D4743B; }
.field-toggle { flex-direction: row; align-items: center; gap: 10px; }
.file-list { list-style: none; padding: 0; max-height: 260px; overflow-y: auto; border: 1px solid #E5E0D8; border-radius: 8px; }
.file-list li { padding: 10px 12px; border-bottom: 1px solid #F0ECE5; }
.file-list li:last-child { border-bottom: none; }
.file-list label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; }
.file-label { flex: 1; color: #0A0A08; }
.file-size { color: #5A564E; font-variant-numeric: tabular-nums; }
footer { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 24px; border-top: 1px solid #E5E0D8; }
.btn-primary, .btn-secondary { padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
.btn-primary { background: #D4743B; color: white; border: none; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary:hover:not(:disabled) { background: #C0551F; }
.btn-secondary { background: transparent; border: 1px solid #E5E0D8; color: #0A0A08; }
.btn-secondary:hover { border-color: #D4743B; }
</style>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/Property/PropertyLinkForm.vue
git commit -m "feat: PropertyLinkForm slide-over with file picker and expiry"
```

---

## Task 4.3: Wire Links tab into `PropertyDetailPage.vue`

**Files:**
- Modify: `resources/js/Pages/Admin/PropertyDetailPage.vue`

- [ ] **Step 1: Find the existing tabs area and add the Links tab**

In `PropertyDetailPage.vue`, locate the `<script setup>` section and add this import near the existing Property sub-component imports:

```js
import PropertyLinksTab from '@/Components/Admin/Property/PropertyLinksTab.vue';
```

Then find the tabs list (search for `activeTab` or `tab ===`) and add a new tab entry `'links'`. In the template, add a new tab button and a new tab panel:

```vue
<button
  type="button"
  class="tab-btn"
  :class="{ active: activeTab === 'links' }"
  @click="activeTab = 'links'"
>
  Links
</button>
```

```vue
<PropertyLinksTab
  v-if="activeTab === 'links'"
  :property-id="property.id"
  :available-files="property.files || []"
/>
```

Note: The backend is expected to include `files` in the `property` prop passed to this page. If it doesn't, a follow-up sub-step adds it to the page's data source.

- [ ] **Step 2: Verify in browser** (manual)

Start dev server: `cd /Users/max/srhomes && npm run dev` in one terminal and `php artisan serve` in another.
Open `http://localhost:8000/admin/properties/{id}` and click the "Links" tab. Expect empty state with "Neuer Link" button.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/PropertyDetailPage.vue
git commit -m "feat: wire Links tab into PropertyDetailPage"
```

---

## Task 5.1: `PropertyLinkDetail.vue` — detail page

**Files:**
- Create: `resources/js/Pages/Admin/PropertyLinkDetail.vue`
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php` — add Inertia::render for browser route
- Modify: `routes/web.php` — split the show route: Inertia HTML response vs JSON

**Note:** The existing `show` action returns JSON. For a browser hit we need Inertia to render the page. We split the action by checking `Request::wantsJson()`.

- [ ] **Step 1: Update `show` action to render Inertia when non-JSON**

In `PropertyLinkController::show()`, change the top of the method:

```php
    public function show(Request $request, Property $property, PropertyLink $link)
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->load('sessions.events');

        $sessions = $link->sessions->map(fn ($s) => [
            'id' => $s->id,
            'email' => $s->email,
            'first_seen_at' => $s->first_seen_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'dsgvo_accepted_at' => $s->dsgvo_accepted_at?->toIso8601String(),
            'events' => $s->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'property_file_id' => $e->property_file_id,
                'duration_s' => $e->duration_s,
                'created_at' => $e->created_at,
            ])->values(),
        ])->values();

        $payload = [
            'link' => $this->serialize($link),
            'sessions' => $sessions,
            'property' => ['id' => $property->id, 'project_name' => $property->project_name, 'address' => $property->address],
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return \Inertia\Inertia::render('Admin/PropertyLinkDetail', $payload);
    }
```

- [ ] **Step 2: Create the Vue page**

```vue
<!-- resources/js/Pages/Admin/PropertyLinkDetail.vue -->
<template>
  <div class="detail-page">
    <header class="detail-header">
      <a :href="`/admin/properties/${property.id}`" class="back">← Zurueck zu {{ property.project_name }}</a>
      <h1>{{ link.name }}</h1>
      <div class="meta">
        <span class="status" :data-status="link.status">{{ statusLabel(link.status) }}</span>
        <span>Laeuft am {{ formatDate(link.expires_at) }}</span>
        <span>Erstellt am {{ formatDate(link.created_at) }}</span>
      </div>
      <div class="url-box">
        <code>{{ link.url }}</code>
        <button @click="copyUrl">Kopieren</button>
      </div>
    </header>

    <section class="metrics">
      <div class="metric">
        <strong>{{ totalOpens }}</strong>
        <span>Aufrufe</span>
      </div>
      <div class="metric">
        <strong>{{ sessions.length }}</strong>
        <span>Personen</span>
      </div>
      <div class="metric">
        <strong>{{ totalViews }}</strong>
        <span>Dokument-Ansichten</span>
      </div>
      <div class="metric">
        <strong>{{ totalDownloads }}</strong>
        <span>Downloads</span>
      </div>
    </section>

    <section class="timeline">
      <h2>Aktivitaet</h2>
      <div v-if="sessions.length === 0" class="empty">Noch keine Zugriffe.</div>
      <ul v-else>
        <li v-for="session in sessions" :key="session.id">
          <div class="session-head">
            <strong>{{ session.email }}</strong>
            <span>{{ formatDateTime(session.first_seen_at) }}</span>
          </div>
          <ul class="events">
            <li v-for="event in session.events" :key="event.id">
              <span class="event-type" :data-type="event.event_type">{{ eventLabel(event.event_type) }}</span>
              <span class="event-meta">{{ formatDateTime(event.created_at) }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  link: Object,
  sessions: Array,
  property: Object,
});

const totalOpens = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'link_opened').length, 0)
);
const totalViews = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_viewed').length, 0)
);
const totalDownloads = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_downloaded').length, 0)
);

function statusLabel(s) {
  return { active: 'AKTIV', expired: 'ABGELAUFEN', revoked: 'GESPERRT' }[s] || s;
}
function formatDate(iso) {
  if (!iso) return 'unbegrenzt';
  return new Date(iso).toLocaleDateString('de-AT');
}
function formatDateTime(iso) {
  return new Date(iso).toLocaleString('de-AT');
}
function eventLabel(t) {
  return { link_opened: 'Link geoeffnet', doc_viewed: 'Dokument angesehen', doc_downloaded: 'Heruntergeladen' }[t] || t;
}
async function copyUrl() {
  await navigator.clipboard.writeText(props.link.url);
  window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'URL kopiert' } }));
}
</script>

<style scoped>
.detail-page { max-width: 1100px; margin: 0 auto; padding: 40px 32px; font-family: 'Outfit', sans-serif; color: #0A0A08; }
.back { color: #5A564E; text-decoration: none; font-size: 14px; }
.back:hover { color: #D4743B; }
h1 { font-size: 32px; font-weight: 600; margin: 12px 0 6px; }
.meta { display: flex; gap: 16px; color: #5A564E; font-size: 14px; margin-bottom: 20px; }
.status { font-weight: 600; }
.status[data-status="active"] { color: #15803d; }
.status[data-status="expired"] { color: #b45309; }
.status[data-status="revoked"] { color: #b91c1c; }
.url-box { display: flex; gap: 8px; background: #FAF8F5; border: 1px solid #E5E0D8; border-radius: 12px; padding: 14px 18px; align-items: center; max-width: 720px; }
.url-box code { flex: 1; color: #0A0A08; font-family: 'JetBrains Mono', monospace; font-size: 13px; }
.url-box button { background: #D4743B; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; }
.metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 32px 0; }
.metric { background: white; border: 1px solid #E5E0D8; border-radius: 12px; padding: 24px; text-align: center; }
.metric strong { display: block; font-size: 32px; font-weight: 600; color: #D4743B; margin-bottom: 4px; }
.metric span { font-size: 13px; color: #5A564E; }
.timeline h2 { font-size: 22px; font-weight: 600; margin-bottom: 16px; }
.timeline ul { list-style: none; padding: 0; }
.timeline > ul > li { border-bottom: 1px solid #E5E0D8; padding: 16px 0; }
.session-head { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
.events { padding-left: 16px; }
.events li { display: flex; justify-content: space-between; font-size: 13px; color: #5A564E; padding: 4px 0; }
.event-type { font-weight: 500; color: #0A0A08; }
.event-type[data-type="doc_downloaded"] { color: #D4743B; }
.empty { padding: 40px; text-align: center; color: #5A564E; background: #FAF8F5; border-radius: 12px; }
</style>
```

- [ ] **Step 3: Run the feature test to make sure JSON mode still works**

Run: `php artisan test tests/Feature/Admin/PropertyLinkControllerTest.php --filter=test_show`
Expected: Still passes.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Admin/PropertyLinkDetail.vue app/Http/Controllers/Admin/PropertyLinkController.php
git commit -m "feat: PropertyLinkDetail Inertia page with metrics and timeline"
```

---

## Task 6.1: `PublicDocumentController::show` — landing + unlocked states

**Files:**
- Create: `app/Http/Controllers/PublicDocumentController.php`
- Create: `tests/Feature/PublicDocumentControllerTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/PublicDocumentControllerTest.php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function makeLink(array $overrides = []): PropertyLink
    {
        return PropertyLink::factory()->create($overrides);
    }

    public function test_show_renders_email_gate_for_valid_token_without_cookie(): void
    {
        $link = $this->makeLink();

        $response = $this->get("/docs/{$link->token}");

        $response->assertOk()
            ->assertSee('Unterlagen ansehen')
            ->assertSee('Ich stimme zu', false);
    }

    public function test_show_returns_410_for_expired_token(): void
    {
        $link = $this->makeLink(['expires_at' => now()->subDay()]);

        $response = $this->get("/docs/{$link->token}");

        $response->assertStatus(410);
    }

    public function test_show_returns_410_for_revoked_token(): void
    {
        $link = $this->makeLink(['revoked_at' => now()]);

        $response = $this->get("/docs/{$link->token}");

        $response->assertStatus(410);
    }

    public function test_show_returns_404_for_unknown_token(): void
    {
        $response = $this->get("/docs/unknown-token-does-not-exist-in-db-for-sure-42");

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php`
Expected: `404` or similar — no route defined.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/PublicDocumentController.php

namespace App\Http\Controllers;

use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Services\LinkActivityLogger;
use App\Services\PropertyLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PublicDocumentController extends Controller
{
    public function __construct(
        protected PropertyLinkService $service,
        protected LinkActivityLogger $logger,
    ) {
    }

    public function show(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();

        if (!$link) {
            return response()->view('docs.error', ['reason' => 'not_found'], 404);
        }

        if ($link->revoked_at) {
            return response()->view('docs.error', ['reason' => 'revoked', 'link' => $link], 410);
        }

        if ($link->expires_at && $link->expires_at->isPast()) {
            return response()->view('docs.error', ['reason' => 'expired', 'link' => $link], 410);
        }

        // Check session cookie
        $session = $this->resolveSessionFromCookie($request, $link);

        $link->load('property');

        if ($session) {
            $files = DB::table('property_files')
                ->whereIn('id', $link->documentIds())
                ->get();

            return response()->view('docs.landing', [
                'link' => $link,
                'session' => $session,
                'files' => $files,
                'state' => 'unlocked',
            ]);
        }

        return response()->view('docs.landing', [
            'link' => $link,
            'state' => 'locked',
        ]);
    }

    protected function resolveSessionFromCookie(Request $request, PropertyLink $link): ?PropertyLinkSession
    {
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $request->cookie($cookieName);

        if (!$cookieValue || !str_contains($cookieValue, '.')) {
            return null;
        }

        [$sessionId, $hmac] = explode('.', $cookieValue, 2);

        if (!hash_equals(hash_hmac('sha256', $sessionId, config('app.key')), $hmac)) {
            return null;
        }

        return PropertyLinkSession::where('id', $sessionId)
            ->where('property_link_id', $link->id)
            ->first();
    }
}
```

- [ ] **Step 4: Register the public routes**

In `routes/web.php`, add after the admin links group:

```php
// Public document delivery
Route::prefix('docs')->group(function () {
    Route::get('{token}', [\App\Http\Controllers\PublicDocumentController::class, 'show'])->name('docs.show');
    Route::post('{token}/unlock', [\App\Http\Controllers\PublicDocumentController::class, 'unlock']);
    Route::get('{token}/file/{fileId}/{mode}', [\App\Http\Controllers\PublicDocumentController::class, 'file'])->where('mode', 'view|download');
    Route::post('{token}/event', [\App\Http\Controllers\PublicDocumentController::class, 'event']);
});
```

- [ ] **Step 5: Create stub Blade templates (content filled in Task 7)**

Create `resources/views/docs/landing.blade.php`:

```blade
@if ($state === 'locked')
    <div>
        <form method="POST" action="/docs/{{ $link->token }}/unlock">
            @csrf
            <label>Email <input type="email" name="email" required></label>
            <label><input type="checkbox" name="dsgvo" required> Ich stimme zu, dass meine Daten verarbeitet werden.</label>
            <button type="submit">Unterlagen ansehen</button>
        </form>
    </div>
@else
    <div>
        @foreach ($files as $file)
            <a href="/docs/{{ $link->token }}/file/{{ $file->id }}/view">{{ $file->label ?: $file->filename }}</a>
        @endforeach
    </div>
@endif
```

Create `resources/views/docs/error.blade.php`:

```blade
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Unterlagen nicht verfuegbar</title></head>
<body>
<main>
    @if ($reason === 'not_found')
        <h1>Link nicht gefunden</h1>
        <p>Dieser Link existiert nicht oder wurde entfernt.</p>
    @elseif ($reason === 'expired')
        <h1>Link abgelaufen</h1>
        <p>Dieser Link ist am {{ $link->expires_at->format('d.m.Y') }} abgelaufen. Bitte kontaktieren Sie uns fuer einen neuen Link.</p>
    @elseif ($reason === 'revoked')
        <h1>Zugriff beendet</h1>
        <p>Der Zugriff wurde beendet. Bitte kontaktieren Sie uns fuer Details.</p>
    @endif
</main>
</body>
</html>
```

- [ ] **Step 6: Run — expect PASS**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php`
Expected: All 4 show tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php tests/Feature/PublicDocumentControllerTest.php routes/web.php resources/views/docs/landing.blade.php resources/views/docs/error.blade.php
git commit -m "feat: PublicDocumentController show action with email gate and error states"
```

---

## Task 6.2: `PublicDocumentController::unlock` — email gate

**Files:**
- Modify: `app/Http/Controllers/PublicDocumentController.php`
- Modify: `tests/Feature/PublicDocumentControllerTest.php`

- [ ] **Step 1: Write failing tests**

Append:

```php
    public function test_unlock_creates_session_and_sets_cookie_with_dsgvo(): void
    {
        $link = $this->makeLink();

        $response = $this->post("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
            'dsgvo' => '1',
        ]);

        $response->assertRedirect("/docs/{$link->token}");
        $this->assertDatabaseCount('property_link_sessions', 1);

        $session = \App\Models\PropertyLinkSession::first();
        $this->assertSame('lisa@example.com', $session->email);
        $this->assertNotNull($session->dsgvo_accepted_at);
        $this->assertSame(64, strlen($session->ip_hash));

        // Cookie set
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $response->assertCookie($cookieName);

        // Activity written
        $this->assertDatabaseCount('activities', 1);
        $this->assertDatabaseHas('activities', [
            'stakeholder' => 'lisa@example.com',
            'category' => 'link_opened',
            'link_session_id' => $session->id,
        ]);
    }

    public function test_unlock_rejects_missing_dsgvo(): void
    {
        $link = $this->makeLink();

        $response = $this->post("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('property_link_sessions', 0);
    }

    public function test_unlock_rate_limits_after_10_attempts(): void
    {
        $link = $this->makeLink();

        for ($i = 0; $i < 10; $i++) {
            $this->post("/docs/{$link->token}/unlock", [
                'email' => "spam{$i}@example.com",
                'dsgvo' => '1',
            ]);
        }

        $response = $this->post("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
            'dsgvo' => '1',
        ]);

        $response->assertStatus(429);
    }

    public function test_unlock_reuses_session_for_same_email_within_24h(): void
    {
        $link = $this->makeLink();

        $this->post("/docs/{$link->token}/unlock", ['email' => 'a@a.com', 'dsgvo' => '1']);
        $this->post("/docs/{$link->token}/unlock", ['email' => 'a@a.com', 'dsgvo' => '1']);

        $this->assertDatabaseCount('property_link_sessions', 1);
    }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Add `unlock` method**

Append to `PublicDocumentController`:

```php
    public function unlock(Request $request, string $token)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);

        if ($link->revoked_at || ($link->expires_at && $link->expires_at->isPast())) {
            return response()->view('docs.error', ['reason' => $link->revoked_at ? 'revoked' : 'expired', 'link' => $link], 410);
        }

        $rateLimitKey = "unlock:{$token}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return response()->view('docs.error', ['reason' => 'rate_limited', 'link' => $link], 429);
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'dsgvo' => ['required', 'accepted'],
        ]);

        $email = strtolower(trim($data['email']));
        $salt = config('app.key');

        $session = PropertyLinkSession::where('property_link_id', $link->id)
            ->where('email', $email)
            ->where('last_seen_at', '>', now()->subDay())
            ->first();

        if (!$session) {
            $session = PropertyLinkSession::create([
                'property_link_id' => $link->id,
                'email' => $email,
                'dsgvo_accepted_at' => now(),
                'ip_hash' => hash('sha256', $request->ip() . $salt),
                'user_agent_hash' => hash('sha256', ($request->userAgent() ?? '') . $salt),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
            ]);
        } else {
            $session->update(['last_seen_at' => now()]);
        }

        // Log first "link_opened" event and activity
        $this->logger->recordEvent($session, \App\Models\PropertyLinkEvent::TYPE_LINK_OPENED);
        $this->logger->recordLinkOpened($session);

        // Set session cookie
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $session->id . '.' . hash_hmac('sha256', (string) $session->id, $salt);

        return redirect("/docs/{$link->token}")->cookie(
            $cookieName,
            $cookieValue,
            1440, // 24h
            '/',
            null,
            true, // secure
            true, // httpOnly
            false,
            'lax'
        );
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php --filter=test_unlock`
Expected: All unlock tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php tests/Feature/PublicDocumentControllerTest.php
git commit -m "feat: PublicDocumentController unlock with rate-limit, session upsert, cookie"
```

---

## Task 6.3: `PublicDocumentController::file` — stream view + download

**Files:**
- Modify: `app/Http/Controllers/PublicDocumentController.php`
- Modify: `tests/Feature/PublicDocumentControllerTest.php`

- [ ] **Step 1: Write failing tests**

Append:

```php
    public function test_file_requires_valid_session_cookie(): void
    {
        $link = $this->makeLink();
        $fileId = \DB::table('property_files')->insertGetId([
            'property_id' => $link->property_id, 'label' => 'Expose', 'filename' => 'expose.pdf',
            'path' => 'test/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100,
            'sort_order' => 1, 'is_website_download' => 0, 'created_at' => now(),
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id' => $link->id, 'property_file_id' => $fileId, 'sort_order' => 0, 'created_at' => now(),
        ]);

        // No cookie → rejected
        $response = $this->get("/docs/{$link->token}/file/{$fileId}/view");
        $response->assertStatus(403);
    }

    public function test_file_downloads_pdf_when_session_valid(): void
    {
        $link = $this->makeLink();
        \Storage::fake('local');
        \Storage::put('test/expose.pdf', 'fake pdf content');

        $fileId = \DB::table('property_files')->insertGetId([
            'property_id' => $link->property_id, 'label' => 'Expose', 'filename' => 'expose.pdf',
            'path' => 'test/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 16,
            'sort_order' => 1, 'is_website_download' => 0, 'created_at' => now(),
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id' => $link->id, 'property_file_id' => $fileId, 'sort_order' => 0, 'created_at' => now(),
        ]);

        $session = \App\Models\PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'lisa@example.com',
        ]);
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $session->id . '.' . hash_hmac('sha256', (string) $session->id, config('app.key'));

        $response = $this->withCookie($cookieName, $cookieValue)
            ->get("/docs/{$link->token}/file/{$fileId}/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition');
    }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Add `file` method**

Append to `PublicDocumentController`:

```php
    public function file(Request $request, string $token, int $fileId, string $mode)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);
        abort_if($link->revoked_at || ($link->expires_at && $link->expires_at->isPast()), 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        abort_unless($session, 403);

        // Check the file belongs to the link
        $allowed = DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->where('property_file_id', $fileId)
            ->exists();
        abort_unless($allowed, 403);

        $file = DB::table('property_files')->where('id', $fileId)->first();
        abort_unless($file, 404);

        $disk = \Storage::disk('local');
        abort_unless($disk->exists($file->path), 404);

        // Log the event
        $eventType = $mode === 'download'
            ? \App\Models\PropertyLinkEvent::TYPE_DOC_DOWNLOADED
            : \App\Models\PropertyLinkEvent::TYPE_DOC_VIEWED;
        $this->logger->recordEvent($session, $eventType, $fileId);

        $headers = [
            'Content-Type' => $file->mime_type ?? 'application/pdf',
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $mode === 'download' ? 'attachment' : 'inline',
                addslashes($file->filename),
            ),
            'Cache-Control' => 'no-store, private',
        ];

        return response($disk->get($file->path), 200, $headers);
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php --filter=test_file`
Expected: Both file tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php tests/Feature/PublicDocumentControllerTest.php
git commit -m "feat: PublicDocumentController file action with session guard and event log"
```

---

## Task 6.4: `PublicDocumentController::event` — JS heartbeat endpoint

**Files:**
- Modify: `app/Http/Controllers/PublicDocumentController.php`
- Modify: `tests/Feature/PublicDocumentControllerTest.php`

- [ ] **Step 1: Write failing test**

Append:

```php
    public function test_event_endpoint_creates_event_and_updates_activity_summary(): void
    {
        $link = $this->makeLink();
        $session = \App\Models\PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'lisa@example.com',
        ]);
        // Seed an initial activity row (normally created by unlock)
        \App\Models\Activity::create([
            'property_id' => $link->property_id,
            'activity_date' => now()->toDateString(),
            'stakeholder' => 'lisa@example.com',
            'activity' => 'initial',
            'category' => 'link_opened',
            'link_session_id' => $session->id,
        ]);

        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $session->id . '.' . hash_hmac('sha256', (string) $session->id, config('app.key'));

        $response = $this->withCookie($cookieName, $cookieValue)
            ->postJson("/docs/{$link->token}/event", [
                'type' => 'doc_viewed',
                'file_id' => 99,
                'duration_s' => 45,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('property_link_events', [
            'session_id' => $session->id,
            'event_type' => 'doc_viewed',
            'property_file_id' => 99,
            'duration_s' => 45,
        ]);

        // Activity summary reflects the new event
        $activity = \App\Models\Activity::where('link_session_id', $session->id)->first();
        $this->assertStringContainsString('1 Dokumente angesehen', $activity->activity);
    }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Add `event` method**

Append:

```php
    public function event(Request $request, string $token)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);
        abort_if($link->revoked_at || ($link->expires_at && $link->expires_at->isPast()), 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        abort_unless($session, 403);

        $data = $request->validate([
            'type' => ['required', 'in:doc_viewed,doc_downloaded'],
            'file_id' => ['nullable', 'integer'],
            'duration_s' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        // Rate limit: 100 events/session/hour
        $key = "event:session:{$session->id}";
        if (RateLimiter::tooManyAttempts($key, 100)) {
            return response()->json(['error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 3600);

        $this->logger->recordEvent(
            $session,
            $data['type'],
            $data['file_id'] ?? null,
            $data['duration_s'] ?? null,
        );

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 4: Run — expect PASS**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicDocumentController.php tests/Feature/PublicDocumentControllerTest.php
git commit -m "feat: PublicDocumentController event endpoint with session guard and rate limit"
```

---

## Task 7.1: Public landing Blade shell + CSS

**Files:**
- Rewrite: `resources/views/docs/landing.blade.php`
- Create: `resources/views/docs/partials/_email_gate.blade.php`
- Create: `resources/views/docs/partials/_unlocked.blade.php`
- Create: `public/docs/docs.css`

- [ ] **Step 1: Create `docs.css` with design tokens**

```css
/* public/docs/docs.css */
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

:root {
  --bg-cream: #FAF8F5;
  --bg-dark: #0A0A08;
  --accent: #D4743B;
  --accent-hover: #C0551F;
  --text-strong: #0A0A08;
  --text-muted: #5A564E;
  --border-soft: #E5E0D8;
  --radius-md: 12px;
  --radius-lg: 16px;
  --radius-xl: 24px;
  --shadow-soft: 0 4px 24px rgba(10,10,8,0.06);
  --shadow-hover: 0 12px 48px rgba(10,10,8,0.12);
  --ease: cubic-bezier(0.25, 0.46, 0.45, 0.94);
  --dur: 300ms;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Outfit', -apple-system, system-ui, sans-serif; background: var(--bg-cream); color: var(--text-strong); line-height: 1.5; }

.docs-container { max-width: 1200px; margin: 0 auto; padding: 40px 24px; }

.hero {
  position: relative;
  height: 560px;
  border-radius: var(--radius-lg);
  overflow: hidden;
  margin-bottom: 48px;
}
.hero img { width: 100%; height: 100%; object-fit: cover; }
.hero::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to bottom, rgba(10,10,8,0) 40%, rgba(10,10,8,0.75)); }
.hero-text { position: absolute; bottom: 48px; left: 48px; color: white; z-index: 1; }
.hero-text h1 { font-size: 64px; font-weight: 600; line-height: 1.1; margin-bottom: 12px; }
.hero-text .meta { font-size: 18px; opacity: 0.85; }

.email-gate-card {
  max-width: 560px;
  margin: 0 auto;
  background: white;
  border-radius: var(--radius-xl);
  padding: 48px;
  box-shadow: var(--shadow-soft);
}
.email-gate-card h2 { font-size: 26px; font-weight: 600; margin-bottom: 12px; }
.email-gate-card p { color: var(--text-muted); margin-bottom: 28px; }
.form-field { margin-bottom: 24px; }
.underline-input {
  width: 100%;
  border: none;
  border-bottom: 2px solid var(--border-soft);
  padding: 14px 0;
  font-size: 16px;
  font-family: inherit;
  background: transparent;
  transition: border-color var(--dur) var(--ease);
}
.underline-input:focus { outline: none; border-color: var(--accent); }
.dsgvo-check { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 28px; font-size: 13px; color: var(--text-muted); }
.dsgvo-check input { margin-top: 4px; }
.cta-primary {
  width: 100%;
  height: 56px;
  background: var(--accent);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
  transition: all var(--dur) var(--ease);
}
.cta-primary:hover { background: var(--accent-hover); transform: scale(1.02); box-shadow: var(--shadow-hover); }

.unlocked-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
  margin-top: 40px;
}
.doc-card {
  background: white;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-lg);
  padding: 32px;
  box-shadow: var(--shadow-soft);
  transition: all 250ms var(--ease);
  animation: card-fade-in 400ms var(--ease) both;
}
.doc-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
@keyframes card-fade-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.doc-card h3 { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
.doc-card .file-size { color: var(--text-muted); font-size: 13px; margin-bottom: 20px; }
.doc-card .actions { display: flex; gap: 10px; }
.doc-card .btn-view, .doc-card .btn-download {
  flex: 1;
  padding: 10px 16px;
  border-radius: 8px;
  font-size: 14px;
  text-align: center;
  cursor: pointer;
  border: 1px solid var(--border-soft);
  background: transparent;
  color: var(--text-strong);
  text-decoration: none;
  transition: all 200ms var(--ease);
}
.doc-card .btn-view { background: var(--accent); color: white; border-color: var(--accent); }
.doc-card .btn-view:hover { background: var(--accent-hover); }
.doc-card .btn-download:hover { border-color: var(--accent); color: var(--accent); }

@media (max-width: 960px) {
  .hero { height: 320px; }
  .hero-text h1 { font-size: 36px; }
  .hero-text { bottom: 24px; left: 24px; right: 24px; }
  .email-gate-card { padding: 32px 24px; }
  .unlocked-grid { grid-template-columns: 1fr; gap: 16px; }
}

/* Error pages */
.error-page { text-align: center; padding: 120px 24px; }
.error-page h1 { font-size: 40px; font-weight: 600; margin-bottom: 16px; }
.error-page p { color: var(--text-muted); font-size: 17px; max-width: 560px; margin: 0 auto; }
.error-page .cta-link { display: inline-block; margin-top: 32px; color: var(--accent); font-weight: 500; }
```

- [ ] **Step 2: Rewrite `landing.blade.php` as shell**

```blade
<!-- resources/views/docs/landing.blade.php -->
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $link->property->project_name ?? 'Wohnprojekt' }} · Unterlagen</title>
    <link rel="stylesheet" href="{{ asset('docs/docs.css') }}">
</head>
<body>
<main class="docs-container">
    @if ($state === 'locked')
        @include('docs.partials._email_gate', ['link' => $link])
    @else
        @include('docs.partials._unlocked', ['link' => $link, 'files' => $files, 'session' => $session])
    @endif
</main>
</body>
</html>
```

- [ ] **Step 3: Create `_email_gate.blade.php`**

```blade
<!-- resources/views/docs/partials/_email_gate.blade.php -->
<section class="hero">
    <img src="{{ $link->property->title_image_url ?? 'https://via.placeholder.com/1200x560' }}" alt="">
    <div class="hero-text">
        <h1>{{ $link->property->project_name ?? 'Ihre Unterlagen' }}</h1>
        <div class="meta">{{ $link->property->address ?? '' }} · {{ $link->property->city ?? '' }}</div>
    </div>
</section>

<article class="email-gate-card">
    <h2>Unterlagen ansehen</h2>
    <p>Bitte bestaetigen Sie Ihre Email-Adresse, um die Unterlagen einzusehen.</p>

    <form method="POST" action="/docs/{{ $link->token }}/unlock">
        @csrf
        <div class="form-field">
            <input type="email" name="email" class="underline-input" placeholder="ihre@email.at" required>
        </div>

        <label class="dsgvo-check">
            <input type="checkbox" name="dsgvo" required>
            <span>Ich stimme zu, dass meine Email-Adresse im Rahmen der Betreuung dieses Immobilien-Projekts verarbeitet wird. Details in der <a href="/datenschutz">Datenschutzerklaerung</a>.</span>
        </label>

        <button type="submit" class="cta-primary">Unterlagen ansehen →</button>
    </form>
</article>
```

- [ ] **Step 4: Create `_unlocked.blade.php`**

```blade
<!-- resources/views/docs/partials/_unlocked.blade.php -->
<section class="hero" style="height: 360px;">
    <img src="{{ $link->property->title_image_url ?? 'https://via.placeholder.com/1200x360' }}" alt="">
    <div class="hero-text">
        <h1>{{ $link->property->project_name ?? 'Ihre Unterlagen' }}</h1>
        <div class="meta">{{ count($files) }} Dokument(e) · angesehen als {{ $session->email }}</div>
    </div>
</section>

<section class="unlocked-grid">
    @foreach ($files as $i => $file)
        <article class="doc-card" style="animation-delay: {{ $i * 80 }}ms;">
            <h3>{{ $file->label ?: $file->filename }}</h3>
            <div class="file-size">{{ $file->file_size ? number_format($file->file_size / 1024 / 1024, 1) . ' MB' : 'PDF' }}</div>
            <div class="actions">
                <button type="button" class="btn-view" data-file-id="{{ $file->id }}" data-file-name="{{ $file->filename }}">
                    Ansehen
                </button>
                <a class="btn-download" href="/docs/{{ $link->token }}/file/{{ $file->id }}/download">
                    Download
                </a>
            </div>
        </article>
    @endforeach
</section>

<div id="viewer-root"></div>
<script src="{{ asset('docs/docs.js') }}"></script>
```

- [ ] **Step 5: Re-run feature tests**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php`
Expected: Still passes (the test asserts "Unterlagen ansehen" and "Ich stimme zu" — both present).

- [ ] **Step 6: Commit**

```bash
git add resources/views/docs/landing.blade.php resources/views/docs/partials/_email_gate.blade.php resources/views/docs/partials/_unlocked.blade.php public/docs/docs.css
git commit -m "feat: docs landing with hypermodern design tokens and Outfit font"
```

---

## Task 7.2: Error Blade with design tokens

**Files:**
- Rewrite: `resources/views/docs/error.blade.php`

- [ ] **Step 1: Rewrite error template**

```blade
<!-- resources/views/docs/error.blade.php -->
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unterlagen nicht verfuegbar</title>
    <link rel="stylesheet" href="{{ asset('docs/docs.css') }}">
</head>
<body>
<main class="docs-container error-page">
    @if ($reason === 'not_found')
        <h1>Link nicht gefunden</h1>
        <p>Dieser Link existiert nicht oder wurde entfernt. Bitte pruefen Sie den URL in Ihrer Email oder kontaktieren Sie uns.</p>
    @elseif ($reason === 'expired')
        <h1>Link abgelaufen</h1>
        <p>Dieser Link ist am {{ $link->expires_at->format('d.m.Y') }} abgelaufen.</p>
        <p>Gerne stellen wir Ihnen einen neuen Zugriff zur Verfuegung — kontaktieren Sie uns unter <strong>office@sr-homes.at</strong>.</p>
    @elseif ($reason === 'revoked')
        <h1>Zugriff beendet</h1>
        <p>Der Zugriff zu diesen Unterlagen wurde beendet. Bitte kontaktieren Sie uns fuer Details.</p>
    @elseif ($reason === 'rate_limited')
        <h1>Zu viele Versuche</h1>
        <p>Bitte versuchen Sie es in einer Stunde erneut.</p>
    @endif

    <a href="mailto:office@sr-homes.at" class="cta-link">office@sr-homes.at</a>
</main>
</body>
</html>
```

- [ ] **Step 2: Re-run tests**

Run: `php artisan test tests/Feature/PublicDocumentControllerTest.php`
Expected: Still passes.

- [ ] **Step 3: Commit**

```bash
git add resources/views/docs/error.blade.php
git commit -m "feat: docs error page with design tokens"
```

---

## Task 8.1: PDF.js viewer integration

**Files:**
- Create: `public/docs/docs.js`
- Create: `public/docs/pdf.worker.min.js` (vendored from npm pdfjs-dist)

- [ ] **Step 1: Vendor PDF.js worker**

Run:

```bash
cd /Users/max/srhomes
npm install pdfjs-dist@4.6.82 --save-dev
cp node_modules/pdfjs-dist/build/pdf.worker.min.mjs public/docs/pdf.worker.min.js
```

- [ ] **Step 2: Create `docs.js` — viewer + heartbeat**

```javascript
// public/docs/docs.js
(function () {
  const TOKEN_MATCH = window.location.pathname.match(/^\/docs\/([^/]+)/);
  const TOKEN = TOKEN_MATCH ? TOKEN_MATCH[1] : null;
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

  function postEvent(type, fileId, durationS) {
    fetch(`/docs/${TOKEN}/event`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': CSRF,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ type, file_id: fileId, duration_s: durationS }),
    }).catch(() => {});
  }

  let currentPdf = null;
  let heartbeatInterval = null;
  let openedAt = 0;
  let openedFileId = null;

  function openViewer(fileId, fileName) {
    const url = `/docs/${TOKEN}/file/${fileId}/view`;
    openedAt = Date.now();
    openedFileId = fileId;

    const root = document.getElementById('viewer-root');
    root.innerHTML = `
      <div class="viewer-backdrop">
        <div class="viewer">
          <header class="viewer-header">
            <span>${fileName}</span>
            <div>
              <a class="viewer-download" href="/docs/${TOKEN}/file/${fileId}/download">Download</a>
              <button class="viewer-close" type="button">×</button>
            </div>
          </header>
          <div class="viewer-canvas-wrap">
            <iframe class="viewer-iframe" src="${url}" title="${fileName}"></iframe>
          </div>
        </div>
      </div>
    `;

    root.querySelector('.viewer-close').addEventListener('click', closeViewer);
    root.querySelector('.viewer-backdrop').addEventListener('click', (e) => {
      if (e.target.classList.contains('viewer-backdrop')) closeViewer();
    });

    // Send first "viewed" event
    postEvent('doc_viewed', fileId, 0);

    // Heartbeat every 30s — partial duration
    heartbeatInterval = setInterval(() => {
      const duration = Math.round((Date.now() - openedAt) / 1000);
      postEvent('doc_viewed', fileId, duration);
    }, 30000);
  }

  function closeViewer() {
    if (heartbeatInterval) {
      clearInterval(heartbeatInterval);
      heartbeatInterval = null;
    }
    if (openedFileId) {
      const duration = Math.round((Date.now() - openedAt) / 1000);
      postEvent('doc_viewed', openedFileId, duration);
      openedFileId = null;
    }
    const root = document.getElementById('viewer-root');
    if (root) root.innerHTML = '';
  }

  document.querySelectorAll('.btn-view').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const fileId = parseInt(btn.dataset.fileId, 10);
      const fileName = btn.dataset.fileName;
      openViewer(fileId, fileName);
    });
  });

  document.querySelectorAll('.btn-download').forEach((a) => {
    a.addEventListener('click', () => {
      const match = a.href.match(/file\/(\d+)\//);
      if (match) postEvent('doc_downloaded', parseInt(match[1], 10), null);
    });
  });

  window.addEventListener('beforeunload', closeViewer);
})();
```

- [ ] **Step 3: Add viewer CSS to `docs.css`**

Append at the bottom of `public/docs/docs.css`:

```css
.viewer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(10,10,8,0.85);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fade-in 300ms var(--ease);
}
.viewer {
  width: 90vw;
  height: 90vh;
  max-width: 1200px;
  background: var(--bg-dark);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 24px 64px rgba(0,0,0,0.4);
  animation: slide-up 400ms var(--ease);
}
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes slide-up { from { transform: translateY(40px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.viewer-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 72px;
  padding: 0 24px;
  background: #0A0A08;
  color: white;
  border-bottom: 1px solid #1F1E1A;
}
.viewer-header span { font-size: 15px; font-weight: 500; }
.viewer-header > div { display: flex; gap: 12px; align-items: center; }
.viewer-download {
  color: var(--accent);
  text-decoration: none;
  font-size: 14px;
  padding: 8px 14px;
  border: 1px solid var(--accent);
  border-radius: 6px;
  transition: all 200ms var(--ease);
}
.viewer-download:hover { background: var(--accent); color: white; }
.viewer-close {
  background: transparent;
  border: none;
  color: white;
  font-size: 28px;
  cursor: pointer;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  transition: background 200ms;
}
.viewer-close:hover { background: rgba(255,255,255,0.1); }
.viewer-canvas-wrap { flex: 1; background: #0A0A08; }
.viewer-iframe { width: 100%; height: 100%; border: none; background: white; }

@media (max-width: 960px) {
  .viewer { width: 100vw; height: 100vh; max-width: none; border-radius: 0; }
}
```

- [ ] **Step 4: Add CSRF meta tag to landing Blade**

In `resources/views/docs/landing.blade.php`, add inside `<head>`:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

- [ ] **Step 5: Commit**

```bash
git add public/docs/docs.js public/docs/pdf.worker.min.js public/docs/docs.css resources/views/docs/landing.blade.php package.json package-lock.json
git commit -m "feat: PDF viewer modal with heartbeat tracking and close handler"
```

---

## Task 9.1: Inbox composer — Link-einfuegen button

**Files:**
- Modify: `resources/js/Components/Admin/Inbox/InboxChatView.vue`
- Create: `resources/js/Components/Admin/Inbox/LinkPickerPopover.vue`
- Modify: `routes/web.php` — add inbox-facing link list endpoint
- Modify: `app/Http/Controllers/Admin/PropertyLinkController.php` — add `activeForProperty` method

- [ ] **Step 1: Add the `activeForProperty` controller method**

In `PropertyLinkController`, append:

```php
    public function activeForProperty(Property $property): JsonResponse
    {
        $links = $property->propertyLinks()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PropertyLink $link) => [
                'id' => $link->id,
                'name' => $link->name,
                'url' => url("/docs/{$link->token}"),
                'expires_at' => $link->expires_at?->toIso8601String(),
                'document_ids' => $link->documentIds()->all(),
                'is_default' => (bool) $link->is_default,
            ]);

        return response()->json(['links' => $links]);
    }
```

- [ ] **Step 2: Register the route**

In `routes/web.php`, inside the admin links group, add:

```php
Route::get('/active', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'activeForProperty'])
    ->name('admin.property-links.active');
```

(Put it before the `/{link}` routes so `/active` matches first.)

- [ ] **Step 3: Create `LinkPickerPopover.vue`**

```vue
<!-- resources/js/Components/Admin/Inbox/LinkPickerPopover.vue -->
<template>
  <div class="popover" @click.stop>
    <header>
      <h4>Link einfuegen</h4>
      <button class="close" @click="$emit('close')">×</button>
    </header>
    <div v-if="loading" class="loading">Lade Links …</div>
    <div v-else-if="links.length === 0" class="empty">
      <p>Keine aktiven Links fuer dieses Objekt.</p>
      <a :href="`/admin/properties/${propertyId}`">Jetzt erstellen →</a>
    </div>
    <ul v-else>
      <li v-for="link in links" :key="link.id">
        <button type="button" @click="$emit('pick', link)">
          <strong>{{ link.name }}</strong>
          <span>{{ link.document_ids.length }} Dokument(e){{ link.expires_at ? ' · laeuft am ' + formatDate(link.expires_at) : '' }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({ propertyId: { type: Number, required: true } });
defineEmits(['close', 'pick']);

const links = ref([]);
const loading = ref(true);

function formatDate(iso) {
  return new Date(iso).toLocaleDateString('de-AT');
}

onMounted(async () => {
  const { data } = await axios.get(`/admin/properties/${props.propertyId}/links/active`);
  links.value = data.links;
  loading.value = false;
});
</script>

<style scoped>
.popover { position: absolute; bottom: 60px; right: 20px; width: 340px; background: white; border: 1px solid #E5E0D8; border-radius: 12px; box-shadow: 0 12px 48px rgba(10,10,8,0.16); z-index: 50; }
header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #F0ECE5; }
header h4 { font-size: 14px; font-weight: 600; color: #0A0A08; }
.close { background: transparent; border: none; font-size: 20px; cursor: pointer; color: #5A564E; }
.loading, .empty { padding: 20px; text-align: center; color: #5A564E; font-size: 13px; }
.empty a { display: block; margin-top: 8px; color: #D4743B; text-decoration: none; font-weight: 500; }
ul { list-style: none; padding: 6px; max-height: 300px; overflow-y: auto; }
ul li button { width: 100%; text-align: left; padding: 10px 14px; background: transparent; border: none; border-radius: 8px; cursor: pointer; transition: background 150ms; }
ul li button:hover { background: #FAF8F5; }
ul li strong { display: block; font-size: 13px; color: #0A0A08; margin-bottom: 2px; }
ul li span { font-size: 12px; color: #5A564E; }
</style>
```

- [ ] **Step 4: Wire button + popover into `InboxChatView.vue`**

In `InboxChatView.vue`, add the import:

```js
import LinkPickerPopover from './LinkPickerPopover.vue';
```

Add the reactive state near the other composer refs:

```js
const linkPickerOpen = ref(false);
```

Add the button in the composer toolbar (next to the existing attach button):

```vue
<button type="button" class="composer-btn" @click="linkPickerOpen = !linkPickerOpen">
  🔗 Link einfuegen
</button>
```

Add the popover near the composer root:

```vue
<LinkPickerPopover
  v-if="linkPickerOpen && propertyId"
  :property-id="propertyId"
  @close="linkPickerOpen = false"
  @pick="insertLinkBlock"
/>
```

Add the `insertLinkBlock` function:

```js
function insertLinkBlock(link) {
  const html = `
<div style="border:1px solid #E5E0D8; border-radius:12px; padding:16px; margin:16px 0; background:#FAF8F5; font-family:Outfit,sans-serif;">
  <div style="font-weight:500; color:#D4743B; font-size:14px;">🔗 Ihre Unterlagen</div>
  <a href="${link.url}" style="color:#0A0A08; text-decoration:none; font-weight:500;">
    ${link.name} · ${link.document_ids.length} Dokumente
  </a>
  ${link.expires_at ? `<div style="font-size:13px; color:#5A564E; margin-top:4px;">Gueltig bis ${new Date(link.expires_at).toLocaleDateString('de-AT')}</div>` : ''}
</div>
`.trim();

  // Editor is expected to expose an insertHtmlAtCursor() method; otherwise append.
  if (typeof composerEditor.value?.insertHtmlAtCursor === 'function') {
    composerEditor.value.insertHtmlAtCursor(html);
  } else {
    replyBody.value = (replyBody.value || '') + '\n' + html;
  }
  linkPickerOpen.value = false;
}
```

(Note: `composerEditor` and `replyBody` refer to the existing editor ref and body ref in `InboxChatView.vue`. The exact names may differ — use whatever the existing composer uses. If the composer is purely markdown-based, fall back to pasting the HTML into the text area.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/PropertyLinkController.php routes/web.php resources/js/Components/Admin/Inbox/LinkPickerPopover.vue resources/js/Components/Admin/Inbox/InboxChatView.vue
git commit -m "feat: inbox composer link picker popover with HTML block insert"
```

---

## Task 10.1: AI auto-insert for Erstanfragen — detection + prompt change

**Files:**
- Modify: `app/Http/Controllers/Admin/ConversationController.php` — `matchGenerateDraft` or equivalent method
- Create: `tests/Feature/Admin/ConversationControllerAutoInsertTest.php`

- [ ] **Step 1: Locate the existing draft-generation method**

Run: `grep -n "matchGenerateDraft\|generateDraft" app/Http/Controllers/Admin/ConversationController.php`

The method that builds the AI prompt will be our modification target. Note its exact name and signature.

- [ ] **Step 2: Write failing test**

```php
<?php
// tests/Feature/Admin/ConversationControllerAutoInsertTest.php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerAutoInsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_draft_on_erstanfrage_includes_default_link_url(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'email_verified_at' => now()]);
        $property = Property::factory()->create();
        $defaultLink = PropertyLink::factory()->default()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        // Stub the AI response via a test-only hook
        \App\Services\AiDraftStub::$nextResponse = 'Sehr geehrte Damen und Herren, danke fuer Ihre Anfrage. [LINK]';

        $conversationId = $this->seedErstanfrageConversation($property);

        $response = $this->actingAs($admin)
            ->postJson("/admin/conversations/{$conversationId}/generate-draft");

        $response->assertOk();
        $this->assertStringContainsString($defaultLink->token, $response->json('draft_body'));
    }

    protected function seedErstanfrageConversation(Property $property): int
    {
        // Inline setup — adjust table/column names to match existing schema
        $conversationId = \DB::table('conversations')->insertGetId([
            'property_id' => $property->id,
            'subject' => 'Anfrage Erstkontakt',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('portal_emails')->insert([
            'conversation_id' => $conversationId,
            'property_id' => $property->id,
            'direction' => 'in',
            'category' => 'anfrage',
            'from_email' => 'lisa@example.com',
            'subject' => 'Anfrage Erstkontakt',
            'body' => 'Hallo, ich interessiere mich fuer das Objekt.',
            'created_at' => now(),
        ]);
        return $conversationId;
    }
}
```

- [ ] **Step 3: Run — expect FAIL**

- [ ] **Step 4: Implement the changes in `ConversationController`**

Inside the existing `matchGenerateDraft` (or whichever method builds the draft), after the prompt is built and before the AI call:

```php
// Docs link auto-insert
$isErstanfrage = $this->detectErstanfrage($conversationId);
$defaultLink = \App\Models\PropertyLink::where('property_id', $property->id)
    ->where('is_default', true)
    ->whereNull('revoked_at')
    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
    ->first();

if ($isErstanfrage && $defaultLink) {
    $linkUrl = url("/docs/{$defaultLink->token}");
    $prompt .= "\n\nWICHTIG: Am Ende der Mail einen Absatz einfuegen, in dem die Unterlagen angeboten werden und diese URL eingefuegt wird: {$linkUrl}";
}
```

And add the detection helper at the bottom of the class:

```php
    protected function detectErstanfrage(int $conversationId): bool
    {
        $inCount = \DB::table('portal_emails')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'in')
            ->count();

        if ($inCount !== 1) {
            return false;
        }

        $first = \DB::table('portal_emails')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'in')
            ->first();

        $category = $first?->category ?? null;
        return $category === null || $category === 'anfrage';
    }
```

- [ ] **Step 5: Run — expect PASS**

Run: `php artisan test tests/Feature/Admin/ConversationControllerAutoInsertTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ConversationController.php tests/Feature/Admin/ConversationControllerAutoInsertTest.php
git commit -m "feat: ai draft auto-inserts default link url on Erstanfragen"
```

---

## Task 11.1: `PurgeOldLinkSessions` scheduled command

**Files:**
- Create: `app/Console/Commands/PurgeOldLinkSessions.php`
- Create: `tests/Feature/PurgeOldLinkSessionsTest.php`
- Modify: `routes/console.php` — register schedule

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/PurgeOldLinkSessionsTest.php

namespace Tests\Feature;

use App\Console\Commands\PurgeOldLinkSessions;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOldLinkSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_sessions_older_than_90_days(): void
    {
        $link = PropertyLink::factory()->create();

        // Old session — should be deleted
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'last_seen_at' => now()->subDays(91),
            'first_seen_at' => now()->subDays(91),
            'created_at' => now()->subDays(91),
        ]);

        // Fresh session — should survive
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'last_seen_at' => now()->subDays(10),
        ]);

        $this->artisan('links:purge-old-sessions')->assertSuccessful();

        $this->assertDatabaseCount('property_link_sessions', 1);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Create the command**

```php
<?php
// app/Console/Commands/PurgeOldLinkSessions.php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\PropertyLinkSession;
use Illuminate\Console\Command;

class PurgeOldLinkSessions extends Command
{
    protected $signature = 'links:purge-old-sessions {--days=90}';
    protected $description = 'Deletes property link sessions older than N days (default 90) and pseudonymizes activities.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $sessionIds = PropertyLinkSession::where('last_seen_at', '<', $cutoff)->pluck('id');

        if ($sessionIds->isEmpty()) {
            $this->info('No sessions to purge.');
            return self::SUCCESS;
        }

        Activity::whereIn('link_session_id', $sessionIds)->update([
            'stakeholder' => 'geloeschter-empfaenger@deleted.local',
            'link_session_id' => null,
        ]);

        PropertyLinkSession::whereIn('id', $sessionIds)->delete();

        $this->info(sprintf('Purged %d sessions older than %d days.', $sessionIds->count(), $days));
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register schedule in `routes/console.php`**

In `routes/console.php`, add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('links:purge-old-sessions')->dailyAt('03:15');
```

- [ ] **Step 5: Run — expect PASS**

Run: `php artisan test tests/Feature/PurgeOldLinkSessionsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PurgeOldLinkSessions.php tests/Feature/PurgeOldLinkSessionsTest.php routes/console.php
git commit -m "feat: PurgeOldLinkSessions command with scheduled daily run"
```

---

## Task 11.2: DSGVO export + delete endpoints

**Files:**
- Create: `app/Http/Controllers/Admin/DsgvoLinkController.php`
- Create: `tests/Feature/Admin/DsgvoLinkControllerTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/Admin/DsgvoLinkControllerTest.php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DsgvoLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'email_verified_at' => now()]);
    }

    public function test_export_returns_all_sessions_for_email(): void
    {
        $admin = $this->admin();
        $link = PropertyLink::factory()->create(['created_by' => $admin->id]);

        PropertyLinkSession::factory()->count(3)->create([
            'property_link_id' => $link->id,
            'email' => 'target@example.com',
        ]);
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'other@example.com',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/dsgvo/links?email=target@example.com');

        $response->assertOk()
            ->assertJsonCount(3, 'sessions');
    }

    public function test_delete_removes_all_sessions_for_email(): void
    {
        $admin = $this->admin();
        $link = PropertyLink::factory()->create(['created_by' => $admin->id]);
        PropertyLinkSession::factory()->count(2)->create([
            'property_link_id' => $link->id,
            'email' => 'target@example.com',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/admin/dsgvo/links', ['email' => 'target@example.com'])
            ->assertOk();

        $this->assertDatabaseMissing('property_link_sessions', ['email' => 'target@example.com']);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Admin/DsgvoLinkController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\PropertyLinkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DsgvoLinkController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $sessions = PropertyLinkSession::with('events')
            ->where('email', strtolower(trim($data['email'])))
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'property_link_id' => $s->property_link_id,
                'email' => $s->email,
                'dsgvo_accepted_at' => $s->dsgvo_accepted_at?->toIso8601String(),
                'first_seen_at' => $s->first_seen_at?->toIso8601String(),
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'events' => $s->events,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($data['email']));

        $sessionIds = PropertyLinkSession::where('email', $email)->pluck('id');

        Activity::whereIn('link_session_id', $sessionIds)->update([
            'stakeholder' => 'geloeschter-empfaenger@deleted.local',
            'link_session_id' => null,
        ]);

        $deleted = PropertyLinkSession::whereIn('id', $sessionIds)->delete();

        return response()->json(['deleted' => $deleted]);
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/web.php`, inside an admin-protected group:

```php
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])->prefix('admin/dsgvo')->group(function () {
    Route::get('/links', [\App\Http\Controllers\Admin\DsgvoLinkController::class, 'export']);
    Route::delete('/links', [\App\Http\Controllers\Admin\DsgvoLinkController::class, 'destroy']);
});
```

- [ ] **Step 5: Run — expect PASS**

Run: `php artisan test tests/Feature/Admin/DsgvoLinkControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/DsgvoLinkController.php tests/Feature/Admin/DsgvoLinkControllerTest.php routes/web.php
git commit -m "feat: DSGVO export and delete endpoints for link sessions"
```

---

## Task 12.1: Full test suite run + manual browser checklist

- [ ] **Step 1: Run the full suite**

Run: `cd /Users/max/srhomes && php artisan test`
Expected: All new tests green. Existing tests green (or same-state as before this plan).

- [ ] **Step 2: Build frontend**

Run: `cd /Users/max/srhomes && npm run build`
Expected: No Vue compilation errors. Output assets in `public/build/`.

- [ ] **Step 3: Manual browser test — admin flow**

Start:
```bash
cd /Users/max/srhomes
php artisan serve --port=8000 &
npm run dev &
```

Walk through:
1. Login as an admin.
2. Navigate to `/admin/properties/{id}`.
3. Click the "Links" tab.
4. Click "Neuer Link", fill name "Erstanfrage", toggle Standard, select all files, click Speichern.
5. Verify toast "Link erstellt & kopiert".
6. Verify URL is in the clipboard (paste into a new browser tab).
7. Click "Details →" on the card. Verify metrics, empty timeline, URL copy button.

- [ ] **Step 4: Manual browser test — customer flow**

1. Paste the URL from step 3 above into an incognito tab.
2. Verify the email gate renders with the hero, card, checkbox, and button.
3. Submit with an invalid email format — form validation should block.
4. Submit with a valid email and no DSGVO check — should be blocked.
5. Submit correctly — redirected to the unlocked view with the doc grid.
6. Click "Ansehen" on a PDF — viewer modal opens with iframe.
7. Close the viewer.
8. Click "Download" — PDF downloads.
9. Go back to the admin Detail page and verify metrics updated (1 Aufruf, 1 Ansicht, 1 Download).

- [ ] **Step 5: Manual email-client test**

1. In the admin Inbox, open a conversation for a property with the "Erstanfrage" link as default.
2. Click "Entwurf generieren" — draft should include the link URL.
3. Click "Link einfuegen ▾" — popover shows the active link; click it and verify the formatted HTML block inserts into the composer.
4. Send the email to a test inbox. Verify in Gmail / Outlook / Apple Mail that the block renders with design styling and the link is clickable.

- [ ] **Step 6: Final commit — none expected**

If all tests pass and manual checks succeed, there's nothing new to commit here. If manual testing uncovered issues, go back to the relevant task, fix, and commit.

---

## Deploy notes (after plan complete)

```bash
# SSH to VPS
ssh srhomes-vps

# Pull + migrate + build
cd /var/www/srhomes
git pull origin main
php artisan migrate --force
npm ci && npm run build

# Restart services
sudo supervisorctl restart all
sudo systemctl restart php8.3-fpm

# Verify schedule is registered
php artisan schedule:list | grep purge-old-sessions
```

---

## Self-Review Notes (done inline during plan writing)

- **Spec coverage:** All 13 spec sections covered. Scope items 1–11 map to Tasks 1.*–11.*. Public flow (landing, unlock, file, event) covered in Task 6. Admin CRUD + Vue UI in Tasks 3–5. Auto-insert in Task 10. Retention + DSGVO in Task 11. E2E in Task 12.
- **Placeholder scan:** No "TBD", "TODO", or "similar to" references left. Each task has runnable code.
- **Type consistency:** `PropertyLink`, `PropertyLinkSession`, `PropertyLinkEvent` names used consistently. Event type constants (`TYPE_LINK_OPENED`, `TYPE_DOC_VIEWED`, `TYPE_DOC_DOWNLOADED`) used consistently in tests and controllers. `property_file_id` column name matches between pivot migration, controller, and tests.
- **Risk areas flagged:**
  - `composerEditor` / `replyBody` refs in Task 9.1 Step 4 depend on the existing `InboxChatView.vue` internals — the executing engineer will need to adjust names based on what's actually there.
  - `ConversationController::matchGenerateDraft` method name in Task 10.1 is inferred from the spec. The executing engineer must find the actual method in the current file and adapt the prompt injection site.
  - `AiDraftStub` in Task 10.1 Step 2 is a test stub that does not yet exist. Before running the test, the executing engineer needs to either implement that stub or rewrite the test to mock the HTTP client via Laravel's `Http::fake()`.
