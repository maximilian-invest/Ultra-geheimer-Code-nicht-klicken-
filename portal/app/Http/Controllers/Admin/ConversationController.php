<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $propertyId  = intval($request->query('property_id', 0));
        // Multi-User: broker_id Scoping (assistenz sees all)
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $brokerConvFilter = ($brokerId && $userType !== 'assistenz') ? "AND a.property_id IN (SELECT id FROM properties WHERE broker_id = {$brokerId})" : "";
        $stakeholder = $request->query('stakeholder', '');
        $page    = max(1, intval($request->query('page', 1)));
        $perPage = min(50, max(1, intval($request->query('per_page', 20))));
        $norm    = StakeholderHelper::normSH('a.stakeholder');

        // Single conversation
        if ($stakeholder && $propertyId) {
            $cluster = $request->query('cluster', '0') === '1';

            if ($cluster) {
                $allNames = array_column(
                    DB::select("SELECT DISTINCT a.stakeholder FROM activities a WHERE a.property_id = ? AND a.stakeholder IS NOT NULL AND a.stakeholder != ''", [$propertyId]),
                    'stakeholder'
                );
                $variants = [$stakeholder];
                // AI-based clustering would be handled by AnthropicService
                // For now, use static clustering
                if (count($allNames) >= 2) {
                    $variants = $this->staticClusterNames($allNames, $stakeholder);
                } elseif (count($allNames) === 1) {
                    $variants = $allNames;
                }
                $normConditions = [];
                foreach ($variants as $v) {
                    $normConditions[] = $norm . ' = ' . StakeholderHelper::normSH("'" . addslashes($v) . "'");
                }
                $stakeholderWhere = '(' . implode(' OR ', $normConditions) . ')';
            } else {
                $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");
                $stakeholderWhere = "{$norm} = {$normInput}";
            }

            $msgs = DB::select("
                SELECT
                    a.id, a.activity_date, a.stakeholder,
                    a.activity, a.result, a.duration, a.category,
                    CASE WHEN a.category IN ('anfrage','email-in','besichtigung','kaufanbot','absage') THEN 'inbound' ELSE 'outbound' END as direction,
                    pe.from_email, pe.to_email, pe.subject, pe.ai_summary,
                    SUBSTRING(pe.body_text, 1, 2000) as body_text,
                    pe.has_attachment, pe.attachment_names, pe.id as email_id
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                WHERE a.property_id = ? {$brokerConvFilter}
                  AND {$stakeholderWhere}
                ORDER BY a.activity_date ASC, a.id ASC
            ", [$propertyId]);

            $property = DB::selectOne("SELECT address, city, ref_id, purchase_price as price, total_area as size_m2, rooms_amount as rooms, object_type as type FROM properties WHERE id = ?", [$propertyId]);

            $lastMsg = !empty($msgs) ? (array) end($msgs) : [];

            return response()->json([
                'stakeholder'        => $stakeholder,
                'property_id'        => $propertyId,
                'property'           => $property,
                'messages'           => $msgs,
                'total_messages'     => count($msgs),
                'first_contact'      => !empty($msgs) ? ((array)$msgs[0])['activity_date'] : null,
                'last_activity'      => $lastMsg['activity_date'] ?? null,
                'status'             => in_array($lastMsg['category'] ?? '', ['anfrage','email-in','besichtigung','kaufanbot']) ? 'open' : 'handled',
                'clustered_variants' => $cluster ? ($variants ?? []) : [],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // All conversations (paginated)
        $whereProperty = $propertyId ? "AND a.property_id = {$propertyId}" : '';
        $offset = ($page - 1) * $perPage;

        $conversations = DB::select("
            SELECT
                conv.display_name as stakeholder, conv.property_id,
                conv.total_messages, conv.first_contact, conv.last_date,
                conv.last_category, conv.last_activity, conv.last_result,
                conv.categories,
                CASE
                    WHEN conv.last_category NOT IN ('anfrage', 'email-in', 'besichtigung', 'kaufanbot') THEN 'handled'
                    WHEN EXISTS (
                        SELECT 1 FROM viewings v
                        WHERE v.property_id = conv.property_id
                          AND v.status IN ('geplant', 'bestaetigt', 'durchgefuehrt')
                          AND LOWER(v.person_name) LIKE CONCAT('%', SUBSTRING_INDEX(conv.norm_name, ' ', 1), '%')
                    ) THEN 'handled'
                    ELSE 'open'
                END as status,
                p.address, p.city, p.ref_id
            FROM (
                SELECT
                    {$norm} as norm_name,
                    MAX(a.stakeholder) as display_name,
                    a.property_id,
                    COUNT(*) as total_messages,
                    MIN(a.activity_date) as first_contact,
                    MAX(a.activity_date) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC), ',', 1) as last_category,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.activity ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC SEPARATOR '|||'), '|||', 1) as last_activity,
                    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(a.result,'') ORDER BY a.activity_date DESC, CASE WHEN a.category IN ('email-out','expose','update') THEN 0 ELSE 1 END, a.id DESC SEPARATOR '|||'), '|||', 1) as last_result,
                    GROUP_CONCAT(DISTINCT a.category) as categories
                FROM activities a
                WHERE 1=1 {$whereProperty} {$brokerConvFilter}
                GROUP BY norm_name, a.property_id
            ) conv
            LEFT JOIN properties p ON conv.property_id = p.id
            ORDER BY conv.last_date DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");

        $total = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM (
                SELECT {$norm} as norm_name, a.property_id
                FROM activities a WHERE 1=1 {$whereProperty} {$brokerConvFilter}
                GROUP BY norm_name, a.property_id
            ) sub
        ")->cnt;

        return response()->json([
            'conversations' => $conversations,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage,
            'total_pages'   => (int) ceil($total / $perPage),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Static name clustering fallback (without AI).
     */
    private function staticClusterNames(array $allNames, string $targetStakeholder): array
    {
        $groups = [];
        foreach ($allNames as $n) {
            $clean = trim(preg_replace('/\s*\(.*?\)\s*$/', '', $n));
            $key = strtolower(str_replace(' ', '', $clean));
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $n;
        }

        $targetClean = trim(preg_replace('/\s*\(.*?\)\s*$/', '', $targetStakeholder));
        $targetKey = strtolower(str_replace(' ', '', $targetClean));

        return $groups[$targetKey] ?? [$targetStakeholder];
    }
}
