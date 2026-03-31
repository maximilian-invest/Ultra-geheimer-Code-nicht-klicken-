<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\ImapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ImapService $imapService): void
    {
        $accounts = EmailAccount::where('is_active', true)->get();

        foreach ($accounts as $account) {
            try {
                $count = $imapService->fetchNewEmails($account);
                Log::info("Fetched {$count} new emails from {$account->email_address}");
            } catch (\Exception $e) {
                Log::error("Email fetch failed for {$account->email_address}: {$e->getMessage()}");
            }
        }
    }
}
