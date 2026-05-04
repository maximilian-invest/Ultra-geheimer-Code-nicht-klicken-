<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Schlanker Client fuer die Immoji Public REST API (v1).
 *
 * Eigenstaendig vom alten GraphQL-Pfad in ImmojiUploadService — laeuft mit dem
 * imk_live_… Bearer-Key aus .env (IMMOJI_API_KEY) und ist auf Endpoints
 * begrenzt, die die REST-API laut Spec stabil persistiert:
 *   - POST /v1/realties/bulk        (status, title, address, manager, energy)
 *   - GET  /v1/realties             (drift detection)
 *   - GET  /v1/realties/{id}        (recovery)
 *   - POST /v1/realties/{id}/media  (media upload)
 *
 * Reserved-Felder (costs, building, areas) und nicht-existente Felder
 * (descriptions, parking, heating, …) bleiben weiterhin am GraphQL-Pfad.
 */
class ImmojiRestClient
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.immoji.api_key');
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.immoji.base_url', 'https://api.immoji.org'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Bulk-Upsert. Items mit `id` sind Updates, ohne `id` Creates. Maximal 100
     * pro Call. Per-Item-Outcomes im Body unter `items[].status`.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{items: array<int, array<string, mixed>>}
     */
    public function bulkUpsertRealties(array $items): array
    {
        $this->guardConfigured();

        if (count($items) > 100) {
            throw new \InvalidArgumentException('bulkUpsertRealties accepts at most 100 items per call.');
        }

        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($this->baseUrl . '/v1/realties/bulk', ['items' => array_values($items)]);

        if (!$resp->successful()) {
            $body = $resp->body();
            throw new \RuntimeException("Immoji bulk upsert HTTP {$resp->status()}: " . substr($body, 0, 500));
        }

        return $resp->json() ?? ['items' => []];
    }

    /**
     * Mappt unseren Portal-Status auf das Immoji-status-Enum.
     * Portal: aktiv | inaktiv | verkauft (enum)
     * Immoji: active | inactive | sold | rented | archived
     *
     * Wenn marketing_type = miete/pacht UND realty_status = verkauft → rented.
     * Sonst verkauft → sold.
     */
    public static function mapPortalStatus(?string $realtyStatus, ?string $marketingType): ?string
    {
        $rs = strtolower(trim((string) $realtyStatus));
        $mt = strtolower(trim((string) $marketingType));

        return match ($rs) {
            'aktiv'    => 'active',
            'inaktiv'  => 'inactive',
            'verkauft' => in_array($mt, ['miete', 'pacht'], true) ? 'rented' : 'sold',
            default    => null,
        };
    }

    /**
     * Sammelt alle Properties mit openimmo_id, baut Bulk-Items mit gemappten
     * Status, und schickt sie in 100er-Chunks an die REST-API.
     *
     * @param array<int, int>|null $propertyIds Wenn null: alle mit openimmo_id.
     * @return array{
     *   total: int,
     *   succeeded: int,
     *   failed: int,
     *   skipped: int,
     *   chunks: int,
     *   errors: array<int, array{property_id:int, immoji_id:string, status:int, message:string}>
     * }
     */
    public function syncStatusForProperties(?array $propertyIds = null, bool $dryRun = false): array
    {
        $this->guardConfigured();

        $query = DB::table('properties')
            ->whereNotNull('openimmo_id')
            ->where('openimmo_id', '<>', '')
            ->select('id', 'ref_id', 'openimmo_id', 'realty_status', 'marketing_type');

        if (is_array($propertyIds) && !empty($propertyIds)) {
            $query->whereIn('id', $propertyIds);
        }

        $rows = $query->get();
        $total = $rows->count();

        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $chunks = 0;

        foreach ($rows->chunk(100) as $chunk) {
            $items = [];
            $idIndex = []; // index → ['property_id', 'immoji_id']

            foreach ($chunk as $row) {
                $immojiStatus = self::mapPortalStatus($row->realty_status, $row->marketing_type);
                if ($immojiStatus === null) {
                    $skipped++;
                    continue;
                }
                $idx = count($items);
                $items[] = [
                    'id'     => $row->openimmo_id,
                    'status' => $immojiStatus,
                ];
                $idIndex[$idx] = [
                    'property_id' => (int) $row->id,
                    'immoji_id'   => (string) $row->openimmo_id,
                    'ref_id'      => (string) $row->ref_id,
                ];
            }

            if (empty($items)) {
                continue;
            }

            if ($dryRun) {
                $succeeded += count($items);
                $chunks++;
                continue;
            }

            $chunks++;

            try {
                $result = $this->bulkUpsertRealties($items);
            } catch (\Throwable $e) {
                Log::warning('[Immoji-REST] Bulk-Status-Chunk failed', ['err' => $e->getMessage()]);
                $failed += count($items);
                foreach ($idIndex as $meta) {
                    $errors[] = $meta + ['status' => 0, 'message' => $e->getMessage()];
                }
                continue;
            }

            foreach (($result['items'] ?? []) as $i => $itemResult) {
                $meta = $idIndex[$itemResult['index'] ?? $i] ?? null;
                if (!$meta) continue;

                $status = (int) ($itemResult['status'] ?? 0);
                if ($status >= 200 && $status < 300) {
                    $succeeded++;
                } else {
                    $failed++;
                    $errors[] = $meta + [
                        'status'  => $status,
                        'message' => $itemResult['error']['message'] ?? 'unknown',
                    ];
                }
            }
        }

        return [
            'total'     => $total,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'chunks'    => $chunks,
            'errors'    => $errors,
        ];
    }

    private function guardConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('IMMOJI_API_KEY ist nicht in .env gesetzt.');
        }
    }
}
