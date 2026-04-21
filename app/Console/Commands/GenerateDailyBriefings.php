<?php

namespace App\Console\Commands;

use App\Services\DailyBriefingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDailyBriefings extends Command
{
    protected $signature = 'briefing:generate-daily {--user= : Nur für einen bestimmten User generieren}';
    protected $description = 'Generiert Tagesbriefings für alle aktiven Admin/Makler/Assistenz Users';

    public function handle(DailyBriefingService $service): int
    {
        $today = now()->toDateString();
        $specificUser = $this->option('user');

        $query = DB::table('users')
            ->whereIn('user_type', ['admin', 'makler', 'assistenz', 'backoffice']);

        if ($specificUser) {
            $query->where('id', $specificUser);
        } else {
            // Nur User mit mindestens einer Aktivität in den letzten 7 Tagen
            // (Admin immer inkludiert, da portfoliofern tätig)
            $query->where(function ($q) {
                $q->where('user_type', 'admin')
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('activities as a')
                          ->join('properties as p', 'a.property_id', '=', 'p.id')
                          ->whereRaw('p.broker_id = users.id')
                          ->where('a.activity_date', '>=', now()->subDays(7));
                  });
            });
        }

        $users = $query->select(['id', 'name', 'user_type'])->get();

        $this->info('Generating Tagesbriefings für ' . $users->count() . ' User...');
        $successful = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $service->generate($user->id, $today, forceRefresh: true);
                $successful++;
                $this->line('  ✓ ' . $user->name . ' (' . $user->user_type . ')');
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  ✗ ' . $user->name . ': ' . $e->getMessage());
                Log::error('Briefing generation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Fertig: {$successful} erfolgreich, {$failed} Fehler");
        return $failed > 0 ? 1 : 0;
    }
}
