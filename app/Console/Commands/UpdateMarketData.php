<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Admin\MarketIntelligenceController;
use Illuminate\Http\Request;

class UpdateMarketData extends Command
{
    protected $signature = 'market:update';
    protected $description = 'Aktualisiert alle Marktdaten und generiert KI-Analyse';

    public function handle()
    {
        $this->info('Market Intelligence Update gestartet...');
        $started = microtime(true);

        try {
            $controller = app(MarketIntelligenceController::class);
            $response = $controller->refresh(new Request());
            $data = json_decode($response->getContent(), true);

            $duration = round(microtime(true) - $started, 1);
            $this->info("Fertig in {$duration}s: " . ($data['message'] ?? 'OK'));

        } catch (\Exception $e) {
            $this->error('Fehler: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
