<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Services\PropertyMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPropertyMatching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        protected int $conversationId,
        protected int $emailId
    ) {}

    public function handle(PropertyMatcherService $matcher): void
    {
        $conv = Conversation::find($this->conversationId);
        $email = PortalEmail::find($this->emailId);

        if (!$conv || !$email) {
            Log::warning("[CrossMatch Job] Conv {$this->conversationId} or email {$this->emailId} not found");
            return;
        }

        try {
            $matcher->analyzeAndMatch($conv, $email);
        } catch (\Throwable $e) {
            Log::error("[CrossMatch Job] Error for conv {$this->conversationId}: " . $e->getMessage());
            throw $e;
        }
    }
}
