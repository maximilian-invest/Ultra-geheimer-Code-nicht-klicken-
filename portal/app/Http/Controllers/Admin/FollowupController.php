<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowupController extends Controller
{

private static function findEmailInText(string $text, array $excludePatterns = []): ?string
    {
        $validTlds = ['at','de','com','net','org','info','io','eu','ch','uk','biz','me','cc','tv','top','to','li','hr','si','ro','bg','rs','cz','hu','sk','pl','it','fr','es','nl','be','se','no','fi','dk','pt','ru','us','ca','au','nz','jp','cn','in','br','mx','za','online','app','dev','gmbh','wien','mobi','xyz','live','email','club','shop','site','world'];

        $trimTld = function(string $candidate) use ($validTlds) {
            $lastDot = strrpos($candidate, '.');
            if ($lastDot === false) return null;
            $rawTld = strtolower(substr($candidate, $lastDot + 1));
            $bestTld = null;
            foreach ($validTlds as $tld) {
                if (stripos($rawTld, $tld) === 0 && (!$bestTld || strlen($tld) > strlen($bestTld))) {
                    $bestTld = $tld;
                }
            }
            return $bestTld ? strtolower(substr($candidate, 0, $lastDot + 1) . $bestTld) : null;
        };

        $isExcluded = function(string $email) use ($excludePatterns) {
            foreach ($excludePatterns as $p) {
                if (stripos($email, $p) !== false) return true;
            }
            return false;
        };

        // Step 1: Try labeled patterns first (Email: xxx@yyy.zz or Emailxxx@yyy.zz)
        // This avoids the greedy local-part problem when text is concatenated
        if (preg_match_all('/(?:e-?mail|E-?Mail)[=:\s]*([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $clean = $trimTld($candidate);
                if ($clean && !$isExcluded($clean)) return $clean;
            }
        }

        // Step 2: Generic email match with TLD trimming
        if (preg_match_all('/([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $clean = $trimTld($candidate);
                if ($clean && !$isExcluded($clean)) return $clean;
            }
        }
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        // Multi-User: broker_id des eingeloggten Users
        $currentUser = \Auth::user();
        $brokerId = $currentUser ? $currentUser->id : null;
        $userType = $currentUser->user_type ?? 'makler';
        // Assistenz/Backoffice sees all data (no broker/account filter)
        $scopeAll = in_array($userType, ['assistenz', 'backoffice']);
        // Assistenz can pass broker_filter param to filter by specific broker
        $brokerFilterParam = $request->query('broker_filter');
        if ($scopeAll && $brokerFilterParam && is_numeric($brokerFilterParam)) {
            $brokerFilter = "AND p.broker_id = " . intval($brokerFilterParam);
            $accountFilter = "AND pe.account_id IN (SELECT id FROM email_accounts WHERE user_id = " . intval($brokerFilterParam) . " OR user_id IS NULL)";
        } else {
            $brokerFilter = ($brokerId && !$scopeAll) ? "AND p.broker_id = {$brokerId}" : "";
            $accountFilter = ($brokerId && !$scopeAll) ? "AND pe.account_id IN (SELECT id FROM email_accounts WHERE user_id = {$brokerId} OR user_id IS NULL)" : "";
        }

        $filter  = $request->query('filter', 'all');
        $mode    = $request->query('mode', 'unanswered');
        $norm    = StakeholderHelper::normSH('a.stakeholder');
        $sysFilter = StakeholderHelper::systemStakeholderFilter('a.stakeholder');

        $minDays = intval($request->query('min_days', $mode === 'followup' ? 3 : 0));

        // Stage-1-Modus: 24h Nachfassen – frühe Rückgabe
        if ($mode === 'stage1') {
            $stage1Results = $this->getStage1Followups($norm, $sysFilter, $brokerFilter);
            return response()->json([
                'total_open'         => 0,
                'total_followup'     => 0,
                'total_stage1'       => count($stage1Results),
                'followups'          => $stage1Results,
                'unmatched'          => [],
                'total_unmatched'    => 0,
                'on_hold'            => [],
                'on_hold_unanswered' => [],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // GROUP_CONCAT-Limit erhöhen, damit lange Aktivitäts-Listen nicht abgeschnitten werden
        DB::statement('SET SESSION group_concat_max_len = 65536');

        $filterSql = '';
        if ($filter === 'week')       $filterSql = 'AND DATEDIFF(NOW(), conv.last_date) > 7';
        elseif ($filter === 'twoweeks') $filterSql = 'AND DATEDIFF(NOW(), conv.last_date) > 14';
        elseif ($filter === 'kaufanbot') $filterSql = 'AND conv.has_kaufanbot = 1';

        if ($mode === 'followup') {
            // Stufe 2: nachfassen (beliebig) >= 3 Tage ODER expose/email-out >= 4 Tage (Stufe 1 verpasst)
            $categoryFilter = "(
                (conv.last_category = 'nachfassen' AND conv.has_erstanfrage = 1 AND conv.inbound_replies = 0 AND DATEDIFF(NOW(), conv.last_date) >= 3)
                OR (conv.last_category IN ('expose', 'email-out') AND conv.has_erstanfrage = 1 AND conv.inbound_replies = 0 AND DATEDIFF(NOW(), conv.last_date) >= 4)
            )";
            $minDays = 0; // DATEDIFF bereits in categoryFilter eingebaut
        } else {
            $categoryFilter = "conv.last_category NOT IN ('email-out', 'expose', 'update', 'nachfassen') AND conv.last_has_email = 1";
        }

        $sql = "
            SELECT
                conv.last_id as id,
                conv.display_name as from_name,
                conv.property_id,
                conv.last_activity as subject,
                conv.last_result as ai_summary,
                conv.last_date as email_date,
                conv.last_category as category,
                conv.total_messages,
                conv.first_contact,
                conv.has_kaufanbot,
                conv.norm_name,
                DATEDIFF(NOW(), conv.last_date) as days_waiting,
                CASE
                    WHEN conv.has_kaufanbot = 1 THEN 'critical'
                    WHEN DATEDIFF(NOW(), conv.last_date) > 14 THEN 'urgent'
                    WHEN DATEDIFF(NOW(), conv.last_date) > 7 THEN 'warning'
                    ELSE 'info'
                END as urgency,
                p.address, p.city, p.ref_id, p.object_type as property_type,
                p.broker_id,
                c.phone as contact_phone,
                c.email as contact_email,
                u.name as broker_name
            FROM (
                SELECT
                    {$norm} as norm_name,
                    MAX(a.stakeholder) as display_name,
                    a.property_id,
                    COUNT(*) as total_messages,
                    MIN(COALESCE(pe.email_date, a.activity_date)) as first_contact,
                    MAX(COALESCE(pe.email_date, a.activity_date)) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.id ORDER BY a.id DESC), ',', 1) as last_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.id DESC), ',', 1) as last_category,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.activity ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) as last_activity,
                    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(a.result,'') ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) as last_result,
                    MAX(a.category = 'kaufanbot') as has_kaufanbot,
                    MAX(a.category IN ('anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer')) as has_inbound,
                    MAX(a.category = 'anfrage') as has_erstanfrage,
                    SUM(a.category IN ('email-in','besichtigung','kaufanbot','absage')) as inbound_replies,
                    MAX(a.source_email_id IS NOT NULL) as has_real_email,
                    -- Check if the LAST activity (the one we filter on) has a source email
                    SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN a.source_email_id IS NOT NULL THEN 1 ELSE 0 END ORDER BY a.id DESC), ',', 1) as last_has_email,
                    MAX(CASE WHEN a.snooze_until IS NOT NULL AND a.snooze_until > NOW() THEN a.snooze_until ELSE NULL END) as snooze_until,
                    SUM(CASE WHEN a.category = 'nachfassen' AND COALESCE(a.followup_stage, 0) != 1 THEN 1 ELSE 0 END) as stage2_nachfassen_count
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                WHERE {$sysFilter}
                GROUP BY norm_name, a.property_id
            ) conv
            LEFT JOIN properties p ON conv.property_id = p.id
            LEFT JOIN (
                SELECT full_name,
                       MAX(phone) as phone,
                       MAX(CASE WHEN email NOT LIKE '%noreply%' AND email NOT LIKE '%no-reply%' AND email NOT LIKE '%willhaben%' AND email NOT LIKE '%immowelt%' THEN email ELSE NULL END) as email
                FROM contacts
                GROUP BY full_name COLLATE utf8mb4_unicode_ci
            ) c ON c.full_name COLLATE utf8mb4_unicode_ci = conv.display_name COLLATE utf8mb4_unicode_ci
            LEFT JOIN users u ON u.id = p.broker_id
            WHERE {$categoryFilter}
              AND DATEDIFF(NOW(), conv.last_date) >= {$minDays}
              AND conv.property_id IS NOT NULL
              AND COALESCE(p.on_hold, 0) = 0
              " . ($mode === 'followup' ? "AND conv.stage2_nachfassen_count < 2" : "") . "
              {$brokerFilter}
              AND (conv.snooze_until IS NULL OR conv.snooze_until <= NOW())
            {$filterSql}
            ORDER BY
                conv.last_date ASC
            LIMIT 50
        ";

        $followups = array_map(fn($r) => (array) $r, DB::select($sql));

        // Enrich each followup with recent messages and email fallback
        foreach ($followups as &$f) {
            $normInput = StakeholderHelper::normSH("'" . addslashes($f['from_name']) . "'");
            $pid = intval($f['property_id']);

            $recent = DB::select("
                SELECT a.activity_date, a.category, a.activity
                FROM activities a
                WHERE a.property_id = ? AND {$norm} = {$normInput}
                ORDER BY a.activity_date DESC, a.id DESC
                LIMIT 8
            ", [$pid]);

            $f['conversation_count'] = count($recent);
            $f['recent_messages'] = array_map(function ($m) {
                $m = (array) $m;
                return [
                    'date'      => $m['activity_date'],
                    'direction' => in_array($m['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'in' : 'out',
                    'category'  => $m['category'],
                    'text'      => mb_substr($m['activity'], 0, 120),
                ];
            }, array_reverse($recent));

            $f['recommendation'] = null;

            // Email fallback — skip noreply/platform addresses
            $isUsableEmail = function($email) {
                if (empty($email)) return false;
                $lower = strtolower($email);
                return !preg_match('/(noreply|no-reply|mailer|notification|system|typeform|followups|info@willhaben|info@immowelt)/', $lower);
            };

            // 1. Check if contact_email is usable
            if (!$isUsableEmail($f['contact_email'] ?? '')) {
                $f['contact_email'] = '';
            }

            // 2. Fallback: look in contacts table
            if (empty($f['contact_email'])) {
                $contact = DB::selectOne("SELECT email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND email IS NOT NULL AND email != '' LIMIT 1", [$f['from_name']]);
                if ($contact && $isUsableEmail($contact->email)) {
                    $f['contact_email'] = $contact->email;
                }
            }

            // 3. Fallback: extract from portal_emails body (platform mails contain real email in body)
            if (empty($f['contact_email'])) {
                $emailRow = DB::selectOne("
                    SELECT from_email, body_text FROM portal_emails
                    WHERE property_id = ? AND stakeholder LIKE ?
                    AND direction = 'inbound'
                    ORDER BY email_date DESC LIMIT 1
                ", [$pid, '%' . mb_substr($f['from_name'], 0, 10) . '%']);
                if ($emailRow) {
                    if ($isUsableEmail($emailRow->from_email)) {
                        $f['contact_email'] = $emailRow->from_email;
                    } elseif ($emailRow->body_text) {
                        $searchText = preg_replace('/\s+/', ' ', strip_tags($emailRow->body_text));
                        $excludes = ['willhaben', 'immowelt', 'noreply', 'no-reply', 'typeform', 'followups', 'scout24', 'sr-homes'];
                        $found = self::findEmailInText($searchText, $excludes);
                        if ($found && $isUsableEmail($found)) {
                            $f['contact_email'] = $found;
                        }
                    }
                }
            }

            // Phone fallback: extract from email body if not in contacts
            if (empty($f['contact_phone'])) {
                $emailRow = DB::selectOne("SELECT body_text FROM portal_emails WHERE property_id = ? AND stakeholder LIKE ? AND direction = 'inbound' ORDER BY email_date DESC LIMIT 1", [$pid, '%' . mb_substr($f['from_name'], 0, 10) . '%']);
                if ($emailRow && $emailRow->body_text) {
                    $flat = preg_replace('/\s+/', ' ', $emailRow->body_text);
                    // Typeform: "Phone number+436605646605Email" or "Telefon: +43 664 ..."
                    if (preg_match('/(?:Phone\s*number|Telefon|Tel\.?|Mobil|Handy)[:\s]*([+\d][\d\s\/\-()]{6,20})/i', $flat, $pm)) {
                        $f['contact_phone'] = trim(preg_replace('/\s+/', ' ', $pm[1]));
                    }
                }
            }

            // Expose from_email for the frontend
            $f['from_email'] = $f['contact_email'] ?? '';
        }
        unset($f);

        // Embed pre-generated drafts into followup items (batch lookup)
        if (!empty($followups)) {
            $activityIds = array_column($followups, 'id');
            $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
            // Get source_email_id + stakeholder + property_id for each activity
            $activityMeta = DB::select("
                SELECT id as act_id, source_email_id, stakeholder, property_id FROM activities WHERE id IN ({$placeholders})
            ", $activityIds);
            $actToSource = [];
            $sourceIds = [];
            foreach ($activityMeta as $am) {
                $actToSource[$am->act_id] = [
                    'source_email_id' => $am->source_email_id,
                    'stakeholder'     => $am->stakeholder,
                    'property_id'     => $am->property_id,
                ];
                if ($am->source_email_id) $sourceIds[] = $am->source_email_id;
            }
            // Fetch all drafts by source_email_id in one query
            $draftBySource = [];
            if (!empty($sourceIds)) {
                $dp = implode(',', array_fill(0, count($sourceIds), '?'));
                $drafts = DB::select("SELECT * FROM email_drafts WHERE source_email_id IN ({$dp}) ORDER BY id DESC", $sourceIds);
                foreach ($drafts as $dr) {
                    if (!isset($draftBySource[$dr->source_email_id])) {
                        $draftBySource[$dr->source_email_id] = $dr;
                    }
                }
            }
            // Also fetch all drafts (for stakeholder+property fallback)
            $allDrafts = DB::select("SELECT * FROM email_drafts ORDER BY id DESC");
            $draftByStakeholderProp = [];
            foreach ($allDrafts as $dr) {
                $key = strtolower(trim($dr->stakeholder)) . '|' . $dr->property_id;
                if (!isset($draftByStakeholderProp[$key])) {
                    $draftByStakeholderProp[$key] = $dr;
                }
            }
            // Attach draft to each followup
            foreach ($followups as &$f) {
                $meta = $actToSource[$f['id']] ?? null;
                $draft = null;
                if ($meta) {
                    // Try source_email_id first
                    if ($meta['source_email_id'] && isset($draftBySource[$meta['source_email_id']])) {
                        $draft = $draftBySource[$meta['source_email_id']];
                    }
                    // Fallback: stakeholder + property_id
                    if (!$draft) {
                        $key = strtolower(trim($meta['stakeholder'])) . '|' . $meta['property_id'];
                        $draft = $draftByStakeholderProp[$key] ?? null;
                    }
                }
                if ($draft && !empty($draft->body)) {
                    $f['draft'] = [
                        'body'             => $draft->body,
                        'subject'          => $draft->subject,
                        'to'               => $draft->to_email,
                        'id'               => $draft->id,
                        'call_script'      => $draft->call_script ?? null,
                        'preferred_action' => $draft->preferred_action ?? 'email',
                        'lead_phase'       => $draft->lead_phase ?? null,
                        'mail_type'        => $draft->mail_type ?? null,
                        'lead_status'      => $draft->lead_status ?? null,
                        'mail_goal'        => $draft->mail_goal ?? null,
                    ];
                } else {
                    $f['draft'] = null;
                }
            }
            unset($f);
        }

        // Bei Stufe-2-Modus: followup_stage=2 zu jedem Item hinzufuegen
        if ($mode === 'followup') {
            foreach ($followups as &$f) {
                $f['followup_stage'] = 2;
            }
            unset($f);
        }

        // Stats — Unbeantwortet
        $countUnanswered = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM (
                SELECT {$norm} as norm_name, a.property_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.id DESC), ',', 1) as last_cat,
                    MAX(COALESCE(pe.email_date, a.activity_date)) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN a.source_email_id IS NOT NULL THEN 1 ELSE 0 END ORDER BY a.id DESC), ',', 1) as last_has_email
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                JOIN properties p ON p.id = a.property_id
                WHERE {$sysFilter} AND a.property_id IS NOT NULL AND COALESCE(p.on_hold, 0) = 0 {$brokerFilter}
                GROUP BY norm_name, a.property_id
                HAVING last_cat NOT IN ('email-out', 'expose', 'update', 'nachfassen') AND DATEDIFF(NOW(), last_date) >= 0 AND last_has_email = 1
            ) sub
        ")->cnt;

        // Stats — Nachfassen (only Erstanfragen)
        $countFollowup = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM (
                SELECT {$norm} as norm_name, a.property_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.id DESC), ',', 1) as last_cat,
                    MAX(COALESCE(pe.email_date, a.activity_date)) as last_date,
                    MAX(a.category = 'anfrage') as has_erstanfrage,
                    SUM(a.category IN ('email-in','besichtigung','kaufanbot','absage')) as inbound_replies,
                    SUM(CASE WHEN a.category = 'nachfassen' AND COALESCE(a.followup_stage, 0) != 1 THEN 1 ELSE 0 END) as stage2_nachfassen_count
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                JOIN properties p ON p.id = a.property_id
                WHERE {$sysFilter} AND a.property_id IS NOT NULL AND COALESCE(p.on_hold, 0) = 0 {$brokerFilter}
                GROUP BY norm_name, a.property_id
                HAVING last_cat IN ('email-out', 'expose', 'nachfassen') AND DATEDIFF(NOW(), last_date) >= 3 AND has_erstanfrage = 1 AND inbound_replies = 0 AND stage2_nachfassen_count < 2
            ) sub
        ")->cnt;

        // On-Hold list
        $normA2 = StakeholderHelper::normSH('a2.stakeholder');
        $sysFilterA2 = StakeholderHelper::systemStakeholderFilter('a2.stakeholder');

        $onHoldList = DB::select("
            SELECT p.id as property_id, p.ref_id, p.address, p.on_hold_note, p.on_hold_since,
                (SELECT COUNT(DISTINCT {$normA2}) FROM activities a2 WHERE a2.property_id = p.id AND {$sysFilterA2}) as conv_count
            FROM properties p
            WHERE p.on_hold = 1 {$brokerFilter}
            ORDER BY p.on_hold_since DESC
        ");

        // Unmatched inbound emails (no property assigned, not bounces/system)
        $unmatched = [];
        if ($mode === 'unanswered') {
            $unmatched = array_map(fn($r) => (array) $r, DB::select("
                SELECT pe.id, pe.from_name, pe.from_email, pe.to_email, pe.subject,
                       pe.body_text, pe.ai_summary, pe.email_date, pe.category, pe.stakeholder,
                       pe.has_attachment, pe.account_id,
                       DATEDIFF(NOW(), pe.email_date) as days_waiting
                FROM portal_emails pe
                WHERE pe.property_id IS NULL
                  AND pe.category NOT IN ('bounce', 'spam', 'email-out', 'intern')
                  AND (pe.is_deleted = 0 OR pe.is_deleted IS NULL)
                  {$accountFilter}
                  AND pe.from_email NOT REGEXP '(noreply|no-reply|mailer-daemon|postmaster|notification)'
                  AND NOT EXISTS (
                      SELECT 1 FROM portal_emails pe2
                      WHERE pe2.direction = 'outbound'
                        AND pe2.stakeholder = pe.stakeholder
                        AND pe2.email_date > pe.email_date
                  )
                ORDER BY pe.email_date DESC
                LIMIT 50
            "));
        }
        $countUnmatched = count($unmatched);

        // On-Hold unanswered: same logic as main query but for on_hold=1 properties
        $onHoldUnanswered = [];
        if ($mode === 'unanswered') {
            $sqlOnHold = str_replace(
                'AND COALESCE(p.on_hold, 0) = 0',
                'AND COALESCE(p.on_hold, 0) = 1',
                $sql
            );
            $onHoldUnanswered = array_map(fn($r) => (array) $r, DB::select($sqlOnHold));
            foreach ($onHoldUnanswered as &$f) {
                $normInput = StakeholderHelper::normSH("'" . addslashes($f['from_name']) . "'");
                $pid = intval($f['property_id']);
                $recent = DB::select("
                    SELECT a.activity_date, a.category, a.activity
                    FROM activities a
                    WHERE a.property_id = ? AND {$norm} = {$normInput}
                    ORDER BY a.activity_date DESC, a.id DESC
                    LIMIT 8
                ", [$pid]);
                $f['conversation_count'] = count($recent);
                $f['on_hold'] = true;
                // Add on_hold_note
                $prop = DB::selectOne("SELECT on_hold_note FROM properties WHERE id=?", [$pid]);
                $f['on_hold_note'] = $prop->on_hold_note ?? null;
            }
            unset($f);
        }

        return response()->json([
            'total_open'           => $countUnanswered,
            'total_followup'       => $mode === 'followup' ? count($followups) : $countFollowup,
            'total_urgent'         => $countFollowup,
            'followups'            => $followups,
            'unmatched'            => $unmatched,
            'total_unmatched'      => $countUnmatched,
            'on_hold'              => $onHoldList,
            'on_hold_unanswered'   => $onHoldUnanswered,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * On-demand AI follow-up recommendation for a specific contact.
     */
    public function recommendation(Request $request): JsonResponse
    {
        $stakeholder = $request->query('stakeholder', '');
        $propertyId  = intval($request->query('property_id', 0));

        if (!$stakeholder || !$propertyId) {
            return response()->json(['error' => 'stakeholder and property_id required'], 400);
        }

        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");

        $thread = DB::select("
            SELECT a.activity_date, a.category, a.activity, a.result
            FROM activities a
            WHERE a.property_id = ? AND {$norm} = {$normInput}
            ORDER BY a.activity_date ASC, a.id ASC
        ", [$propertyId]);

        if (empty($thread)) {
            return response()->json(['recommendation' => null, 'error' => 'No conversation found']);
        }

        $contact = DB::selectOne("SELECT phone, email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        $phone = $contact->phone ?? null;

        $propData = DB::selectOne("SELECT address, city, ref_id FROM properties WHERE id = ?", [$propertyId]);

        // Format thread
        $threadContext = '';
        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $dir = in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'KUNDE' : 'SR-HOMES';
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$msg['category']}): {$msg['activity']}";
            if ($msg['result']) $threadContext .= " → {$msg['result']}";
            $threadContext .= "\n";
        }

        $lastMsg = (array) end($thread);
        $daysWaiting = (string)(int)((time() - strtotime($lastMsg['activity_date'])) / 86400);
        $propAddr = ($propData->address ?? '') . ', ' . ($propData->city ?? '');
        $refId = $propData->ref_id ?? '';

        // Knowledge base context
        $kbContext = '';
        $kbItems = DB::select("
            SELECT category, title, content, confidence, is_verified
            FROM property_knowledge
            WHERE property_id = ? AND is_active = 1
            ORDER BY confidence DESC, is_verified DESC, created_at DESC
        ", [$propertyId]);

        if (!empty($kbItems)) {
            $kbGrouped = [];
            foreach ($kbItems as $k) {
                $k = (array) $k;
                $kbGrouped[$k['category']][] = $k;
            }
            $kbLabels = [
                'objektbeschreibung' => 'Objektbeschreibung', 'ausstattung' => 'Ausstattung',
                'lage_umgebung' => 'Lage & Umgebung', 'preis_markt' => 'Preis & Markt',
                'feedback_positiv' => 'Feedback positiv', 'feedback_negativ' => 'Feedback negativ',
                'feedback_besichtigung' => 'Feedback Besichtigung', 'verhandlung' => 'Verhandlung',
                'eigentuemer_info' => 'Eigentümer-Info', 'energetik' => 'Energetik',
            ];
            $kbContext = "\nOBJEKTWISSEN (nutze für persönlichere Ansprache):\n";
            $kbChars = 0;
            foreach ($kbGrouped as $cat => $items) {
                $label = $kbLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat));
                $section = "=== {$label} ===\n";
                foreach (array_slice($items, 0, 3) as $item) {
                    $section .= "- {$item['title']}: {$item['content']}\n";
                }
                if ($kbChars + strlen($section) > 1500) break;
                $kbContext .= $section;
                $kbChars += strlen($section);
            }
        }

        // AI follow-up recommendation
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            $recentActivities = DB::select("SELECT activity_date, category, activity, stakeholder FROM activities WHERE property_id = ? ORDER BY activity_date DESC LIMIT 15", [$propertyId]);
            $recommendation = $anthropic->generateFollowupRecommendation($stakeholder, $propAddr, array_map(fn($a) => (array)$a, $recentActivities));
        } catch (\Exception $e) {
            $recommendation = null;
        }

        // Parse JSON recommendation and extract just the suggestion text
        if ($recommendation) {
            $parsed = json_decode($recommendation, true);
            if (is_array($parsed) && isset($parsed['suggestion'])) {
                $recommendation = $parsed['suggestion'];
            }
        }

        return response()->json([
            'recommendation' => $recommendation,
            'stakeholder'    => $stakeholder,
            'property_id'    => $propertyId,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }


    /**
     * Generate AI draft for a single followup contact (for wizard).
     */
    public function draft(Request $request): JsonResponse
    {
        $stakeholder = $request->query('stakeholder', '');
        $propertyId  = intval($request->query('property_id', 0));

        if (!$stakeholder || !$propertyId) {
            return response()->json(['error' => 'stakeholder and property_id required'], 400);
        }

        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");

        // Get thread — nur Aktivitäten VOR heute
        $today = date('Y-m-d');
        $thread = DB::select("
            SELECT a.activity_date, a.created_at, a.category, a.activity, a.result
            FROM activities a
            WHERE a.property_id = ? AND {$norm} = {$normInput}
              AND a.activity_date < ?
            ORDER BY a.activity_date ASC, a.id ASC
        ", [$propertyId, $today]);

        if (empty($thread)) {
            return response()->json(['error' => 'No conversation found'], 404);
        }

        $lastActivityRow = (array) end($thread);
        $daysSinceLastContact = (int) floor((time() - strtotime($lastActivityRow['activity_date'])) / 86400);

        $hasUnansweredQuestion = false;
        $lastInboundText = '';
        $lastInboundDate = '';
        $lastOutboundDate = '';
        foreach ($thread as $msg) {
            $m = (array) $msg;
            $isIn = in_array($m['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']);
            if ($isIn) { $lastInboundText = ($m['activity'] ?? '') . ' ' . ($m['result'] ?? ''); $lastInboundDate = $m['activity_date']; }
            else { $lastOutboundDate = $m['activity_date']; }
        }
        if ($lastInboundDate && str_contains($lastInboundText, '?') && ($lastOutboundDate < $lastInboundDate || !$lastOutboundDate)) {
            $hasUnansweredQuestion = true;
        }

        $threadContext = '';
        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $dir = in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'KUNDE' : 'SR-HOMES';
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$msg['category']}): {$msg['activity']}";
            if ($msg['result']) $threadContext .= " → {$msg['result']}";
            $threadContext .= "
";
        }

        // Fetch actual email bodies for last outbound + last inbound
        $lastOutEmail = DB::selectOne("
            SELECT pe.body_text, pe.subject, pe.email_date
            FROM portal_emails pe
            JOIN activities a ON a.source_email_id = pe.id
            WHERE a.property_id = ? AND pe.direction = 'outbound'
              AND {$norm} = {$normInput}
              AND DATE(pe.email_date) < CURDATE()
            ORDER BY pe.email_date DESC LIMIT 1
        ", [$propertyId]);

        $lastInEmail = DB::selectOne("
            SELECT pe.body_text, pe.subject, pe.from_name, pe.email_date
            FROM portal_emails pe
            JOIN activities a ON a.source_email_id = pe.id
            WHERE a.property_id = ? AND pe.direction = 'inbound'
              AND {$norm} = {$normInput}
              AND DATE(pe.email_date) < CURDATE()
            ORDER BY pe.email_date DESC LIMIT 1
        ", [$propertyId]);

        if ($lastOutEmail && $lastOutEmail->body_text) {
            $outBody = strip_tags($lastOutEmail->body_text);
            $outBody = mb_substr(trim(preg_replace('/\s+/', ' ', $outBody)), 0, 1500);
            $threadContext .= "\n--- LETZTE GESENDETE NACHRICHT ({$lastOutEmail->email_date}) ---\nBetreff: {$lastOutEmail->subject}\n{$outBody}\n--- ENDE GESENDETE NACHRICHT ---\n";
        }

        if ($lastInEmail && $lastInEmail->body_text) {
            $inBody = strip_tags($lastInEmail->body_text);
            $inBody = mb_substr(trim(preg_replace('/\s+/', ' ', $inBody)), 0, 1000);
            $threadContext .= "\n--- LETZTE NACHRICHT VOM KUNDEN ({$lastInEmail->email_date}) ---\nVon: {$lastInEmail->from_name}\nBetreff: {$lastInEmail->subject}\n{$inBody}\n--- ENDE KUNDEN-NACHRICHT ---\n";
        }

        // Get contact info
        $contact = DB::selectOne("SELECT phone, email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        $phone = $contact->phone ?? null;
        $email = $contact->email ?? null;

        // Phone fallback: extract from email body
        if (!$phone) {
            $phoneRow = DB::selectOne("SELECT body_text FROM portal_emails WHERE property_id = ? AND stakeholder LIKE ? AND direction = 'inbound' ORDER BY email_date DESC LIMIT 1", [$propertyId, '%' . mb_substr($stakeholder, 0, 10) . '%']);
            if ($phoneRow && $phoneRow->body_text) {
                $flat = preg_replace('/\s+/', ' ', $phoneRow->body_text);
                if (preg_match('/(?:Phone\s*number|Telefon|Tel\.?|Mobil|Handy)[:\s]*([+\d][\d\s\/\-()]{6,20})/i', $flat, $pm)) {
                    $phone = trim(preg_replace('/\s+/', ' ', $pm[1]));
                }
            }
        }

        // Email fallback — extract real email from platform notifications
        if (!$email) {
            $fb = DB::selectOne("SELECT from_email, body_text FROM portal_emails WHERE property_id = ? AND stakeholder LIKE ? AND direction = 'inbound' ORDER BY email_date DESC LIMIT 1", [$propertyId, '%' . mb_substr($stakeholder, 0, 10) . '%']);
            if ($fb) {
                $isNoreply = preg_match('/(noreply|no-reply|notification|typeform|followups|info@willhaben|info@immowelt)/i', $fb->from_email);
                if (!$isNoreply) {
                    $email = $fb->from_email;
                } elseif ($fb->body_text) {
                    $flat = preg_replace('/\s+/', ' ', strip_tags($fb->body_text));
                    $excludes = ['willhaben', 'immowelt', 'noreply', 'typeform', 'followups', 'scout24', 'sr-homes'];
                    $found = self::findEmailInText($flat, $excludes);
                    if ($found) $email = $found;
                }
            }
        }

        // Property info
        $prop = DB::selectOne("SELECT address, city, ref_id FROM properties WHERE id = ?", [$propertyId]);
        $propAddr = ($prop->address ?? '') . ', ' . ($prop->city ?? '');

        // --- CACHE CHECK: Return cached draft if thread hasn't changed ---
        $threadHash = md5($threadContext);
        try {
            $cached = DB::selectOne("
                SELECT body, subject, call_script, preferred_action, lead_phase, mail_type, lead_status, mail_goal, created_at
                FROM email_drafts
                WHERE property_id = ? AND stakeholder = ? AND thread_hash = ?
                ORDER BY id DESC LIMIT 1
            ", [$propertyId, $stakeholder, $threadHash]);
        } catch (\Exception $e) {
            \Log::warning('Draft cache lookup failed (migration pending?)', ['error' => $e->getMessage()]);
            $cached = null;
        }

        if ($cached && $cached->body) {
            // Build thread for display
            $displayThread = array_map(function ($msg) {
                $msg = (array) $msg;
                return [
                    'date'      => $msg['activity_date'],
                    'datetime'  => $msg['created_at'] ?? $msg['activity_date'],
                    'direction' => in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'in' : 'out',
                    'category'  => $msg['category'],
                    'text'      => mb_substr($msg['activity'], 0, 200),
                ];
            }, $thread);

            return response()->json([
                'draft'       => [
                    'email_body'       => $cached->body,
                    'email_subject'    => $cached->subject,
                    'call_script'      => $cached->call_script,
                    'preferred_action' => $cached->preferred_action ?? 'email',
                    'lead_phase'       => $cached->lead_phase,
                    'mail_type'        => $cached->mail_type,
                    'lead_status'      => $cached->lead_status,
                    'mail_goal'        => $cached->mail_goal,
                ],
                'phone'       => $phone,
                'email'       => $email,
                'property'    => $prop ? (array) $prop : null,
                'stakeholder' => $stakeholder,
                'thread'      => $displayThread,
                'cached'      => true,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // Knowledge base context
        $kbContext = '';
        try {
            $kbItems = DB::select("
                SELECT category, title, content FROM property_knowledge
                WHERE property_id = ? AND is_active = 1
                ORDER BY confidence DESC LIMIT 10
            ", [$propertyId]);
        } catch (\Exception $e) {
            $kbItems = [];
        }

        if (!empty($kbItems)) {
            $kbContext = "OBJEKTWISSEN:\n";
            $chars = 0;
            foreach ($kbItems as $k) {
                $k = (array) $k;
                $line = "- {$k['title']}: {$k['content']}\n";
                if ($chars + strlen($line) > 1200) break;
                $kbContext .= $line;
                $chars += strlen($line);
            }
        }

        // Stufen-spezifische KI-Anweisung (Stage 1 oder 2)
        $followupStage = intval($request->query('followup_stage', 0));
        if ($followupStage === 1) {
            $stageHint  = "\n\n--- STUFEN-ANWEISUNG ---\n";
            $stageHint .= "Dies ist die ERSTE Nachfass-Mail, 24 Stunden nach dem Expose-Versand.\n";
            $stageHint .= "Schreibe eine sehr kurze, freundliche Nachfrage (maximal 3-4 Saetze).\n";
            $stageHint .= "Frage ob das Expose angekommen ist und ob Fragen zum Objekt bestehen.\n";
            $stageHint .= "KEIN Verkaufsdruck. KEIN direktes Besichtigungsangebot in dieser Mail.\n";
            $stageHint .= "Ton: locker, professionell, kurz - Ich wollte nur kurz nachfragen.\n";
            $stageHint .= "--- ENDE STUFEN-ANWEISUNG ---\n";
            $threadContext .= $stageHint;
        } elseif ($followupStage === 2) {
            $stageHint  = "\n\n--- STUFEN-ANWEISUNG ---\n";
            $stageHint .= "Dies ist das ZWEITE Nachfassen. Der Interessent hat auf die erste Nachfass-Mail NICHT reagiert.\n";
            $stageHint .= "Ton: DIREKT, ABSCHLIESSEND, aber hoeflich. Maximal 4-5 Saetze.\n";
            $stageHint .= "Struktur:\n";
            $stageHint .= "1. Bezug: 'Ich habe vor einigen Tagen bei Ihnen nachgefragt...'\n";
            $stageHint .= "2. Feststellen: '...da ich leider noch keine Rueckmeldung erhalten habe...'\n";
            $stageHint .= "3. Direkte Frage: '...ob Sie bereits zu einer Entscheidung gekommen sind'\n";
            $stageHint .= "4. Easy-Out: '...oder ob das Objekt nichts fuer Sie ist'\n";
            $stageHint .= "5. Absagegrund: 'In dem Fall waere es sehr freundlich, uns kurz den Grund mitzuteilen'\n";
            $stageHint .= "KEIN Expose nochmal anbieten. KEIN Objekt beschreiben. NUR nach dem Status fragen.\n";
            $stageHint .= "--- ENDE STUFEN-ANWEISUNG ---\n";
            $threadContext .= $stageHint;
        }

        // Detect if this is a second followup (nachfassen already in thread)
        $isSecondFollowup = $followupStage === 2 || (bool) preg_match('/nachfassen|Nachfass-Mail|erste Erinnerung/i', $threadContext);

                // Generate AI draft
        $anthropic = app(\App\Services\AnthropicService::class);
        $draft = $anthropic->generateFollowupDraft($stakeholder, $propAddr, $threadContext, $kbContext, !empty($phone), 'professional', $daysSinceLastContact, $hasUnansweredQuestion, $today, $isSecondFollowup);

        // --- CACHE WRITE: Save generated draft for instant future access ---
        try {
            DB::insert("
                INSERT INTO email_drafts (property_id, stakeholder, thread_hash, subject, body, call_script, preferred_action, lead_phase, mail_type, lead_status, mail_goal, tone, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'professional', NOW(), NOW())
            ", [
                $propertyId,
                $stakeholder,
                $threadHash,
                $draft['email_subject'] ?? '',
                $draft['email_body'] ?? '',
                $draft['call_script'] ?? null,
                $draft['preferred_action'] ?? 'email',
                $draft['lead_phase'] ?? null,
                $draft['mail_type'] ?? null,
                $draft['lead_status'] ?? null,
                $draft['mail_goal'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to cache followup draft', ['error' => $e->getMessage()]);
        }

        // Build thread for display
        $displayThread = array_map(function ($msg) {
            $msg = (array) $msg;
            return [
                'date'      => $msg['activity_date'],
                'datetime'  => $msg['created_at'] ?? $msg['activity_date'],
                'direction' => in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'in' : 'out',
                'category'  => $msg['category'],
                'text'      => mb_substr($msg['activity'], 0, 200),
            ];
        }, $thread);

        return response()->json([
            'draft'       => $draft,
            'phone'       => $phone,
            'email'       => $email,
            'property'    => $prop ? (array) $prop : null,
            'stakeholder' => $stakeholder,
            'thread'      => $displayThread,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save AI style feedback (user edited a draft).
     */
    public function saveAiFeedback(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $original = $input['original_text'] ?? '';
        $edited   = $input['edited_text'] ?? '';

        if (!$original || !$edited || $original === $edited) {
            return response()->json(['skipped' => true]);
        }

        DB::insert("
            INSERT INTO ai_style_feedback (original_text, edited_text, context_type, stakeholder, property_id, tone, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ", [
            mb_substr($original, 0, 5000),
            mb_substr($edited, 0, 5000),
            $input['context_type'] ?? 'email_reply',
            $input['stakeholder'] ?? null,
            intval($input['property_id'] ?? 0) ?: null,
            $input['tone'] ?? 'professional',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Stufe-1-Nachfassen: 24h nach Exposé / email-out, noch keine Antwort
     */
    private function getStage1Followups(string $norm, string $sysFilter, string $brokerFilter): array
    {
        DB::statement('SET SESSION group_concat_max_len = 65536');

        $sql = "
            SELECT
                conv.last_id as id,
                conv.display_name as from_name,
                conv.property_id,
                conv.last_activity as subject,
                conv.last_result as ai_summary,
                conv.last_date as email_date,
                conv.last_category as category,
                conv.total_messages,
                conv.first_contact,
                conv.has_kaufanbot,
                conv.norm_name,
                DATEDIFF(NOW(), conv.last_date) as days_waiting,
                'info' as urgency,
                p.address, p.city, p.ref_id, p.object_type as property_type,
                p.broker_id,
                c.phone as contact_phone,
                c.email as contact_email,
                u.name as broker_name,
                1 as followup_stage
            FROM (
                SELECT
                    {$norm} as norm_name,
                    MAX(a.stakeholder) as display_name,
                    a.property_id,
                    COUNT(*) as total_messages,
                    MIN(COALESCE(pe.email_date, a.activity_date)) as first_contact,
                    MAX(COALESCE(pe.email_date, a.activity_date)) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.id ORDER BY a.id DESC), ',', 1) as last_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.id DESC), ',', 1) as last_category,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.activity ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) as last_activity,
                    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(a.result,'') ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) as last_result,
                    MAX(a.category = 'kaufanbot') as has_kaufanbot,
                    MAX(a.category = 'anfrage') as has_erstanfrage,
                    SUM(a.category IN ('email-in','besichtigung','kaufanbot','absage')) as inbound_replies,
                    SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN a.source_email_id IS NOT NULL THEN 1 ELSE 0 END ORDER BY a.id DESC), ',', 1) as last_has_email
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                WHERE {$sysFilter}
                GROUP BY norm_name, a.property_id
            ) conv
            LEFT JOIN properties p ON conv.property_id = p.id
            LEFT JOIN (
                SELECT full_name,
                       MAX(phone) as phone,
                       MAX(CASE WHEN email NOT LIKE '%noreply%' AND email NOT LIKE '%no-reply%' AND email NOT LIKE '%willhaben%' AND email NOT LIKE '%immowelt%' THEN email ELSE NULL END) as email
                FROM contacts
                GROUP BY full_name COLLATE utf8mb4_unicode_ci
            ) c ON c.full_name COLLATE utf8mb4_unicode_ci = conv.display_name COLLATE utf8mb4_unicode_ci
            LEFT JOIN users u ON u.id = p.broker_id
            WHERE conv.last_category IN ('expose', 'email-out')
              AND DATEDIFF(NOW(), conv.last_date) >= 1
              AND DATEDIFF(NOW(), conv.last_date) < 4
              AND conv.has_erstanfrage = 1
              AND conv.inbound_replies = 0
              AND conv.property_id IS NOT NULL
              AND COALESCE(p.on_hold, 0) = 0
              {$brokerFilter}
              AND NOT EXISTS (
                  SELECT 1 FROM activities a2
                  WHERE a2.property_id = conv.property_id
                    AND a2.followup_stage IN (1, 2)
                    AND a2.stakeholder COLLATE utf8mb4_unicode_ci = conv.display_name COLLATE utf8mb4_unicode_ci
              )
            ORDER BY conv.last_date ASC
            LIMIT 50
        ";

        $items = array_map(fn($r) => (array) $r, DB::select($sql));

        $isUsableEmail = function($email) {
            if (empty($email)) return false;
            $lower = strtolower($email);
            return !preg_match('/(noreply|no-reply|mailer|notification|system|typeform|followups|info@willhaben|info@immowelt)/', $lower);
        };

        foreach ($items as &$f) {
            $normInput = StakeholderHelper::normSH("'" . addslashes($f['from_name']) . "'");
            $pid = intval($f['property_id']);

            $recent = DB::select("
                SELECT a.activity_date, a.category, a.activity
                FROM activities a
                WHERE a.property_id = ? AND {$norm} = {$normInput}
                ORDER BY a.activity_date DESC, a.id DESC
                LIMIT 8
            ", [$pid]);

            $f['conversation_count'] = count($recent);
            $f['recent_messages'] = array_map(function ($m) {
                $m = (array) $m;
                return [
                    'date'      => $m['activity_date'],
                    'direction' => in_array($m['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'in' : 'out',
                    'category'  => $m['category'],
                    'text'      => mb_substr($m['activity'], 0, 120),
                ];
            }, array_reverse($recent));

            $f['recommendation'] = null;
            $f['draft'] = null;

            // Email Fallback
            if (!$isUsableEmail($f['contact_email'] ?? '')) {
                $f['contact_email'] = '';
            }
            if (empty($f['contact_email'])) {
                $contact = DB::selectOne("SELECT email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND email IS NOT NULL AND email != '' LIMIT 1", [$f['from_name']]);
                if ($contact && $isUsableEmail($contact->email)) {
                    $f['contact_email'] = $contact->email;
                }
            }
            if (empty($f['contact_email'])) {
                $emailRow = DB::selectOne("
                    SELECT from_email, body_text FROM portal_emails
                    WHERE property_id = ? AND stakeholder LIKE ?
                    AND direction = 'inbound'
                    ORDER BY email_date DESC LIMIT 1
                ", [$pid, '%' . mb_substr($f['from_name'], 0, 10) . '%']);
                if ($emailRow) {
                    if ($isUsableEmail($emailRow->from_email)) {
                        $f['contact_email'] = $emailRow->from_email;
                    } elseif ($emailRow->body_text) {
                        $searchText = preg_replace('/\s+/', ' ', strip_tags($emailRow->body_text));
                        $excludes = ['willhaben', 'immowelt', 'noreply', 'no-reply', 'typeform', 'followups', 'scout24', 'sr-homes'];
                        $found = self::findEmailInText($searchText, $excludes);
                        if ($found && $isUsableEmail($found)) {
                            $f['contact_email'] = $found;
                        }
                    }
                }
            }
            if (empty($f['contact_phone'])) {
                $emailRow = DB::selectOne("SELECT body_text FROM portal_emails WHERE property_id = ? AND stakeholder LIKE ? AND direction = 'inbound' ORDER BY email_date DESC LIMIT 1", [$pid, '%' . mb_substr($f['from_name'], 0, 10) . '%']);
                if ($emailRow && $emailRow->body_text) {
                    $flat = preg_replace('/\s+/', ' ', $emailRow->body_text);
                    if (preg_match('/(?:Phone\s*number|Telefon|Tel\.?|Mobil|Handy)[:\s]*([+\d][\d\s\/\-()]{6,20})/i', $flat, $pm)) {
                        $f['contact_phone'] = trim(preg_replace('/\s+/', ' ', $pm[1]));
                    }
                }
            }
            $f['from_email'] = $f['contact_email'] ?? '';
        }
        unset($f);

        return $items;
    }


    /**
     * Returns a simple array of leads eligible for auto-send.
     * Used by AutoFollowupCommand.
     *
     * @param string $mode  'stage1' or 'followup'
     * @return array
     */
    public function getLeadsForAutoSend(string $mode): array
    {
        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $sysFilter = StakeholderHelper::systemStakeholderFilter('a.stakeholder');

        if ($mode === 'stage1') {
            $items = $this->getStage1Followups($norm, $sysFilter, '');
            return array_map(fn($f) => [
                'property_id'      => $f['property_id'],
                'stakeholder'      => $f['from_name'],
                'email'            => $f['contact_email'] ?? $f['from_email'] ?? '',
                'property_ref'     => $f['ref_id'] ?? '',
                'property_address' => ($f['address'] ?? '') . ($f['city'] ? ', ' . $f['city'] : ''),
            ], $items);
        }

        // mode=followup: Stage-2-Kandidaten
        DB::statement('SET SESSION group_concat_max_len = 65536');

        $categoryFilter = "(
            (conv.last_category = 'nachfassen' AND conv.has_erstanfrage = 1 AND conv.inbound_replies = 0 AND DATEDIFF(NOW(), conv.last_date) >= 3)
            OR (conv.last_category IN ('expose', 'email-out') AND conv.has_erstanfrage = 1 AND conv.inbound_replies = 0 AND DATEDIFF(NOW(), conv.last_date) >= 4)
        )";

        $sql = "
            SELECT
                conv.display_name as from_name,
                conv.property_id,
                p.address, p.city, p.ref_id,
                c.email as contact_email
            FROM (
                SELECT
                    {$norm} as norm_name,
                    MAX(a.stakeholder) as display_name,
                    a.property_id,
                    MAX(a.category = 'anfrage') as has_erstanfrage,
                    SUM(a.category IN ('email-in','besichtigung','kaufanbot','absage')) as inbound_replies,
                    SUM(CASE WHEN a.category = 'nachfassen' AND COALESCE(a.followup_stage, 0) != 1 THEN 1 ELSE 0 END) as stage2_nachfassen_count,
                    MAX(COALESCE(pe.email_date, a.activity_date)) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.id DESC), ',', 1) as last_category,
                    MAX(CASE WHEN a.snooze_until IS NOT NULL AND a.snooze_until > NOW() THEN a.snooze_until ELSE NULL END) as snooze_until
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                WHERE {$sysFilter}
                GROUP BY norm_name, a.property_id
            ) conv
            LEFT JOIN properties p ON conv.property_id = p.id
            LEFT JOIN (
                SELECT full_name,
                       MAX(CASE WHEN email NOT LIKE '%noreply%' AND email NOT LIKE '%no-reply%'
                                     AND email NOT LIKE '%willhaben%' AND email NOT LIKE '%immowelt%'
                                THEN email ELSE NULL END) as email
                FROM contacts
                GROUP BY full_name COLLATE utf8mb4_unicode_ci
            ) c ON c.full_name COLLATE utf8mb4_unicode_ci = conv.display_name COLLATE utf8mb4_unicode_ci
            WHERE {$categoryFilter}
              AND conv.property_id IS NOT NULL
              AND COALESCE(p.on_hold, 0) = 0
              AND (conv.snooze_until IS NULL OR conv.snooze_until <= NOW())
              AND conv.stage2_nachfassen_count < 2
            ORDER BY conv.last_date ASC
            LIMIT 50
        ";

        $rows = array_map(fn($r) => (array) $r, DB::select($sql));

        $isUsableEmail = fn($email) => !empty($email) && !preg_match(
            '/(noreply|no-reply|mailer|notification|system|typeform|followups|info@willhaben|info@immowelt)/',
            strtolower($email)
        );

        return array_map(function ($r) use ($isUsableEmail) {
            $email = $isUsableEmail($r['contact_email'] ?? '') ? $r['contact_email'] : '';

            if (empty($email)) {
                $emailRow = DB::selectOne("
                    SELECT from_email, body_text FROM portal_emails
                    WHERE property_id = ? AND stakeholder LIKE ? AND direction = 'inbound'
                    ORDER BY email_date DESC LIMIT 1
                ", [$r['property_id'], '%' . mb_substr($r['from_name'], 0, 10) . '%']);
                if ($emailRow) {
                    if ($isUsableEmail($emailRow->from_email)) {
                        $email = $emailRow->from_email;
                    } elseif ($emailRow->body_text) {
                        $flat     = preg_replace('/\s+/', ' ', strip_tags($emailRow->body_text));
                        $excludes = ['willhaben','immowelt','noreply','no-reply','typeform','followups','scout24','sr-homes'];
                        $found    = self::findEmailInText($flat, $excludes);
                        if ($found && $isUsableEmail($found)) $email = $found;
                    }
                }
            }

            return [
                'property_id'      => $r['property_id'],
                'stakeholder'      => $r['from_name'],
                'email'            => $email,
                'property_ref'     => $r['ref_id'] ?? '',
                'property_address' => ($r['address'] ?? '') . ($r['city'] ? ', ' . $r['city'] : ''),
            ];
        }, $rows);
    }

    /**
     * Generates an AI draft for a lead, used by AutoFollowupCommand.
     *
     * @param  array $lead   Keys: property_id, stakeholder, email, property_address
     * @param  int   $stage  1 oder 2
     * @return array  ['subject' => ..., 'body' => ...] oder leer bei Fehler
     */
    public function generateAutoDraft(array $lead, int $stage): array
    {
        $stakeholder = $lead['stakeholder'] ?? '';
        $propertyId  = intval($lead['property_id'] ?? 0);
        if (!$stakeholder || !$propertyId) return [];

        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");
        $today     = date('Y-m-d');

        $thread = DB::select("
            SELECT a.activity_date, a.created_at, a.category, a.activity, a.result
            FROM activities a
            WHERE a.property_id = ? AND {$norm} = {$normInput}
              AND a.activity_date < ?
            ORDER BY a.activity_date ASC, a.id ASC
        ", [$propertyId, $today]);

        if (empty($thread)) return [];

        $lastRow = (array) end($thread);
        $daysSinceLastContact = (int) floor((time() - strtotime($lastRow['activity_date'])) / 86400);

        $hasUnansweredQuestion = false;
        $lastInboundText  = '';
        $lastInboundDate  = '';
        $lastOutboundDate = '';
        foreach ($thread as $msg) {
            $m    = (array) $msg;
            $isIn = in_array($m['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']);
            if ($isIn) {
                $lastInboundText = ($m['activity'] ?? '') . ' ' . ($m['result'] ?? '');
                $lastInboundDate = $m['activity_date'];
            } else {
                $lastOutboundDate = $m['activity_date'];
            }
        }
        if ($lastInboundDate && str_contains($lastInboundText, '?') && ($lastOutboundDate < $lastInboundDate || !$lastOutboundDate)) {
            $hasUnansweredQuestion = true;
        }

        $threadContext = '';
        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $dir = in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce']) ? 'KUNDE' : 'SR-HOMES';
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$msg['category']}): {$msg['activity']}";
            if ($msg['result']) $threadContext .= " -> {$msg['result']}";
            $threadContext .= "\n";
        }

        $lastOutEmail = DB::selectOne("
            SELECT pe.body_text, pe.subject, pe.email_date
            FROM portal_emails pe
            JOIN activities a ON a.source_email_id = pe.id
            WHERE a.property_id = ? AND pe.direction = 'outbound'
              AND {$norm} = {$normInput}
              AND DATE(pe.email_date) < CURDATE()
            ORDER BY pe.email_date DESC LIMIT 1
        ", [$propertyId]);
        if ($lastOutEmail && $lastOutEmail->body_text) {
            $outBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastOutEmail->body_text))), 0, 1500);
            $threadContext .= "\n--- LETZTE GESENDETE NACHRICHT ({$lastOutEmail->email_date}) ---\nBetreff: {$lastOutEmail->subject}\n{$outBody}\n--- ENDE GESENDETE NACHRICHT ---\n";
        }

        $lastInEmail = DB::selectOne("
            SELECT pe.body_text, pe.subject, pe.from_name, pe.email_date
            FROM portal_emails pe
            JOIN activities a ON a.source_email_id = pe.id
            WHERE a.property_id = ? AND pe.direction = 'inbound'
              AND {$norm} = {$normInput}
              AND DATE(pe.email_date) < CURDATE()
            ORDER BY pe.email_date DESC LIMIT 1
        ", [$propertyId]);
        if ($lastInEmail && $lastInEmail->body_text) {
            $inBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastInEmail->body_text))), 0, 1000);
            $threadContext .= "\n--- LETZTE NACHRICHT VOM KUNDEN ({$lastInEmail->email_date}) ---\nVon: {$lastInEmail->from_name}\nBetreff: {$lastInEmail->subject}\n{$inBody}\n--- ENDE KUNDEN-NACHRICHT ---\n";
        }

        if ($stage === 1) {
            $stageHint  = "\n\n--- STUFEN-ANWEISUNG ---\n";
            $stageHint .= "Dies ist die ERSTE Nachfass-Mail, 24 Stunden nach dem Expose-Versand.\n";
            $stageHint .= "Schreibe eine sehr kurze, freundliche Nachfrage (maximal 3-4 Saetze).\n";
            $stageHint .= "Frage ob das Expose angekommen ist und ob Fragen zum Objekt bestehen.\n";
            $stageHint .= "KEIN Verkaufsdruck. KEIN direktes Besichtigungsangebot in dieser Mail.\n";
            $stageHint .= "Ton: locker, professionell, kurz - Ich wollte nur kurz nachfragen.\n";
            $stageHint .= "--- ENDE STUFEN-ANWEISUNG ---\n";
            $threadContext .= $stageHint;
        } elseif ($stage === 2) {
            $stageHint  = "\n\n--- STUFEN-ANWEISUNG ---\n";
            $stageHint .= "Dies ist die ZWEITE Nachfass-Mail. Der Interessent hat sich nach Expose-Versand und erster Nachfrage noch nicht gemeldet.\n";
            $stageHint .= "Schreibe eine substantiellere, aber freundliche Nachfrage.\n";
            $stageHint .= "Biete konkret an, einen Besichtigungstermin zu vereinbaren.\n";
            $stageHint .= "Formuliere so, dass klar ist, dass man bereits Kontakt aufgenommen hat.\n";
            $stageHint .= "Bleib professionell und ohne Druck. Schliesse mit einem konkreten Call-to-Action.\n";
            $stageHint .= "--- ENDE STUFEN-ANWEISUNG ---\n";
            $threadContext .= $stageHint;
        }

        $contact  = DB::selectOne("SELECT phone FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        $hasPhone = !empty($contact->phone);

        $prop     = DB::selectOne("SELECT address, city FROM properties WHERE id = ?", [$propertyId]);
        $propAddr = ($prop->address ?? '') . ', ' . ($prop->city ?? '');

        $kbContext = '';
        $kbItems   = DB::select("
            SELECT category, title, content FROM property_knowledge
            WHERE property_id = ? AND is_active = 1
            ORDER BY confidence DESC LIMIT 10
        ", [$propertyId]);
        if (!empty($kbItems)) {
            $kbContext = "OBJEKTWISSEN:\n";
            $chars     = 0;
            foreach ($kbItems as $k) {
                $k    = (array) $k;
                $line = "- {$k['title']}: {$k['content']}\n";
                if ($chars + strlen($line) > 1200) break;
                $kbContext .= $line;
                $chars     += strlen($line);
            }
        }

        $anthropic = app(\App\Services\AnthropicService::class);
        $isSecondFollowup = $stage >= 2;
        $result    = $anthropic->generateFollowupDraft(
            $stakeholder, $propAddr, $threadContext, $kbContext,
            $hasPhone, 'professional', $daysSinceLastContact, $hasUnansweredQuestion, $today, $isSecondFollowup
        );

        if (!$result || empty($result['email_subject']) || empty($result['email_body'])) {
            return [];
        }

        return [
            'subject' => $result['email_subject'],
            'body'    => $result['email_body'],
        ];
    }

    /**
     * Mark a contact as called.
     */
    public function markCalled(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $stakeholder = $input['stakeholder'] ?? '';
        $propertyId  = intval($input['property_id'] ?? 0);
        $note        = $input['note'] ?? 'Telefonisch nachgefasst';

        if (!$stakeholder || !$propertyId) {
            return response()->json(['error' => 'stakeholder and property_id required'], 400);
        }

        DB::insert("
            INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category, created_at)
            VALUES (?, NOW(), ?, ?, NULL, 'email-out', NOW())
        ", [$propertyId, $stakeholder, $note]);

        return response()->json(['success' => true]);
    }

}