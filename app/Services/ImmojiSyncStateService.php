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
