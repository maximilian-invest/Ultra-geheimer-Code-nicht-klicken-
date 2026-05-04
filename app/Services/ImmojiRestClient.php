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
     * GET /v1/realties/{id}. Liefert null bei 404 (egal ob ge-loescht oder
     * Cross-Tenant — laut Spec gleicher Body), wirft sonst.
     */
    public function getRealty(string $immojiId): ?array
    {
        $this->guardConfigured();

        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->get($this->baseUrl . '/v1/realties/' . urlencode($immojiId));

        if ($resp->status() === 404) return null;
        if (!$resp->successful()) {
            throw new \RuntimeException("Immoji getRealty HTTP {$resp->status()}: " . substr($resp->body(), 0, 300));
        }
        return $resp->json();
    }

    /**
     * Sucht ein Realty per Free-Text q (durchsucht title/subtitle/objectNumber/
     * city/street/postalCode). Gibt das ERSTE exakt-objectNumber-Match zurueck,
     * sonst null. Pragmatisch: q ist kein exakter Index, also filtern wir
     * client-seitig auf objectNumber == refId.
     */
    public function findRealtyByObjectNumber(string $refId): ?array
    {
        $this->guardConfigured();
        if ($refId === '') return null;

        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->get($this->baseUrl . '/v1/realties', ['q' => $refId, 'limit' => 20]);

        if (!$resp->successful()) {
            throw new \RuntimeException("Immoji search HTTP {$resp->status()}: " . substr($resp->body(), 0, 300));
        }
        $items = $resp->json('items') ?? [];
        $needle = mb_strtolower($refId);
        foreach ($items as $r) {
            if (mb_strtolower((string) ($r['objectNumber'] ?? '')) === $needle) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Listet alle Realties paginiert (max page=200). Generator-style: gibt
     * alle Items als flaches Array zurueck. Bei sehr grossen Tenants koennte
     * man auf Generator umstellen — bei 518 Items ist Memory unkritisch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllRealties(): array
    {
        $this->guardConfigured();
        $all = [];
        $offset = 0;
        $limit = 200;
        do {
            $resp = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(60)
                ->get($this->baseUrl . '/v1/realties', ['limit' => $limit, 'offset' => $offset]);
            if (!$resp->successful()) {
                throw new \RuntimeException("Immoji list HTTP {$resp->status()}: " . substr($resp->body(), 0, 300));
            }
            $items = $resp->json('items') ?? [];
            $total = (int) ($resp->json('total') ?? 0);
            foreach ($items as $it) $all[] = $it;
            $offset += count($items);
            if (empty($items)) break;
        } while ($offset < $total);
        return $all;
    }

    /**
     * PATCH /v1/realties/{id} — nur Felder die laut Spec persistiert werden
     * (title/marketingType/status/subtitle/objectNumber/address/realtyManagerId).
     * Aufrufer ist verantwortlich nur sinnvolle Felder mitzugeben.
     */
    public function patchRealty(string $immojiId, array $fields): array
    {
        $this->guardConfigured();

        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->patch($this->baseUrl . '/v1/realties/' . urlencode($immojiId), $fields);

        if (!$resp->successful()) {
            throw new \RuntimeException("Immoji patchRealty HTTP {$resp->status()}: " . substr($resp->body(), 0, 300));
        }
        return $resp->json() ?? [];
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

    /**
     * Scannt den Drift zwischen unserer DB und Immoji.
     *
     * Findet drei Klassen:
     *   stale_uuid     → unsere openimmo_id zeigt auf 404 drueben
     *   ref_mismatch   → drueben existiert, aber objectNumber != ref_id
     *   orphan         → drueben existiert, aber bei uns kein Property mit
     *                    dieser openimmo_id (objectNumber dient als Hinweis)
     *
     * Zusaetzlich versucht stale_uuid per q=ref_id aufzuloesen — wenn drueben
     * ein Realty mit demselben objectNumber existiert, wird die neue UUID
     * im Eintrag mitgegeben (resolved_to).
     *
     * @return array{stale_uuid: array, ref_mismatch: array, orphan: array, summary: array}
     */
    public function runDriftScan(): array
    {
        $this->guardConfigured();

        $remote = $this->listAllRealties();
        $remoteById = [];
        $remoteByObjectNumber = [];
        foreach ($remote as $r) {
            $id = (string) ($r['id'] ?? '');
            $obj = (string) ($r['objectNumber'] ?? '');
            if ($id !== '') $remoteById[$id] = $r;
            if ($obj !== '') $remoteByObjectNumber[mb_strtolower($obj)][] = $r;
        }

        $local = DB::table('properties')
            ->select('id', 'ref_id', 'openimmo_id', 'realty_status', 'project_name')
            ->get();

        $localByImmojiId = [];
        foreach ($local as $p) {
            if (!empty($p->openimmo_id)) {
                $localByImmojiId[(string) $p->openimmo_id] = $p;
            }
        }

        // Auch property_units.immoji_id einbeziehen — Neubauprojekt-Tops
        // landen dort, nicht auf properties.openimmo_id. Sonst werden sie
        // alle faelschlich als Orphans gemeldet.
        $unitImmojiIds = DB::table('property_units')
            ->whereNotNull('immoji_id')
            ->where('immoji_id', '<>', '')
            ->pluck('immoji_id')
            ->all();
        foreach ($unitImmojiIds as $uid) {
            $localByImmojiId[(string) $uid] = (object) ['_unit' => true];
        }

        $stale = [];
        $mismatch = [];

        foreach ($local as $p) {
            $immojiId = (string) ($p->openimmo_id ?? '');
            if ($immojiId === '') continue;

            if (!isset($remoteById[$immojiId])) {
                // Stale — drueben weg. Versuch Auto-Resolve via ref_id.
                $resolved = null;
                if (!empty($p->ref_id) && isset($remoteByObjectNumber[mb_strtolower((string) $p->ref_id)])) {
                    $resolved = $remoteByObjectNumber[mb_strtolower((string) $p->ref_id)][0];
                }
                $stale[] = [
                    'property_id'   => (int) $p->id,
                    'ref_id'        => (string) $p->ref_id,
                    'our_uuid'      => $immojiId,
                    'resolved_to'   => $resolved['id'] ?? null,
                    'resolved_obj'  => $resolved['objectNumber'] ?? null,
                ];
                continue;
            }

            // Beide Seiten existieren — pruefe objectNumber-Match
            $remoteObj = (string) ($remoteById[$immojiId]['objectNumber'] ?? '');
            if ($p->ref_id && $remoteObj && mb_strtolower($remoteObj) !== mb_strtolower((string) $p->ref_id)) {
                $mismatch[] = [
                    'property_id' => (int) $p->id,
                    'immoji_id'   => $immojiId,
                    'ours'        => (string) $p->ref_id,
                    'theirs'      => $remoteObj,
                ];
            }
        }

        $orphans = [];
        foreach ($remote as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '') continue;
            if (!isset($localByImmojiId[$id])) {
                $orphans[] = [
                    'immoji_id'    => $id,
                    'objectNumber' => (string) ($r['objectNumber'] ?? ''),
                    'title'        => (string) ($r['title'] ?? ''),
                    'status'       => (string) ($r['status'] ?? ''),
                ];
            }
        }

        return [
            'stale_uuid'   => $stale,
            'ref_mismatch' => $mismatch,
            'orphan'       => $orphans,
            'summary'      => [
                'remote_count'    => count($remote),
                'local_with_id'   => count($localByImmojiId),
                'stale_uuid'      => count($stale),
                'ref_mismatch'    => count($mismatch),
                'orphan'          => count($orphans),
            ],
        ];
    }

    private function guardConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('IMMOJI_API_KEY ist nicht in .env gesetzt.');
        }
    }
}
