<?php

namespace App\Console\Commands;

use App\Models\PortalEmail;
use App\Services\ConversationService;
use Illuminate\Console\Command;

class BackfillMismatchHints extends Command
{
    protected $signature = 'inbox:backfill-mismatch-hints
                            {--dry : Nur scannen, nichts schreiben}
                            {--limit=0 : Nur die N neuesten Mails verarbeiten (0 = alle)}';

    protected $description = 'Scannt vorhandene portal_emails auf Ref-IDs anderer Objekte und befuellt property_mismatch_ref_id. Wird einmalig nach Deploy ausgefuehrt damit Mismatch-Banner auch fuer Bestands-Mails erscheinen.';

    public function handle(ConversationService $service): int
    {
        $dry = (bool) $this->option('dry');
        $limit = (int) $this->option('limit');

        $query = PortalEmail::query()
            ->where(function ($q) {
                // Nur nicht-trashed Mails — Papierkorb-Eintraege scannen
                // ist Verschwendung.
                $q->where('is_deleted', 0)->orWhereNull('is_deleted');
            })
            ->orderByDesc('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $this->info(($dry ? '[DRY] ' : '') . "Scanning {$total} emails for mismatched ref_ids...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = ['scanned' => 0, 'set' => 0, 'cleared' => 0, 'unchanged' => 0];

        $query->chunkById(200, function ($emails) use ($service, $dry, &$stats, $bar) {
            foreach ($emails as $email) {
                $stats['scanned']++;
                $detected = $service->detectMismatchedRefId($email);
                $current = $email->property_mismatch_ref_id;

                if ($detected === $current) {
                    $stats['unchanged']++;
                } elseif ($detected === null) {
                    $stats['cleared']++;
                    if (!$dry) {
                        $email->property_mismatch_ref_id = null;
                        $email->saveQuietly();
                    }
                } else {
                    $stats['set']++;
                    if (!$dry) {
                        $email->property_mismatch_ref_id = $detected;
                        $email->saveQuietly();
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info('Done.');
        $this->table(
            ['scanned', 'mismatch set', 'cleared', 'unchanged'],
            [[$stats['scanned'], $stats['set'], $stats['cleared'], $stats['unchanged']]]
        );

        if ($dry) {
            $this->warn('Dry-Run: keine Aenderungen geschrieben. Erneut ohne --dry ausfuehren um zu persistieren.');
        }

        return self::SUCCESS;
    }
}
