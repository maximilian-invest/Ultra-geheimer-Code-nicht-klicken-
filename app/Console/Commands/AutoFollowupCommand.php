<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Admin\FollowupController;
use App\Services\EmailService;
use App\Models\Activity;

class AutoFollowupCommand extends Command
{
    protected $signature = 'followup:auto-send {--dry-run : Nur anzeigen, nicht senden}';
    protected $description = 'Sendet automatische Nachfass-Mails fuer aktivierte Stufen';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $stage1Enabled = DB::table('settings')->where('key', 'auto_followup_stage1_enabled')->value('value') === '1';
        $stage2Enabled = DB::table('settings')->where('key', 'auto_followup_stage2_enabled')->value('value') === '1';
        $accountId     = (int) DB::table('settings')->where('key', 'auto_followup_account_id')->value('value');

        $this->info('AutoFollowup gestartet' . ($dryRun ? ' [DRY RUN]' : ''));
        $this->info("Stage1: " . ($stage1Enabled ? 'AN' : 'AUS') . " | Stage2: " . ($stage2Enabled ? 'AN' : 'AUS') . " | Account: {$accountId}");

        if (!$accountId) {
            Log::warning('AutoFollowup: Kein Email-Konto konfiguriert.');
            $this->error('Kein Email-Konto fuer Auto-Nachfassen konfiguriert.');
            return 1;
        }

        $followupController = new FollowupController();
        $emailService       = app(EmailService::class);

        if ($stage1Enabled) {
            $this->processStage($followupController, $emailService, 1, $accountId, $dryRun);
        } else {
            $this->line('Stage 1: deaktiviert, wird uebersprungen.');
        }

        if ($stage2Enabled) {
            $this->processStage($followupController, $emailService, 2, $accountId, $dryRun);
        } else {
            $this->line('Stage 2: deaktiviert, wird uebersprungen.');
        }

        $this->info('AutoFollowup abgeschlossen.');
        return 0;
    }

    private function processStage(FollowupController $fc, EmailService $emailService, int $stage, int $accountId, bool $dryRun)
    {
        $mode  = $stage === 1 ? 'stage1' : 'followup';
        $leads = $fc->getLeadsForAutoSend($mode);

        $this->info("Stage {$stage}: " . count($leads) . " Leads gefunden" . ($dryRun ? ' [DRY RUN]' : ''));
        Log::info("AutoFollowup Stage {$stage}: " . count($leads) . " Leads", ['dry_run' => $dryRun]);

        foreach ($leads as $lead) {
            try {
                // Skip if conversation is marked as erledigt
                $convStatus = DB::table('conversations')
                    ->where('property_id', $lead['property_id'] ?? 0)
                    ->where('stakeholder', $lead['stakeholder'] ?? '')
                    ->value('status');
                if ($convStatus === 'erledigt') {
                    $this->line('  [SKIP] Erledigt: ' . ($lead['stakeholder'] ?? '?'));
                    continue;
                }

                if (empty($lead['email'])) {
                    $this->line("  [SKIP] Keine Email fuer: " . ($lead['stakeholder'] ?? '?'));
                    Log::info('AutoFollowup: Kein Email-Empfaenger', ['stakeholder' => $lead['stakeholder'] ?? '?', 'property_id' => $lead['property_id'] ?? 0]);
                    continue;
                }

                if ($dryRun) {
                    $name = $lead['stakeholder'] ?? '?';
                    $email = $lead['email'] ?? '?';
                    $ref = $lead['property_ref'] ?? '?';
                    $this->line("  [DRY] Wuerde senden an: {$email} ({$name}) | Property: {$ref}");
                    continue;
                }

                // KI-Entwurf generieren
                $draft = $fc->generateAutoDraft($lead, $stage);

                if (empty($draft['subject']) || empty($draft['body'])) {
                    Log::warning('AutoFollowup: Kein Draft generiert', [
                        'stakeholder' => $lead['stakeholder'] ?? '?',
                        'property_id' => $lead['property_id'] ?? 0,
                    ]);
                    $this->line("  [SKIP] Kein Draft generiert fuer: " . ($lead['stakeholder'] ?? '?'));
                    continue;
                }

                // Email senden
                $result = $emailService->send(
                    accountId:  $accountId,
                    to:         $lead['email'],
                    subject:    $draft['subject'],
                    body:       $draft['body'],
                    propertyId: $lead['property_id'] ?? null,
                    stakeholder: $lead['stakeholder'] ?? null,
                    outCategory: 'nachfassen'
                );

                // followup_stage auf die erstellte Activity schreiben
                if (!empty($result['activity_id'])) {
                    Activity::where('id', $result['activity_id'])
                        ->update(['followup_stage' => $stage]);
                }

                $this->line("  OK Gesendet an: {$lead['email']} ({$lead['stakeholder']})");
                Log::info('AutoFollowup gesendet', [
                    'to'          => $lead['email'],
                    'stage'       => $stage,
                    'property_id' => $lead['property_id'] ?? 0,
                    'stakeholder' => $lead['stakeholder'] ?? '?',
                ]);

                // Kurze Pause zwischen Mails
                sleep(2);

            } catch (\Exception $e) {
                Log::error('AutoFollowup Fehler', [
                    'error'      => $e->getMessage(),
                    'stakeholder' => $lead['stakeholder'] ?? '?',
                    'property_id' => $lead['property_id'] ?? 0,
                ]);
                $this->error("  FEHLER bei " . ($lead['stakeholder'] ?? '?') . ": " . $e->getMessage());
            }
        }
    }
}
