<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\KaufanbotHelper;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BriefingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Multi-User: broker_id des eingeloggten Users
        $brokerId = \Auth::id();
        $brokerFilter = $brokerId ? "AND p.broker_id = {$brokerId}" : "";
        $brokerFilterDirect = $brokerId ? "AND property_id IN (SELECT id FROM properties WHERE broker_id = {$brokerId})" : "";
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $weekAgo   = date('Y-m-d', strtotime('-7 days'));

        $norm  = StakeholderHelper::normSH('a.stakeholder');
        $norm2 = StakeholderHelper::normSH('a2.stakeholder');
        $sysFilter = StakeholderHelper::systemStakeholderFilter('a.stakeholder');
        $normSurname = StakeholderHelper::normSHSurname('stakeholder');
        $partnerExclude = StakeholderHelper::partnerExcludeFilter('stakeholder');

        // 1. Neue Erstanfragen seit gestern
        $inquiries = DB::select("
            SELECT
                a.id, a.stakeholder as from_name, '' as from_email,
                a.activity as subject, a.result as ai_summary,
                a.activity_date as email_date, a.category,
                a.property_id, p.address, p.city, p.ref_id
            FROM activities a
            LEFT JOIN properties p ON a.property_id = p.id
            WHERE a.category IN ('anfrage', 'email-in') {$brokerFilter}
              AND a.activity_date >= ?
              AND a.id = (
                  SELECT MIN(a2.id) FROM activities a2
                  WHERE {$norm2} = {$norm}
                    AND a2.property_id = a.property_id
                    AND a2.category IN ('anfrage', 'email-in')
              )
            ORDER BY a.activity_date DESC
        ", [$yesterday . ' 00:00:00']);

        // 2. Nachfass-Fälle
        $overdue = DB::select("
            SELECT
                conv.last_id as id,
                conv.display_name as from_name,
                '' as from_email,
                conv.last_activity as subject,
                conv.last_result as ai_summary,
                conv.last_date as email_date,
                conv.last_category as category,
                conv.property_id,
                p.address, p.city, p.ref_id,
                DATEDIFF(NOW(), conv.last_date) as days_waiting,
                c.phone as contact_phone
            FROM (
                SELECT
                    {$norm} as norm_name,
                    MAX(a.stakeholder) as display_name,
                    a.property_id,
                    MAX(a.activity_date) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.id ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC), ',', 1) as last_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC), ',', 1) as last_category,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.activity ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC SEPARATOR '|||'), '|||', 1) as last_activity,
                    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(a.result,'') ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC SEPARATOR '|||'), '|||', 1) as last_result
                FROM activities a
                WHERE {$sysFilter}
                GROUP BY norm_name, a.property_id
            ) conv
            LEFT JOIN properties p ON conv.property_id = p.id
            LEFT JOIN contacts c ON c.full_name COLLATE utf8mb4_unicode_ci = conv.display_name COLLATE utf8mb4_unicode_ci
            WHERE conv.last_category IN ('email-out', 'expose') {$brokerFilter}
              AND DATEDIFF(NOW(), conv.last_date) >= 3
            ORDER BY DATEDIFF(NOW(), conv.last_date) DESC
            LIMIT 10
        ");

        // 3. Heutige Besichtigungen
        $viewingsToday = [];
        try {
            $viewingsToday = DB::select("
                SELECT v.*, p.address, p.city, p.ref_id
                FROM viewings v
                JOIN properties p ON p.id = v.property_id
                WHERE v.viewing_date = ? {$brokerFilter}
                  AND v.status IN ('geplant', 'bestaetigt')
                ORDER BY v.viewing_time ASC
            ", [$today]);
        } catch (\Exception $e) {
            // viewings table may not exist
        }

        // 4. Hot Leads
        $leads = DB::select("
            SELECT sub.* FROM (
                SELECT
                    a.stakeholder, '' as from_email,
                    COUNT(*) as email_count,
                    MAX(a.activity_date) as last_contact,
                    GROUP_CONCAT(DISTINCT a.category) as categories,
                    GROUP_CONCAT(DISTINCT p.ref_id) as properties
                FROM activities a
                LEFT JOIN properties p ON a.property_id = p.id
                WHERE a.activity_date >= ? {$brokerFilter}
                GROUP BY a.stakeholder
                HAVING email_count >= 3
            ) sub
            ORDER BY
                CASE WHEN sub.categories LIKE '%kaufanbot%' THEN 0
                     WHEN sub.categories LIKE '%besichtigung%' THEN 1
                     ELSE 2
                END,
                sub.email_count DESC
            LIMIT 10
        ", [$weekAgo]);

        // 5. Statistiken
        $normSurnameStakeholder = StakeholderHelper::normSHSurname('stakeholder');
        $partnerExcludeStakeholder = StakeholderHelper::partnerExcludeFilter('stakeholder');

        $statsData = (array) DB::selectOne("
            SELECT
                (SELECT COUNT(DISTINCT CONCAT(stakeholder,'|',property_id))
                 FROM activities WHERE category IN ('anfrage', 'email-in')
                 AND activity_date >= ?
                 {$brokerFilterDirect}
                 AND id = (SELECT MIN(id) FROM activities a2
                           WHERE a2.stakeholder = activities.stakeholder
                           AND a2.property_id = activities.property_id
                           AND a2.category IN ('anfrage', 'email-in'))
                ) as new_24h,
                (SELECT COUNT(*) FROM activities
                 WHERE category='besichtigung' AND activity_date >= ? {$brokerFilterDirect}) as viewing_requests_week,
                (SELECT COUNT(DISTINCT CONCAT({$normSurnameStakeholder},'|',COALESCE(property_id,0)))
                 FROM activities WHERE category='kaufanbot' AND {$partnerExcludeStakeholder} AND activity_date >= ? {$brokerFilterDirect}) as offers_week,
                (SELECT COUNT(*) FROM activities
                 WHERE category='absage' AND activity_date >= ? {$brokerFilterDirect}) as cancellations_week,
                (SELECT COUNT(*) FROM activities
                 WHERE category='expose' AND activity_date >= ? {$brokerFilterDirect}) as exposes_week
        ", [
            $yesterday . ' 00:00:00',
            $weekAgo, $weekAgo, $weekAgo, $weekAgo,
        ]);

        // Delta: Vorwoche
        $twoWeeksAgo = date('Y-m-d', strtotime('-14 days'));
        $prev = (array) DB::selectOne("
            SELECT
                (SELECT COUNT(*) FROM activities WHERE category='besichtigung'
                 AND activity_date >= ? AND activity_date < ? {$brokerFilterDirect}) as prev_viewings,
                (SELECT COUNT(DISTINCT CONCAT({$normSurnameStakeholder},'|',COALESCE(property_id,0)))
                 FROM activities WHERE category='kaufanbot' AND {$partnerExcludeStakeholder}
                 AND activity_date >= ? AND activity_date < ? {$brokerFilterDirect}) as prev_offers,
                (SELECT COUNT(*) FROM activities WHERE category='absage'
                 AND activity_date >= ? AND activity_date < ? {$brokerFilterDirect}) as prev_cancellations
        ", [$twoWeeksAgo, $weekAgo, $twoWeeksAgo, $weekAgo, $twoWeeksAgo, $weekAgo]);

        $statsData['prev_viewings_week'] = (int)($prev['prev_viewings'] ?? 0);
        $statsData['prev_offers_week']   = (int)($prev['prev_offers'] ?? 0);

        // Antwortquote 24h
        $totalIn = (int) DB::selectOne("
            SELECT COUNT(DISTINCT CONCAT(stakeholder,'|',property_id)) as total
            FROM activities WHERE category IN ('anfrage','email-in') AND activity_date >= ? {$brokerFilterDirect}
        ", [$yesterday . ' 00:00:00'])->total;

        $answeredCount = (int) DB::selectOne("
            SELECT COUNT(DISTINCT CONCAT(a.stakeholder,'|',a.property_id)) as answered
            FROM activities a
            WHERE a.category IN ('anfrage','email-in') AND a.activity_date >= ?
            {$brokerFilterDirect}
            AND EXISTS (
                SELECT 1 FROM activities a2
                WHERE a2.property_id = a.property_id
                AND a2.category IN ('email-out','expose')
                AND a2.activity_date >= a.activity_date
            )
        ", [$yesterday . ' 00:00:00'])->answered;

        $statsData['answer_rate_answered'] = $answeredCount;
        $statsData['answer_rate_total']    = $totalIn;

        // Objekte ohne Aktivität 7 Tage
        $inactiveProps = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM properties p
            WHERE p.realty_status NOT IN ('verkauft')
            " . ($brokerId ? "AND p.broker_id = {$brokerId}" : "") . "
            AND NOT EXISTS (
                SELECT 1 FROM activities a WHERE a.property_id = p.id AND a.activity_date >= ?
            )
        ", [$weekAgo])->cnt;
        $statsData['inactive_properties_7d'] = $inactiveProps;

        // Offene Kaufanbote
        $normS  = StakeholderHelper::normSHSurname('a.stakeholder');
        $normS2 = StakeholderHelper::normSHSurname('a2.stakeholder');
        $partnerA = StakeholderHelper::partnerExcludeFilter('a.stakeholder');

        $openOffers = (int) DB::selectOne("
            SELECT COUNT(DISTINCT CONCAT({$normS},'|',COALESCE(a.property_id,0))) as cnt FROM activities a
            WHERE a.category = 'kaufanbot' AND {$partnerA}
            AND NOT EXISTS (
                SELECT 1 FROM activities a2
                WHERE a2.property_id = a.property_id
                AND {$normS2} = {$normS}
                AND a2.category = 'absage'
                AND a2.activity_date > a.activity_date
            )
            AND a.property_id IN (SELECT id FROM properties WHERE status NOT IN ('verkauft')" . ($brokerId ? " AND broker_id = {$brokerId}" : "") . ")
        ")->cnt;
        $statsData['open_offers'] = $openOffers;

        // Nachfass count
        $openConvCount = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM (
                SELECT {$norm} as norm_name, a.property_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC), ',', 1) as last_cat,
                    MAX(a.activity_date) as last_date
                FROM activities a
                WHERE {$sysFilter} {$brokerFilterDirect}
                GROUP BY norm_name, a.property_id
                HAVING last_cat IN ('email-out', 'expose') AND DATEDIFF(NOW(), last_date) >= 3
            ) sub
        ")->cnt;

        $statsData['emails_today']        = $statsData['new_24h'];
        $statsData['open_conversations']  = $openConvCount;

        // Property Momentum
        $normSurnameStakeholder2 = StakeholderHelper::normSHSurname('stakeholder');
        $partnerExcludeStakeholder2 = StakeholderHelper::partnerExcludeFilter('stakeholder');

        $momentum = DB::select("
            SELECT p.id, p.ref_id, p.address, p.city, p.realty_status,
                DATEDIFF(NOW(), p.inserat_since) as days_on_market,
                (SELECT MAX(activity_date) FROM activities WHERE property_id = p.id) as last_activity,
                DATEDIFF(NOW(), (SELECT MAX(activity_date) FROM activities WHERE property_id = p.id)) as days_since_activity,
                (SELECT COUNT(DISTINCT CASE WHEN category IN ('anfrage','email-in') THEN stakeholder END) FROM activities WHERE property_id = p.id) as leads,
                (SELECT COUNT(*) FROM activities WHERE property_id = p.id AND category IN ('anfrage','email-in')
                 AND activity_date >= ?) as inquiries_7d,
                (SELECT SUM(category IN ('email-out','expose')) FROM activities WHERE property_id = p.id) as outbound,
                (SELECT SUM(category='besichtigung') FROM activities WHERE property_id = p.id) as viewings,
                (SELECT COUNT(DISTINCT {$normSurnameStakeholder2}) FROM activities WHERE property_id = p.id AND category='kaufanbot' AND {$partnerExcludeStakeholder2}) as offers
            FROM properties p
            WHERE p.realty_status NOT IN ('verkauft')
            " . ($brokerId ? "AND p.broker_id = {$brokerId}" : "") . "
            ORDER BY (SELECT MAX(activity_date) FROM activities WHERE property_id = p.id) ASC
            LIMIT 8
        ", [$weekAgo]);

        // Override offers per property with KaufanbotHelper
        $kaufanbotMap = KaufanbotHelper::countByProperty($brokerId ?: null);
        $momentum = array_map(function ($m) use ($kaufanbotMap) {
            $m = (array) $m;
            $m['offers'] = $kaufanbotMap[$m['id']] ?? 0;
            $score = 50;
            $mLeads = (int)($m['leads'] ?? 0);
            $mOut   = (int)($m['outbound'] ?? 0);
            $mDom   = (int)($m['days_on_market'] ?? 0);

            if ($mLeads > 15) $score += 15;
            elseif ($mLeads > 8) $score += 10;
            elseif ($mLeads > 3) $score += 5;
            elseif ($mLeads < 2) $score -= 10;

            if ($mOut > 0 && $mLeads > 0) {
                $ratio = $mOut / $mLeads;
                if ($ratio > 1.0) $score += 10;
                elseif ($ratio < 0.5) $score -= 10;
            }

            if ((int)($m['offers'] ?? 0) > 0) $score += 15;
            if ((int)($m['viewings'] ?? 0) > 3) $score += 10;
            if ($mDom > 90) $score -= 15;
            elseif ($mDom > 60) $score -= 5;

            $m['health_score'] = max(0, min(100, $score));
            return $m;
        }, $momentum);

        usort($momentum, fn($a, $b) => $a['health_score'] - $b['health_score']);
        $momentum = array_slice($momentum, 0, 5);

        return response()->json([
            'date'              => $today,
            'stats'             => $statsData,
            'new_inquiries'     => $inquiries,
            'overdue_followups' => $overdue,
            'viewings_today'    => $viewingsToday,
            'hot_leads'         => $leads,
            'property_momentum' => $momentum,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
