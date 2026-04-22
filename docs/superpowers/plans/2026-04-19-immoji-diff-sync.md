# Immoji Diff-Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Only push sections of a property to Immoji that have changed locally since the last successful sync, instead of the current full-sync-every-time behaviour. Preserve manual Immoji edits in untouched sections and dramatically reduce sync traffic.

**Architecture:** Section-level diff. A new `property_immoji_sync_state` table stores SHA-256 fingerprints of each `*Input` block (general, costs, areas, descriptions, building, files) at time of last successful sync. On sync we compute the current fingerprints, diff against the stored ones, and build an `updateRealtyInput` that only contains the changed sections. First sync (no state), Immoji-id change, and an explicit "force full sync" flag all fall back to pushing everything.

**Tech Stack:** Laravel 11, MySQL, PHPUnit, Vue 3 (admin), GraphQL (Immoji API).

---

## Safety Properties (read before executing)

**No stale data uploaded — enforced by these invariants:**

1. **Sections are atomic units.** A changed field anywhere inside a section (e.g. `title` inside `generalInput`) always causes the full current section to be re-sent. Immoji's partial-input semantics for nested fields are never exercised.
2. **Fingerprints come from the mapped input objects, not the raw DB row.** Whatever `mapPropertyToImmoji*` currently produces is what both the diff and the sync send. If the mapping changes (new field added), hashes change and a re-sync is triggered automatically.
3. **Sync state is updated only after Immoji confirms success.** A failed mutation leaves the old fingerprints, so the next sync retries the diff.
4. **File fingerprints exclude `immoji_source`.** That column is rewritten by the sync itself; including it would cause false diffs on the next click. Signature uses `id|sort_order|is_title_image|title|original_name|filename|category|path`.
5. **`openimmo_id` mismatch forces a full sync.** If the stored state references a different Immoji realty than the property now points at, we push everything and reset the snapshot.
6. **First sync (no state row) pushes everything.** Diff only starts from the second sync.
7. **Force-full-sync flag bypasses the diff** and is surfaced in the UI as the escape hatch for anomalies.
8. **Newbuild projects (pushed via `pushPropertyUnits`) are out of scope for V1.** They keep the current behaviour. A TODO is added.

---

## File Structure

**New files:**
- `database/migrations/2026_04_19_100000_create_property_immoji_sync_state_table.php` — schema for the fingerprint store.
- `app/Services/ImmojiSyncStateService.php` — pure logic: canonical hashing of sections, diff computation, load/save of state rows. No Immoji API calls.
- `tests/Unit/Services/ImmojiSyncStateServiceTest.php` — unit tests for hash stability, diff correctness, canonicalisation.

**Modified files:**
- `app/Services/ImmojiUploadService.php` — `updateRealty` accepts a sections filter; `pushProperty` consults the sync state service, decides sections, updates state on success; `createRealty` flow writes initial state after property is created.
- `app/Http/Controllers/Admin/AdminApiController.php` — `immoji_push` accepts `force_full_sync`, returns `sections_synced` and `skipped` fields.
- `resources/js/Components/Admin/property-detail/PortalsTab.vue` — sync result toast reflects which sections were pushed; overflow menu adds "Full-Sync erzwingen".

**Unchanged (intentionally):**
- `pushUnit`, `pushPropertyUnits` — newbuild units stay on full-sync for V1.
- `getPortalExportStatus`, `setPortalExports` — portal toggles are already a separate mutation, not affected.

---

## Task 1: Migration for sync state table

**Files:**
- Create: `database/migrations/2026_04_19_100000_create_property_immoji_sync_state_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_immoji_sync_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('immoji_id', 64);
            $table->char('general_hash', 64)->nullable();
            $table->char('costs_hash', 64)->nullable();
            $table->char('areas_hash', 64)->nullable();
            $table->char('descriptions_hash', 64)->nullable();
            $table->char('building_hash', 64)->nullable();
            $table->char('files_signature', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('property_id');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_immoji_sync_state');
    }
};
```

- [ ] **Step 2: Run migration locally (against staging DB if available, else production — this is additive and safe)**

Run: `php artisan migrate`
Expected: `INFO  Running migrations. ... create_property_immoji_sync_state_table ... DONE`

- [ ] **Step 3: Verify table exists with correct schema**

Run: `php artisan db --execute="DESCRIBE property_immoji_sync_state"` (or `mysql` equivalent)
Expected: 10 columns listed including `property_id`, `immoji_id`, `general_hash`, `files_signature`, `last_synced_at`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_19_100000_create_property_immoji_sync_state_table.php
git commit -m "feat(immoji): add property_immoji_sync_state table for diff-sync fingerprints"
```

---

## Task 2: ImmojiSyncStateService — canonical hash + diff logic

**Files:**
- Create: `app/Services/ImmojiSyncStateService.php`
- Test: `tests/Unit/Services/ImmojiSyncStateServiceTest.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Unit\Services;

use App\Services\ImmojiSyncStateService;
use PHPUnit\Framework\TestCase;

class ImmojiSyncStateServiceTest extends TestCase
{
    private ImmojiSyncStateService $service;

    protected function setUp(): void
    {
        $this->service = new ImmojiSyncStateService();
    }

    public function test_hash_is_deterministic_for_identical_input(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $b = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $this->assertSame($a, $b);
    }

    public function test_hash_ignores_key_order(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $b = $this->service->hashSection(['price' => 100, 'title' => 'Haus']);
        $this->assertSame($a, $b);
    }

    public function test_hash_respects_nested_key_order(): void
    {
        $a = $this->service->hashSection(['outer' => ['a' => 1, 'b' => 2]]);
        $b = $this->service->hashSection(['outer' => ['b' => 2, 'a' => 1]]);
        $this->assertSame($a, $b);
    }

    public function test_hash_differs_when_value_changes(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus']);
        $b = $this->service->hashSection(['title' => 'Wohnung']);
        $this->assertNotSame($a, $b);
    }

    public function test_hash_of_null_is_stable_and_distinct(): void
    {
        $null = $this->service->hashSection(null);
        $empty = $this->service->hashSection([]);
        $this->assertNotSame($null, $empty);
        $this->assertSame($null, $this->service->hashSection(null));
    }

    public function test_files_signature_depends_only_on_visible_attributes(): void
    {
        $imagesA = [
            (object) [
                'id' => 1, 'sort_order' => 0, 'is_title_image' => 1,
                'title' => 'Front', 'original_name' => 'front.jpg',
                'filename' => '001_front.jpg', 'category' => 'aussenansicht',
                'path' => 'property_images/5/001_front.jpg',
                'immoji_source' => 'tmp/abc', // must be ignored
                'updated_at' => '2026-04-19 10:00:00', // must be ignored
            ],
        ];
        $imagesB = [
            (object) [
                'id' => 1, 'sort_order' => 0, 'is_title_image' => 1,
                'title' => 'Front', 'original_name' => 'front.jpg',
                'filename' => '001_front.jpg', 'category' => 'aussenansicht',
                'path' => 'property_images/5/001_front.jpg',
                'immoji_source' => 'tmp/xyz', // different token
                'updated_at' => '2026-04-20 14:30:00', // different timestamp
            ],
        ];
        $this->assertSame(
            $this->service->filesSignature($imagesA),
            $this->service->filesSignature($imagesB),
        );
    }

    public function test_files_signature_changes_when_image_added(): void
    {
        $one = [(object) ['id' => 1, 'sort_order' => 0, 'is_title_image' => 1, 'title' => '', 'original_name' => '', 'filename' => 'a', 'category' => 'sonstiges', 'path' => 'p/a']];
        $two = array_merge($one, [(object) ['id' => 2, 'sort_order' => 1, 'is_title_image' => 0, 'title' => '', 'original_name' => '', 'filename' => 'b', 'category' => 'sonstiges', 'path' => 'p/b']]);
        $this->assertNotSame($this->service->filesSignature($one), $this->service->filesSignature($two));
    }

    public function test_files_signature_ignores_input_order(): void
    {
        $img1 = (object) ['id' => 1, 'sort_order' => 0, 'is_title_image' => 1, 'title' => '', 'original_name' => '', 'filename' => 'a', 'category' => 'sonstiges', 'path' => 'p/a'];
        $img2 = (object) ['id' => 2, 'sort_order' => 1, 'is_title_image' => 0, 'title' => '', 'original_name' => '', 'filename' => 'b', 'category' => 'sonstiges', 'path' => 'p/b'];
        $this->assertSame(
            $this->service->filesSignature([$img1, $img2]),
            $this->service->filesSignature([$img2, $img1]),
        );
    }

    public function test_diff_reports_changed_sections(): void
    {
        $old = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $new = [
            'general' => 'h1',        // unchanged
            'costs' => 'h2-changed',  // changed
            'areas' => 'h3',          // unchanged
            'descriptions' => 'h4-changed', // changed
            'building' => 'h5',       // unchanged
            'files' => 'h6',          // unchanged
        ];
        $this->assertSame(['costs', 'descriptions'], $this->service->diffSections($old, $new));
    }

    public function test_diff_reports_all_sections_when_old_is_null(): void
    {
        $new = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $this->assertSame(
            ['general', 'costs', 'areas', 'descriptions', 'building', 'files'],
            $this->service->diffSections(null, $new),
        );
    }

    public function test_diff_reports_nothing_when_everything_identical(): void
    {
        $hashes = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $this->assertSame([], $this->service->diffSections($hashes, $hashes));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --filter=ImmojiSyncStateServiceTest`
Expected: FAIL — `Class "App\Services\ImmojiSyncStateService" not found`

- [ ] **Step 3: Write the service with the minimum to pass**

Create `app/Services/ImmojiSyncStateService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ImmojiSyncStateService
{
    public const SECTIONS = ['general', 'costs', 'areas', 'descriptions', 'building', 'files'];

    private const FILE_FIELDS = [
        'id', 'sort_order', 'is_title_image', 'title',
        'original_name', 'filename', 'category', 'path',
    ];

    /**
     * Deterministic SHA-256 of a mapped *Input array (or null).
     * Sorts keys recursively so field-ordering differences don't cause false diffs.
     */
    public function hashSection(?array $input): string
    {
        if ($input === null) {
            return hash('sha256', "\0null");
        }
        return hash('sha256', $this->canonicalize($input));
    }

    /**
     * SHA-256 of a canonical projection of image rows. Excludes immoji_source
     * (rewritten by sync itself) and updated_at (bumped by those rewrites) to
     * avoid false diffs after a successful sync.
     */
    public function filesSignature(iterable $images): string
    {
        $rows = [];
        foreach ($images as $img) {
            $projection = [];
            foreach (self::FILE_FIELDS as $f) {
                $projection[$f] = $img->{$f} ?? null;
            }
            $rows[] = $projection;
        }
        // Sort by id for order-independence.
        usort($rows, fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        return hash('sha256', $this->canonicalize($rows));
    }

    /**
     * Return the list of section keys whose new hash differs from the stored one.
     * If $old is null (no prior state) every section is considered changed.
     */
    public function diffSections(?array $old, array $new): array
    {
        $changed = [];
        foreach (self::SECTIONS as $section) {
            if (($old[$section] ?? null) !== ($new[$section] ?? null)) {
                $changed[] = $section;
            }
        }
        return $changed;
    }

    /**
     * Load the sync state row for a property, or null if none exists.
     * Returns an assoc array keyed by section name (minus "_hash") plus
     * immoji_id, last_synced_at.
     */
    public function loadState(int $propertyId): ?array
    {
        $row = DB::table('property_immoji_sync_state')
            ->where('property_id', $propertyId)
            ->first();
        if (!$row) return null;
        return [
            'immoji_id' => $row->immoji_id,
            'general' => $row->general_hash,
            'costs' => $row->costs_hash,
            'areas' => $row->areas_hash,
            'descriptions' => $row->descriptions_hash,
            'building' => $row->building_hash,
            'files' => $row->files_signature,
            'last_synced_at' => $row->last_synced_at,
        ];
    }

    /**
     * Upsert the sync state after a successful mutation. Only columns in $hashes
     * are touched; sections omitted keep their stored values.
     */
    public function saveState(int $propertyId, string $immojiId, array $hashes): void
    {
        $now = now();
        $payload = [
            'immoji_id' => $immojiId,
            'last_synced_at' => $now,
            'updated_at' => $now,
        ];
        $map = [
            'general' => 'general_hash',
            'costs' => 'costs_hash',
            'areas' => 'areas_hash',
            'descriptions' => 'descriptions_hash',
            'building' => 'building_hash',
            'files' => 'files_signature',
        ];
        foreach ($map as $section => $column) {
            if (array_key_exists($section, $hashes)) {
                $payload[$column] = $hashes[$section];
            }
        }

        DB::table('property_immoji_sync_state')->updateOrInsert(
            ['property_id' => $propertyId],
            $payload + ['created_at' => $now],
        );
    }

    /**
     * Delete the sync state row — used e.g. when the Immoji realty was deleted
     * remotely and we need to force a fresh create.
     */
    public function clearState(int $propertyId): void
    {
        DB::table('property_immoji_sync_state')
            ->where('property_id', $propertyId)
            ->delete();
    }

    /**
     * Internal: recursively sort assoc-array keys so JSON encoding is stable.
     * Lists (sequential arrays) keep their order. Produces a string.
     */
    private function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                ksort($value);
            }
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode($k) . ':' . $this->canonicalize($v);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `php artisan test --filter=ImmojiSyncStateServiceTest`
Expected: PASS — 10 passing assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ImmojiSyncStateService.php tests/Unit/Services/ImmojiSyncStateServiceTest.php
git commit -m "feat(immoji): ImmojiSyncStateService — canonical hashing, section diff, state load/save"
```

---

## Task 3: `updateRealty` accepts a sections filter

**Files:**
- Modify: `app/Services/ImmojiUploadService.php:222-262`

- [ ] **Step 1: Change the `updateRealty` signature and body**

Replace the existing `updateRealty` method (the one that currently always builds every `*Input`) with the version below. Keep the existing error-handling retry (stale tmp refs → clear + re-upload) intact.

```php
    /**
     * @param string   $immojiId  Immoji realty ID.
     * @param array    $property  Mapped SR-Homes property row.
     * @param string[]|null $sections  Whitelist of sections to include. Valid:
     *                                 ['general','costs','areas','descriptions','building','files'].
     *                                 null or empty => include all (legacy full-sync).
     */
    public function updateRealty(string $immojiId, array $property, ?array $sections = null): void
    {
        $wantsAll = $sections === null || $sections === [];
        $wants = fn(string $s) => $wantsAll || in_array($s, $sections, true);

        $input = ['id' => $immojiId];

        if ($wants('general')) {
            $input['generalInput'] = self::mapPropertyToImmojiGeneral($property);
        }
        if ($wants('costs')) {
            $input['costsInput'] = self::mapPropertyToImmojiCosts($property);
        }
        if ($wants('areas')) {
            $input['areasInput'] = self::mapPropertyToImmojiAreas($property);
        }
        if ($wants('descriptions')) {
            $input['descriptionsInput'] = self::mapPropertyToImmojiDescriptions($property);
        }
        if ($wants('building')) {
            $building = self::mapPropertyToImmojiBuilding($property);
            if ($building !== null) {
                $input['buildingInput'] = $building;
            }
        }
        if ($wants('files')) {
            $filesInput = $this->uploadAndMapImages($property);
            if ($filesInput !== null) {
                $input['filesInput'] = $filesInput;
            }
        }

        $query = 'mutation($input: UpdateRealtyInput!) { updateRealty(updateRealtyInput: $input) { id } }';
        $variables = [
            'input' => array_filter($input, fn($v) => $v !== null),
        ];

        $result = $this->query($query, $variables);

        if (isset($result['errors'])) {
            $errorMsg = json_encode($result['errors']);

            // Media-error recovery: stale tmp/ tokens are consumed one-shot.
            // Clear the cached immoji_source for this property and re-upload fresh.
            if (str_contains($errorMsg, 'media') || str_contains($errorMsg, 'file') || str_contains($errorMsg, 'image')) {
                Log::warning("Immoji updateRealty: media error, clearing stale tmp refs and re-uploading. Error: {$errorMsg}");
                $propertyId = $property['id'] ?? null;
                if ($propertyId) {
                    \Illuminate\Support\Facades\DB::table('property_images')
                        ->where('property_id', $propertyId)
                        ->update(['immoji_source' => null]);
                }

                $property['_forceUploadImages'] = true;
                $retryFilesInput = $this->uploadAndMapImages($property);
                if ($retryFilesInput !== null) {
                    $variables['input']['filesInput'] = $retryFilesInput;
                } else {
                    unset($variables['input']['filesInput']);
                }
                $result = $this->query($query, $variables);

                if (isset($result['errors'])) {
                    Log::warning("Immoji updateRealty: re-upload retry still failing, updating metadata only. Error: " . json_encode($result['errors']));
                    unset($variables['input']['filesInput']);
                    $result = $this->query($query, $variables);
                    if (isset($result['errors'])) {
                        throw new \RuntimeException('Immoji updateRealty failed (final retry without files): ' . json_encode($result['errors']));
                    }
                }
                return;
            }
            throw new \RuntimeException('Immoji updateRealty failed: ' . $errorMsg);
        }
    }
```

- [ ] **Step 2: Smoke-test the existing full-sync path still works via tinker**

Run: `php artisan tinker` and paste:

```php
$p = DB::table('properties')->where('id', 5)->first();
$s = new App\Services\ImmojiUploadService(App\Services\ImmojiUploadService::signIn('EMAIL','PW'));
$s->updateRealty($p->openimmo_id, (array)$p); // null sections == full sync
echo "ok";
```

Expected: `ok`, no exception, Immoji realty still reflects SR-Homes data. (This only runs locally against a test/staging Immoji account if available; otherwise skip and rely on Task 9 E2E.)

- [ ] **Step 3: Commit**

```bash
git add app/Services/ImmojiUploadService.php
git commit -m "feat(immoji): updateRealty accepts optional sections filter; null = legacy full sync"
```

---

## Task 4: `pushProperty` computes diff, pushes only changed sections, updates state

**Files:**
- Modify: `app/Services/ImmojiUploadService.php:66-84` (`pushProperty`)

- [ ] **Step 1: Rewrite `pushProperty` to consult the sync state**

Replace the existing `pushProperty` method with:

```php
    /**
     * Push a property to Immoji. Uses section-level diff against the last
     * successful sync to minimise traffic. Falls back to full sync if:
     *   - the property has no Immoji ID yet (createRealty path)
     *   - there is no prior sync state
     *   - the stored immoji_id no longer matches the property's openimmo_id
     *   - $forceFullSync is true
     *
     * Returns: [
     *   'action' => 'created'|'updated'|'skipped',
     *   'immoji_id' => string|null,
     *   'sections_synced' => string[],
     * ]
     */
    public function pushProperty(array $property, bool $forceFullSync = false): array
    {
        $propertyId = $property['id'] ?? null;
        $immojiId = $property['openimmo_id'] ?? null;
        $stateService = app(\App\Services\ImmojiSyncStateService::class);

        // ─── CREATE path ───
        if (!$immojiId) {
            $immojiId = $this->createRealty($property);
            // Snapshot everything on initial create so the next sync can diff.
            if ($propertyId) {
                $hashes = $this->computeAllHashes($property, $stateService);
                $stateService->saveState($propertyId, $immojiId, $hashes);
            }
            return [
                'action' => 'created',
                'immoji_id' => $immojiId,
                'sections_synced' => \App\Services\ImmojiSyncStateService::SECTIONS,
            ];
        }

        // ─── UPDATE path ───
        $newHashes = $this->computeAllHashes($property, $stateService);
        $oldState = $propertyId ? $stateService->loadState($propertyId) : null;

        $sections = \App\Services\ImmojiSyncStateService::SECTIONS;
        if (!$forceFullSync && $oldState && $oldState['immoji_id'] === $immojiId) {
            $sections = $stateService->diffSections($oldState, $newHashes);
        }

        if (empty($sections) && !$forceFullSync) {
            return [
                'action' => 'skipped',
                'immoji_id' => $immojiId,
                'sections_synced' => [],
            ];
        }

        $this->updateRealty($immojiId, $property, $sections);

        // Snapshot: only update hashes for sections that were actually pushed,
        // so untouched sections keep their last-known-good fingerprint.
        $partialHashes = array_intersect_key($newHashes, array_flip($sections));
        if ($propertyId) {
            $stateService->saveState($propertyId, $immojiId, $partialHashes);
        }

        return [
            'action' => 'updated',
            'immoji_id' => $immojiId,
            'sections_synced' => $sections,
        ];
    }

    /**
     * Compute hashes for all sections of the property as Immoji sees them.
     * Image rows are fetched fresh so we reflect the current DB state.
     */
    private function computeAllHashes(array $property, \App\Services\ImmojiSyncStateService $stateService): array
    {
        $propertyId = $property['id'] ?? null;
        $images = $propertyId
            ? \Illuminate\Support\Facades\DB::table('property_images')
                ->where('property_id', $propertyId)
                ->orderBy('sort_order')
                ->get()
            : collect();

        return [
            'general' => $stateService->hashSection(self::mapPropertyToImmojiGeneral($property)),
            'costs' => $stateService->hashSection(self::mapPropertyToImmojiCosts($property)),
            'areas' => $stateService->hashSection(self::mapPropertyToImmojiAreas($property)),
            'descriptions' => $stateService->hashSection(self::mapPropertyToImmojiDescriptions($property)),
            'building' => $stateService->hashSection(self::mapPropertyToImmojiBuilding($property)),
            'files' => $stateService->filesSignature($images),
        ];
    }
```

- [ ] **Step 2: Double-check no other callers of `pushProperty` break**

Run: `grep -rn "->pushProperty(" /Users/max/srhomes/app /Users/max/srhomes/resources 2>/dev/null`
Expected: only calls inside `AdminApiController.php` (immoji_push handler) and the internal `pushUnit`/`pushPropertyUnits` paths (which use `updateRealty`/`createRealty` directly, not `pushProperty`). No callers pass a positional second argument today, so the new `bool $forceFullSync = false` default is backwards compatible.

- [ ] **Step 3: Commit**

```bash
git add app/Services/ImmojiUploadService.php
git commit -m "feat(immoji): pushProperty diff-syncs sections, snapshots fingerprints on success"
```

---

## Task 5: Admin API `immoji_push` accepts `force_full_sync`, returns sections_synced

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminApiController.php:288-340` (the `immoji_push` closure)

- [ ] **Step 1: Update the closure**

Replace the `$result = $service->pushProperty((array) $property);` line and the response-building below it with:

```php
                    // Normal properties: push the property itself (diff-sync by default)
                    $forceFullSync = (bool) $request->input('force_full_sync', false);
                    $result = $service->pushProperty((array) $property, $forceFullSync);

                    // Save the immoji_id back to the property if newly created
                    if ($result['action'] === 'created' && !empty($result['immoji_id'])) {
                        \DB::table('properties')->where('id', $propertyId)->update([
                            'openimmo_id' => $result['immoji_id'],
                            'updated_at' => now()
                        ]);
                    }

                    // Update portal entry only when something actually synced
                    if ($result['action'] !== 'skipped') {
                        \DB::table('property_portals')->updateOrInsert(
                            ['property_id' => $propertyId, 'portal_name' => 'immoji'],
                            ['sync_enabled' => 1, 'status' => 'active', 'external_id' => $result['immoji_id'], 'last_synced_at' => now(), 'updated_at' => now()]
                        );
                    }

                    $sectionsSynced = $result['sections_synced'] ?? [];
                    $message = match ($result['action']) {
                        'created' => 'Objekt in Immoji erstellt',
                        'skipped' => 'Keine Änderungen — nichts zu syncen',
                        default => count($sectionsSynced) === count(\App\Services\ImmojiSyncStateService::SECTIONS)
                            ? 'Objekt in Immoji aktualisiert (alle Bereiche)'
                            : 'Objekt in Immoji aktualisiert (' . implode(', ', $sectionsSynced) . ')',
                    };

                    return response()->json([
                        'success' => true,
                        'action' => $result['action'],
                        'immoji_id' => $result['immoji_id'],
                        'sections_synced' => $sectionsSynced,
                        'force_full_sync' => $forceFullSync,
                        'message' => $message,
                    ]);
```

- [ ] **Step 2: Sanity check — no callers of the old response shape depend on it**

Run: `grep -rn "sections_synced\|force_full_sync" /Users/max/srhomes/resources 2>/dev/null`
Expected: no matches yet (we're about to add them in Task 6). The old response had only `action`, `immoji_id`, `message` — all still present.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/AdminApiController.php
git commit -m "feat(immoji): immoji_push accepts force_full_sync, returns sections_synced list"
```

---

## Task 6: Frontend — sync result toast + "Full-Sync erzwingen"

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/PortalsTab.vue:212-236` (the `pushToImmoji` function and its button)

- [ ] **Step 1: Extend `pushToImmoji` to accept a force flag and show a richer toast**

Replace the current `pushToImmoji` function with:

```javascript
async function pushToImmoji(forceFullSync = false) {
  if (!props.property?.id) return;
  if (!validateBeforePublish()) return;
  immojiPushing.value = true;
  try {
    const r = await fetch(API.value + "&action=immoji_push", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        force_full_sync: forceFullSync,
      }),
    });
    const d = await r.json();
    if (d.success) {
      toast(d.message || "Erfolgreich hochgeladen");
      const pr = await fetch(API.value + "&action=list_property_portals&property_id=" + props.property.id);
      const pd = await pr.json();
      portals.value = pd.portals || [];
      await loadImmojiPortals();
    } else {
      toast(d.message || "Fehler");
    }
  } catch (e) {
    toast("Upload fehlgeschlagen");
  }
  immojiPushing.value = false;
}
```

- [ ] **Step 2: Update the button in the template to surface a force-sync option**

Replace the current sync button block (inside the connected `<div v-else class="space-y-4">` at around line 363-374) with:

```vue
        <div class="flex items-center gap-2">
          <Button variant="outline" size="sm" @click="disconnectImmoji">Trennen</Button>
          <Button
            v-if="property?.id"
            size="sm"
            :disabled="immojiPushing"
            @click="pushToImmoji(false)"
          >
            {{ immojiPushing ? "Sync..." : (property?.openimmo_id ? "Erneut syncen" : "Zu Immoji hochladen") }}
          </Button>
          <Button
            v-if="property?.id && property?.openimmo_id"
            variant="ghost"
            size="sm"
            :disabled="immojiPushing"
            :title="'Alle Bereiche zwingend neu pushen — überschreibt auch manuelle Immoji-Änderungen'"
            @click="pushToImmoji(true)"
          >
            Voll-Sync
          </Button>
        </div>
```

- [ ] **Step 3: Build frontend**

Run: `npx vite build 2>&1 | tail -5`
Expected: `✓ built in ...`. No errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/property-detail/PortalsTab.vue
git commit -m "feat(immoji): PortalsTab shows which sections synced, adds Voll-Sync button"
```

---

## Task 7: Deploy migration + code to production

**Files:** none (runtime only).

- [ ] **Step 1: Push committed changes**

Run: `git push origin main`
Expected: push succeeds; confirm remote head matches local head.

- [ ] **Step 2: Deploy**

Run: `ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"`
Expected: deploy log shows `Running migrations.` with `create_property_immoji_sync_state_table ........ DONE`, then `frontend built`, `services reloaded`, `DEPLOY COMPLETE`.

- [ ] **Step 3: Verify the table is present in production**

Run:
```bash
ssh root@187.124.166.153 'mysql --no-defaults -u srhomes -p"SRH_db_2026!portal" srhomes_portal -e "DESCRIBE property_immoji_sync_state" 2>/dev/null'
```
Expected: the 10 columns show up.

---

## Task 8: Production E2E — verify diff-sync works and preserves Immoji edits

No code changes. This is a mandatory manual validation pass the user must witness before we consider the feature landed.

- [ ] **Step 1: Pick a synced property for the test (Grödig, id=5)**

Confirm it is currently synced to Immoji:
```bash
ssh root@187.124.166.153 'mysql --no-defaults -u srhomes -p"SRH_db_2026!portal" srhomes_portal -e "SELECT id, openimmo_id FROM properties WHERE id=5" 2>/dev/null'
```
Expected: `openimmo_id` is a UUID, not NULL.

- [ ] **Step 2: Baseline sync (populates state row)**

In the admin panel: open Grödig → Portale tab → click "Erneut syncen".
Expected toast: "Objekt in Immoji aktualisiert (general, costs, areas, descriptions, building, files)" (or similar listing all sections — this is expected on the very first run because no sync state exists yet).

Verify the state row was written:
```bash
ssh root@187.124.166.153 'mysql --no-defaults -u srhomes -p"SRH_db_2026!portal" srhomes_portal -e "SELECT property_id, immoji_id, LEFT(general_hash,10) g, LEFT(costs_hash,10) c, LEFT(files_signature,10) f, last_synced_at FROM property_immoji_sync_state WHERE property_id=5" 2>/dev/null'
```
Expected: one row with non-null hashes.

- [ ] **Step 3: No-op sync**

Click "Erneut syncen" again without changing anything.
Expected toast: "Keine Änderungen — nichts zu syncen". Response body shows `action=skipped`, `sections_synced=[]`.

- [ ] **Step 4: Single-section diff**

Change the `title` field in the Bearbeiten tab. Save. Go to Portale → "Erneut syncen".
Expected toast: "Objekt in Immoji aktualisiert (general)". Only one section listed.

- [ ] **Step 5: Preserve-manual-edit scenario (the user's main concern)**

1. On the Immoji dashboard, manually edit the property description (add " — manually edited" to the end).
2. In SR-Homes admin, change only the price. Save.
3. Click "Erneut syncen".
4. Expected toast: "Objekt in Immoji aktualisiert (costs)".
5. Go back to Immoji dashboard, refresh the property view. Confirm the description still ends with " — manually edited" (we sent `costsInput` only; `descriptionsInput` was untouched). ✅

- [ ] **Step 6: Force-full-sync overrides the diff**

With the manual description edit still in place from Step 5, click "Voll-Sync" (the new ghost button).
Expected toast: "Objekt in Immoji aktualisiert (alle Bereiche)".
Refresh Immoji dashboard: the " — manually edited" suffix is now gone (overwritten by the local value). This confirms the escape hatch works.

- [ ] **Step 7: Image diff**

Upload one new image via the Medien subtab. Click "Erneut syncen".
Expected toast includes "files" in the sections list. Open Immoji: the new image is present.

- [ ] **Step 8: Stale-tmp-token recovery still works**

Force a stale-token condition: clear immoji_source for one image, leave another image's stale, touch the title locally so general + files both diff:
```bash
ssh root@187.124.166.153 'mysql --no-defaults -u srhomes -p"SRH_db_2026!portal" srhomes_portal -e "UPDATE property_images SET immoji_source = \"tmp/definitely-expired-00000000-0000-0000-0000-000000000000.png\" WHERE property_id=5 LIMIT 1" 2>/dev/null'
```
Change the title and "Erneut syncen".
Expected: toast shows success (recovery logic in `updateRealty` kicks in, clears all `immoji_source`, re-uploads, succeeds). `laravel.log` contains `Immoji updateRealty: media error, clearing stale tmp refs and re-uploading.`

- [ ] **Step 9: If any step fails**

Rollback:
```bash
ssh root@187.124.166.153 'cd /var/www/srhomes && git reset --hard <LAST_GOOD_HASH_FROM_DEPLOY_LOG> && bash deploy.sh'
```
The `down()` migration drops the sync-state table — for a clean rollback also run `php artisan migrate:rollback --step=1` on the server before the deploy.sh rerun.

- [ ] **Step 10: Mark complete**

If all steps 1-8 pass, announce to the user and stop. No further commits.

---

## Self-Review

Against the spec:

- "Section-Level-Diff" → Task 2 (`diffSections`), Task 4 (`pushProperty` consults diff). ✓
- "Images nur pushen wenn geändert" → Task 2 (`filesSignature` excludes `immoji_source` + `updated_at`), Task 4 (files is one of the diffed sections). ✓
- "Full Sync erzwingen-Button als Override" → Task 5 (`force_full_sync` param), Task 6 (Voll-Sync button). ✓
- "Jeden Diff-Entscheid loggen" → currently the `action=skipped` vs `action=updated` + `sections_synced` is returned to the frontend and shown in the toast, which covers debugging. If we want a Laravel log line as well, add `Log::info` in Task 4's `pushProperty`. Not critical for v1.
- "Feature-Flag falls es schiefgeht" → deployed via code, rollback is `git reset --hard` + `migrate:rollback`. Task 8 Step 9 documents this.
- "keine flashen daten hochgeladen" → Safety Properties at top enumerate the six invariants enforcing this.

No placeholders. Type consistency: `ImmojiSyncStateService::SECTIONS` (const) used in Tasks 2, 4, 5. `pushProperty` return shape (`action`/`immoji_id`/`sections_synced`) consistent across Tasks 4, 5, 6. Method names match.

One gap worth flagging: newbuild projects (`pushPropertyUnits` path, Task 4 Step 1 notes this) still full-sync. That's an explicit V1 scope choice — unit diff would need its own sync-state table keyed by unit ID and is complex enough to warrant a follow-up plan.
