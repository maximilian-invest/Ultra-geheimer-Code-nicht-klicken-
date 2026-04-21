<?php

namespace App\Services;

use App\Models\DailyBriefing;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyBriefingService
{
    public function __construct(
        private AnthropicService $anthropic,
    ) {}

    /**
     * Haupt-Einstiegspunkt. Lädt aus Cache oder generiert frisch.
     */
    public function generate(int $userId, ?string $date = null, bool $forceRefresh = false): array
    {
        $date = $date ?: now()->toDateString();

        if (!$forceRefresh) {
            $cached = $this->loadFromCache($userId, $date);
            if ($cached) return $cached;
        }

        $context = $this->gatherContext($userId, $date);

        $activityCount = count($context['activities_24h']);
        $threadCount = count($context['active_threads']);

        $aiResult = null;
        $modelUsed = 'fallback';

        if ($activityCount >= 3 || $threadCount > 0) {
            $aiResult = $this->callAi($context);
            if ($aiResult) $modelUsed = 'claude-haiku-4-5';
        }

        $result = $aiResult ?: $this->fallbackTemplate($context);

        $result['threads'] = $this->formatThreadsForFrontend(
            $context['active_threads'],
            $result['thread_annotations'] ?? []
        );
        $result['agenda'] = $this->formatAgendaForFrontend($context);

        $this->saveToCache($userId, $date, $result, $modelUsed);

        return $result;
    }

    /**
     * Sammelt alle Rohdaten für das Briefing eines Users.
     * Broker-scoped:
     *   - Makler: nur eigene Properties (broker_id = user_id)
     *   - Admin:  auch nur eigene Properties + Mails aus eigenen email_accounts
     *             (jede Person sieht nur ihre eigenen Daten)
     *   - Assistenz/Backoffice: sehen alles (shared portfolio support)
     *
     * Achtung: Admin ist NICHT scopeAll, sonst wuerde der Admin die Briefings
     * aller Makler sehen. Das war der Bug "Info von anderen Maklern".
     */
    public function gatherContext(int $userId, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        // Security: Ohne User-ID leeres Ergebnis (kein Datenleck)
        if (!$userId) {
            return $this->emptyContext($date);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) return $this->emptyContext($date);

        $userType = $user->user_type ?? 'makler';
        // Nur Assistenz + Backoffice sehen alles. Admin + Makler bekommen
        // ihr eigenes Portfolio, genau wie in Conversation::scopeForBroker.
        $scopeAll = in_array($userType, ['assistenz', 'backoffice'], true);

        return [
            'date' => $date,
            'broker_name' => $user->name ?? 'Makler',
            'activities_24h' => $this->getActivities($userId, $scopeAll),
            'active_threads' => $this->getActiveThreads($userId, $scopeAll),
            'tasks_due' => $this->getTasks($userId),
            'viewings_today' => $this->getViewings($userId, $scopeAll),
            'property_signals' => $this->getPropertySignals($userId, $scopeAll),
            'nachfass_outcome' => $this->getNachfassOutcome($userId, $scopeAll),
        ];
    }

    /**
     * Ruft Claude mit dem gesammelten Context auf.
     */
    public function callAi(array $context): ?array
    {
        if (empty($context) || empty($context['date'])) return null;

        $systemPrompt = 'Du bist ein Assistenz-System für einen österreichischen Immobilienmakler. '
            . 'Fasse den gestrigen Tag in 3 Sätzen faktisch zusammen (Block: narrative, 100-150 Wörter). '
            . 'Erkenne Muster wie Kunden-Beschwerden, Hot/Cooling Properties, Eigentümer-Unmut. '
            . 'Keine Floskeln. Beschwerde-Signale in Kundennachrichten IMMER als Anomalie (kind:warn) markieren. '
            . 'Antworte NUR mit valid JSON in folgendem Format: '
            . '{"preview": "1-zeiliger Highlight max 180 Zeichen", '
            . '"narrative": "3-4 Sätze mit <strong>Zahlen/Namen</strong> und <mark>Alarm-Signalen</mark>", '
            . '"anomalies": [{"kind":"hot|cool|warn","property_ref":"...","text":"..."}], '
            . '"thread_annotations": {"<conv_id>":{"priority":"red|orange|yellow|green","label":"wartet 3 Tage"}}}';

        $userMessage = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = $this->anthropic->chatJson($systemPrompt, $userMessage, 2000);

            if (!is_array($result)) return null;
            if (!isset($result['preview'], $result['narrative'])) return null;

            $result['preview'] = mb_substr((string) $result['preview'], 0, 180);
            $result['narrative'] = $this->truncateNarrative((string) $result['narrative'], 300);
            $result['anomalies'] = array_slice((array) ($result['anomalies'] ?? []), 0, 3);
            $result['thread_annotations'] = (array) ($result['thread_annotations'] ?? []);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('DailyBriefingService::callAi failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Deterministisches Briefing ohne KI.
     */
    public function fallbackTemplate(array $context): array
    {
        $activities = $context['activities_24h'];
        $threads = $context['active_threads'];
        $total = count($activities);

        if ($total < 3 && empty($threads)) {
            return [
                'preview' => 'Ruhiger Tag — keine besonderen Vorkommnisse.',
                'narrative' => 'Ruhiger Tag. In den letzten 24 Stunden sind keine besonderen Aktivitäten registriert worden.',
                'anomalies' => [],
                'thread_annotations' => [],
            ];
        }

        $byCategory = [];
        foreach ($activities as $a) {
            $byCategory[$a['category']] = ($byCategory[$a['category']] ?? 0) + 1;
        }

        $parts = [];
        if (($byCategory['anfrage'] ?? 0) > 0) $parts[] = $byCategory['anfrage'] . ' neue Anfragen';
        if (($byCategory['kaufanbot'] ?? 0) > 0) $parts[] = $byCategory['kaufanbot'] . ' Kaufanbote';
        if (($byCategory['besichtigung'] ?? 0) > 0) $parts[] = $byCategory['besichtigung'] . ' Besichtigungen';
        if (($byCategory['email-out'] ?? 0) > 0) $parts[] = $byCategory['email-out'] . ' verschickte E-Mails';

        $narrative = 'Letzte 24 Stunden: ' . (empty($parts) ? $total . ' Aktivitäten' : implode(', ', $parts)) . '.';

        if (count($threads) > 0) {
            $narrative .= ' ' . count($threads) . ' aktive Gesprächsfäden der letzten 5 Tage.';
        }

        $preview = mb_substr($narrative, 0, 177);
        if (mb_strlen($narrative) > 180) $preview .= '…';

        $anomalies = [];
        foreach ($context['property_signals'] as $sig) {
            if ($sig['kind'] === 'hot') {
                $anomalies[] = [
                    'kind' => 'hot',
                    'property_ref' => $sig['property_ref'],
                    'text' => $sig['property_ref'] . ': ' . $sig['sessions_24h'] . ' Exposé-Aufrufe in 24h — hohes Interesse',
                ];
            } elseif ($sig['kind'] === 'cool') {
                $anomalies[] = [
                    'kind' => 'cool',
                    'property_ref' => $sig['property_ref'],
                    'text' => $sig['property_ref'] . ': Anfragen von ' . $sig['previous_inquiries'] . ' auf ' . $sig['recent_inquiries'] . ' eingebrochen',
                ];
            }
        }

        $threadAnnotations = [];
        foreach ($threads as $t) {
            $days = (int) ($t['days_waiting'] ?? 0);
            $priority = $days >= 2 ? 'red' : ($days >= 1 ? 'orange' : 'green');
            $label = $days >= 2 ? 'wartet ' . $days . ' Tage' : '';
            $threadAnnotations[(string) $t['id']] = ['priority' => $priority, 'label' => $label];
        }

        return [
            'preview' => $preview,
            'narrative' => $narrative,
            'anomalies' => array_slice($anomalies, 0, 3),
            'thread_annotations' => $threadAnnotations,
        ];
    }

    public function loadFromCache(int $userId, string $date): ?array
    {
        $row = DailyBriefing::where('user_id', $userId)
            ->where('briefing_date', $date)
            ->first();

        return $row ? $row->data : null;
    }

    public function saveToCache(int $userId, string $date, array $data, string $modelUsed): void
    {
        DailyBriefing::updateOrCreate(
            ['user_id' => $userId, 'briefing_date' => $date],
            [
                'data' => $data,
                'model_used' => $modelUsed,
                'generated_at' => now(),
            ]
        );
    }

    // ===== private helpers =====

    private function emptyContext(string $date): array
    {
        return [
            'date' => $date,
            'broker_name' => 'Makler',
            'activities_24h' => [],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ];
    }

    private function getActivities(int $userId, bool $scopeAll): array
    {
        $q = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->where('a.activity_date', '>=', now()->subHours(24))
            ->whereNotIn('a.category', ['link_opened'])
            ->select([
                'a.id', 'a.activity', 'a.category', 'a.stakeholder',
                'a.property_id', 'a.activity_date',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('a.activity_date', 'desc')
            ->limit(100);

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        return $q->get()->map(fn($row) => (array) $row)->all();
    }

    private function getActiveThreads(int $userId, bool $scopeAll): array
    {
        $q = DB::table('conversations as c')
            ->leftJoin('properties as p', 'c.property_id', '=', 'p.id')
            ->where('c.last_inbound_at', '>=', now()->subDays(5))
            ->whereNotIn('c.status', ['erledigt'])
            ->where('c.match_dismissed', '=', 0)
            ->select([
                'c.id', 'c.stakeholder', 'c.contact_email', 'c.property_id',
                'c.status', 'c.last_inbound_at', 'c.last_outbound_at',
                'c.inbound_count', 'c.outbound_count',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('c.last_inbound_at', 'desc')
            ->limit(20);

        if (!$scopeAll) {
            $q->where(function ($sub) use ($userId) {
                $sub->where('p.broker_id', $userId)
                    ->orWhere(function ($s2) use ($userId) {
                        $s2->whereNull('c.property_id')
                           ->whereIn('c.last_email_id', function ($s3) use ($userId) {
                               $s3->select('id')->from('portal_emails')
                                  ->whereIn('account_id', function ($s4) use ($userId) {
                                      $s4->select('id')->from('email_accounts')->where('user_id', $userId);
                                  });
                           });
                    });
            });
        }

        $threads = $q->get()->map(fn($row) => (array) $row)->all();

        foreach ($threads as &$t) {
            $t['recent_messages'] = DB::table('portal_emails')
                ->where(function ($q) use ($t) {
                    $q->where('from_email', $t['contact_email'])
                      ->orWhere('to_email', 'like', '%' . $t['contact_email'] . '%');
                })
                ->orderBy('email_date', 'desc')
                ->limit(3)
                ->get(['subject', 'direction', 'email_date'])
                ->map(function ($m) {
                    $arr = (array) $m;
                    $arr['date_received'] = $arr['email_date']; // alias for frontend compatibility
                    return $arr;
                })
                ->all();

            $t['days_waiting'] = $t['last_inbound_at']
                ? (int) Carbon::parse($t['last_inbound_at'])->diffInDays(now())
                : 0;
        }

        return $threads;
    }

    private function getTasks(int $userId): array
    {
        return DB::table('tasks')
            ->where('is_done', 0)
            ->where(function ($q) {
                $q->whereDate('due_date', '<=', now()->toDateString())
                  ->orWhereNull('due_date');
            })
            ->where('assigned_to', $userId)
            ->orderBy('priority', 'desc')
            ->limit(20)
            ->get(['id', 'title', 'priority', 'due_date', 'property_id'])
            ->map(fn($t) => (array) $t)
            ->all();
    }

    private function getViewings(int $userId, bool $scopeAll): array
    {
        $q = DB::table('viewings as v')
            ->leftJoin('properties as p', 'v.property_id', '=', 'p.id')
            ->whereDate('v.viewing_date', now()->toDateString())
            ->where(function ($q) {
                $q->where('v.status', '!=', 'storniert')->orWhereNull('v.status');
            })
            ->select([
                'v.id', 'v.viewing_time', 'v.person_name',
                'v.property_id', 'v.notes',
                'p.ref_id as property_ref', 'p.address as property_address',
            ])
            ->orderBy('v.viewing_time');

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        return $q->get()->map(fn($row) => (array) $row)->all();
    }

    private function getPropertySignals(int $userId, bool $scopeAll): array
    {
        $signals = [];

        try {
            $hotQ = DB::table('property_link_sessions as s')
                ->join('properties as p', 's.property_id', '=', 'p.id')
                ->where('s.created_at', '>=', now()->subHours(24))
                ->groupBy('s.property_id', 'p.ref_id', 'p.address')
                ->select([
                    's.property_id',
                    'p.ref_id', 'p.address',
                    DB::raw('COUNT(*) as sessions_24h'),
                ])
                ->havingRaw('COUNT(*) >= 10');

            if (!$scopeAll) {
                $hotQ->where('p.broker_id', $userId);
            }

            foreach ($hotQ->get() as $row) {
                $signals[] = [
                    'kind' => 'hot',
                    'property_id' => $row->property_id,
                    'property_ref' => $row->ref_id,
                    'sessions_24h' => (int) $row->sessions_24h,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('getPropertySignals hot query failed', ['error' => $e->getMessage()]);
        }

        try {
            $brokerFilter = $scopeAll ? '' : 'AND p.broker_id = ' . intval($userId);
            $coolQ = DB::select("
                SELECT p.id as property_id, p.ref_id, p.address,
                       SUM(CASE WHEN a.activity_date >= ? THEN 1 ELSE 0 END) as recent,
                       SUM(CASE WHEN a.activity_date < ? AND a.activity_date >= ? THEN 1 ELSE 0 END) as previous
                FROM properties p
                LEFT JOIN activities a ON a.property_id = p.id AND a.category = 'anfrage'
                WHERE p.realty_status IN ('aktiv', 'inserat', 'auftrag')
                  {$brokerFilter}
                GROUP BY p.id, p.ref_id, p.address
                HAVING previous >= 5 AND recent < (previous * 0.3)
            ", [
                now()->subDays(14)->toDateTimeString(),
                now()->subDays(14)->toDateTimeString(),
                now()->subDays(28)->toDateTimeString(),
            ]);

            foreach ($coolQ as $row) {
                $signals[] = [
                    'kind' => 'cool',
                    'property_id' => $row->property_id,
                    'property_ref' => $row->ref_id,
                    'recent_inquiries' => (int) $row->recent,
                    'previous_inquiries' => (int) $row->previous,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('getPropertySignals cool query failed', ['error' => $e->getMessage()]);
        }

        return $signals;
    }

    private function getNachfassOutcome(int $userId, bool $scopeAll): array
    {
        $q = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->where('a.category', 'nachfassen')
            ->where('a.activity_date', '>=', now()->subHours(48));

        if (!$scopeAll) {
            $q->where('p.broker_id', $userId);
        }

        $sent = (int) $q->count();

        return ['sent' => $sent, 'replied' => 0];
    }

    private function truncateNarrative(string $text, int $maxWords): string
    {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $maxWords) return $text;

        $truncated = implode(' ', array_slice($words, 0, $maxWords));
        $lastDot = max(strrpos($truncated, '.'), strrpos($truncated, '!'), strrpos($truncated, '?'));
        if ($lastDot !== false) {
            $truncated = substr($truncated, 0, $lastDot + 1);
        }
        return $truncated;
    }

    private function formatThreadsForFrontend(array $threads, array $annotations): array
    {
        $priorityOrder = ['red' => 0, 'orange' => 1, 'yellow' => 2, 'green' => 3];

        $formatted = array_map(function ($t) use ($annotations) {
            $id = (string) ($t['id'] ?? '');
            $ann = $annotations[$id] ?? ['priority' => 'green', 'label' => ''];

            $trail = [];
            foreach (array_reverse((array) ($t['recent_messages'] ?? [])) as $msg) {
                $whenRaw = $msg['email_date'] ?? $msg['date_received'] ?? null;
                $when = $whenRaw ? Carbon::parse($whenRaw)->isoFormat('dd') : '?';
                $dir = ($msg['direction'] ?? '') === 'outbound' ? 'geschickt' : 'erhalten';
                $subject = mb_substr($msg['subject'] ?? '', 0, 40);
                $trail[] = "{$when} · {$dir}: «{$subject}»";
            }

            return [
                'id' => $t['id'] ?? null,
                'stakeholder' => $t['stakeholder'] ?? '',
                'property_ref' => $t['property_ref'] ?? null,
                'property_address' => $t['property_address'] ?? null,
                'priority' => $ann['priority'],
                'label' => $ann['label'],
                'trail' => $trail,
                'days_waiting' => (int) ($t['days_waiting'] ?? 0),
            ];
        }, $threads);

        usort($formatted, function ($a, $b) use ($priorityOrder) {
            $pa = $priorityOrder[$a['priority']] ?? 4;
            $pb = $priorityOrder[$b['priority']] ?? 4;
            return $pa <=> $pb;
        });

        return array_slice($formatted, 0, 8);
    }

    private function formatAgendaForFrontend(array $context): array
    {
        $agenda = ['termine' => [], 'offen' => []];

        foreach ($context['viewings_today'] as $v) {
            $agenda['termine'][] = [
                'time' => substr($v['viewing_time'] ?? '', 0, 5),
                'kind' => 'viewing',
                'text' => 'Besichtigung ' . ($v['property_ref'] ?? '?') . ' · ' . ($v['person_name'] ?? ''),
                'property_id' => $v['property_id'] ?? null,
            ];
        }
        foreach ($context['tasks_due'] as $t) {
            if (!empty($t['due_date'])) {
                try {
                    $time = Carbon::parse($t['due_date'])->format('H:i');
                } catch (\Throwable $e) {
                    $time = '--:--';
                }
                $agenda['termine'][] = [
                    'time' => $time,
                    'kind' => 'task',
                    'text' => $t['title'],
                    'task_id' => $t['id'],
                ];
            } else {
                $agenda['offen'][] = [
                    'kind' => 'task',
                    'label' => 'fällig',
                    'text' => $t['title'],
                ];
            }
        }

        $nf = $context['nachfass_outcome'];
        if ($nf['sent'] > 0) {
            $agenda['offen'][] = [
                'kind' => 'nachfass',
                'label' => 'laufend',
                'text' => $nf['sent'] . ' Nachfass-Mails der letzten 48h',
            ];
        }

        usort($agenda['termine'], fn($a, $b) => strcmp($a['time'], $b['time']));

        return $agenda;
    }
}
