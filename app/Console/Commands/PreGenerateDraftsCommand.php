<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\StakeholderHelper;
use App\Http\Controllers\Admin\FollowupController;
use Illuminate\Http\Request;

class PreGenerateDraftsCommand extends Command
{
    protected $signature = 'followup:pre-generate {--dry-run : Nur anzeigen, nicht generieren} {--limit=20 : Max Drafts pro Lauf}';
    protected $description = 'Generiert KI-Entwuerfe im Hintergrund fuer alle offenen Nachfass- und Unbeantwortet-Items';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $this->info('Pre-Generate Drafts gestartet' . ($dryRun ? ' [DRY RUN]' : '') . " (max {$limit})");

        $fc   = new FollowupController();
        $norm = StakeholderHelper::normSH('a.stakeholder');

        // Collect all items that need drafts
        $items = $this->collectItems($fc, $norm);
        $this->info(count($items) . ' Items gefunden');

        // Filter: only items without cached draft
        $needGeneration = [];
        foreach ($items as $item) {
            if (count($needGeneration) >= $limit) break;
            if (!$this->hasCachedDraft($item['stakeholder'], $item['property_id'])) {
                // Skip erledigt conversations
                $convStatus = DB::table('conversations')
                    ->where('property_id', $item['property_id'])
                    ->where('stakeholder', $item['stakeholder'])
                    ->value('status');
                if ($convStatus === 'erledigt') continue;
                $needGeneration[] = $item;
            }
        }

        $this->info(count($needGeneration) . ' Items ohne Cache');

        if ($dryRun) {
            foreach ($needGeneration as $item) {
                $this->line("  [DRY] {$item['stakeholder']} | Property {$item['property_id']} | Stage {$item['stage']}");
            }
            return 0;
        }

        $generated = 0;
        foreach ($needGeneration as $item) {
            try {
                $this->line("  Generiere: {$item['stakeholder']} | Property {$item['property_id']}...");

                // Simulate request to draft() which handles caching automatically
                $request = Request::create('/api/admin_api.php', 'GET', [
                    'action'         => $item['stage'] === 1 ? 'followup_draft_staged' : 'followup_draft',
                    'stakeholder'    => $item['stakeholder'],
                    'property_id'    => $item['property_id'],
                    'followup_stage' => $item['stage'],
                ]);

                $response = $fc->draft($request);
                $data     = json_decode($response->getContent(), true);

                if (!empty($data['draft']['email_body'])) {
                    $generated++;
                    $cached = !empty($data['cached']) ? ' (cached)' : ' (neu generiert)';
                    $this->line("    OK{$cached}");
                } else {
                    $this->line("    SKIP: Kein Draft erhalten");
                }

                // Rate limiting
                if (empty($data['cached'])) {
                    sleep(2);
                }
            } catch (\Exception $e) {
                $this->error("    FEHLER: " . $e->getMessage());
                Log::warning('PreGenerate draft failed', [
                    'stakeholder' => $item['stakeholder'],
                    'property_id' => $item['property_id'],
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->info("Fertig: {$generated}/{$limit} Drafts generiert");
        Log::info("PreGenerate: {$generated} drafts generated");
        return 0;
    }

    private function collectItems(FollowupController $fc, string $norm): array
    {
        $sysFilter = StakeholderHelper::systemStakeholderFilter('a.stakeholder');
        $items = [];

        // Stage 2: Nachfassen items
        $leads = $fc->getLeadsForAutoSend('followup');
        foreach ($leads as $lead) {
            $items[] = [
                'stakeholder' => $lead['stakeholder'],
                'property_id' => $lead['property_id'],
                'stage'       => 2,
            ];
        }

        // Stage 1: 24h Nachfassen items
        $stage1Leads = $fc->getLeadsForAutoSend('stage1');
        foreach ($stage1Leads as $lead) {
            $items[] = [
                'stakeholder' => $lead['stakeholder'],
                'property_id' => $lead['property_id'],
                'stage'       => 1,
            ];
        }

        // Unanswered items (mode=unanswered from index)
        try {
            $request  = Request::create('/api/admin_api.php', 'GET', ['mode' => 'unanswered', 'filter' => 'all']);
            $response = $fc->index($request);
            $data     = json_decode($response->getContent(), true);
            foreach ($data['followups'] ?? [] as $f) {
                $items[] = [
                    'stakeholder' => $f['from_name'] ?? '',
                    'property_id' => $f['property_id'] ?? 0,
                    'stage'       => 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('PreGenerate: Could not load unanswered items', ['error' => $e->getMessage()]);
        }

        return $items;
    }

    private function hasCachedDraft(string $stakeholder, int $propertyId): bool
    {
        if (!$stakeholder || !$propertyId) return true; // skip invalid

        $cached = DB::selectOne("
            SELECT id FROM email_drafts
            WHERE property_id = ? AND stakeholder = ? AND body IS NOT NULL AND body != ''
              AND created_at > NOW() - INTERVAL 24 HOUR
            ORDER BY id DESC LIMIT 1
        ", [$propertyId, $stakeholder]);

        return (bool) $cached;
    }
}
