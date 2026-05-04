<?php

namespace App\Console\Commands;

use App\Services\ImmojiRestClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Synct den Immoji-Status (active/inactive/sold/rented) per Bulk-REST.
 *
 * Beispiele:
 *   php artisan immoji:sync-status --dry-run
 *   php artisan immoji:sync-status                    # alle openimmo_id-Properties
 *   php artisan immoji:sync-status --property=218
 *   php artisan immoji:sync-status --changed-since=24h
 */
class ImmojiSyncStatus extends Command
{
    protected $signature = 'immoji:sync-status
        {--dry-run : Mapping anzeigen, kein API-Call}
        {--property=* : Nur diese Property-IDs (mehrfach erlaubt)}
        {--changed-since= : Nur Properties deren updated_at neuer ist (z.B. 24h, 7d)}';

    protected $description = 'Synct den Status (verkauft/aktiv/...) per Bulk-REST nach Immoji.';

    public function handle(ImmojiRestClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('IMMOJI_API_KEY ist nicht in .env gesetzt.');
            return self::FAILURE;
        }

        $propertyIds = (array) $this->option('property');
        $propertyIds = array_filter(array_map('intval', $propertyIds));

        $changedSince = $this->option('changed-since');
        if ($changedSince) {
            $cutoff = $this->parseDuration($changedSince);
            if (!$cutoff) {
                $this->error("Ungueltige --changed-since-Angabe: {$changedSince}");
                return self::FAILURE;
            }
            $idsByTime = DB::table('properties')
                ->whereNotNull('openimmo_id')
                ->where('openimmo_id', '<>', '')
                ->where('updated_at', '>=', $cutoff)
                ->pluck('id')
                ->all();
            $propertyIds = $propertyIds
                ? array_values(array_intersect($propertyIds, $idsByTime))
                : $idsByTime;
            $this->info(sprintf('Geaendert seit %s: %d Properties.', $cutoff->toDateTimeString(), count($propertyIds)));
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->preview($propertyIds);
        }

        $this->line(($dryRun ? '[DRY-RUN] ' : '') . 'Starte Status-Sync ...');
        $res = $client->syncStatusForProperties($propertyIds ?: null, $dryRun);

        $this->info(sprintf(
            'Total %d, Erfolgreich %d, Fehler %d, Skipped %d, Chunks %d',
            $res['total'], $res['succeeded'], $res['failed'], $res['skipped'], $res['chunks']
        ));

        if (!empty($res['errors'])) {
            $this->warn('Fehler:');
            foreach ($res['errors'] as $err) {
                $this->line(sprintf(
                    '  Property #%d (%s) → %s   [HTTP %d] %s',
                    $err['property_id'], $err['ref_id'] ?? '-', $err['immoji_id'], $err['status'], $err['message']
                ));
            }
        }

        return $res['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Zeigt fuer --dry-run die Mapping-Tabelle.
     */
    private function preview(array $propertyIds): void
    {
        $q = DB::table('properties')
            ->whereNotNull('openimmo_id')
            ->where('openimmo_id', '<>', '')
            ->select('id', 'ref_id', 'realty_status', 'marketing_type');
        if (!empty($propertyIds)) {
            $q->whereIn('id', $propertyIds);
        }
        $rows = $q->orderBy('id')->limit(20)->get();

        $this->line('Erste 20 Properties (Vorschau):');
        $this->table(
            ['ID', 'ref_id', 'Portal-Status', 'Marketing', 'Immoji-Status'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->ref_id,
                $r->realty_status,
                $r->marketing_type,
                ImmojiRestClient::mapPortalStatus($r->realty_status, $r->marketing_type) ?? '(skip)',
            ])->all()
        );
    }

    private function parseDuration(string $s): ?\Illuminate\Support\Carbon
    {
        if (preg_match('/^(\d+)([hdm])$/i', $s, $m)) {
            $n = (int) $m[1];
            $unit = strtolower($m[2]);
            return match ($unit) {
                'm' => now()->subMinutes($n),
                'h' => now()->subHours($n),
                'd' => now()->subDays($n),
                default => null,
            };
        }
        try {
            return \Illuminate\Support\Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
