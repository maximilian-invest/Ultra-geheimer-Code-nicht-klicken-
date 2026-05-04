<?php

namespace App\Console\Commands;

use App\Services\ImmojiRestClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Zeigt und repariert den Drift zwischen unserer DB und Immoji.
 *
 *   php artisan immoji:drift                         (nur anzeigen)
 *   php artisan immoji:drift --auto-fix --dry-run    (zeigen was repariert wuerde)
 *   php artisan immoji:drift --auto-fix              (reparieren)
 *
 * Was ist sicher reparierbar?
 *   stale_uuid     → wenn objectNumber drueben gefunden: openimmo_id auf neue
 *                    UUID umsetzen. Wenn nicht: openimmo_id auf NULL setzen
 *                    (naechster Sync legt frisch an).
 *   ref_mismatch   → objectNumber drueben per PATCH /v1/realties/{id} auf
 *                    unseren ref_id setzen.
 *   orphan         → NICHT auto-repariert — kann ein gewolltes drueben-Listing
 *                    sein. Nur Anzeige; Loeschung muss der User entscheiden.
 */
class ImmojiDrift extends Command
{
    protected $signature = 'immoji:drift
        {--auto-fix : Stale UUIDs umsetzen + ref_mismatch patchen}
        {--dry-run : Bei --auto-fix nur anzeigen was passieren wuerde}';

    protected $description = 'Drift-Report Portal vs. Immoji + optionale Auto-Reparatur.';

    public function handle(ImmojiRestClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('IMMOJI_API_KEY ist nicht in .env gesetzt.');
            return self::FAILURE;
        }

        $this->line('Lade Realties von Immoji ...');
        $report = $client->runDriftScan();

        $this->printSummary($report['summary']);
        $this->printStale($report['stale_uuid']);
        $this->printMismatch($report['ref_mismatch']);
        $this->printOrphans($report['orphan']);

        if (!$this->option('auto-fix')) {
            $this->line('');
            $this->comment('Nur Report. Mit --auto-fix werden stale_uuid und ref_mismatch repariert.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $this->line('');
        $this->info($dry ? '[DRY-RUN] Auto-Fix Vorschau:' : 'Starte Auto-Fix ...');

        $fixed = $this->autoFixStale($client, $report['stale_uuid'], $dry);
        $patched = $this->autoFixMismatch($client, $report['ref_mismatch'], $dry);

        $this->line('');
        $this->info(sprintf(
            ($dry ? '[DRY-RUN] ' : '') . 'Stale gefixt: %d (resolved %d, genullt %d) · Mismatch gepatcht: %d',
            $fixed['count'], $fixed['resolved'], $fixed['nulled'], $patched
        ));
        return self::SUCCESS;
    }

    private function printSummary(array $s): void
    {
        $this->line('');
        $this->info(sprintf(
            'Immoji: %d Realties · DB mit openimmo_id: %d',
            $s['remote_count'], $s['local_with_id']
        ));
        $this->line(sprintf(
            'Drift → stale_uuid: %d · ref_mismatch: %d · orphan: %d',
            $s['stale_uuid'], $s['ref_mismatch'], $s['orphan']
        ));
    }

    private function printStale(array $rows): void
    {
        if (empty($rows)) return;
        $this->line('');
        $this->warn('--- STALE UUID (unsere openimmo_id zeigt auf 404 drueben) ---');
        $this->table(
            ['Prop-ID', 'ref_id', 'unsere UUID', 'aufgeloest auf', 'objectNumber drueben'],
            array_map(fn ($r) => [
                $r['property_id'],
                $r['ref_id'],
                substr($r['our_uuid'], 0, 12) . '…',
                $r['resolved_to'] ? substr($r['resolved_to'], 0, 12) . '…' : '— (kein Match)',
                $r['resolved_obj'] ?? '',
            ], $rows)
        );
    }

    private function printMismatch(array $rows): void
    {
        if (empty($rows)) return;
        $this->line('');
        $this->warn('--- REF_ID MISMATCH (drueben vs. unser ref_id) ---');
        $this->table(
            ['Prop-ID', 'unsere ref_id', 'objectNumber drueben', 'UUID'],
            array_map(fn ($r) => [
                $r['property_id'],
                $r['ours'],
                $r['theirs'],
                substr($r['immoji_id'], 0, 12) . '…',
            ], $rows)
        );
    }

    private function printOrphans(array $rows): void
    {
        if (empty($rows)) return;
        $this->line('');
        $this->warn('--- ORPHAN (drueben existiert, bei uns nicht) ---');
        $this->table(
            ['UUID', 'objectNumber', 'Titel', 'Status'],
            array_map(fn ($r) => [
                substr($r['immoji_id'], 0, 12) . '…',
                $r['objectNumber'] ?: '—',
                mb_substr($r['title'], 0, 50),
                $r['status'],
            ], $rows)
        );
        $this->comment('Orphans werden NICHT auto-gefixt — entscheide pro Eintrag (drueben loeschen oder bei uns zuordnen).');
    }

    private function autoFixStale(ImmojiRestClient $client, array $rows, bool $dry): array
    {
        $resolved = 0;
        $nulled = 0;

        foreach ($rows as $r) {
            $propertyId = $r['property_id'];
            $newUuid = $r['resolved_to'] ?? null;

            if ($newUuid) {
                $this->line(sprintf(
                    '  Property #%d (%s): %s → %s',
                    $propertyId, $r['ref_id'], substr($r['our_uuid'], 0, 12) . '…', substr($newUuid, 0, 12) . '…'
                ));
                if (!$dry) {
                    DB::table('properties')->where('id', $propertyId)->update([
                        'openimmo_id' => $newUuid,
                        'updated_at'  => now(),
                    ]);
                    DB::table('property_immoji_sync_state')
                        ->where('property_id', $propertyId)
                        ->update(['immoji_id' => $newUuid, 'updated_at' => now()]);
                }
                $resolved++;
            } else {
                $this->line(sprintf(
                    '  Property #%d (%s): %s → NULL (drueben weg, kein objectNumber-Match)',
                    $propertyId, $r['ref_id'], substr($r['our_uuid'], 0, 12) . '…'
                ));
                if (!$dry) {
                    DB::table('properties')->where('id', $propertyId)->update([
                        'openimmo_id' => null,
                        'updated_at'  => now(),
                    ]);
                    DB::table('property_immoji_sync_state')->where('property_id', $propertyId)->delete();
                    DB::table('property_images')->where('property_id', $propertyId)->update(['immoji_source' => null]);
                }
                $nulled++;
            }
        }

        return ['count' => $resolved + $nulled, 'resolved' => $resolved, 'nulled' => $nulled];
    }

    private function autoFixMismatch(ImmojiRestClient $client, array $rows, bool $dry): int
    {
        $patched = 0;
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  Property #%d: objectNumber drueben "%s" → "%s"',
                $r['property_id'], $r['theirs'], $r['ours']
            ));
            if (!$dry) {
                try {
                    $client->patchRealty($r['immoji_id'], ['objectNumber' => $r['ours']]);
                    $patched++;
                } catch (\Throwable $e) {
                    $this->warn('    Fehler: ' . $e->getMessage());
                }
            } else {
                $patched++;
            }
        }
        return $patched;
    }
}
