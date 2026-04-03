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

        // Step 4: Summary
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

        // Status distribution
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
