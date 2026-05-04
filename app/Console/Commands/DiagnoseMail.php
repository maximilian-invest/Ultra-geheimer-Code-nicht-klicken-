<?php

namespace App\Console\Commands;

use App\Models\PortalEmail;
use App\Services\ConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseMail extends Command
{
    protected $signature = 'inbox:diagnose-mail
                            {needle : Suchbegriff (Email-Adresse, Stakeholder oder Email-ID)}
                            {--limit=5 : Wieviele Mails maximal anzeigen}';

    protected $description = 'Komplett-Diagnose fuer eine Mail oder einen Kontakt: zeigt aktuelle Zuordnung, Mismatch-Hint, alle Ref-IDs die im Body vorkommen + Conversation-Kontext. Hilft bei der Fehlersuche bei falsch zugeordneten Mails.';

    public function handle(ConversationService $service): int
    {
        $needle = (string) $this->argument('needle');
        $limit = (int) $this->option('limit');

        $query = PortalEmail::query();
        if (ctype_digit($needle)) {
            $query->where('id', (int) $needle);
        } else {
            $like = '%' . $needle . '%';
            $query->where(function ($q) use ($like) {
                $q->where('from_email', 'like', $like)
                  ->orWhere('to_email', 'like', $like)
                  ->orWhere('stakeholder', 'like', $like)
                  ->orWhere('from_name', 'like', $like);
            });
        }

        $emails = $query->orderByDesc('email_date')->limit($limit)->get();

        if ($emails->isEmpty()) {
            $this->error("Keine Mails fuer '{$needle}' gefunden.");
            return self::FAILURE;
        }

        $allProperties = DB::table('properties')
            ->whereNotNull('ref_id')->where('ref_id', '!=', '')
            ->get(['id', 'ref_id', 'address', 'city', 'realty_status', 'broker_id'])
            ->keyBy('id');

        $this->info("Gefundene Mails: {$emails->count()}");
        $this->newLine();

        foreach ($emails as $email) {
            $this->line(str_repeat('=', 78));
            $this->line("MAIL #{$email->id}");
            $this->line(str_repeat('=', 78));

            $this->table(['Feld', 'Wert'], [
                ['Datum',       (string) $email->email_date],
                ['Direction',   $email->direction ?? '-'],
                ['From',        $email->from_email ?? '-'],
                ['From-Name',   $email->from_name ?? '-'],
                ['To',          $email->to_email ?? '-'],
                ['Stakeholder', $email->stakeholder ?? '-'],
                ['Subject',     mb_substr($email->subject ?? '-', 0, 90)],
                ['Account-ID',  $email->account_id ?? '-'],
                ['property_id (DB)', $email->property_id ?? 'NULL'],
                ['matched_ref_id (DB)', $email->matched_ref_id ?? 'NULL'],
                ['property_mismatch_ref_id (DB)', $email->property_mismatch_ref_id ?? 'NULL'],
                ['is_deleted', $email->is_deleted ? 'JA (Trash)' : 'nein'],
            ]);

            $currentProp = $email->property_id ? ($allProperties[$email->property_id] ?? null) : null;
            $this->newLine();
            if ($currentProp) {
                $this->line("→ Aktuell zugeordnet: <fg=cyan>{$currentProp->ref_id}</> "
                    . ($currentProp->address ? "({$currentProp->address})" : '')
                    . " · status={$currentProp->realty_status} · broker={$currentProp->broker_id}");
            } else {
                $this->line('→ <fg=red>Aktuell KEINE Property zugeordnet</>');
            }

            // Welche Ref-IDs koennten im Body vorkommen?
            $body = (string) ($email->body_text ?? '');
            $subj = (string) ($email->subject ?? '');
            $haystack = strtolower($subj . ' ' . $body);
            $haystackNorm = strtolower(preg_replace('/[\s\-_]+/', '', $subj . ' ' . $body));

            $hits = [];
            foreach ($allProperties as $p) {
                $ref = strtolower((string) $p->ref_id);
                if (strlen($ref) < 4) continue;
                $found = null;
                if (str_contains($haystack, $ref)) {
                    $pos = strpos($haystack, $ref);
                    $found = ['exact', $pos];
                } else {
                    $refNorm = preg_replace('/[\s\-_]+/', '', $ref);
                    if (strlen($refNorm) >= 4 && str_contains($haystackNorm, $refNorm)) {
                        $pos = strpos($haystackNorm, $refNorm);
                        $found = ['normalized', $pos];
                    }
                }
                if ($found) {
                    $hits[] = [
                        'ref_id'    => $p->ref_id,
                        'pid'       => $p->id,
                        'mode'      => $found[0],
                        'position'  => $found[1],
                        'is_current'=> ((int) $p->id === (int) ($email->property_id ?? 0)),
                        'status'    => $p->realty_status,
                    ];
                }
            }

            $this->newLine();
            if (empty($hits)) {
                $this->line('<fg=yellow>Body+Subject: KEINE Ref-IDs erkannt (weder exact noch normalized).</>');
            } else {
                $this->line('Ref-IDs im Subject+Body (sortiert nach Position):');
                usort($hits, fn($a, $b) => $a['position'] <=> $b['position']);
                $rows = [];
                foreach ($hits as $h) {
                    $marker = $h['is_current'] ? '← AKTUELL' : '';
                    $rows[] = [
                        $h['ref_id'] . ' ' . $marker,
                        $h['pid'],
                        $h['mode'],
                        $h['position'],
                        $h['status'],
                    ];
                }
                $this->table(['ref_id', 'pid', 'match-mode', 'pos', 'status'], $rows);
            }

            // Live-Detection ausfuehren (sollte denselben Hint produzieren wie der gespeicherte)
            $this->newLine();
            $detectedNow = $service->detectMismatchedRefId($email);
            $stored = $email->property_mismatch_ref_id ?? null;
            if ($detectedNow === $stored) {
                $this->line("Live-Detection vs DB: <fg=green>UEBEREINSTIMMUNG</> (" . ($detectedNow ?: 'kein Hint') . ")");
            } else {
                $this->line("Live-Detection vs DB: <fg=red>WEICHT AB</> · DB='" . ($stored ?: 'NULL') . "', Live-Detection='" . ($detectedNow ?: 'NULL') . "'");
                $this->line('  → Backfill-Command ausfuehren: php artisan inbox:backfill-mismatch-hints');
            }

            // Body-Snippet rund um die erste Ref-ID anzeigen, damit man den Kontext sieht
            if (!empty($hits)) {
                $firstHit = strtolower($hits[0]['ref_id']);
                $pos = stripos($body, $firstHit);
                if ($pos !== false) {
                    $start = max(0, $pos - 80);
                    $snippet = mb_substr($body, $start, 200);
                    $this->newLine();
                    $this->line('Body-Auszug rund um den Treffer:');
                    $this->line('<fg=gray>...' . str_replace(["\r", "\n"], ' ', $snippet) . '...</>');
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
