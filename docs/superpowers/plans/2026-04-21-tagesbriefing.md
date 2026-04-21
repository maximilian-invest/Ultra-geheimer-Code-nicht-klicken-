# Tagesbriefing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** KI-generiertes Tagesbriefing im Admin-Dashboard, öffnet als Sheet aus neuer Card oberhalb der bestehenden "Guten Morgen"-Card. Zeigt 4 Blöcke: Narrative, Active Threads mit Trail, Anstehend heute, Auffälligkeiten.

**Architecture:** Laravel Service (`DailyBriefingService`) sammelt Daten aus Activities/Conversations/Tasks/Viewings und ruft `AnthropicService::chatJson` für die KI-Zusammenfassung. Caching in neuer Tabelle `daily_briefings` (1 Briefing pro User pro Tag). Scheduled Command regeneriert täglich 06:30 Vienna. Frontend: 3 neue Vue-Components im shadcn-vue Design, eingebunden am Anfang von `TodayTab.vue`.

**Tech Stack:** Laravel 11 + PHP 8.2, Vue 3 + Inertia, shadcn-vue (Card/Sheet/Badge), AnthropicService (claude-haiku-4-5), MySQL 8.

**Spec:** [`docs/superpowers/specs/2026-04-21-tagesbriefing-design.md`](../specs/2026-04-21-tagesbriefing-design.md)

---

## File Structure

**Backend (create):**
- `database/migrations/2026_04_21_120000_create_daily_briefings_table.php`
- `app/Models/DailyBriefing.php`
- `app/Services/DailyBriefingService.php`
- `app/Console/Commands/GenerateDailyBriefings.php`

**Backend (modify):**
- `app/Http/Controllers/Admin/AdminApiController.php` (neue Actions `briefing_get`, `briefing_regenerate`)
- `routes/console.php` (Schedule-Registrierung)

**Frontend (create):**
- `resources/js/Components/Admin/TagesbriefingThread.vue` (einzelne Thread-Row mit Trail)
- `resources/js/Components/Admin/TagesbriefingSheet.vue` (Sheet-Content mit 4 Sektionen)
- `resources/js/Components/Admin/TagesbriefingCard.vue` (Einstiegspunkt-Card)

**Frontend (modify):**
- `resources/js/Components/Admin/TodayTab.vue` (Card + Sheet oben einbinden)

**Tests (create):**
- `tests/Unit/DailyBriefingServiceTest.php`
- `tests/Feature/BriefingApiTest.php`

---

## Task 1: DB Migration + Model

**Files:**
- Create: `database/migrations/2026_04_21_120000_create_daily_briefings_table.php`
- Create: `app/Models/DailyBriefing.php`

- [ ] **Step 1: Migration anlegen**

Create `database/migrations/2026_04_21_120000_create_daily_briefings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('briefing_date');
            $table->longText('data'); // JSON: {preview, narrative, threads, agenda, anomalies}
            $table->string('model_used')->nullable(); // z.B. 'claude-haiku-4-5' oder 'fallback'
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'briefing_date']);
            $table->index('briefing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefings');
    }
};
```

- [ ] **Step 2: Migration laufen lassen**

Run: `php artisan migrate`
Expected: `Migrating: 2026_04_21_120000_create_daily_briefings_table` → `Migrated`

- [ ] **Step 3: Model anlegen**

Create `app/Models/DailyBriefing.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBriefing extends Model
{
    protected $fillable = [
        'user_id', 'briefing_date', 'data', 'model_used', 'generated_at',
    ];

    protected $casts = [
        'briefing_date' => 'date',
        'generated_at' => 'datetime',
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Verify via tinker**

Run: `php artisan tinker --execute="echo \App\Models\DailyBriefing::count();"`
Expected: `0` (leere Tabelle existiert)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_21_120000_create_daily_briefings_table.php app/Models/DailyBriefing.php
git commit -m "feat(briefing): add daily_briefings table + model"
```

---

## Task 2: Service — gatherContext

**Files:**
- Create: `app/Services/DailyBriefingService.php`
- Create: `tests/Unit/DailyBriefingServiceTest.php`

- [ ] **Step 1: Test schreiben (gatherContext)**

Create `tests/Unit/DailyBriefingServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\Task;
use App\Models\User;
use App\Models\Viewing;
use App\Services\DailyBriefingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyBriefingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gatherContext_returns_empty_structure_for_quiet_user(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);

        $service = app(DailyBriefingService::class);
        $ctx = $service->gatherContext($user->id);

        $this->assertArrayHasKey('date', $ctx);
        $this->assertArrayHasKey('activities_24h', $ctx);
        $this->assertArrayHasKey('active_threads', $ctx);
        $this->assertArrayHasKey('tasks_due', $ctx);
        $this->assertArrayHasKey('viewings_today', $ctx);
        $this->assertArrayHasKey('nachfass_outcome', $ctx);
        $this->assertCount(0, $ctx['activities_24h']);
        $this->assertCount(0, $ctx['active_threads']);
    }

    public function test_gatherContext_pulls_recent_activities(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create(['broker_id' => $user->id]);
        Activity::create([
            'property_id' => $property->id,
            'activity_date' => now(),
            'stakeholder' => 'Test Kunde',
            'activity' => 'Kaufanbot erhalten',
            'category' => 'kaufanbot',
        ]);

        $service = app(DailyBriefingService::class);
        $ctx = $service->gatherContext($user->id);

        $this->assertCount(1, $ctx['activities_24h']);
        $this->assertSame('kaufanbot', $ctx['activities_24h'][0]['category']);
    }

    public function test_gatherContext_respects_broker_scope_for_makler(): void
    {
        $makler1 = User::factory()->create(['user_type' => 'makler']);
        $makler2 = User::factory()->create(['user_type' => 'makler']);
        $prop2 = Property::factory()->create(['broker_id' => $makler2->id]);
        Activity::create([
            'property_id' => $prop2->id,
            'activity_date' => now(),
            'stakeholder' => 'Kunde von Makler 2',
            'activity' => 'Fremde Aktivität',
            'category' => 'email-in',
        ]);

        $service = app(DailyBriefingService::class);
        $ctx = $service->gatherContext($makler1->id);

        $this->assertCount(0, $ctx['activities_24h'], 'Makler 1 darf keine Activities von Makler 2 sehen');
    }

    public function test_gatherContext_returns_empty_without_user_id(): void
    {
        $service = app(DailyBriefingService::class);
        $ctx = $service->gatherContext(0);

        $this->assertCount(0, $ctx['activities_24h']);
        $this->assertCount(0, $ctx['active_threads']);
    }
}
```

- [ ] **Step 2: Run test, expect FAIL**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php`
Expected: FAIL with "Class DailyBriefingService not found"

- [ ] **Step 3: Service mit gatherContext implementieren**

Create `app/Services/DailyBriefingService.php`:

```php
<?php

namespace App\Services;

use App\Models\DailyBriefing;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyBriefingService
{
    public function __construct(
        private AnthropicService $anthropic,
    ) {}

    /**
     * Sammelt alle Rohdaten für das Briefing eines Users.
     * Broker-scoped: Makler sehen nur eigene Properties, Admin/Assistenz/Backoffice alles.
     */
    public function gatherContext(int $userId, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        // Security: Ohne User-ID leeres Ergebnis (kein Datenleck wie bei Conversation-Bug)
        if (!$userId) {
            return $this->emptyContext($date);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) return $this->emptyContext($date);

        $userType = $user->user_type ?? 'makler';
        $scopeAll = in_array($userType, ['admin', 'assistenz', 'backoffice'], true);

        return [
            'date' => $date,
            'broker_name' => $user->name ?? 'Makler',
            'activities_24h' => $this->getActivities($userId, $scopeAll),
            'active_threads' => $this->getActiveThreads($userId, $scopeAll),
            'tasks_due' => $this->getTasks($userId),
            'viewings_today' => $this->getViewings($userId, $scopeAll),
            'property_signals' => $this->getPropertySignals($userId, $scopeAll),
            'nachfass_outcome' => $this->getNachfassOutcome($userId, $scopeAll),
        ];
    }

    private function emptyContext(string $date): array
    {
        return [
            'date' => $date,
            'broker_name' => 'Makler',
            'activities_24h' => [],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ];
    }

    private function getActivities(int $userId, bool $scopeAll): array
    {
        $q = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->where('a.activity_date', '>=', now()->subHours(24))
            ->whereNotIn('a.category', ['link_opened']) // Rauschen raus
            ->select([
                'a.id', 'a.activity', 'a.category', 'a.stakeholder',
                'a.property_id', 'a.activity_date',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('a.activity_date', 'desc')
            ->limit(100);

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        return $q->get()->map(fn($row) => (array) $row)->all();
    }

    private function getActiveThreads(int $userId, bool $scopeAll): array
    {
        $q = DB::table('conversations as c')
            ->leftJoin('properties as p', 'c.property_id', '=', 'p.id')
            ->where('c.last_inbound_at', '>=', now()->subDays(5))
            ->whereNotIn('c.status', ['erledigt'])
            ->where('c.match_dismissed', '=', 0)
            ->select([
                'c.id', 'c.stakeholder', 'c.contact_email', 'c.property_id',
                'c.status', 'c.last_inbound_at', 'c.last_outbound_at',
                'c.inbound_count', 'c.outbound_count',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('c.last_inbound_at', 'desc')
            ->limit(20);

        if (!$scopeAll) {
            $q->where(function ($sub) use ($userId) {
                $sub->where('p.broker_id', $userId)
                    ->orWhere(function ($s2) use ($userId) {
                        $s2->whereNull('c.property_id')
                           ->whereIn('c.last_email_id', function ($s3) use ($userId) {
                               $s3->select('id')->from('portal_emails')
                                  ->whereIn('account_id', function ($s4) use ($userId) {
                                      $s4->select('id')->from('email_accounts')->where('user_id', $userId);
                                  });
                           });
                    });
            });
        }

        $threads = $q->get()->map(fn($row) => (array) $row)->all();

        // Pro Thread: letzte 3 Messages (für Trail)
        foreach ($threads as &$t) {
            $t['recent_messages'] = DB::table('portal_emails')
                ->where(function ($q) use ($t) {
                    $q->where('from_email', $t['contact_email'])
                      ->orWhere('to_email', 'like', '%' . $t['contact_email'] . '%');
                })
                ->orderBy('date_received', 'desc')
                ->limit(3)
                ->get(['subject', 'direction', 'date_received'])
                ->map(fn($m) => (array) $m)
                ->all();

            $t['days_waiting'] = $t['last_inbound_at']
                ? now()->diffInDays(Carbon::parse($t['last_inbound_at']))
                : 0;
        }

        return $threads;
    }

    private function getTasks(int $userId): array
    {
        return DB::table('tasks')
            ->where('is_done', 0)
            ->where(function ($q) {
                $q->whereDate('due_date', '<=', now()->toDateString())
                  ->orWhereNull('due_date');
            })
            ->where('assigned_to', $userId)
            ->orderBy('priority', 'desc')
            ->limit(20)
            ->get(['id', 'title', 'priority', 'due_date', 'property_id'])
            ->map(fn($t) => (array) $t)
            ->all();
    }

    private function getViewings(int $userId, bool $scopeAll): array
    {
        $q = DB::table('viewings as v')
            ->leftJoin('properties as p', 'v.property_id', '=', 'p.id')
            ->whereDate('v.viewing_date', now()->toDateString())
            ->where(function ($q) {
                $q->where('v.status', '!=', 'storniert')->orWhereNull('v.status');
            })
            ->select([
                'v.id', 'v.viewing_time', 'v.person_name',
                'v.property_id', 'v.notes',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('v.viewing_time');

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        return $q->get()->map(fn($row) => (array) $row)->all();
    }

    private function getPropertySignals(int $userId, bool $scopeAll): array
    {
        $signals = [];

        // Hot: Link-Sessions letzte 24h > 3× Median der letzten 7 Tage
        $hotQ = DB::table('property_link_sessions as s')
            ->join('properties as p', 's.property_id', '=', 'p.id')
            ->where('s.created_at', '>=', now()->subHours(24))
            ->groupBy('s.property_id', 'p.ref_id', 'p.address')
            ->select([
                's.property_id',
                'p.ref_id', 'p.address',
                DB::raw('COUNT(*) as sessions_24h'),
            ])
            ->havingRaw('COUNT(*) >= 10');

        if (!$scopeAll) {
            $hotQ->where('p.broker_id', $userId);
        }

        foreach ($hotQ->get() as $row) {
            $signals[] = [
                'kind' => 'hot',
                'property_id' => $row->property_id,
                'property_ref' => $row->ref_id,
                'sessions_24h' => (int) $row->sessions_24h,
            ];
        }

        // Cooling: Anfragen letzte 14 Tage < 30% der 14 Tage davor
        $coolQ = DB::select("
            SELECT p.id as property_id, p.ref_id, p.address,
                   SUM(CASE WHEN a.activity_date >= ? THEN 1 ELSE 0 END) as recent,
                   SUM(CASE WHEN a.activity_date < ? AND a.activity_date >= ? THEN 1 ELSE 0 END) as previous
            FROM properties p
            LEFT JOIN activities a ON a.property_id = p.id AND a.category = 'anfrage'
            WHERE p.realty_status IN ('aktiv', 'inserat', 'auftrag')
              " . ($scopeAll ? '' : 'AND p.broker_id = ' . intval($userId)) . "
            GROUP BY p.id, p.ref_id, p.address
            HAVING previous >= 5 AND recent < (previous * 0.3)
        ", [now()->subDays(14), now()->subDays(14), now()->subDays(28)]);

        foreach ($coolQ as $row) {
            $signals[] = [
                'kind' => 'cool',
                'property_id' => $row->property_id,
                'property_ref' => $row->ref_id,
                'recent_inquiries' => (int) $row->recent,
                'previous_inquiries' => (int) $row->previous,
            ];
        }

        return $signals;
    }

    private function getNachfassOutcome(int $userId, bool $scopeAll): array
    {
        $q = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->where('a.category', 'nachfassen')
            ->where('a.activity_date', '>=', now()->subHours(48));

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        $sent = (int) $q->count();

        return ['sent' => $sent, 'replied' => 0]; // Reply-Matching in späterer Iteration
    }
}
```

- [ ] **Step 4: Run tests, expect PASS**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php`
Expected: All 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/DailyBriefingService.php tests/Unit/DailyBriefingServiceTest.php
git commit -m "feat(briefing): add DailyBriefingService::gatherContext with broker scoping"
```

---

## Task 3: Service — fallbackTemplate

**Files:**
- Modify: `app/Services/DailyBriefingService.php`
- Modify: `tests/Unit/DailyBriefingServiceTest.php`

- [ ] **Step 1: Test für fallbackTemplate schreiben**

Append zu `tests/Unit/DailyBriefingServiceTest.php`:

```php
    public function test_fallbackTemplate_produces_valid_structure(): void
    {
        $service = app(DailyBriefingService::class);

        $context = [
            'date' => '2026-04-21',
            'broker_name' => 'Max',
            'activities_24h' => [
                ['activity' => 'Kaufanbot erhalten', 'category' => 'kaufanbot', 'property_ref' => 'KAU-74', 'stakeholder' => 'Mayr'],
                ['activity' => 'Anfrage', 'category' => 'anfrage', 'property_ref' => 'RIV-1', 'stakeholder' => 'Müller'],
            ],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertArrayHasKey('preview', $out);
        $this->assertArrayHasKey('narrative', $out);
        $this->assertArrayHasKey('anomalies', $out);
        $this->assertArrayHasKey('thread_annotations', $out);
        $this->assertIsString($out['preview']);
        $this->assertLessThanOrEqual(180, strlen($out['preview']));
    }

    public function test_fallbackTemplate_quiet_day(): void
    {
        $service = app(DailyBriefingService::class);
        $context = [
            'date' => '2026-04-21',
            'broker_name' => 'Max',
            'activities_24h' => [],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertStringContainsString('Ruhiger Tag', $out['narrative']);
    }
```

- [ ] **Step 2: Run test, expect FAIL**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php --filter=fallbackTemplate`
Expected: FAIL mit "Call to undefined method fallbackTemplate"

- [ ] **Step 3: fallbackTemplate implementieren**

In `app/Services/DailyBriefingService.php` am Ende der Klasse anhängen:

```php
    /**
     * Deterministisches Briefing ohne KI.
     * Verwendet wenn <3 Activities ODER KI fehlschlägt.
     */
    public function fallbackTemplate(array $context): array
    {
        $activities = $context['activities_24h'];
        $threads = $context['active_threads'];
        $total = count($activities);

        if ($total < 3 && empty($threads)) {
            return [
                'preview' => 'Ruhiger Tag — keine besonderen Vorkommnisse.',
                'narrative' => 'Ruhiger Tag. In den letzten 24 Stunden sind keine besonderen Aktivitäten registriert worden.',
                'anomalies' => [],
                'thread_annotations' => [],
            ];
        }

        // Zähle nach Kategorie
        $byCategory = [];
        foreach ($activities as $a) {
            $byCategory[$a['category']] = ($byCategory[$a['category']] ?? 0) + 1;
        }

        $parts = [];
        if (($byCategory['anfrage'] ?? 0) > 0) $parts[] = $byCategory['anfrage'] . ' neue Anfragen';
        if (($byCategory['kaufanbot'] ?? 0) > 0) $parts[] = $byCategory['kaufanbot'] . ' Kaufanbote';
        if (($byCategory['besichtigung'] ?? 0) > 0) $parts[] = $byCategory['besichtigung'] . ' Besichtigungen';
        if (($byCategory['email-out'] ?? 0) > 0) $parts[] = $byCategory['email-out'] . ' verschickte E-Mails';

        $narrative = 'Letzte 24 Stunden: ' . (empty($parts) ? $total . ' Aktivitäten' : implode(', ', $parts)) . '.';

        if (count($threads) > 0) {
            $narrative .= ' ' . count($threads) . ' aktive Gesprächsfäden der letzten 5 Tage.';
        }

        // Preview: erste 180 Zeichen der Narrative
        $preview = mb_substr($narrative, 0, 177);
        if (mb_strlen($narrative) > 180) $preview .= '…';

        // Anomalies aus property_signals konvertieren
        $anomalies = [];
        foreach ($context['property_signals'] as $sig) {
            if ($sig['kind'] === 'hot') {
                $anomalies[] = [
                    'kind' => 'hot',
                    'property_ref' => $sig['property_ref'],
                    'text' => $sig['property_ref'] . ': ' . $sig['sessions_24h'] . ' Exposé-Aufrufe in 24h — hohes Interesse',
                ];
            } elseif ($sig['kind'] === 'cool') {
                $anomalies[] = [
                    'kind' => 'cool',
                    'property_ref' => $sig['property_ref'],
                    'text' => $sig['property_ref'] . ': Anfragen von ' . $sig['previous_inquiries'] . ' auf ' . $sig['recent_inquiries'] . ' eingebrochen',
                ];
            }
        }

        // Thread-Annotations: nach Wartezeit priorisieren
        $threadAnnotations = [];
        foreach ($threads as $t) {
            $days = (int) ($t['days_waiting'] ?? 0);
            $priority = $days >= 2 ? 'red' : ($days >= 1 ? 'orange' : 'green');
            $label = $days >= 2 ? 'wartet ' . $days . ' Tage' : '';
            $threadAnnotations[(string) $t['id']] = ['priority' => $priority, 'label' => $label];
        }

        return [
            'preview' => $preview,
            'narrative' => $narrative,
            'anomalies' => array_slice($anomalies, 0, 3),
            'thread_annotations' => $threadAnnotations,
        ];
    }
```

- [ ] **Step 4: Run tests, expect PASS**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php --filter=fallbackTemplate`
Expected: Beide Tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/DailyBriefingService.php tests/Unit/DailyBriefingServiceTest.php
git commit -m "feat(briefing): add fallbackTemplate for KI-lose Zusammenfassung"
```

---

## Task 4: Service — callAi

**Files:**
- Modify: `app/Services/DailyBriefingService.php`
- Modify: `tests/Unit/DailyBriefingServiceTest.php`

- [ ] **Step 1: Test für callAi-Fehlerbehandlung schreiben**

Append zu `tests/Unit/DailyBriefingServiceTest.php`:

```php
    public function test_callAi_returns_null_on_invalid_context(): void
    {
        $service = app(DailyBriefingService::class);
        $out = $service->callAi([]);
        $this->assertNull($out);
    }

    public function test_callAi_validates_response_structure(): void
    {
        // Mock AnthropicService
        $anthropicMock = $this->createMock(\App\Services\AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn([
            'preview' => 'Test Preview',
            'narrative' => 'Test Narrative',
            'anomalies' => [],
            'thread_annotations' => [],
        ]);
        $this->app->instance(\App\Services\AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi([
            'date' => '2026-04-21',
            'broker_name' => 'Max',
            'activities_24h' => [['activity' => 'X', 'category' => 'anfrage']],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ]);

        $this->assertSame('Test Preview', $out['preview']);
    }

    public function test_callAi_returns_null_when_anthropic_fails(): void
    {
        $anthropicMock = $this->createMock(\App\Services\AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn(null);
        $this->app->instance(\App\Services\AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi([
            'date' => '2026-04-21',
            'broker_name' => 'Max',
            'activities_24h' => [['activity' => 'X', 'category' => 'anfrage']],
            'active_threads' => [], 'tasks_due' => [], 'viewings_today' => [],
            'property_signals' => [], 'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ]);

        $this->assertNull($out);
    }
```

- [ ] **Step 2: Run tests, expect FAIL**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php --filter=callAi`
Expected: FAIL mit "Call to undefined method callAi"

- [ ] **Step 3: callAi implementieren**

In `app/Services/DailyBriefingService.php` am Ende der Klasse anhängen:

```php
    /**
     * Ruft Claude mit dem gesammelten Context auf.
     * Gibt validiertes Result-Array zurück oder null bei Fehler.
     */
    public function callAi(array $context): ?array
    {
        if (empty($context) || empty($context['date'])) return null;

        $systemPrompt = 'Du bist ein Assistenz-System für einen österreichischen Immobilienmakler. '
            . 'Fasse den gestrigen Tag in 3 Sätzen faktisch zusammen (Block: narrative, 100-150 Wörter). '
            . 'Erkenne Muster wie Kunden-Beschwerden, Hot/Cooling Properties, Eigentümer-Unmut. '
            . 'Keine Floskeln. Beschwerde-Signale in Kundennachrichten IMMER als Anomalie markieren. '
            . 'Antworte NUR mit valid JSON in folgendem Format: '
            . '{"preview": "1-zeiliger Highlight max 180 Zeichen", '
            . '"narrative": "3-4 Sätze mit <strong>Zahlen</strong> und <mark>Alarm-Signalen</mark>", '
            . '"anomalies": [{"kind":"hot|cool|warn","property_ref":"...","text":"..."}], '
            . '"thread_annotations": {"<conv_id>":{"priority":"red|orange|yellow|green","label":"wartet 3 Tage"}}}';

        $userMessage = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = $this->anthropic->chatJson($systemPrompt, $userMessage, 2000);

            if (!is_array($result)) return null;
            if (!isset($result['preview'], $result['narrative'])) return null;

            // Sanitize / truncate
            $result['preview'] = mb_substr((string) $result['preview'], 0, 180);
            $result['narrative'] = $this->truncateNarrative((string) $result['narrative'], 300);
            $result['anomalies'] = array_slice((array) ($result['anomalies'] ?? []), 0, 3);
            $result['thread_annotations'] = (array) ($result['thread_annotations'] ?? []);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('DailyBriefingService::callAi failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function truncateNarrative(string $text, int $maxWords): string
    {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $maxWords) return $text;

        $truncated = implode(' ', array_slice($words, 0, $maxWords));
        // Bis zum letzten Satzende zurück
        $lastDot = max(strrpos($truncated, '.'), strrpos($truncated, '!'), strrpos($truncated, '?'));
        if ($lastDot !== false) {
            $truncated = substr($truncated, 0, $lastDot + 1);
        }
        return $truncated;
    }
```

- [ ] **Step 4: Run tests, expect PASS**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php --filter=callAi`
Expected: Alle 3 Tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/DailyBriefingService.php tests/Unit/DailyBriefingServiceTest.php
git commit -m "feat(briefing): add callAi with validation + sanitization"
```

---

## Task 5: Service — generate + Cache

**Files:**
- Modify: `app/Services/DailyBriefingService.php`
- Modify: `tests/Unit/DailyBriefingServiceTest.php`

- [ ] **Step 1: Test für generate + cache schreiben**

Append zu `tests/Unit/DailyBriefingServiceTest.php`:

```php
    public function test_generate_returns_cached_result_when_exists(): void
    {
        $user = User::factory()->create();
        \App\Models\DailyBriefing::create([
            'user_id' => $user->id,
            'briefing_date' => now()->toDateString(),
            'data' => ['preview' => 'Gestern 1 Aktivität', 'narrative' => 'Test', 'anomalies' => [], 'thread_annotations' => []],
            'model_used' => 'fallback',
            'generated_at' => now(),
        ]);

        $service = app(DailyBriefingService::class);
        $out = $service->generate($user->id);

        $this->assertSame('Gestern 1 Aktivität', $out['preview']);
    }

    public function test_generate_falls_back_when_ai_fails(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $anthropicMock = $this->createMock(\App\Services\AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn(null);
        $this->app->instance(\App\Services\AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->generate($user->id);

        $this->assertArrayHasKey('preview', $out);
        // Fallback-Template produziert immer preview
        $this->assertNotEmpty($out['preview']);

        // Persisted in DB mit model_used=fallback
        $row = \App\Models\DailyBriefing::where('user_id', $user->id)->first();
        $this->assertSame('fallback', $row->model_used);
    }
```

- [ ] **Step 2: Run tests, expect FAIL**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php --filter=generate`
Expected: FAIL mit "undefined method generate"

- [ ] **Step 3: generate + Helpers implementieren**

In `app/Services/DailyBriefingService.php` am Ende der Klasse anhängen:

```php
    /**
     * Haupt-Einstiegspunkt. Lädt aus Cache oder generiert frisch.
     */
    public function generate(int $userId, ?string $date = null, bool $forceRefresh = false): array
    {
        $date = $date ?: now()->toDateString();

        if (!$forceRefresh) {
            $cached = $this->loadFromCache($userId, $date);
            if ($cached) return $cached;
        }

        $context = $this->gatherContext($userId, $date);

        // <3 Activities UND keine threads → direkt fallback ohne KI-Call
        $activityCount = count($context['activities_24h']);
        $threadCount = count($context['active_threads']);

        $aiResult = null;
        $modelUsed = 'fallback';

        if ($activityCount >= 3 || $threadCount > 0) {
            $aiResult = $this->callAi($context);
            if ($aiResult) $modelUsed = 'claude-haiku-4-5';
        }

        $result = $aiResult ?: $this->fallbackTemplate($context);

        // Threads + Agenda + Context für Frontend mitgeben
        $result['threads'] = $this->formatThreadsForFrontend(
            $context['active_threads'],
            $result['thread_annotations'] ?? []
        );
        $result['agenda'] = $this->formatAgendaForFrontend($context);

        $this->saveToCache($userId, $date, $result, $modelUsed);

        return $result;
    }

    public function loadFromCache(int $userId, string $date): ?array
    {
        $row = DailyBriefing::where('user_id', $userId)
            ->where('briefing_date', $date)
            ->first();

        return $row ? $row->data : null;
    }

    public function saveToCache(int $userId, string $date, array $data, string $modelUsed): void
    {
        DailyBriefing::updateOrCreate(
            ['user_id' => $userId, 'briefing_date' => $date],
            [
                'data' => $data,
                'model_used' => $modelUsed,
                'generated_at' => now(),
            ]
        );
    }

    private function formatThreadsForFrontend(array $threads, array $annotations): array
    {
        $priorityOrder = ['red' => 0, 'orange' => 1, 'yellow' => 2, 'green' => 3];

        $formatted = array_map(function ($t) use ($annotations) {
            $id = (string) ($t['id'] ?? '');
            $ann = $annotations[$id] ?? ['priority' => 'green', 'label' => ''];

            $trail = [];
            foreach (array_reverse((array) ($t['recent_messages'] ?? [])) as $msg) {
                $when = $msg['date_received'] ? \Carbon\Carbon::parse($msg['date_received'])->isoFormat('dd') : '?';
                $dir = ($msg['direction'] ?? '') === 'outbound' ? 'geschickt' : 'Nachricht';
                $subject = mb_substr($msg['subject'] ?? '', 0, 40);
                $trail[] = "{$when} · {$dir}: «{$subject}»";
            }

            return [
                'id' => $t['id'] ?? null,
                'stakeholder' => $t['stakeholder'] ?? '',
                'property_ref' => $t['property_ref'] ?? null,
                'property_address' => $t['property_address'] ?? null,
                'priority' => $ann['priority'],
                'label' => $ann['label'],
                'trail' => $trail,
                'days_waiting' => (int) ($t['days_waiting'] ?? 0),
            ];
        }, $threads);

        usort($formatted, function ($a, $b) use ($priorityOrder) {
            $pa = $priorityOrder[$a['priority']] ?? 4;
            $pb = $priorityOrder[$b['priority']] ?? 4;
            return $pa <=> $pb;
        });

        return array_slice($formatted, 0, 8);
    }

    private function formatAgendaForFrontend(array $context): array
    {
        $agenda = ['termine' => [], 'offen' => []];

        foreach ($context['viewings_today'] as $v) {
            $agenda['termine'][] = [
                'time' => substr($v['viewing_time'] ?? '', 0, 5),
                'kind' => 'viewing',
                'text' => 'Besichtigung ' . ($v['property_ref'] ?? '?') . ' · ' . ($v['person_name'] ?? ''),
                'property_id' => $v['property_id'] ?? null,
            ];
        }
        foreach ($context['tasks_due'] as $t) {
            if ($t['due_date']) {
                $agenda['termine'][] = [
                    'time' => \Carbon\Carbon::parse($t['due_date'])->format('H:i'),
                    'kind' => 'task',
                    'text' => $t['title'],
                    'task_id' => $t['id'],
                ];
            } else {
                $agenda['offen'][] = [
                    'kind' => 'task',
                    'label' => 'fällig',
                    'text' => $t['title'],
                ];
            }
        }

        $nf = $context['nachfass_outcome'];
        if ($nf['sent'] > 0) {
            $agenda['offen'][] = [
                'kind' => 'nachfass',
                'label' => 'laufend',
                'text' => $nf['sent'] . ' Nachfass-Mails der letzten 48h',
            ];
        }

        usort($agenda['termine'], fn($a, $b) => strcmp($a['time'], $b['time']));

        return $agenda;
    }
```

- [ ] **Step 4: Run tests, expect PASS**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php`
Expected: Alle Tests PASS (mindestens 9 Tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/DailyBriefingService.php tests/Unit/DailyBriefingServiceTest.php
git commit -m "feat(briefing): add generate + caching + frontend formatters"
```

---

## Task 6: AdminApiController Actions

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`
- Create: `tests/Feature/BriefingApiTest.php`

- [ ] **Step 1: Feature-Test schreiben**

Create `tests/Feature/BriefingApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BriefingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_briefing_requires_auth(): void
    {
        $res = $this->postJson('/api/admin_api.php?action=briefing_get&key=' . config('portal.api_key'));
        $res->assertStatus(401);
    }

    public function test_get_briefing_returns_data_for_authenticated_user(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $res = $this->postJson('/api/admin_api.php?action=briefing_get&key=' . config('portal.api_key'));
        $res->assertOk();
        $res->assertJsonStructure(['success', 'briefing' => ['preview', 'narrative', 'threads', 'agenda', 'anomalies']]);
    }

    public function test_regenerate_briefing_rate_limited(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        // 1. Call sollte klappen
        $this->postJson('/api/admin_api.php?action=briefing_regenerate&key=' . config('portal.api_key'))->assertOk();

        // 2. Call innerhalb 60s sollte rate-limited sein
        $res = $this->postJson('/api/admin_api.php?action=briefing_regenerate&key=' . config('portal.api_key'));
        $this->assertContains($res->status(), [200, 429], 'Erwartet: entweder 429 rate-limited oder 200 aber mit error-flag');
    }
}
```

- [ ] **Step 2: Test laufen lassen, erwartet FAIL**

Run: `php artisan test tests/Feature/BriefingApiTest.php`
Expected: FAIL mit "action not recognized" oder ähnlich

- [ ] **Step 3: AdminApiController Actions hinzufügen**

Öffne `app/Http/Controllers/Admin/AdminApiController.php`, finde den `match ($action)` Block (ca. Zeile 80). Füge vor dem `default`-Fall folgende Cases hinzu:

```php
            'briefing_get'        => $this->briefingGet($request),
            'briefing_regenerate' => $this->briefingRegenerate($request),
```

Dann am Ende der Klasse (vor `}`) folgende Methoden hinzufügen:

```php
    private function briefingGet(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = (int) \Auth::id();
        if (!$userId) return response()->json(['error' => 'Nicht angemeldet'], 401);

        $date = $request->query('date') ?: now()->toDateString();

        $service = app(\App\Services\DailyBriefingService::class);
        $briefing = $service->generate($userId, $date);

        return response()->json(['success' => true, 'briefing' => $briefing]);
    }

    private function briefingRegenerate(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = (int) \Auth::id();
        if (!$userId) return response()->json(['error' => 'Nicht angemeldet'], 401);

        // Rate-limit: max 1× pro 60s pro User
        $cacheKey = 'briefing_regen_' . $userId;
        if (\Cache::get($cacheKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Bitte warte einen Moment bevor du erneut regenerierst',
                'rate_limited' => true,
            ], 200);
        }
        \Cache::put($cacheKey, 1, 60);

        $service = app(\App\Services\DailyBriefingService::class);
        $briefing = $service->generate($userId, now()->toDateString(), forceRefresh: true);

        return response()->json(['success' => true, 'briefing' => $briefing]);
    }
```

- [ ] **Step 4: Run Feature-Tests, expect PASS**

Run: `php artisan test tests/Feature/BriefingApiTest.php`
Expected: Alle 3 Tests PASS

- [ ] **Step 5: Lint-Check**

Run: `php -l app/Http/Controllers/Admin/AdminApiController.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php tests/Feature/BriefingApiTest.php
git commit -m "feat(briefing): add briefing_get + briefing_regenerate API actions"
```

---

## Task 7: Scheduled Command

**Files:**
- Create: `app/Console/Commands/GenerateDailyBriefings.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Command-Class anlegen**

Create `app/Console/Commands/GenerateDailyBriefings.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\DailyBriefingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDailyBriefings extends Command
{
    protected $signature = 'briefing:generate-daily {--user= : Nur für einen bestimmten User}';
    protected $description = 'Generiert Tagesbriefings für alle aktiven Admin/Makler/Assistenz Users';

    public function handle(DailyBriefingService $service): int
    {
        $today = now()->toDateString();
        $specificUser = $this->option('user');

        $query = DB::table('users')
            ->whereIn('user_type', ['admin', 'makler', 'assistenz', 'backoffice'])
            ->where(function ($q) {
                // Nur User mit mind. 1 Aktivität in letzten 7 Tagen
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('activities as a')
                        ->join('properties as p', 'a.property_id', '=', 'p.id')
                        ->whereRaw('p.broker_id = users.id')
                        ->where('a.activity_date', '>=', now()->subDays(7));
                })->orWhere('user_type', 'admin'); // Admins auch wenn keine Activities
            });

        if ($specificUser) {
            $query->where('id', $specificUser);
        }

        $users = $query->select(['id', 'name', 'user_type'])->get();

        $this->info('Generating Tagesbriefings für ' . $users->count() . ' User...');
        $successful = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $service->generate($user->id, $today, forceRefresh: true);
                $successful++;
                $this->line('  ✓ ' . $user->name . ' (' . $user->user_type . ')');
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  ✗ ' . $user->name . ': ' . $e->getMessage());
                Log::error('Briefing generation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Fertig: {$successful} erfolgreich, {$failed} Fehler");
        return $failed > 0 ? 1 : 0;
    }
}
```

- [ ] **Step 2: Schedule in routes/console.php registrieren**

Öffne `routes/console.php` und füge nach der letzten `Schedule::command(...)` Zeile hinzu:

```php
Schedule::command('briefing:generate-daily')
    ->dailyAt('06:30')
    ->timezone('Europe/Vienna')
    ->withoutOverlapping();
```

- [ ] **Step 3: Command manuell testen**

Run: `php artisan briefing:generate-daily --user=1`
Expected: Output zeigt `✓ <User-Name> (<user_type>)` und "Fertig: 1 erfolgreich, 0 Fehler"

- [ ] **Step 4: Schedule-Liste prüfen**

Run: `php artisan schedule:list 2>&1 | grep briefing`
Expected: Zeile mit `briefing:generate-daily` und `30 6 * * *` (6:30 daily)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/GenerateDailyBriefings.php routes/console.php
git commit -m "feat(briefing): add scheduled daily generation at 06:30 Vienna"
```

---

## Task 8: Frontend — TagesbriefingThread Component

**Files:**
- Create: `resources/js/Components/Admin/TagesbriefingThread.vue`

- [ ] **Step 1: Component schreiben**

Create `resources/js/Components/Admin/TagesbriefingThread.vue`:

```vue
<script setup>
import { computed } from "vue";
import { ChevronRight } from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";

const props = defineProps({
    thread: { type: Object, required: true },
});
const emit = defineEmits(["open"]);

const dotColor = computed(() => {
    return {
        red: "bg-red-500",
        orange: "bg-orange-500",
        yellow: "bg-yellow-500",
        green: "bg-emerald-500",
    }[props.thread.priority] || "bg-gray-400";
});

const labelVariant = computed(() => {
    if (props.thread.priority === "red") return "bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300";
    if (props.thread.priority === "orange") return "bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-300";
    if (props.thread.priority === "yellow") return "bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300";
    return "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300";
});

const displayName = computed(() => {
    const name = props.thread.stakeholder || "Unbekannt";
    const ref = props.thread.property_ref;
    return ref ? `${name} @ ${ref}` : name;
});
</script>

<template>
    <div
        class="py-3 border-b border-border/60 last:border-b-0 cursor-pointer hover:bg-accent/40 -mx-3 px-3 rounded-md transition-colors"
        @click="emit('open', thread.id)"
    >
        <div class="flex items-center gap-2.5 mb-1.5 flex-wrap">
            <span class="w-2 h-2 rounded-full shrink-0" :class="dotColor"></span>
            <span class="text-sm font-semibold text-foreground">{{ displayName }}</span>
            <Badge v-if="thread.label" class="text-[10px] px-2 py-0.5 border-0" :class="labelVariant">
                {{ thread.label }}
            </Badge>
            <ChevronRight class="w-4 h-4 text-muted-foreground ml-auto" />
        </div>
        <div v-if="thread.trail && thread.trail.length" class="text-xs text-muted-foreground leading-relaxed pl-4">
            <span v-for="(entry, i) in thread.trail" :key="i">
                <span :class="i === thread.trail.length - 1 ? 'text-foreground font-medium' : ''">{{ entry }}</span>
                <span v-if="i < thread.trail.length - 1" class="mx-1.5">→</span>
            </span>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Syntax-Check via ESLint/Build**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds. Falls Fehler → fix und erneut bauen.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/TagesbriefingThread.vue
git commit -m "feat(briefing): add TagesbriefingThread component"
```

---

## Task 9: Frontend — TagesbriefingSheet Component

**Files:**
- Create: `resources/js/Components/Admin/TagesbriefingSheet.vue`

- [ ] **Step 1: Component schreiben**

Create `resources/js/Components/Admin/TagesbriefingSheet.vue`:

```vue
<script setup>
import { computed } from "vue";
import { RefreshCw, X, Flame, TrendingDown, AlertTriangle, Clock, CheckSquare, Mail, Eye } from "lucide-vue-next";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import TagesbriefingThread from "./TagesbriefingThread.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    briefing: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    date: { type: String, default: "" },
});
const emit = defineEmits(["update:open", "regenerate", "open-conversation", "open-task", "open-viewing"]);

const narrativeHtml = computed(() => {
    if (!props.briefing?.narrative) return "";
    return props.briefing.narrative; // KI liefert HTML mit <strong>, <mark>
});

const weekday = computed(() => {
    if (!props.date) return "";
    const d = new Date(props.date + "T00:00:00");
    return d.toLocaleDateString("de-AT", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
});

const anomalyIcon = (kind) => ({ hot: Flame, cool: TrendingDown, warn: AlertTriangle })[kind] || AlertTriangle;
const anomalyClasses = (kind) => ({
    hot: "bg-red-50 border-red-200 text-red-900 dark:bg-red-950/20 dark:border-red-900/40 dark:text-red-200",
    cool: "bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/20 dark:border-blue-900/40 dark:text-blue-200",
    warn: "bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/20 dark:border-amber-900/40 dark:text-amber-200",
}[kind] || "bg-muted text-foreground");
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent side="right" class="w-full sm:max-w-2xl overflow-y-auto">
            <SheetHeader class="pr-8">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <SheetTitle class="text-lg">Tagesbriefing</SheetTitle>
                        <SheetDescription class="text-xs mt-1">{{ weekday }} · Zusammenfassung gestern &amp; heute</SheetDescription>
                    </div>
                    <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0" @click="emit('regenerate')" :disabled="loading" title="Neu generieren">
                        <RefreshCw :class="['w-4 h-4', loading && 'animate-spin']" />
                    </Button>
                </div>
            </SheetHeader>

            <div v-if="loading && !briefing" class="mt-6 flex items-center justify-center h-32 text-sm text-muted-foreground">
                Briefing wird generiert…
            </div>

            <div v-else-if="briefing" class="mt-6 space-y-6">

                <!-- Block A: Narrative -->
                <section>
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Gestern in 3 Sätzen</h3>
                    <div
                        class="bg-muted rounded-lg p-4 text-sm leading-relaxed prose-briefing"
                        v-html="narrativeHtml"
                    ></div>
                </section>

                <!-- Block B: Threads -->
                <section v-if="briefing.threads && briefing.threads.length">
                    <div class="flex items-center gap-2 mb-2.5">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Aktive Threads mit Kontext</h3>
                        <Badge variant="outline" class="ml-auto text-[10px]">{{ briefing.threads.length }} offen</Badge>
                    </div>
                    <div>
                        <TagesbriefingThread
                            v-for="t in briefing.threads"
                            :key="t.id"
                            :thread="t"
                            @open="emit('open-conversation', $event)"
                        />
                    </div>
                </section>

                <!-- Block C: Agenda -->
                <section v-if="briefing.agenda">
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Anstehend heute</h3>

                    <div v-if="briefing.agenda.termine && briefing.agenda.termine.length">
                        <div
                            v-for="(item, i) in briefing.agenda.termine" :key="'t'+i"
                            class="flex items-center gap-3 py-2 text-sm cursor-pointer hover:bg-accent/40 -mx-2 px-2 rounded-md"
                            @click="item.kind === 'viewing' ? emit('open-viewing', item.property_id) : emit('open-task', item.task_id)"
                        >
                            <span class="text-xs font-semibold text-[#EE7600] w-14 shrink-0">{{ item.time }}</span>
                            <Eye v-if="item.kind === 'viewing'" class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <CheckSquare v-else class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <span>{{ item.text }}</span>
                        </div>
                    </div>

                    <Separator v-if="briefing.agenda.termine?.length && briefing.agenda.offen?.length" class="my-3" />

                    <div v-if="briefing.agenda.offen && briefing.agenda.offen.length">
                        <div v-for="(item, i) in briefing.agenda.offen" :key="'o'+i" class="flex items-center gap-3 py-2 text-sm">
                            <span class="text-[11px] font-medium text-muted-foreground w-14 shrink-0">{{ item.label }}</span>
                            <Mail v-if="item.kind === 'nachfass'" class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <Clock v-else class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <span>{{ item.text }}</span>
                        </div>
                    </div>

                    <p v-if="!briefing.agenda.termine?.length && !briefing.agenda.offen?.length" class="text-sm text-muted-foreground italic">
                        Keine Termine geplant.
                    </p>
                </section>

                <!-- Block D: Anomalies -->
                <section v-if="briefing.anomalies && briefing.anomalies.length">
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Auffälligkeiten</h3>
                    <div class="space-y-2">
                        <div
                            v-for="(a, i) in briefing.anomalies" :key="'a'+i"
                            class="flex gap-2.5 p-3 rounded-lg border text-sm leading-relaxed"
                            :class="anomalyClasses(a.kind)"
                        >
                            <component :is="anomalyIcon(a.kind)" class="w-4 h-4 shrink-0 mt-0.5" />
                            <div v-html="a.text"></div>
                        </div>
                    </div>
                </section>

            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.prose-briefing :deep(strong) { font-weight: 600; color: hsl(var(--foreground)); }
.prose-briefing :deep(mark) {
    background: rgb(254 226 226);
    color: rgb(153 27 27);
    font-weight: 500;
    padding: 0 3px;
    border-radius: 3px;
}
:root.dark .prose-briefing :deep(mark) {
    background: rgb(69 10 10 / 0.4);
    color: rgb(254 202 202);
}
</style>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -10`
Expected: Build ok. Bei Fehlern (z.B. fehlende Imports) → fixen.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/TagesbriefingSheet.vue
git commit -m "feat(briefing): add TagesbriefingSheet with 4 sections"
```

---

## Task 10: Frontend — TagesbriefingCard Component

**Files:**
- Create: `resources/js/Components/Admin/TagesbriefingCard.vue`

- [ ] **Step 1: Component schreiben**

Create `resources/js/Components/Admin/TagesbriefingCard.vue`:

```vue
<script setup>
import { computed } from "vue";
import { Sun, ArrowRight, Loader2 } from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const props = defineProps({
    briefing: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    date: { type: String, default: "" },
});
const emit = defineEmits(["open"]);

const weekday = computed(() => {
    if (!props.date) return "";
    const d = new Date(props.date + "T00:00:00");
    return d.toLocaleDateString("de-AT", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
});

const previewHtml = computed(() => {
    if (props.loading && !props.briefing) return "Briefing wird generiert…";
    if (!props.briefing?.preview) return "Noch kein Briefing für heute — klicke zum Öffnen.";
    return props.briefing.preview;
});
</script>

<template>
    <div
        class="relative rounded-xl border border-border/40 bg-card shadow-sm overflow-hidden cursor-pointer hover:shadow-md transition-shadow"
        @click="emit('open')"
    >
        <!-- Orange Akzent-Leiste links -->
        <div class="absolute left-0 top-0 bottom-0 w-[3px] bg-[#EE7600]"></div>

        <div class="flex items-center gap-4 p-5 pl-6">
            <!-- Icon-Box -->
            <div class="w-10 h-10 rounded-lg bg-[#fff7ed] dark:bg-orange-950/20 flex items-center justify-center shrink-0">
                <Sun class="w-5 h-5 text-[#EE7600]" />
            </div>

            <!-- Body -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="text-base font-semibold tracking-tight">Tagesbriefing</span>
                    <Badge variant="secondary" class="text-[10px] px-2 py-0.5">KI</Badge>
                    <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">{{ weekday }}</span>
                </div>
                <div class="text-sm text-muted-foreground leading-snug">
                    <Loader2 v-if="loading && !briefing" class="inline w-3.5 h-3.5 animate-spin mr-1" />
                    <span>{{ previewHtml }}</span>
                </div>
            </div>

            <!-- CTA Button -->
            <Button size="sm" class="shrink-0 hidden sm:inline-flex" @click.stop="emit('open')">
                Vollständig lesen
                <ArrowRight class="w-3.5 h-3.5 ml-1" />
            </Button>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -5`
Expected: ok.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/TagesbriefingCard.vue
git commit -m "feat(briefing): add TagesbriefingCard entry component"
```

---

## Task 11: Frontend — Integration in TodayTab + Dashboard

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue`
- Modify: `resources/js/Pages/Admin/Dashboard.vue` (nur Event-Handling falls nötig)

- [ ] **Step 1: Imports + State in TodayTab hinzufügen**

Öffne `resources/js/Components/Admin/TodayTab.vue`. Füge am Anfang der Imports (neben den existierenden) hinzu:

```javascript
import TagesbriefingCard from "@/Components/Admin/TagesbriefingCard.vue";
import TagesbriefingSheet from "@/Components/Admin/TagesbriefingSheet.vue";
```

Im `<script setup>`-Block nach `const unansweredCount = inject("unansweredCount");` (ca. Zeile 26) folgendes hinzufügen:

```javascript
// Tagesbriefing
const briefingData = ref(null);
const briefingLoading = ref(true);
const briefingOpen = ref(false);
const briefingDate = ref(new Date().toISOString().slice(0, 10));

async function loadBriefing() {
    briefingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=briefing_get&date=" + briefingDate.value);
        const d = await r.json();
        if (d.success) briefingData.value = d.briefing;
    } catch (e) {
        console.error("Briefing load failed:", e);
    } finally {
        briefingLoading.value = false;
    }
}

async function regenerateBriefing() {
    briefingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=briefing_regenerate", { method: "POST" });
        const d = await r.json();
        if (d.success) briefingData.value = d.briefing;
        else if (d.rate_limited) toast("Zu schnell — bitte kurz warten");
    } catch (e) {
        toast("Fehler beim Regenerieren");
    } finally {
        briefingLoading.value = false;
    }
}

function openBriefingConversation(convId) {
    briefingOpen.value = false;
    window.location.hash = "";
    setTimeout(() => {
        switchTab("inbox");
        window.dispatchEvent(new CustomEvent("open-conversation", { detail: { convId } }));
    }, 100);
}

function openBriefingViewing(propertyId) {
    briefingOpen.value = false;
    setTimeout(() => switchTab("properties"), 100);
}

function openBriefingTask(taskId) {
    briefingOpen.value = false;
    setTimeout(() => switchTab("tasks"), 100);
}
```

Im `onMounted`-Block (ca. Zeile 305) `loadBriefing()` zum Promise.all hinzufügen:

```javascript
onMounted(async () => {
    if (userType.value !== "assistenz") loadSalesAndCommissions();
    await Promise.all([
        loadTasks(),
        loadKaufanboteStats(),
        loadPerformance(),
        loadUpcoming(),
        loadBriefing(),
    ]);
});
```

- [ ] **Step 2: Template-Integration**

Im `<template>`-Block, finde die Zeile `<div class="px-4 py-6 space-y-6">` (ca. Zeile 310). Direkt nach dieser Zeile, VOR dem `<!-- Section 1: Action Card -->`, füge hinzu:

```vue
        <!-- Tagesbriefing (NEU: oberhalb der Action-Card) -->
        <TagesbriefingCard
            :briefing="briefingData"
            :loading="briefingLoading"
            :date="briefingDate"
            @open="briefingOpen = true"
        />

        <TagesbriefingSheet
            v-model:open="briefingOpen"
            :briefing="briefingData"
            :loading="briefingLoading"
            :date="briefingDate"
            @regenerate="regenerateBriefing"
            @open-conversation="openBriefingConversation"
            @open-viewing="openBriefingViewing"
            @open-task="openBriefingTask"
        />
```

- [ ] **Step 3: Build prüfen**

Run: `npm run build 2>&1 | tail -10`
Expected: Build ok.

- [ ] **Step 4: Im Browser testen (lokal)**

Run: `php artisan serve &` (falls nicht schon läuft)
Dann: Browser auf `http://localhost:8000/admin` öffnen, im Dashboard-Tab:
- [ ] Tagesbriefing-Card ist oberhalb "Guten Morgen Max" sichtbar
- [ ] Klick auf Card → Sheet öffnet von rechts
- [ ] 4 Sektionen sichtbar (oder leere ausgeblendet bei leerem Account)
- [ ] ESC-Taste schließt Sheet
- [ ] RefreshCw-Icon regeneriert Briefing

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(briefing): integrate Tagesbriefing-Card + Sheet into TodayTab"
```

---

## Task 12: Deploy

**Files:**
- All modified files

- [ ] **Step 1: Finale Lint-Checks**

Run: `php -l app/Services/DailyBriefingService.php app/Http/Controllers/Admin/AdminApiController.php app/Console/Commands/GenerateDailyBriefings.php app/Models/DailyBriefing.php`
Expected: `No syntax errors detected` für alle Dateien.

- [ ] **Step 2: Full test run**

Run: `php artisan test tests/Unit/DailyBriefingServiceTest.php tests/Feature/BriefingApiTest.php`
Expected: Alle Tests PASS.

- [ ] **Step 3: Frontend Build für Production**

Run: `npm run build`
Expected: Build completes without errors. Assets in `public/build/`.

- [ ] **Step 4: Push nach GitHub**

```bash
git push origin main
```

- [ ] **Step 5: Deploy auf Production**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"
```

Expected: Deploy-Log zeigt `DEPLOY COMPLETE` am Ende. Migration läuft automatisch (Teil von deploy.sh).

- [ ] **Step 6: Production-Smoketest — Command manuell**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan briefing:generate-daily --user=1"
```
Expected: `Fertig: 1 erfolgreich, 0 Fehler`

- [ ] **Step 7: Production-Smoketest — Browser**

- [ ] Login auf https://kundenportal.sr-homes.at/admin
- [ ] Dashboard-Tab zeigt Tagesbriefing-Card oben
- [ ] Sheet öffnet mit Inhalt
- [ ] Regenerate-Button funktioniert

- [ ] **Step 8: Final Commit mit Zusammenfassung (falls noch Änderungen offen)**

```bash
git status
# Falls alles clean → fertig
# Sonst letzten Commit mit Fixes
```

---

## Self-Review (nach Plan-Erstellung)

**Spec coverage:**
- ✅ Card oben im TodayTab → Task 10, 11
- ✅ Sheet mit 4 Blöcken → Task 9
- ✅ Narrative (KI) → Task 4
- ✅ Active Threads mit Trail → Task 8, 9 (Frontend), Task 5 (Backend formatThreadsForFrontend)
- ✅ Agenda (Termine + Offen) → Task 5 (formatAgendaForFrontend), Task 9 (Rendering)
- ✅ Anomalies (Hot/Cool/Warn) → Task 2 (property_signals), Task 3 (fallback), Task 4 (KI)
- ✅ Caching → Task 1 (Schema), Task 5 (saveToCache/loadFromCache)
- ✅ Scheduled Job 06:30 Vienna → Task 7
- ✅ Rate-Limited Regenerate → Task 6 (Cache::put 60s)
- ✅ Broker-Scoping identisch zu scopeForBroker → Task 2 (gatherContext)
- ✅ Fallback wenn KI fehlschlägt → Task 3 (fallbackTemplate), Task 5 (generate)
- ✅ Thread-Klick springt in Inbox → Task 11 (openBriefingConversation)
- ✅ Dark Mode via Tailwind `dark:` classes → Task 9 (anomalyClasses, Sheet)
- ✅ Tests für Security-Regressions → Task 2 (broker_scope_for_makler, returns_empty_without_user_id)

**Placeholder scan:** ✅ Alle Tasks haben konkreten Code, keine TBDs.

**Type consistency:**
- `generate()` Signatur: `generate(int $userId, ?string $date = null, bool $forceRefresh = false): array` — überall gleich (Task 5, 6, 7)
- Frontend props: `briefing` + `loading` + `date` — konsistent in Card + Sheet (Task 9, 10, 11)
- Action-Namen: `briefing_get` + `briefing_regenerate` — konsistent Backend + Frontend (Task 6, 11)
