<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\PropertyLinkSession;
use Illuminate\Console\Command;

class PurgeOldLinkSessions extends Command
{
    protected $signature = 'links:purge-old-sessions {--days=90}';
    protected $description = 'Deletes property link sessions older than N days (default 90) and pseudonymizes activities.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $sessionIds = PropertyLinkSession::where('last_seen_at', '<', $cutoff)->pluck('id');

        if ($sessionIds->isEmpty()) {
            $this->info('No sessions to purge.');
            return self::SUCCESS;
        }

        Activity::whereIn('link_session_id', $sessionIds)->update([
            'stakeholder' => 'geloeschter-empfaenger@deleted.local',
            'link_session_id' => null,
        ]);

        PropertyLinkSession::whereIn('id', $sessionIds)->delete();

        $this->info(sprintf('Purged %d sessions older than %d days.', $sessionIds->count(), $days));
        return self::SUCCESS;
    }
}
