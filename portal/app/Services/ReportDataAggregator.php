<?php

namespace App\Services;

use App\Helpers\KaufanbotHelper;
use Illuminate\Support\Facades\DB;

class ReportDataAggregator
{
    /**
     * Gather ALL data for the Vermarktungsbericht.
     */
    public function gather(int $propertyId): array
    {
        return [
            'property'   => $this->getPropertyData($propertyId),
            'timeline'   => $this->getActivityTimeline($propertyId),
            'funnel'     => $this->calculateFunnel($propertyId),
            'temporal'   => $this->getTemporalAnalysis($propertyId),
            'feedback'   => $this->clusterFeedback($propertyId),
            'viewings'   => $this->getViewingsData($propertyId),
            'kaufanbote' => $this->getKaufanboteData($propertyId),
            'knowledge'  => $this->getKnowledgeEntries($propertyId),
            'leads'      => $this->getLeadQuality($propertyId),
            'emails'     => $this->getEmailInsights($propertyId),
            'market'     => $this->getMarketContext(),
        ];
    }

    /**
     * Property master data + derived values.
     */
    private function getPropertyData(int $propertyId): array
    {
        $row = DB::selectOne("
            SELECT p.*, c.name AS owner_name, c.email AS owner_email, c.phone AS owner_phone,
                DATEDIFF(NOW(), p.inserat_since) AS days_on_market,
                ROUND(p.price / NULLIF(p.total_area, 0)) AS price_per_m2
            FROM properties p
            LEFT JOIN customers c ON c.id = p.customer_id
            WHERE p.id = ?
        ", [$propertyId]);

        return $row ? (array) $row : [];
    }

    /**
     * ALL activities chronologically.
     */
    private function getActivityTimeline(int $propertyId): array
    {
        $rows = DB::select("
            SELECT a.id, a.activity_date, a.stakeholder, a.activity, a.result,
                   a.category, a.duration, a.source_email_id,
                   pe.ai_summary AS email_summary, pe.from_email
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.property_id = ?
            ORDER BY a.activity_date ASC
        ", [$propertyId]);

        return array_map(fn($r) => (array) $r, $rows);
    }

    /**
     * Conversion funnel: Anfrage -> Expose -> Besichtigung -> Kaufanbot
     */
    private function calculateFunnel(int $propertyId): array
    {
        $counts = (array) DB::selectOne("
            SELECT
                COUNT(*) AS total,
                SUM(category IN ('anfrage','email-in')) AS anfragen,
                SUM(category = 'expose') AS exposes,
                SUM(category = 'besichtigung') AS besichtigungen,
                SUM(category = 'kaufanbot') AS kaufanbote,
                SUM(category = 'absage') AS absagen,
                SUM(category = 'nachfassen') AS nachfassen,
                COUNT(DISTINCT CASE WHEN category IN ('anfrage','email-in') THEN stakeholder END) AS unique_anfragen,
                COUNT(DISTINCT CASE WHEN category = 'expose' THEN stakeholder END) AS unique_exposes,
                COUNT(DISTINCT CASE WHEN category = 'besichtigung' THEN stakeholder END) AS unique_besichtigungen,
                COUNT(DISTINCT CASE WHEN category = 'kaufanbot' THEN stakeholder END) AS unique_kaufanbote,
                COUNT(DISTINCT CASE WHEN category = 'absage' THEN stakeholder END) AS unique_absagen
            FROM activities
            WHERE property_id = ?
        ", [$propertyId]);

        // Override kaufanbote with KaufanbotHelper
        $counts['kaufanbote'] = KaufanbotHelper::count($propertyId);
        $counts['unique_kaufanbote'] = KaufanbotHelper::count($propertyId);

        $anfragen = max(1, (int) $counts['unique_anfragen']);
        $exposes = (int) $counts['unique_exposes'];
        $besichtigungen = (int) $counts['unique_besichtigungen'];
        $kaufanbote = (int) $counts['unique_kaufanbote'];

        return [
            'counts' => $counts,
            'rates' => [
                'anfrage_to_expose'   => round($exposes / $anfragen * 100, 1),
                'expose_to_viewing'   => $exposes > 0 ? round($besichtigungen / $exposes * 100, 1) : 0,
                'viewing_to_offer'    => $besichtigungen > 0 ? round($kaufanbote / $besichtigungen * 100, 1) : 0,
                'anfrage_to_absage'   => round((int) $counts['unique_absagen'] / $anfragen * 100, 1),
            ],
        ];
    }

    /**
     * Weekly buckets for trend analysis.
     */
    private function getTemporalAnalysis(int $propertyId): array
    {
        $weeks = DB::select("
            SELECT
                YEARWEEK(activity_date, 1) AS week,
                MIN(activity_date) AS week_start,
                COUNT(*) AS total,
                SUM(category IN ('anfrage','email-in')) AS inbound,
                SUM(category IN ('email-out','expose','nachfassen')) AS outbound,
                SUM(category = 'besichtigung') AS viewings,
                SUM(category = 'kaufanbot') AS kaufanbote,
                SUM(category = 'absage') AS absagen,
                COUNT(DISTINCT stakeholder) AS unique_contacts
            FROM activities
            WHERE property_id = ?
            GROUP BY YEARWEEK(activity_date, 1)
            ORDER BY week
        ", [$propertyId]);

        $weekData = array_map(fn($w) => (array) $w, $weeks);

        // Trend: compare last 2 weeks vs 2 weeks before
        $trend = 'stabil';
        $cnt = count($weekData);
        if ($cnt >= 4) {
            $recent = ($weekData[$cnt-1]['inbound'] ?? 0) + ($weekData[$cnt-2]['inbound'] ?? 0);
            $earlier = ($weekData[$cnt-3]['inbound'] ?? 0) + ($weekData[$cnt-4]['inbound'] ?? 0);
            if ($earlier > 0) {
                $change = ($recent - $earlier) / $earlier;
                if ($change > 0.3) $trend = 'steigend';
                elseif ($change < -0.3) $trend = 'fallend';
            }
        }

        return [
            'weeks' => $weekData,
            'trend' => $trend,
            'total_weeks' => $cnt,
        ];
    }

    /**
     * Cluster feedback from activities, emails, and knowledge base.
     */
    private function clusterFeedback(int $propertyId): array
    {
        // 1. Activities: absage, feedback_negativ, feedback_besichtigung
        $actFeedback = DB::select("
            SELECT a.stakeholder, a.activity, a.result, a.category, a.activity_date,
                   pe.ai_summary AS email_summary
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.property_id = ?
              AND a.category IN ('absage','feedback_negativ','feedback_besichtigung','feedback_positiv')
            ORDER BY a.activity_date DESC
        ", [$propertyId]);

        // 2. Portal emails with category=absage (exclude those already captured via activities)
        $emailFeedback = DB::select("
            SELECT stakeholder, ai_summary, body_text, email_date, category
            FROM portal_emails
            WHERE property_id = ? AND category IN ('absage','feedback')
              AND id NOT IN (SELECT source_email_id FROM activities WHERE property_id = ? AND source_email_id IS NOT NULL)
            ORDER BY email_date DESC
        ", [$propertyId, $propertyId]);

        // 3. Knowledge base feedback entries
        $kbFeedback = DB::select("
            SELECT title, content, category, confidence
            FROM property_knowledge
            WHERE property_id = ? AND category IN ('feedback_positiv','feedback_negativ','feedback_besichtigung','preis_markt')
              AND is_active = 1
            ORDER BY created_at DESC
        ", [$propertyId]);

        // Keyword-based clustering
        $clusterDefs = [
            'preis' => ['preis','teuer','zu hoch','ueberteuert','preislich','budget','kostet','guenstig','preis-leistung'],
            'lage' => ['lage','standort','verkehr','anbindung','infrastruktur','umgebung','entfernung','laerm','strasse'],
            'zustand' => ['zustand','renovier','sanier','modernisier','renovierungsbed','sanierungsbed','alt','abgenutzt'],
            'grundriss' => ['grundriss','aufteilung','raumaufteilung','zimmer','zu klein','zu gross','schnitt','raumgroesse'],
            'betriebskosten' => ['betriebskosten','hausgeld','heizung','energie','nebenkosten','heizkosten','warmwasser'],
            'aussenbereich' => ['garten','terrasse','balkon','freifla','stellplatz','garage','parkplatz','keller'],
            'finanzierung' => ['finanzier','kredit','bank','eigenkapital','foerder','leistbar'],
            'sonstiges' => [],
        ];

        $clusters = [];
        foreach ($clusterDefs as $key => $_) {
            $clusters[$key] = ['items' => [], 'count' => 0];
        }

        // Cluster all feedback items (deduplicate by stakeholder+category)
        $allItems = [];
        $seenFeedback = [];

        foreach ($actFeedback as $item) {
            $dedupeKey = mb_strtolower(trim($item->stakeholder ?? '')) . '|' . ($item->category ?? '');
            if (isset($seenFeedback[$dedupeKey])) continue;
            $seenFeedback[$dedupeKey] = true;
            $allItems[] = [
                'source' => 'activity',
                'text' => mb_strtolower(($item->result ?? '') . ' ' . ($item->activity ?? '') . ' ' . ($item->email_summary ?? '')),
                'stakeholder' => $item->stakeholder,
                'date' => $item->activity_date,
                'category' => $item->category,
                'summary' => $item->email_summary ?: $item->result ?: $item->activity,
            ];
        }

        foreach ($emailFeedback as $item) {
            $dedupeKey = mb_strtolower(trim($item->stakeholder ?? '')) . '|' . ($item->category ?? '');
            if (isset($seenFeedback[$dedupeKey])) continue;
            $seenFeedback[$dedupeKey] = true;
            $allItems[] = [
                'source' => 'email',
                'text' => mb_strtolower(($item->ai_summary ?? '') . ' ' . mb_substr($item->body_text ?? '', 0, 500)),
                'stakeholder' => $item->stakeholder,
                'date' => $item->email_date,
                'category' => $item->category,
                'summary' => $item->ai_summary ?: mb_substr($item->body_text ?? '', 0, 200),
            ];
        }

        foreach ($kbFeedback as $item) {
            $allItems[] = [
                'source' => 'knowledge',
                'text' => mb_strtolower($item->title . ' ' . $item->content),
                'stakeholder' => null,
                'date' => null,
                'category' => $item->category,
                'summary' => $item->title . ': ' . mb_substr($item->content, 0, 200),
            ];
        }

        foreach ($allItems as $item) {
            $matched = false;
            foreach ($clusterDefs as $clusterKey => $keywords) {
                if ($clusterKey === 'sonstiges') continue;
                foreach ($keywords as $kw) {
                    if (mb_strpos($item['text'], $kw) !== false) {
                        $clusters[$clusterKey]['items'][] = $item;
                        $clusters[$clusterKey]['count']++;
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) {
                $clusters['sonstiges']['items'][] = $item;
                $clusters['sonstiges']['count']++;
            }
        }

        // Consolidate items per stakeholder within each cluster
        foreach ($clusters as $clusterKey => &$cluster) {
            if (empty($cluster['items'])) continue;
            $byPerson = [];
            foreach ($cluster['items'] as $item) {
                $name = mb_strtolower(trim($item['stakeholder'] ?? 'unbekannt'));
                if (!isset($byPerson[$name])) {
                    $byPerson[$name] = [
                        'stakeholder' => $item['stakeholder'] ?: 'Unbekannt',
                        'date' => $item['date'],
                        'category' => $item['category'],
                        'summaries' => [],
                        'source' => $item['source'],
                    ];
                }
                if ($item['date'] && (!$byPerson[$name]['date'] || $item['date'] > $byPerson[$name]['date'])) {
                    $byPerson[$name]['date'] = $item['date'];
                }
                $summaryText = trim($item['summary'] ?? '');
                if ($summaryText && !in_array($summaryText, $byPerson[$name]['summaries'])) {
                    $byPerson[$name]['summaries'][] = $summaryText;
                }
            }
            $consolidated = [];
            foreach ($byPerson as $person) {
                $consolidated[] = [
                    'stakeholder' => $person['stakeholder'],
                    'date' => $person['date'],
                    'category' => $person['category'],
                    'source' => $person['source'],
                    'summary' => implode(' | ', array_slice($person['summaries'], 0, 3)),
                    'text' => mb_strtolower(implode(' ', $person['summaries'])),
                ];
            }
            $cluster['items'] = $consolidated;
            $cluster['count'] = count($consolidated);
        }
        unset($cluster);

        // Weight: transaktionskritisch / substanziell / sekundaer
        $weights = [
            'preis' => 'transaktionskritisch',
            'lage' => 'substanziell',
            'zustand' => 'substanziell',
            'grundriss' => 'substanziell',
            'betriebskosten' => 'sekundaer',
            'aussenbereich' => 'sekundaer',
            'finanzierung' => 'transaktionskritisch',
            'sonstiges' => 'sekundaer',
        ];

        $result = [];
        foreach ($clusters as $key => $data) {
            if ($data['count'] > 0) {
                // Limit items to summaries only (save tokens)
                $summaries = array_map(fn($i) => [
                    'summary' => $i['summary'],
                    'stakeholder' => $i['stakeholder'],
                    'date' => $i['date'],
                ], array_slice($data['items'], 0, 10));

                $result[] = [
                    'thema' => $key,
                    'anzahl' => $data['count'],
                    'gewicht' => $weights[$key] ?? 'sekundaer',
                    'items' => $summaries,
                ];
            }
        }

        // Sort by weight (transaktionskritisch first) then count
        usort($result, function ($a, $b) {
            $wOrder = ['transaktionskritisch' => 0, 'substanziell' => 1, 'sekundaer' => 2];
            $wa = $wOrder[$a['gewicht']] ?? 3;
            $wb = $wOrder[$b['gewicht']] ?? 3;
            if ($wa !== $wb) return $wa - $wb;
            return $b['anzahl'] - $a['anzahl'];
        });

        return [
            'clusters' => $result,
            'total_feedback_items' => count($allItems),
        ];
    }

    /**
     * Viewings with status and notes.
     */
    private function getViewingsData(int $propertyId): array
    {
        $viewings = DB::select("
            SELECT id, viewing_date, viewing_time, person_name, person_email, person_phone,
                   status, notes, calendar_event_id
            FROM viewings
            WHERE property_id = ?
            ORDER BY viewing_date DESC
        ", [$propertyId]);

        $stats = (array) DB::selectOne("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'durchgefuehrt') AS done,
                SUM(status IN ('geplant','bestaetigt')) AS upcoming,
                SUM(status = 'abgesagt') AS cancelled
            FROM viewings WHERE property_id = ?
        ", [$propertyId]);

        return [
            'list' => array_map(fn($v) => (array) $v, $viewings),
            'stats' => $stats,
        ];
    }

    /**
     * Kaufanbote (offers) data.
     */
    private function getKaufanboteData(int $propertyId): array
    {
        // Check if property has units (Neubauprojekt)
        $units = DB::select("
            SELECT id, unit_number, unit_label, unit_type, area_m2, price, status,
                   buyer_name, buyer_email, commission_total
            FROM property_units
            WHERE property_id = ? AND is_parking = 0
            ORDER BY unit_number
        ", [$propertyId]);

        // Kaufanbot activities
        $kaufanbote = DB::select("
            SELECT a.activity_date, a.stakeholder, a.activity, a.result,
                   pe.ai_summary AS email_summary
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.property_id = ? AND a.category = 'kaufanbot'
            ORDER BY a.activity_date DESC
        ", [$propertyId]);

        return [
            'kaufanbote' => array_map(fn($k) => (array) $k, $kaufanbote),
            'units' => array_map(fn($u) => (array) $u, $units),
            'total_kaufanbote' => KaufanbotHelper::count($propertyId),
            'total_units' => count($units),
            'verkaufte_units' => count(array_filter($units, fn($u) => $u->status === 'verkauft')),
        ];
    }

    /**
     * Knowledge base entries by category.
     */
    private function getKnowledgeEntries(int $propertyId): array
    {
        $entries = DB::select("
            SELECT id, category, title, content, confidence, is_verified, source_type, mention_count
            FROM property_knowledge
            WHERE property_id = ? AND is_active = 1
            ORDER BY category, mention_count DESC
        ", [$propertyId]);

        $grouped = [];
        foreach ($entries as $e) {
            $cat = $e->category;
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = (array) $e;
        }

        return [
            'by_category' => $grouped,
            'total' => count($entries),
        ];
    }

    /**
     * Lead quality: unique stakeholders with progression score.
     */
    private function getLeadQuality(int $propertyId): array
    {
        $leads = DB::select("
            SELECT
                a.stakeholder,
                COUNT(*) AS total_activities,
                MIN(a.activity_date) AS first_contact,
                MAX(a.activity_date) AS last_contact,
                DATEDIFF(MAX(a.activity_date), MIN(a.activity_date)) AS engagement_days,
                GROUP_CONCAT(DISTINCT a.category ORDER BY a.activity_date) AS categories,
                SUM(a.category IN ('anfrage','email-in')) AS inbound_count,
                SUM(a.category IN ('email-out','expose','nachfassen')) AS outbound_count,
                SUM(a.category = 'besichtigung') AS viewings,
                SUM(a.category = 'kaufanbot') AS offers,
                SUM(a.category = 'absage') AS absagen,
                c.phone, c.email AS contact_email
            FROM activities a
            LEFT JOIN contacts c ON c.full_name COLLATE utf8mb4_unicode_ci = a.stakeholder COLLATE utf8mb4_unicode_ci
            WHERE a.property_id = ? AND a.stakeholder != '' AND a.stakeholder != 'SR-Homes'
            GROUP BY a.stakeholder, c.phone, c.email
            ORDER BY last_contact DESC
        ", [$propertyId]);

        $enriched = [];
        foreach ($leads as $lead) {
            $l = (array) $lead;

            // Progression score: 0-100
            $score = 0;
            if ($l['inbound_count'] > 0) $score += 15;
            if ($l['outbound_count'] > 0) $score += 10;
            if ($l['viewings'] > 0) $score += 30;
            if ($l['offers'] > 0) $score += 40;
            if ($l['absagen'] > 0) $score -= 20;
            if ($l['engagement_days'] > 7) $score += 5;

            $l['progression_score'] = max(0, min(100, $score));

            // Status
            if ($l['absagen'] > 0) $l['status'] = 'abgesagt';
            elseif ($l['offers'] > 0) $l['status'] = 'kaufanbot';
            elseif ($l['viewings'] > 0) $l['status'] = 'besichtigt';
            else $l['status'] = 'interessent';

            $enriched[] = $l;
        }

        // Sort by progression score desc
        usort($enriched, fn($a, $b) => $b['progression_score'] - $a['progression_score']);

        return [
            'leads' => $enriched,
            'total' => count($enriched),
            'active' => count(array_filter($enriched, fn($l) => $l['status'] !== 'abgesagt')),
            'with_viewing' => count(array_filter($enriched, fn($l) => $l['viewings'] > 0)),
            'with_offer' => KaufanbotHelper::count($propertyId),
        ];
    }

    /**
     * Email insights: AI summaries of important threads.
     */
    private function getEmailInsights(int $propertyId): array
    {
        // Recent emails with AI summaries
        $emails = DB::select("
            SELECT id, direction, from_name, from_email, to_email, subject,
                   ai_summary, email_date, category, stakeholder
            FROM portal_emails
            WHERE property_id = ?
              AND ai_summary IS NOT NULL AND ai_summary != ''
            ORDER BY email_date DESC
            LIMIT 30
        ", [$propertyId]);

        // Email stats
        $stats = (array) DB::selectOne("
            SELECT
                COUNT(*) AS total,
                SUM(direction = 'inbound') AS inbound,
                SUM(direction = 'outbound') AS outbound,
                MIN(email_date) AS first_email,
                MAX(email_date) AS last_email
            FROM portal_emails
            WHERE property_id = ?
        ", [$propertyId]);

        return [
            'summaries' => array_map(fn($e) => (array) $e, $emails),
            'stats' => $stats,
        ];
    }

    /**
     * Market context from market_data table.
     */
    private function getMarketContext(): array
    {
        $rows = DB::select("SELECT data_key, data_value, source, updated_at FROM market_data ORDER BY updated_at DESC");
        $data = [];
        foreach ($rows as $r) {
            $data[$r->data_key] = [
                'value' => json_decode($r->data_value, true) ?? $r->data_value,
                'source' => $r->source,
                'updated_at' => $r->updated_at,
            ];
        }
        return $data;
    }

    /**
     * Calculate data quality score for the report.
     */
    public function assessDataQuality(array $data): array
    {
        $score = 0;
        $missing = [];
        $total = 10;

        if (!empty($data['property']['purchase_price'])) $score++; else $missing[] = 'Preis';
        if (!empty($data['property']['total_area'])) $score++; else $missing[] = 'Wohnflaeche';
        if (count($data['timeline']) >= 5) $score++; else $missing[] = 'Ausreichend Aktivitaeten (min. 5)';
        if (($data['funnel']['counts']['anfragen'] ?? 0) >= 3) $score++; else $missing[] = 'Ausreichend Anfragen (min. 3)';
        if (!empty($data['viewings']['list'])) $score++; else $missing[] = 'Besichtigungsdaten';
        if (($data['feedback']['total_feedback_items'] ?? 0) > 0) $score++; else $missing[] = 'Feedback-Daten';
        if (($data['knowledge']['total'] ?? 0) > 0) $score++; else $missing[] = 'Knowledge-Base Eintraege';
        if (($data['emails']['stats']['total'] ?? 0) >= 5) $score++; else $missing[] = 'Ausreichend Emails (min. 5)';
        if (!empty($data['market'])) $score++; else $missing[] = 'Marktdaten';
        if (($data['leads']['total'] ?? 0) >= 3) $score++; else $missing[] = 'Ausreichend Leads (min. 3)';

        $quality = 'niedrig';
        if ($score >= 8) $quality = 'hoch';
        elseif ($score >= 5) $quality = 'mittel';

        return [
            'quality' => $quality,
            'score' => $score,
            'total' => $total,
            'missing' => $missing,
        ];
    }
}
