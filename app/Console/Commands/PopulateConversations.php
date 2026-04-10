<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Services\ConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateConversations extends Command
{
    protected $signature = 'conversations:populate {--fresh : Delete all existing conversations first}';

    protected $description = 'Populate conversations table from existing portal_emails + activities, and migrate KI-drafts from email_drafts';

    public function handle(ConversationService $service): int
    {
        $stats = ['created_before' => Conversation::count(), 'processed' => 0, 'skipped' => 0, 'errors' => 0, 'drafts_migrated' => 0];

        // Step 1: Fresh mode — truncate
        if ($this->option('fresh')) {
            $this->warn('Truncating conversations table...');
            Conversation::truncate();
            $this->info('Conversations table truncated.');
        }

        // Step 2: Process portal_emails with property_id, oldest first
        $totalEmails = PortalEmail::whereNotNull('property_id')->count();
        $this->info("Processing {$totalEmails} emails with property_id...");
        $bar = $this->output->createProgressBar($totalEmails);
        $bar->start();

        PortalEmail::whereNotNull('property_id')
            ->orderBy('email_date', 'asc')
            ->chunk(200, function ($emails) use ($service, &$stats, $bar) {
                foreach ($emails as $email) {
                    try {
                        // Find matching activity via source_email_id
                        $activity = Activity::where('source_email_id', $email->id)->first();

                        $conv = $service->updateFromEmail($email, $activity);

                        if ($conv) {
                            $stats['processed']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        $this->newLine();
                        $this->error("Email #{$email->id}: {$e->getMessage()}");
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // Step 3: Migrate drafts from email_drafts table
        $this->info('Migrating drafts from email_drafts table...');

        $drafts = DB::table('email_drafts')
            ->whereNotNull('property_id')
            ->whereNotNull('stakeholder')
            ->where('body', '!=', '')
            ->get();

        $this->info("Found {$drafts->count()} draft candidates.");

        foreach ($drafts as $draft) {
            try {
                // Try matching by to_email first, then by stakeholder LIKE
                $conv = Conversation::where('property_id', $draft->property_id)
                    ->where(function ($q) use ($draft) {
                        $q->where('contact_email', strtolower(trim($draft->to_email ?? '')));
                        if ($draft->stakeholder) {
                            $q->orWhere('stakeholder', 'LIKE', '%' . $draft->stakeholder . '%');
                        }
                    })
                    ->first();

                if ($conv && !$conv->draft_body) {
                    $conv->draft_body = $draft->body;
                    $conv->draft_subject = $draft->subject;
                    $conv->draft_to = $draft->to_email;
                    $conv->draft_generated_at = $draft->created_at;
                    $conv->save();
                    $stats['drafts_migrated']++;
                }
            } catch (\Throwable $e) {
                $this->error("Draft #{$draft->id}: {$e->getMessage()}");
            }
        }

        // Step 4: Fix status — conversations where we replied to an inquiry but customer hasn't responded = nachfassen
        $this->info('Fixing statuses: nachfassen = we replied but customer never responded directly...');
        // Step 1: Set all conversations with outbound to beantwortet (candidate for nachfassen)
        $candidates = DB::table('conversations')
            ->where('outbound_count', '>=', 1)
            ->whereNotNull('last_outbound_at')
            ->whereNotIn('status', ['beantwortet', 'nachfassen_1', 'nachfassen_2', 'nachfassen_3'])
            ->update(['status' => 'beantwortet']);
        $this->info("Candidates: {$candidates} conversations set to beantwortet");

        // Step 2: Revert those where customer actually replied directly (not via platform)
        $platformPatterns = ['%typeform%', '%willhaben%', '%immoscout%', '%immowelt%', '%noreply%', '%notification%'];
        $convs = Conversation::where('status', 'beantwortet')->get();
        $revertCount = 0;
        foreach ($convs as $conv) {
            $directInbound = DB::table('portal_emails')
                ->where('property_id', $conv->property_id)
                ->where('direction', 'inbound')
                ->where(function ($q) use ($conv) {
                    $q->where('from_email', $conv->contact_email)
                       ->orWhere('stakeholder', $conv->stakeholder);
                });
            foreach ($platformPatterns as $p) {
                $directInbound->where('from_email', 'NOT LIKE', $p);
            }
            if ($directInbound->exists()) {
                $conv->status = 'erledigt';
                $conv->save();
                $revertCount++;
            }
        }
        $this->info("Reverted {$revertCount} conversations -> erledigt (customer replied directly)");
        $this->info("Net nachfassen: " . ($candidates - $revertCount));

        // Step 4b: Correct nachfass stages based on actual nachfass email count
        $this->info('Correcting nachfass stages based on actual email counts...');
        $beantwortetConvs = Conversation::where('status', 'beantwortet')->get();
        $stageFixed = 0;
        foreach ($beantwortetConvs as $conv) {
            $nachfassCount = DB::table('portal_emails')
                ->where('property_id', $conv->property_id)
                ->where('direction', 'outbound')
                ->where('category', 'nachfassen')
                ->where(function ($q) use ($conv) {
                    $q->where('stakeholder', $conv->stakeholder);
                })
                ->count();

            $newStatus = 'beantwortet';
            if ($nachfassCount >= 3) $newStatus = 'erledigt';
            elseif ($nachfassCount >= 2) $newStatus = 'nachfassen_2';
            elseif ($nachfassCount >= 1) $newStatus = 'nachfassen_1';

            if ($newStatus !== 'beantwortet') {
                $conv->status = $newStatus;
                $conv->followup_count = $nachfassCount;
                $conv->save();
                $stageFixed++;
            }
        }
        $this->info("Stage-corrected: {$stageFixed} conversations");

        // Step 5: Summary
        $this->newLine();
        $totalConversations = Conversation::count();
        $this->info("=== Summary ===");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Conversations before', $stats['created_before']],
                ['Emails processed', $stats['processed']],
                ['Emails skipped (no contact)', $stats['skipped']],
                ['Errors', $stats['errors']],
                ['Drafts migrated', $stats['drafts_migrated']],
                ['Total conversations now', $totalConversations],
            ]
        );

        // Status distribution (after fix)
        $this->newLine();
        $this->info('Status distribution:');
        $statusCounts = Conversation::select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        $this->table(
            ['Status', 'Count'],
            $statusCounts->map(fn($row) => [$row->status, $row->cnt])->toArray()
        );

        return self::SUCCESS;
    }
}
