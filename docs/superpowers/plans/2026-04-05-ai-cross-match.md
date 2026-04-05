# AI Cross-Match System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a customer inquiry/rejection arrives, automatically match them against other properties and let the broker send expose emails from within the inbox.

**Architecture:** Background job after IMAP sync extracts search criteria via Haiku, scores all active broker properties programmatically, stores matches in `property_matches` table. Frontend shows animated gradient border on matched conversations, full-screen selection view, and generates AI drafts with expose attachments.

**Tech Stack:** Laravel 11, Vue 3 + Inertia, shadcn-vue, Redis queue (2 Supervisor workers), Anthropic Haiku for criteria extraction + draft generation.

**VPS:** `187.124.166.153` — SSH as root, project at `/var/www/srhomes/`

**After PHP changes:** `supervisorctl restart all && systemctl restart php8.3-fpm`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `database/migrations/2026_04_05_100000_create_property_matches_table.php` | DB schema for matches + conversation columns |
| `app/Models/PropertyMatch.php` | Eloquent model for property_matches |
| `app/Services/PropertyMatcherService.php` | Criteria extraction, scoring, match orchestration |
| `app/Jobs/ProcessPropertyMatching.php` | Async job dispatched after email processing |
| `resources/js/Components/Admin/inbox/InboxMatchView.vue` | Full-screen property selection view |
| `resources/js/Components/Admin/inbox/InboxMatchCard.vue` | Individual property card with selection |

### Modified Files
| File | Change |
|------|--------|
| `app/Services/ImapService.php` | Dispatch ProcessPropertyMatching job after updateFromEmail |
| `app/Http/Controllers/Admin/AdminApiController.php` | Add match action routes |
| `app/Http/Controllers/Admin/ConversationController.php` | Add match_list, match_dismiss, match_generate_draft, activity creation in reply |
| `app/Models/Conversation.php` | Add match_count, match_dismissed to fillable + matches relationship |
| `resources/js/Components/Admin/inbox/InboxConversationItem.vue` | Animated gradient border + match badge |
| `resources/js/Components/Admin/InboxTab.vue` | matchMode state, Matches subtab, match API calls |
| `resources/js/Components/Admin/inbox/InboxAiDraft.vue` | Match object count badge |

---

## Task 1: Database Migration + Model

**Files:**
- Create: `database/migrations/2026_04_05_100000_create_property_matches_table.php`
- Create: `app/Models/PropertyMatch.php`
- Modify: `app/Models/Conversation.php`

- [ ] **Step 1: Create migration**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan make:migration create_property_matches_table"
```

Then replace the generated file content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('match_reason')->nullable();
            $table->json('criteria_json')->nullable();
            $table->string('cross_match_intent', 20)->nullable();
            $table->enum('status', ['pending', 'selected', 'sent', 'dismissed'])->default('pending');
            $table->timestamps();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'status']);
            $table->unique(['conversation_id', 'property_id'], 'uq_conv_prop');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedSmallInteger('match_count')->default(0)->after('is_read');
            $table->boolean('match_dismissed')->default(false)->after('match_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_matches');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['match_count', 'match_dismissed']);
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan migrate"
```

Expected: `property_matches` table created, `conversations` table has new columns.

- [ ] **Step 3: Create PropertyMatch model**

Create `app/Models/PropertyMatch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyMatch extends Model
{
    protected $fillable = [
        'conversation_id',
        'property_id',
        'score',
        'match_reason',
        'criteria_json',
        'cross_match_intent',
        'status',
    ];

    protected $casts = [
        'criteria_json' => 'array',
        'score' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
```

- [ ] **Step 4: Update Conversation model**

In `app/Models/Conversation.php`, add `match_count` and `match_dismissed` to `$fillable`:

```php
// Add to the $fillable array after 'is_read':
"match_count", "match_dismissed",
```

Add the `matches()` relationship:

```php
public function matches()
{
    return $this->hasMany(PropertyMatch::class);
}
```

Add a `$casts` entry:

```php
// In the $casts array:
'match_dismissed' => 'boolean',
```

- [ ] **Step 5: Verify**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan tinker --execute=\"echo implode(',', \Illuminate\Support\Facades\Schema::getColumnListing('property_matches'));\""
```

Expected output includes: `id,conversation_id,property_id,score,match_reason,criteria_json,cross_match_intent,status,created_at,updated_at`

- [ ] **Step 6: Commit**

```bash
cd /var/www/srhomes
git add database/migrations/*property_matches* app/Models/PropertyMatch.php app/Models/Conversation.php
git commit -m "feat: add property_matches table and model for AI cross-match"
```

---

## Task 2: PropertyMatcherService

**Files:**
- Create: `app/Services/PropertyMatcherService.php`

This is the core service. One AI call (Haiku) extracts criteria + intent. Programmatic scoring against all active broker properties. Stores matches in DB.

- [ ] **Step 1: Create PropertyMatcherService**

Create `app/Services/PropertyMatcherService.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyMatcherService
{
    protected AnthropicService $ai;

    // Intent -> minimum score threshold
    protected array $thresholds = [
        'high'   => 60,
        'medium' => 80,
        'low'    => 90,
    ];

    public function __construct(AnthropicService $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Main entry point. Analyze an email conversation and find matching properties.
     */
    public function analyzeAndMatch(Conversation $conv, PortalEmail $email): void
    {
        // Skip if already dismissed or already has matches
        if ($conv->match_dismissed || $conv->match_count > 0) {
            return;
        }

        // Need a broker to scope properties
        $brokerId = null;
        if ($conv->property_id) {
            $brokerId = Property::where('id', $conv->property_id)->value('broker_id');
        }
        if (!$brokerId) {
            $brokerId = DB::table('email_accounts')
                ->where('id', DB::table('portal_emails')->where('id', $email->id)->value('account_id'))
                ->value('user_id');
        }
        if (!$brokerId) {
            Log::info("[CrossMatch] No broker found for conv {$conv->id}");
            return;
        }

        // Load thread context (last 3 messages)
        $threadContext = $this->buildThreadContext($conv, $email);

        // Step 1: AI extracts criteria + intent
        $analysis = $this->extractCriteria($threadContext);
        if (!$analysis || ($analysis['cross_match_intent'] ?? 'none') === 'none') {
            Log::info("[CrossMatch] Intent=none for conv {$conv->id}, skipping");
            return;
        }

        $intent = $analysis['cross_match_intent'];
        $criteria = $analysis['criteria'] ?? [];
        $threshold = $this->thresholds[$intent] ?? 90;

        // Step 2: Find and score candidate properties
        $excludeIds = [$conv->property_id];
        // Exclude same project group
        if ($conv->property_id) {
            $groupId = Property::where('id', $conv->property_id)->value('project_group_id');
            if ($groupId) {
                $sameGroup = Property::where('project_group_id', $groupId)->pluck('id')->toArray();
                $excludeIds = array_merge($excludeIds, $sameGroup);
            }
        }

        $candidates = Property::where('broker_id', $brokerId)
            ->whereIn('realty_status', ['auftrag', 'inserat'])
            ->whereNotIn('id', array_filter($excludeIds))
            ->get();

        if ($candidates->isEmpty()) {
            Log::info("[CrossMatch] No candidate properties for broker {$brokerId}");
            return;
        }

        // Step 3: Score each candidate
        $matches = [];
        foreach ($candidates as $prop) {
            $score = $this->scoreProperty($prop, $criteria);
            if ($score >= $threshold) {
                $matches[] = [
                    'property' => $prop,
                    'score' => $score,
                    'reason' => $this->buildMatchReason($prop, $criteria),
                ];
            }
        }

        // Sort by score desc, limit to 5
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        $matches = array_slice($matches, 0, 5);

        if (empty($matches)) {
            Log::info("[CrossMatch] No matches above threshold {$threshold} for conv {$conv->id}");
            return;
        }

        // Step 4: Store matches
        foreach ($matches as $m) {
            PropertyMatch::updateOrCreate(
                ['conversation_id' => $conv->id, 'property_id' => $m['property']->id],
                [
                    'score' => $m['score'],
                    'match_reason' => $m['reason'],
                    'criteria_json' => $criteria,
                    'cross_match_intent' => $intent,
                    'status' => 'pending',
                ]
            );
        }

        $conv->update(['match_count' => count($matches)]);
        Log::info("[CrossMatch] Found " . count($matches) . " matches for conv {$conv->id}");
    }

    /**
     * Build thread context from last 3 emails in conversation.
     */
    protected function buildThreadContext(Conversation $conv, PortalEmail $latestEmail): string
    {
        $query = DB::table('portal_emails')
            ->where('contact_email', $conv->contact_email)
            ->orderByDesc('date')
            ->limit(3);

        if ($conv->property_id) {
            $query->where('property_id', $conv->property_id);
        } else {
            $query->whereNull('property_id');
        }

        $emails = $query->get(['from_name', 'from_email', 'subject', 'body_text', 'direction']);

        $lines = [];
        foreach ($emails->reverse() as $e) {
            $dir = $e->direction === 'inbound' ? 'KUNDE' : 'MAKLER';
            $lines[] = "[{$dir}] {$e->from_name}: {$e->subject}\n" . mb_substr($e->body_text ?? '', 0, 500);
        }
        return implode("\n---\n", $lines);
    }

    /**
     * AI call: extract search criteria and cross-match intent from thread.
     */
    public function extractCriteria(string $threadContext): ?array
    {
        $systemPrompt = <<<'PROMPT'
Du bist ein Immobilien-Analyst. Analysiere den Email-Thread eines Interessenten und extrahiere Suchkriterien.

Antworte als JSON:
{
  "cross_match_intent": "high|medium|low|none",
  "criteria": {
    "object_types": ["Einfamilienhaus", "Wohnung", etc.],
    "min_area": null or number in m²,
    "max_price": null or number in EUR,
    "locations": ["Ort1", "Ort2"],
    "features": ["Garten", "Balkon", etc.],
    "household": "Beschreibung oder null"
  },
  "reason": "Kurze Begründung warum dieser Intent"
}

Intent-Regeln:
- "high": Absage mit konkreten Suchkriterien, oder explizite Wünsche die über das aktuelle Objekt hinausgehen
- "medium": Erwähnt Präferenzen beiläufig, oder Objekt ist reserviert/verkauft
- "low": Standard-Erstanfrage ohne besondere Signale, allgemeines Interesse
- "none": Interne Mail, Spam, Eigentümer-Kommunikation, oder keine verwertbaren Kriterien

Extrahiere so viele Kriterien wie möglich aus dem Kontext. Bei Erstanfragen: leite Kriterien aus dem angefragten Objekt ab (Typ, Lage, Preisklasse).
PROMPT;

        return $this->ai->chatJson($systemPrompt, $threadContext, 500);
    }

    /**
     * Programmatic scoring: weighted match of property against criteria.
     */
    protected function scoreProperty(Property $prop, array $criteria): int
    {
        $score = 0;

        // Object type match (30 points)
        $types = array_map('mb_strtolower', $criteria['object_types'] ?? []);
        if (!empty($types)) {
            $propType = mb_strtolower($prop->object_type ?? '');
            $propSubtype = mb_strtolower($prop->object_subtype ?? '');
            $propCategory = mb_strtolower($prop->property_category ?? '');
            foreach ($types as $t) {
                if (str_contains($propType, $t) || str_contains($propSubtype, $t) || str_contains($propCategory, $t)
                    || str_contains($t, $propType) || str_contains($t, $propSubtype)) {
                    $score += 30;
                    break;
                }
            }
        }

        // Location match (25 points)
        $locations = array_map('mb_strtolower', $criteria['locations'] ?? []);
        if (!empty($locations)) {
            $propCity = mb_strtolower($prop->city ?? '');
            $propAddr = mb_strtolower($prop->address ?? '');
            foreach ($locations as $loc) {
                if (str_contains($propCity, $loc) || str_contains($propAddr, $loc)
                    || str_contains($loc, $propCity)) {
                    $score += 25;
                    break;
                }
            }
        }

        // Price within range (20 points)
        $maxPrice = $criteria['max_price'] ?? null;
        if ($maxPrice && $prop->purchase_price) {
            if ($prop->purchase_price <= $maxPrice) {
                $score += 20;
            } elseif ($prop->purchase_price <= $maxPrice * 1.15) {
                $score += 10; // within 15% tolerance
            }
        } elseif (!$maxPrice) {
            $score += 10; // no price criteria = neutral
        }

        // Area >= minimum (15 points)
        $minArea = $criteria['min_area'] ?? null;
        $propArea = $prop->living_area ?: $prop->total_area ?: 0;
        if ($minArea && $propArea) {
            if ($propArea >= $minArea) {
                $score += 15;
            } elseif ($propArea >= $minArea * 0.85) {
                $score += 8; // within 15% tolerance
            }
        } elseif (!$minArea) {
            $score += 8; // no area criteria = neutral
        }

        // Features match (10 points)
        $features = array_map('mb_strtolower', $criteria['features'] ?? []);
        if (!empty($features)) {
            $matched = 0;
            foreach ($features as $feat) {
                if (
                    (str_contains($feat, 'garten') && $prop->has_garden) ||
                    (str_contains($feat, 'balkon') && $prop->has_balcony) ||
                    (str_contains($feat, 'terrasse') && $prop->has_terrace) ||
                    (str_contains($feat, 'keller') && ($prop->has_basement || $prop->has_cellar)) ||
                    (str_contains($feat, 'garage') && $prop->garage_spaces > 0) ||
                    (str_contains($feat, 'aufzug') && $prop->has_elevator) ||
                    (str_contains($feat, 'pool') && $prop->has_pool) ||
                    (str_contains($feat, 'barrierefrei') && $prop->has_barrier_free)
                ) {
                    $matched++;
                }
            }
            $score += $matched > 0 ? min(10, (int)(10 * $matched / count($features))) : 0;
        } else {
            $score += 5; // no feature criteria = neutral
        }

        return min(100, $score);
    }

    /**
     * Build human-readable match reason from criteria overlap.
     */
    protected function buildMatchReason(Property $prop, array $criteria): string
    {
        $parts = [];

        $types = $criteria['object_types'] ?? [];
        if (!empty($types) && $prop->object_type) {
            $parts[] = $prop->object_type;
        }

        $propArea = $prop->living_area ?: $prop->total_area;
        if ($propArea && ($criteria['min_area'] ?? null)) {
            $parts[] = $propArea . ' m²';
        }

        if ($prop->city) {
            $parts[] = $prop->city;
        }

        $features = $criteria['features'] ?? [];
        foreach (array_slice($features, 0, 2) as $f) {
            $parts[] = $f;
        }

        return empty($parts) ? 'Allgemeine Übereinstimmung' : implode(', ', $parts);
    }

    /**
     * Get stored matches for a conversation (for API).
     */
    public function getMatchesForConversation(int $convId): array
    {
        $matches = PropertyMatch::where('conversation_id', $convId)
            ->whereIn('status', ['pending', 'selected'])
            ->orderByDesc('score')
            ->with('property:id,ref_id,title,address,city,purchase_price,living_area,total_area,rooms_amount,object_type,main_image_id,expose_path,realty_status')
            ->get();

        $criteria = $matches->first()?->criteria_json;

        return [
            'criteria' => $criteria,
            'matches' => $matches->map(function ($m) {
                $p = $m->property;
                $imageUrl = null;
                if ($p && $p->main_image_id) {
                    $img = DB::table('property_images')->where('id', $p->main_image_id)->value('path');
                    if ($img) $imageUrl = '/storage/' . $img;
                }
                return [
                    'id' => $m->id,
                    'property_id' => $m->property_id,
                    'score' => $m->score,
                    'match_reason' => $m->match_reason,
                    'status' => $m->status,
                    'title' => $p?->title ?? 'Unbekannt',
                    'ref_id' => $p?->ref_id,
                    'address' => trim(($p?->address ?? '') . ', ' . ($p?->city ?? ''), ', '),
                    'price' => $p?->purchase_price,
                    'area' => $p?->living_area ?: $p?->total_area,
                    'rooms' => $p?->rooms_amount,
                    'object_type' => $p?->object_type,
                    'image_url' => $imageUrl,
                    'has_expose' => !empty($p?->expose_path),
                ];
            })->values()->toArray(),
        ];
    }
}
```

- [ ] **Step 2: Verify service instantiation**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan tinker --execute=\"app(App\Services\PropertyMatcherService::class); echo 'OK';\""
```

Expected: `OK` (no errors).

- [ ] **Step 3: Commit**

```bash
cd /var/www/srhomes
git add app/Services/PropertyMatcherService.php
git commit -m "feat: add PropertyMatcherService for AI cross-matching"
```

---

## Task 3: Background Job + ImapService Integration

**Files:**
- Create: `app/Jobs/ProcessPropertyMatching.php`
- Modify: `app/Services/ImapService.php`

- [ ] **Step 1: Create the job**

Create `app/Jobs/ProcessPropertyMatching.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Services\PropertyMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPropertyMatching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        protected int $conversationId,
        protected int $emailId
    ) {}

    public function handle(PropertyMatcherService $matcher): void
    {
        $conv = Conversation::find($this->conversationId);
        $email = PortalEmail::find($this->emailId);

        if (!$conv || !$email) {
            Log::warning("[CrossMatch Job] Conv {$this->conversationId} or email {$this->emailId} not found");
            return;
        }

        try {
            $matcher->analyzeAndMatch($conv, $email);
        } catch (\Throwable $e) {
            Log::error("[CrossMatch Job] Error for conv {$this->conversationId}: " . $e->getMessage());
            throw $e;
        }
    }
}
```

- [ ] **Step 2: Dispatch job from ImapService**

In `app/Services/ImapService.php`, find the line calling `$convService->updateFromEmail($email, ...)` (around line ~855-860). Add the job dispatch right after:

Find this code:
```php
$convService->updateFromEmail($email, $newActivity ?? null);
```

Add immediately after it:
```php
// Cross-match: analyze if customer might fit other properties
if ($email->direction === 'inbound') {
    $conv = \App\Models\Conversation::where('contact_email', $email->contact_email ?? $email->from_email)
        ->where(function ($q) use ($email) {
            if ($email->property_id) {
                $q->where('property_id', $email->property_id);
            } else {
                $q->whereNull('property_id');
            }
        })
        ->first();
    if ($conv) {
        \App\Jobs\ProcessPropertyMatching::dispatch($conv->id, $email->id);
    }
}
```

- [ ] **Step 3: Restart services**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && supervisorctl restart all && systemctl restart php8.3-fpm"
```

- [ ] **Step 4: Verify job dispatching**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan tinker --execute=\"\App\Jobs\ProcessPropertyMatching::dispatch(1, 1); echo 'Job dispatched';\""
```

Check worker log:
```bash
ssh root@187.124.166.153 "tail -20 /var/www/srhomes/storage/logs/worker.log"
```

Expected: Job processed (may log 'No broker found' or similar if IDs don't match real data, but no crash).

- [ ] **Step 5: Commit**

```bash
cd /var/www/srhomes
git add app/Jobs/ProcessPropertyMatching.php app/Services/ImapService.php
git commit -m "feat: dispatch cross-match job after inbound email processing"
```

---

## Task 4: API Endpoints

**Files:**
- Modify: `app/Http/Controllers/Admin/ConversationController.php`
- Modify: `app/Http/Controllers/Admin/AdminApiController.php`

- [ ] **Step 1: Add match methods to ConversationController**

Add these imports at the top of `app/Http/Controllers/Admin/ConversationController.php` (if not already present):

```php
use App\Models\PropertyMatch;
use App\Models\Property;
use App\Services\PropertyMatcherService;
use App\Services\AnthropicService;
use App\Services\ConversationService;
```

Add these methods to the class:

```php
public function matchList(Request $request): JsonResponse
{
    $convId = intval($request->input('conversation_id') ?: $request->query('conversation_id', 0));
    if (!$convId) return response()->json(['error' => 'conversation_id required'], 400);

    $matcher = app(PropertyMatcherService::class);
    $data = $matcher->getMatchesForConversation($convId);

    return response()->json($data);
}

public function matchDismiss(Request $request): JsonResponse
{
    $convId = intval($request->input('conversation_id') ?: $request->query('conversation_id', 0));
    if (!$convId) return response()->json(['error' => 'conversation_id required'], 400);

    Conversation::where('id', $convId)->update(['match_dismissed' => true, 'match_count' => 0]);
    PropertyMatch::where('conversation_id', $convId)->where('status', 'pending')->update(['status' => 'dismissed']);

    return response()->json(['ok' => true]);
}

public function matchGenerateDraft(Request $request): JsonResponse
{
    $input = $request->json()->all();
    $convId = intval($input['conversation_id'] ?? 0);
    $selectedIds = $input['property_ids'] ?? [];

    if (!$convId || empty($selectedIds)) {
        return response()->json(['error' => 'conversation_id and property_ids required'], 400);
    }

    $conv = Conversation::find($convId);
    if (!$conv) return response()->json(['error' => 'Conversation not found'], 404);

    // Mark selected matches
    PropertyMatch::where('conversation_id', $convId)
        ->whereIn('property_id', $selectedIds)
        ->update(['status' => 'selected']);

    // Load selected properties
    $properties = Property::whereIn('id', $selectedIds)->get();

    // Build thread context for draft generation
    $emailQuery = DB::table('portal_emails')
        ->where('contact_email', $conv->contact_email)
        ->orderByDesc('date')
        ->limit(5);

    if ($conv->property_id) {
        $emailQuery->where('property_id', $conv->property_id);
    } else {
        $emailQuery->whereNull('property_id');
    }

    $emails = $emailQuery->get();

    $threadLines = [];
    foreach ($emails->reverse() as $e) {
        $dir = $e->direction === 'inbound' ? 'KUNDE' : 'MAKLER';
        $threadLines[] = "[{$dir}] {$e->subject}\n" . mb_substr($e->body_text ?? '', 0, 400);
    }

    // Build property descriptions for the AI
    $propDescriptions = [];
    foreach ($properties as $i => $p) {
        $area = $p->living_area ?: $p->total_area;
        $propDescriptions[] = ($i + 1) . ". {$p->title} ({$p->address}, {$p->city}) — "
            . ($area ? $area . 'm², ' : '')
            . ($p->rooms_amount ? $p->rooms_amount . ' Zimmer, ' : '')
            . ($p->purchase_price ? '€' . number_format($p->purchase_price, 0, ',', '.') : 'Preis auf Anfrage');
    }

    // Get broker name
    $brokerId = $conv->property_id ? Property::where('id', $conv->property_id)->value('broker_id') : null;
    $brokerName = $brokerId ? DB::table('users')->where('id', $brokerId)->value('name') : 'SR Homes';

    // AI draft generation
    $systemPrompt = <<<'PROMPT'
Du bist ein Immobilienmakler-Assistent. Schreibe eine professionelle Email an den Kunden.
Der Kunde hat sich ursprünglich für ein anderes Objekt interessiert. Du schlägst ihm jetzt zusätzliche passende Objekte vor.

Regeln:
- Formelle Anrede (Sehr geehrte/r)
- Beziehe dich auf den bisherigen Kontext (Anfrage/Absage)
- Stelle die Objekte als natürliche Empfehlungen vor, nicht als Werbung
- Erwähne dass Exposés beigefügt sind
- Kurz und professionell, max 150 Wörter
- Schließe mit "Mit freundlichen Grüßen" und dem Maklernamen

Antworte als JSON:
{"email_subject": "...", "email_body": "..."}
PROMPT;

    $userMessage = "Thread:\n" . implode("\n---\n", $threadLines)
        . "\n\nKunde: {$conv->stakeholder}\n\nVorzuschlagende Objekte:\n" . implode("\n", $propDescriptions)
        . "\n\nMaklername: {$brokerName}";

    $ai = app(AnthropicService::class);
    $draft = $ai->chatJson($systemPrompt, $userMessage, 800);

    if (!$draft) {
        return response()->json(['error' => 'AI draft generation failed'], 500);
    }

    // Collect expose file IDs for attachments
    $fileIds = [];
    foreach ($properties as $p) {
        if ($p->expose_path) {
            $file = DB::table('property_files')
                ->where('property_id', $p->id)
                ->where('path', $p->expose_path)
                ->first();
            if ($file) {
                $fileIds[] = $file->id;
            }
        }
    }

    // Save draft to conversation
    $convService = app(ConversationService::class);
    $convService->saveDraft(
        $conv,
        $draft['email_body'] ?? '',
        $draft['email_subject'] ?? 'Objektvorschläge',
        $conv->contact_email
    );

    return response()->json([
        'draft_body' => $draft['email_body'] ?? '',
        'draft_subject' => $draft['email_subject'] ?? 'Objektvorschläge',
        'draft_to' => $conv->contact_email,
        'file_ids' => $fileIds,
        'matched_property_ids' => $selectedIds,
    ]);
}
```

- [ ] **Step 2: Add routes to AdminApiController**

In `app/Http/Controllers/Admin/AdminApiController.php`, add these cases to the `match ($action)` block, near the other `conv_*` actions:

```php
'match_list'           => app(ConversationController::class)->matchList($request),
'match_dismiss'        => app(ConversationController::class)->matchDismiss($request),
'match_generate_draft' => app(ConversationController::class)->matchGenerateDraft($request),
```

- [ ] **Step 3: Add activity creation to reply()**

In `ConversationController::reply()`, after the email is sent successfully (after the `$emailService->send(...)` call in the success path), add:

```php
// Create activities on cross-matched properties if any were selected
$selectedMatches = PropertyMatch::where('conversation_id', $conv->id)
    ->where('status', 'selected')
    ->get();

if ($selectedMatches->isNotEmpty()) {
    $originalRefId = $conv->property_id ? Property::where('id', $conv->property_id)->value('ref_id') : 'Unbekannt';
    foreach ($selectedMatches as $match) {
        Activity::create([
            'property_id' => $match->property_id,
            'stakeholder' => $conv->stakeholder,
            'category' => 'expose',
            'activity' => "Cross-Match von {$originalRefId} — Exposé gesendet",
            'activity_date' => now(),
        ]);
        $match->update(['status' => 'sent']);
    }
    // Reset match state on conversation
    $conv->update(['match_count' => 0, 'match_dismissed' => true]);
}
```

Add the Activity import if not present:
```php
use App\Models\Activity;
```

- [ ] **Step 4: Support has_matches filter in list()**

In `ConversationController::list()`, add support for the `has_matches` query param. Find where the query is built and add after other filters:

```php
if ($request->query('has_matches')) {
    $query->where('match_count', '>', 0)->where('match_dismissed', false);
}
```

- [ ] **Step 5: Restart services**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && supervisorctl restart all && systemctl restart php8.3-fpm"
```

- [ ] **Step 6: Commit**

```bash
cd /var/www/srhomes
git add app/Http/Controllers/Admin/ConversationController.php app/Http/Controllers/Admin/AdminApiController.php
git commit -m "feat: add match API endpoints (list, dismiss, generate draft, activity on send)"
```

---

## Task 5: InboxMatchCard.vue

**Files:**
- Create: `resources/js/Components/Admin/inbox/InboxMatchCard.vue`

- [ ] **Step 1: Create the component**

Create `resources/js/Components/Admin/inbox/InboxMatchCard.vue`:

```vue
<script setup>
import { computed } from 'vue'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

const props = defineProps({
  match: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle'])

const area = computed(() => props.match.area ? props.match.area + ' m²' : null)
const rooms = computed(() => props.match.rooms ? props.match.rooms + ' Zi.' : null)
const price = computed(() => {
  if (!props.match.price) return 'Preis auf Anfrage'
  return '€ ' + Number(props.match.price).toLocaleString('de-AT')
})

const scoreBg = computed(() => {
  if (props.match.score >= 80) return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (props.match.score >= 60) return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-muted text-muted-foreground border-border'
})
</script>

<template>
  <Card
    class="relative cursor-pointer transition-all duration-150 hover:border-violet-300"
    :class="selected
      ? 'border-violet-500 shadow-[0_0_0_1px_hsl(263_70%_58%),0_4px_16px_hsl(263_70%_58%/0.1)]'
      : 'border-border'"
    @click="emit('toggle', match.property_id)"
  >
    <div class="p-4 flex gap-4">
      <!-- Image -->
      <div class="w-20 h-16 rounded-md bg-muted flex-shrink-0 overflow-hidden flex items-center justify-center">
        <img v-if="match.image_url" :src="match.image_url" class="w-full h-full object-cover" />
        <span v-else class="text-2xl text-muted-foreground/50">🏠</span>
      </div>

      <!-- Info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="text-sm font-semibold truncate">{{ match.title }}</span>
          <Badge variant="outline" :class="scoreBg" class="text-[10px] px-1.5 py-0 font-bold flex-shrink-0">
            {{ match.score }}%
          </Badge>
        </div>
        <p class="text-xs text-muted-foreground truncate">{{ match.address }} — {{ price }}</p>
        <div class="flex gap-1.5 mt-2 flex-wrap">
          <Badge v-if="area" variant="secondary" class="text-[10px] px-1.5 py-0">{{ area }}</Badge>
          <Badge v-if="rooms" variant="secondary" class="text-[10px] px-1.5 py-0">{{ rooms }}</Badge>
          <Badge v-if="match.object_type" variant="secondary" class="text-[10px] px-1.5 py-0">{{ match.object_type }}</Badge>
          <Badge v-if="match.has_expose" variant="secondary" class="text-[10px] px-1.5 py-0 text-violet-600">Exposé</Badge>
        </div>
        <p v-if="match.match_reason" class="text-[11px] text-violet-600 mt-1.5">{{ match.match_reason }}</p>
      </div>

      <!-- Checkbox -->
      <div class="flex-shrink-0 flex items-start pt-0.5">
        <div
          class="w-5 h-5 rounded border-2 flex items-center justify-center transition-all"
          :class="selected
            ? 'bg-gradient-to-br from-violet-500 to-cyan-500 border-violet-500'
            : 'border-muted-foreground/30'"
        >
          <svg v-if="selected" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
      </div>
    </div>
  </Card>
</template>
```

- [ ] **Step 2: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/inbox/InboxMatchCard.vue
git commit -m "feat: add InboxMatchCard component for property match selection"
```

---

## Task 6: InboxMatchView.vue

**Files:**
- Create: `resources/js/Components/Admin/inbox/InboxMatchView.vue`

- [ ] **Step 1: Create the component**

Create `resources/js/Components/Admin/inbox/InboxMatchView.vue`:

```vue
<script setup>
import { ref, computed, onMounted, inject } from 'vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import InboxMatchCard from './InboxMatchCard.vue'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['dismiss', 'generateDraft'])

const API = inject('API')
const loading = ref(true)
const generating = ref(false)
const criteria = ref(null)
const matches = ref([])
const selectedIds = ref(new Set())

onMounted(async () => {
  await loadMatches()
})

async function loadMatches() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=match_list&conversation_id=' + props.item.id)
    const d = await r.json()
    criteria.value = d.criteria
    matches.value = d.matches || []
    // Pre-select high-score matches
    matches.value.forEach(m => {
      if (m.score >= 70) selectedIds.value.add(m.property_id)
    })
  } catch (e) {
    console.error('Failed to load matches', e)
  } finally {
    loading.value = false
  }
}

function toggleSelection(propertyId) {
  if (selectedIds.value.has(propertyId)) {
    selectedIds.value.delete(propertyId)
  } else {
    selectedIds.value.add(propertyId)
  }
  // Force reactivity
  selectedIds.value = new Set(selectedIds.value)
}

const selectedCount = computed(() => selectedIds.value.size)

const criteriaPills = computed(() => {
  if (!criteria.value) return []
  const pills = []
  if (criteria.value.object_types?.length) pills.push(...criteria.value.object_types)
  if (criteria.value.min_area) pills.push('ab ' + criteria.value.min_area + ' m²')
  if (criteria.value.max_price) pills.push('bis € ' + Number(criteria.value.max_price).toLocaleString('de-AT'))
  if (criteria.value.locations?.length) pills.push(...criteria.value.locations)
  if (criteria.value.features?.length) pills.push(...criteria.value.features)
  return pills
})

async function generateDraft() {
  if (selectedCount.value === 0) return
  generating.value = true
  try {
    const r = await fetch(API.value + '&action=match_generate_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        conversation_id: props.item.id,
        property_ids: [...selectedIds.value],
      }),
    })
    const d = await r.json()
    if (d.error) {
      console.error('Draft generation failed:', d.error)
      return
    }
    emit('generateDraft', {
      draft_body: d.draft_body,
      draft_subject: d.draft_subject,
      draft_to: d.draft_to,
      file_ids: d.file_ids || [],
    })
  } catch (e) {
    console.error('Failed to generate draft', e)
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="flex flex-col h-full bg-background">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-cyan-500 flex items-center justify-center">
          <span class="text-white text-sm font-bold">✦</span>
        </div>
        <div>
          <h2 class="text-base font-semibold">Property Matching</h2>
          <p class="text-xs text-muted-foreground">{{ item.stakeholder || item.from_name }}</p>
        </div>
      </div>
      <Button variant="ghost" size="sm" @click="emit('dismiss')">
        Überspringen
      </Button>
    </div>

    <!-- Criteria pills -->
    <div v-if="criteriaPills.length" class="px-6 py-3 border-b flex items-center gap-2 flex-wrap">
      <span class="text-xs text-muted-foreground font-medium">Suchkriterien:</span>
      <Badge v-for="pill in criteriaPills" :key="pill" variant="outline" class="text-xs">
        {{ pill }}
      </Badge>
    </div>

    <!-- Match cards -->
    <ScrollArea class="flex-1">
      <div class="p-6 space-y-3">
        <div v-if="loading" class="flex items-center justify-center py-20">
          <div class="animate-spin w-6 h-6 border-2 border-violet-500 border-t-transparent rounded-full" />
        </div>

        <template v-else-if="matches.length">
          <InboxMatchCard
            v-for="m in matches"
            :key="m.property_id"
            :match="m"
            :selected="selectedIds.has(m.property_id)"
            @toggle="toggleSelection"
          />
        </template>

        <div v-else class="text-center py-20 text-muted-foreground text-sm">
          Keine passenden Objekte gefunden.
        </div>
      </div>
    </ScrollArea>

    <!-- Bottom bar -->
    <div class="px-6 py-4 border-t flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground">
          <strong class="text-violet-600">{{ selectedCount }}</strong> ausgewählt
        </span>
      </div>
      <Button
        :disabled="selectedCount === 0 || generating"
        class="bg-gradient-to-r from-violet-500 to-cyan-500 text-white hover:opacity-90 disabled:opacity-50"
        @click="generateDraft"
      >
        <span class="mr-1.5">✦</span>
        {{ generating ? 'Generiere...' : 'Entwurf generieren' }}
      </Button>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/inbox/InboxMatchView.vue
git commit -m "feat: add InboxMatchView full-screen property matching component"
```

---

## Task 7: InboxConversationItem.vue — Animated Border + Badge

**Files:**
- Modify: `resources/js/Components/Admin/inbox/InboxConversationItem.vue`

- [ ] **Step 1: Add match detection computed**

In the `<script setup>` section, add:

```js
const hasMatches = computed(() => props.item.match_count > 0 && !props.item.match_dismissed)
```

Add Badge import if not present:
```js
import { Badge } from '@/components/ui/badge'
```

- [ ] **Step 2: Wrap with animated border**

The component's root template needs wrapping. Change the outermost element from:

```html
<div class="..." @click="emit('click', item)">
```

To this structure:

```html
<div
  class="relative rounded-lg"
  :class="hasMatches ? 'p-[2px] ai-match-border' : ''"
>
  <div
    class="... bg-background"
    :class="hasMatches ? 'rounded-[6px]' : ''"
    @click="emit('click', item)"
  >
    <!-- all existing content stays here -->

    <!-- Add match badge near other badges in the component -->
    <Badge
      v-if="hasMatches"
      class="bg-gradient-to-r from-violet-500 to-cyan-500 text-white text-[10px] px-1.5 py-0 border-0"
    >
      ✦ {{ item.match_count }} {{ item.match_count === 1 ? 'Match' : 'Matches' }}
    </Badge>
  </div>
</div>
```

**Note:** The inner div needs `bg-background` so the gradient border shows through the 2px padding. The inner div inherits the original classes plus `rounded-[6px]` when matches exist.

- [ ] **Step 3: Add CSS animation**

Add at the end of the file:

```css
<style scoped>
.ai-match-border {
  background: linear-gradient(270deg, hsl(263 70% 58%), hsl(187 72% 53%), hsl(292 84% 60%), hsl(263 70% 58%));
  background-size: 600% 600%;
  animation: aiGlow 4s ease infinite;
}

@keyframes aiGlow {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
</style>
```

- [ ] **Step 4: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/inbox/InboxConversationItem.vue
git commit -m "feat: add animated AI gradient border and match badge to conversation items"
```

---

## Task 8: InboxTab.vue — Match Mode + Matches Tab

**Files:**
- Modify: `resources/js/Components/Admin/InboxTab.vue`

This is the largest frontend change. All additions are surgical — no existing code is removed.

- [ ] **Step 1: Add import**

In the `<script setup>` section, add the import near other inbox component imports:

```js
import InboxMatchView from './inbox/InboxMatchView.vue'
```

- [ ] **Step 2: Add state variables**

Near the other `ref()` declarations (around line ~490-510), add:

```js
const matchMode = ref(false)
const matchItems = ref([])
```

- [ ] **Step 3: Update loadUnanswered to track match items**

In the `loadUnanswered` function, after the data is assigned to the items array, add:

```js
// Track conversations with active matches
matchItems.value = (d.data || d).filter(c => c.match_count > 0 && !c.match_dismissed)
```

- [ ] **Step 4: Add Matches subtab button**

Find the subtab navigation in the template (the row of pill buttons for "Offen", "Nachfassen", etc.). Add a new button:

```html
<button
  class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
  :class="activeSubtab === 'matches' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
  @click="activeSubtab = 'matches'; loadMatchesTab()"
>
  Matches
  <span
    v-if="matchItems.length"
    class="ml-1 inline-flex items-center justify-center px-1 min-w-[16px] h-4 rounded-full bg-gradient-to-r from-violet-500 to-cyan-500 text-white text-[10px] font-bold"
  >
    {{ matchItems.length }}
  </span>
</button>
```

- [ ] **Step 5: Add loadMatchesTab function**

```js
async function loadMatchesTab() {
  try {
    const r = await fetch(API.value + '&action=conv_list&status=offen&has_matches=1')
    const d = await r.json()
    matchItems.value = (d.data || d).filter(c => c.match_count > 0 && !c.match_dismissed)
  } catch (e) {
    console.error('Failed to load matches', e)
  }
}
```

- [ ] **Step 6: Add match mode on conversation click**

Find where `selectedItem` is set when a conversation is clicked. Add this check at the beginning of the click handler:

```js
// Enter match mode for conversations with active matches
if (item.match_count > 0 && !item.match_dismissed) {
  matchMode.value = true
  selectedItem.value = item
  // Mark as read
  if (!item.is_read) {
    fetch(API.value + '&action=conv_read&id=' + item.id, { method: 'POST' }).catch(() => {})
    item.is_read = true
  }
  return
}
matchMode.value = false
```

- [ ] **Step 7: Add InboxMatchView to template**

Find where `InboxChatView` is rendered in the template (right-side panel). Wrap it with a conditional to show InboxMatchView instead when in match mode:

```html
<InboxMatchView
  v-if="matchMode && selectedItem"
  :item="selectedItem"
  @dismiss="handleMatchDismiss"
  @generate-draft="handleMatchDraft"
/>
<InboxChatView
  v-else-if="selectedItem && !composing"
  :item="selectedItem"
  :mode="activeSubtab"
  :messages="selectedMessages"
  @close="selectedItem = null"
/>
```

- [ ] **Step 8: Add event handlers**

```js
async function handleMatchDismiss() {
  if (!selectedItem.value) return
  await fetch(API.value + '&action=match_dismiss', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ conversation_id: selectedItem.value.id }),
  })
  selectedItem.value.match_count = 0
  selectedItem.value.match_dismissed = true
  matchMode.value = false
  matchItems.value = matchItems.value.filter(m => m.id !== selectedItem.value.id)
}

function handleMatchDraft(draftData) {
  matchMode.value = false
  if (selectedItem.value) {
    selectedItem.value.draft_body = draftData.draft_body
    selectedItem.value.draft_subject = draftData.draft_subject
    selectedItem.value.draft_to = draftData.draft_to
    selectedItem.value.match_count = 0
    // Store file IDs for auto-selecting expose attachments
    if (draftData.file_ids?.length) {
      selectedItem.value._matchFileIds = draftData.file_ids
    }
  }
}
```

- [ ] **Step 9: Handle Matches subtab list rendering**

In the template where conversation lists are rendered per subtab, add a condition for the matches subtab. Find where the conversation list is rendered (likely using `InboxConversationList` or a `v-for` over items), and add:

```html
<!-- When matches subtab is active, show matchItems instead -->
<!-- Use the same list component but with matchItems as the source -->
```

The simplest approach: in the computed or method that returns the displayed items, add:

```js
// If you have a computed like displayedItems:
if (activeSubtab.value === 'matches') return matchItems.value
```

- [ ] **Step 10: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/InboxTab.vue
git commit -m "feat: add match mode, Matches subtab, and match event handlers to InboxTab"
```

---

## Task 9: InboxAiDraft.vue — Match Badge

**Files:**
- Modify: `resources/js/Components/Admin/inbox/InboxAiDraft.vue`
- Modify: `resources/js/Components/Admin/InboxTab.vue`

- [ ] **Step 1: Add matchPropertyCount prop**

In `InboxAiDraft.vue`, add to `defineProps`:

```js
matchPropertyCount: { type: Number, default: 0 },
```

- [ ] **Step 2: Add badge in template**

Near where the "KI-Entwurf" label is rendered, add:

```html
<span
  v-if="matchPropertyCount > 0"
  class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-gradient-to-r from-violet-500/10 to-cyan-500/10 text-violet-700 border border-violet-200"
>
  inkl. {{ matchPropertyCount }} {{ matchPropertyCount === 1 ? 'Objekt' : 'Objekte' }}
</span>
```

- [ ] **Step 3: Pass prop from InboxTab.vue**

In InboxTab.vue, where `InboxAiDraft` is used, add the prop:

```html
:match-property-count="selectedItem?._matchFileIds?.length || 0"
```

- [ ] **Step 4: Auto-select expose files after match draft**

In InboxTab.vue, find where files/attachments are loaded for the draft view. After the files are loaded (or when the draft is shown), check for `_matchFileIds` and auto-select them:

```js
// After files load for the selected conversation:
if (selectedItem.value?._matchFileIds?.length && files.value?.length) {
  const matchIds = new Set(selectedItem.value._matchFileIds)
  files.value.forEach(f => {
    if (matchIds.has(f.id)) {
      // Toggle file selection on
      if (!selectedFileIds.value.includes(f.id)) {
        selectedFileIds.value.push(f.id)
      }
    }
  })
  delete selectedItem.value._matchFileIds
}
```

**Note:** The exact integration depends on how `selectedFileIds` and `files` are managed in InboxTab.vue. Look for the existing file attachment loading logic and add the auto-selection there.

- [ ] **Step 5: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/inbox/InboxAiDraft.vue resources/js/Components/Admin/InboxTab.vue
git commit -m "feat: add match badge to AI draft and auto-select expose attachments"
```

---

## Task 10: End-to-End Verification

- [ ] **Step 1: Build frontend**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && npm run build"
```

Expected: Build succeeds with no errors.

- [ ] **Step 2: Restart all services**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && supervisorctl restart all && systemctl restart php8.3-fpm"
```

- [ ] **Step 3: Create test match data**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && php artisan tinker --execute=\"
\$conv = \App\Models\Conversation::where('stakeholder', 'LIKE', '%Obernitz%')->first();
echo 'Conv: ' . \$conv?->id . ' / ' . \$conv?->stakeholder . PHP_EOL;

\$prop = \App\Models\Property::where('broker_id', 1)->whereIn('realty_status', ['auftrag', 'inserat'])->first();
echo 'Prop: ' . \$prop?->id . ' / ' . \$prop?->title . PHP_EOL;

if (\$conv && \$prop) {
    \App\Models\PropertyMatch::updateOrCreate(
        ['conversation_id' => \$conv->id, 'property_id' => \$prop->id],
        [
            'score' => 92,
            'match_reason' => \$prop->object_type . ', ' . \$prop->city,
            'cross_match_intent' => 'high',
            'status' => 'pending',
            'criteria_json' => ['object_types' => ['Einfamilienhaus'], 'min_area' => 100, 'locations' => ['Salzburg']],
        ]
    );
    \$conv->update(['match_count' => 1, 'match_dismissed' => false]);
    echo 'Match created!';
}
\""
```

- [ ] **Step 4: Verify in browser**

1. Open `https://kundenportal.sr-homes.at` and log in
2. Go to Anfragen tab
3. Look for the test conversation — should have animated gradient border and match badge
4. Click it — InboxMatchView should appear with the property card
5. Select the property, click "Entwurf generieren"
6. Should transition to chat view with AI-generated draft

- [ ] **Step 5: Test dismiss flow**

1. Click a conversation with matches
2. Click "Überspringen" in match view
3. Verify gradient border disappears, match is dismissed
4. Refreshing page should not show the match again

- [ ] **Step 6: Final commit**

```bash
cd /var/www/srhomes
git add -A && git diff --cached --stat
# Review what's staged, then commit any remaining changes
git commit -m "feat: AI Cross-Match system complete"
```
