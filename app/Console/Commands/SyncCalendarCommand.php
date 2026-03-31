<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Admin\CalendarController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncCalendarCommand extends Command
{
    protected $signature = 'calendar:sync';
    protected $description = 'Sync Google Calendar events to local database';

    public function handle()
    {
        $controller = new CalendarController();
        $request = Request::create('/api/admin_api.php', 'GET', ['action' => 'calendar_sync']);

        $response = $controller->syncCalendar($request);
        $data = json_decode($response->getContent(), true);

        if (!empty($data['error'])) {
            $this->error($data['error']);
            Log::warning('Calendar sync failed: ' . $data['error']);
            return 1;
        }

        $this->info("Synced: {$data['synced']} events, {$data['new_events']} new, {$data['besichtigungen']} Besichtigungen");
        Log::info("Calendar sync: {$data['synced']} events synced");
        return 0;
    }
}
