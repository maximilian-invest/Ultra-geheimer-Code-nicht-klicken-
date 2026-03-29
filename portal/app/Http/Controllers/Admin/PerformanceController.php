<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\KaufanbotHelper;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        $propFilter = $propertyId ? 'AND a.property_id = ?' : '';
        $propBindings = $propertyId ? [$propertyId] : [];
        // Multi-User: broker_id Scoping (skip for admin users)
        $brokerId = \Auth::id();
        $isAdmin = \Auth::user() && \Auth::user()->user_type === 'admin';
        $brokerFilterPerf = ($brokerId && !$isAdmin) ? "AND a.property_id IN (SELECT id FROM properties WHERE broker_id = ?)" : "";
        $brokerFilterSub  = ($brokerId && !$isAdmin) ? "AND property_id IN (SELECT id FROM properties WHERE broker_id = ?)" : "";
        $brokerBindings = ($brokerId && !$isAdmin) ? [$brokerId] : [];
        $propFilter .= " {$brokerFilterPerf}";
        // Subquery variant without "a." alias prefix
        $propFilterSub = $propertyId ? 'AND property_id = ?' : '';
        $propFilterSub .= " {$brokerFilterSub}";
        $norm  = StakeholderHelper::normSH('a.stakeholder');
        $norm2 = StakeholderHelper::normSH('a2.stakeholder');
        $normSurname = StakeholderHelper::normSHSurname('a.stakeholder');
        $partnerExclude = StakeholderHelper::partnerExcludeFilter('a.stakeholder');

        // Date range parameters (validated to prevent SQL injection)
        $dateFrom = $request->query('date_from', null);
        $dateTo   = $request->query('date_to', null);

        // Validate date formats (YYYY-MM-DD only)
        if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = null;
        if ($dateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = null;

        $dateBindings = [];

        // Build date filter clauses with parameterized bindings
        if ($dateFrom && $dateTo) {
            $platformDateFilter = "AND a.activity_date >= ? AND a.activity_date <= ?";
            $trendDateFilter    = "AND first_act.activity_date >= ? AND first_act.activity_date <= ?";
            $trendSubDateFilter = "AND activity_date >= ? AND activity_date <= ?";
            $funnelDateFilter   = "AND a.activity_date >= ? AND a.activity_date <= ?";
            $responseDateFilter = "AND a.activity_date >= ? AND a.activity_date <= ?";
            $dateBindings = ['platform' => [$dateFrom, $dateTo . ' 23:59:59'], 'trend' => [$dateFrom, $dateTo . ' 23:59:59'], 'trendSub' => [$dateFrom, $dateTo . ' 23:59:59'], 'funnel' => [$dateFrom, $dateTo . ' 23:59:59'], 'response' => [$dateFrom, $dateTo . ' 23:59:59']];
        } elseif ($dateFrom) {
            $platformDateFilter = "AND a.activity_date >= ?";
            $trendDateFilter    = "AND first_act.activity_date >= ?";
            $trendSubDateFilter = "AND activity_date >= ?";
            $funnelDateFilter   = "AND a.activity_date >= ?";
            $responseDateFilter = "AND a.activity_date >= ?";
            $dateBindings = ['platform' => [$dateFrom], 'trend' => [$dateFrom], 'trendSub' => [$dateFrom], 'funnel' => [$dateFrom], 'response' => [$dateFrom]];
        } elseif ($dateTo) {
            $platformDateFilter = "AND a.activity_date <= ?";
            $trendDateFilter    = "AND first_act.activity_date >= DATE_SUB(?, INTERVAL 8 WEEK) AND first_act.activity_date <= ?";
            $trendSubDateFilter = "AND activity_date >= DATE_SUB(?, INTERVAL 8 WEEK) AND activity_date <= ?";
            $funnelDateFilter   = "AND a.activity_date <= ?";
            $responseDateFilter = "AND a.activity_date <= ?";
            $dateBindings = ['platform' => [$dateTo . ' 23:59:59'], 'trend' => [$dateTo, $dateTo . ' 23:59:59'], 'trendSub' => [$dateTo, $dateTo . ' 23:59:59'], 'funnel' => [$dateTo . ' 23:59:59'], 'response' => [$dateTo . ' 23:59:59']];
        } else {
            $platformDateFilter = "AND a.activity_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $trendDateFilter    = "AND first_act.activity_date >= DATE_SUB(NOW(), INTERVAL 8 WEEK)";
            $trendSubDateFilter = "AND activity_date >= DATE_SUB(NOW(), INTERVAL 8 WEEK)";
            $funnelDateFilter   = "AND a.activity_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
            $responseDateFilter = "AND a.activity_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $dateBindings = ['platform' => [], 'trend' => [], 'trendSub' => [], 'funnel' => [], 'response' => []];
        }

        // Erstanfragen pro Plattform
        $platforms = DB::select("
            SELECT
                CASE
                    WHEN a.activity LIKE '%willhaben%' OR a.stakeholder LIKE '%willhaben%' OR pe.from_email LIKE '%willhaben%' THEN 'willhaben'
                    WHEN a.activity LIKE '%immowelt%' OR a.stakeholder LIKE '%immowelt%' OR pe.from_email LIKE '%immowelt%' THEN 'immowelt'
                    WHEN a.activity LIKE '%immoscout%' OR a.stakeholder LIKE '%immoscout%' OR pe.from_email LIKE '%immoscout%' THEN 'ImmobilienScout24'
                    WHEN pe.from_email LIKE '%typeform%' OR a.activity LIKE '%Typeform%' THEN 'Social Media'
                    WHEN a.activity LIKE '%the37.at%' OR a.activity LIKE '%sr-homes%' OR a.activity LIKE '%Homepage%' OR pe.from_email LIKE '%immoji%' THEN 'SR-Homes Website'
                    ELSE 'Direkt'
                END as platform,
                COUNT(*) as count
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.category IN ('anfrage', 'email-in')
              {$platformDateFilter}
              {$propFilter}
              AND a.id = (
                  SELECT MIN(a2.id) FROM activities a2
                  WHERE {$norm2} = {$norm}
                    AND a2.property_id = a.property_id
                    AND a2.category IN ('anfrage', 'email-in')
              )
            GROUP BY platform
            ORDER BY count DESC
        ", array_merge($dateBindings['platform'] ?? [], $propBindings, $brokerBindings));

        $totalInquiries = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM activities a
            WHERE a.category IN ('anfrage', 'email-in')
              {$platformDateFilter}
              {$propFilter}
              AND a.id = (
                  SELECT MIN(a2.id) FROM activities a2
                  WHERE {$norm2} = {$norm}
                    AND a2.property_id = a.property_id
                    AND a2.category IN ('anfrage', 'email-in')
              )
        ", array_merge($dateBindings['platform'] ?? [], $propBindings, $brokerBindings))->cnt;

        // Wöchentlicher Trend (8 Wochen)
        $trend = DB::select("
            SELECT
                YEARWEEK(first_act.activity_date, 1) as yw,
                DATE(MIN(first_act.activity_date)) as week_start,
                COUNT(*) as inquiries,
                (SELECT SUM(category='besichtigung') FROM activities
                 WHERE YEARWEEK(activity_date,1) = YEARWEEK(first_act.activity_date,1)
                 {$trendSubDateFilter} {$propFilterSub}) as viewing_requests,
                (SELECT SUM(category='kaufanbot') FROM activities
                 WHERE YEARWEEK(activity_date,1) = YEARWEEK(first_act.activity_date,1)
                 {$trendSubDateFilter} {$propFilterSub}) as offers,
                (SELECT SUM(category='absage') FROM activities
                 WHERE YEARWEEK(activity_date,1) = YEARWEEK(first_act.activity_date,1)
                 {$trendSubDateFilter} {$propFilterSub}) as cancellations,
                (SELECT SUM(category IN ('email-out','expose')) FROM activities
                 WHERE YEARWEEK(activity_date,1) = YEARWEEK(first_act.activity_date,1)
                 {$trendSubDateFilter} {$propFilterSub}) as outbound
            FROM activities first_act
            WHERE first_act.category IN ('anfrage', 'email-in')
              {$trendDateFilter}
              {$propFilter}
              AND first_act.id = (
                  SELECT MIN(a2.id) FROM activities a2
                  WHERE a2.stakeholder = first_act.stakeholder
                    AND a2.property_id = first_act.property_id
                    AND a2.category IN ('anfrage', 'email-in')
              )
            GROUP BY yw ORDER BY yw ASC
        ", array_merge(
            $dateBindings['trendSub'] ?? [], $propBindings, $brokerBindings,  // viewing_requests subquery
            $dateBindings['trendSub'] ?? [], $propBindings, $brokerBindings,  // offers subquery
            $dateBindings['trendSub'] ?? [], $propBindings, $brokerBindings,  // cancellations subquery
            $dateBindings['trendSub'] ?? [], $propBindings, $brokerBindings,  // outbound subquery
            $dateBindings['trend'] ?? [], $propBindings, $brokerBindings      // main WHERE
        ));

        // Conversion-Funnel
        $funnel = (array) DB::selectOne("
            SELECT
                COUNT(DISTINCT CASE WHEN a.category IN ('anfrage','email-in') THEN CONCAT({$norm},'|',a.property_id) END) as total_leads,
                COUNT(DISTINCT CASE WHEN a.category='besichtigung' THEN CONCAT({$norm},'|',a.property_id) END) as viewing_requests,
                COUNT(DISTINCT CASE WHEN a.category='kaufanbot' AND {$partnerExclude} THEN CONCAT({$normSurname},'|',a.property_id) END) as offers,
                COUNT(DISTINCT CASE WHEN a.category='absage' THEN CONCAT({$norm},'|',a.property_id) END) as cancellations
            FROM activities a
            WHERE 1=1 {$funnelDateFilter} {$propFilter}
        ", array_merge($dateBindings['funnel'] ?? [], $propBindings, $brokerBindings));

        // Override funnel offers with KaufanbotHelper
        if ($propertyId) {
            $funnel['offers'] = KaufanbotHelper::count($propertyId);
        } else {
            $effectiveBrokerId = ($brokerId && !$isAdmin) ? $brokerId : null;
            $funnel['offers'] = KaufanbotHelper::countAll($effectiveBrokerId);
        }

        try {
            $viewingsDone = (int) DB::selectOne(
                "SELECT COUNT(*) as cnt FROM viewings WHERE status='durchgefuehrt'"
                . ($propertyId ? ' AND property_id=?' : ''), $propBindings
            )->cnt;
            $funnel['viewings_done'] = $viewingsDone;
        } catch (\Exception $e) {
            $funnel['viewings_done'] = 0;
        }

        // Antwortzeit
        $avgResponse = (array) DB::selectOne("
            SELECT AVG(response_hours) as avg_hours FROM (
                SELECT
                    TIMESTAMPDIFF(HOUR, a.activity_date, MIN(a2.activity_date)) as response_hours
                FROM activities a
                JOIN activities a2 ON a2.property_id = a.property_id
                    AND {$norm2} = {$norm}
                    AND a2.activity_date > a.activity_date
                    AND a2.category NOT IN ('anfrage','email-in')
                WHERE a.category IN ('anfrage','email-in')
                  {$responseDateFilter} {$propFilter}
                GROUP BY a.id
            ) sub
            WHERE response_hours IS NOT NULL AND response_hours < 720
        ", array_merge($dateBindings['response'] ?? [], $propBindings, $brokerBindings));

        return response()->json([
            'total_inquiries'    => $totalInquiries,
            'platforms'          => $platforms,
            'weekly_trend'       => $trend,
            'funnel'             => $funnel,
            'avg_response_hours' => round((float)($avgResponse['avg_hours'] ?? 0), 1),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
