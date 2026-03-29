<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KaufanbotController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $normS = StakeholderHelper::normSHSurname('a.stakeholder');
        $partnerExclude = StakeholderHelper::partnerExcludeFilter('a.stakeholder');

        $from = $request->query('from', date('Y-m-d', strtotime('-12 months')));
        $to   = $request->query('to', date('Y-m-d'));

        // All Kaufanbot activities
        $rows = DB::select("
            SELECT
                a.stakeholder, a.property_id, a.activity_date, a.activity,
                p.ref_id, p.address, p.city,
                {$normS} as surname_key
            FROM activities a
            LEFT JOIN properties p ON a.property_id = p.id
            WHERE a.category = 'kaufanbot' AND {$partnerExclude}
            AND a.activity_date >= ? AND a.activity_date <= ?
            ORDER BY a.activity_date DESC
        ", [$from, $to . ' 23:59:59']);

        // Group by surname key
        $persons = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $skey = $r['surname_key'];
            if (!isset($persons[$skey])) {
                $persons[$skey] = [
                    'surname_key'   => $skey,
                    'display_name'  => $r['stakeholder'],
                    'properties'    => [],
                    'first_date'    => $r['activity_date'],
                    'last_date'     => $r['activity_date'],
                ];
            }
            if (strlen($r['stakeholder']) > strlen($persons[$skey]['display_name'])) {
                $persons[$skey]['display_name'] = $r['stakeholder'];
            }
            $persons[$skey]['last_date']  = max($persons[$skey]['last_date'], $r['activity_date']);
            $persons[$skey]['first_date'] = min($persons[$skey]['first_date'], $r['activity_date']);

            $pid = $r['property_id'] ?: 0;
            if (!isset($persons[$skey]['properties'][$pid])) {
                $persons[$skey]['properties'][$pid] = [
                    'property_id' => $r['property_id'],
                    'ref_id'      => $r['ref_id'],
                    'address'     => $r['address'],
                    'city'        => $r['city'],
                    'date'        => $r['activity_date'],
                ];
            }
        }

        // Check Absagen and enrich contact data
        $normS2 = StakeholderHelper::normSHSurname('a2.stakeholder');
        foreach ($persons as &$p) {
            $p['has_absage'] = false;
            foreach ($p['properties'] as &$prop) {
                $absCheck = DB::selectOne("
                    SELECT 1 as found FROM activities a2
                    WHERE a2.property_id = ? AND {$normS2} = ?
                    AND a2.category = 'absage' LIMIT 1
                ", [$prop['property_id'], $p['surname_key']]);
                $prop['has_absage'] = (bool)($absCheck->found ?? false);
                if ($prop['has_absage']) $p['has_absage'] = true;
            }
            $p['properties'] = array_values($p['properties']);

            // Contact lookup
            $contact = DB::selectOne("
                SELECT phone, email FROM contacts
                WHERE LOWER(TRIM(full_name)) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ?, '%')
                AND role NOT IN ('partner','bautraeger','intern','makler')
                LIMIT 1
            ", [$p['surname_key']]);
            $p['email'] = $contact->email ?? null;
            $p['phone'] = $contact->phone ?? null;

            // Email fallback
            if (!$p['email']) {
                $normSurnameStakeholder = StakeholderHelper::normSHSurname('a.stakeholder');
                $emailRow = DB::selectOne("
                    SELECT pe.from_email, pe.to_email, pe.direction, pe.body_text
                    FROM activities a
                    LEFT JOIN portal_emails pe ON a.source_email_id = pe.id
                    WHERE a.category = 'kaufanbot' AND {$normSurnameStakeholder} = ?
                    AND pe.id IS NOT NULL
                    ORDER BY a.activity_date DESC LIMIT 1
                ", [$p['surname_key']]);

                if ($emailRow) {
                    $candidateEmail = ($emailRow->direction === 'outbound') ? $emailRow->to_email : $emailRow->from_email;
                    if ($candidateEmail && !preg_match('/sr-homes\.at$/i', $candidateEmail)) {
                        $p['email'] = $candidateEmail;
                    }
                    if (!$p['phone'] && !empty($emailRow->body_text)) {
                        if (preg_match_all('/(?:Tel\.?|Telefon|Mobil|Handy|Phone)[:\s.]*([+\d\s\/\-()]{7,20})/i', $emailRow->body_text, $pms)) {
                            foreach ($pms[1] as $candidate) {
                                $clean = preg_replace('/[\s\-\/()]/', '', trim($candidate));
                                if (str_contains($clean, '436642600930') || str_contains($clean, '6642600930')) continue;
                                $p['phone'] = trim($candidate);
                                break;
                            }
                        }
                    }
                }
            }

            // Last resort email fallback
            if (!$p['email']) {
                $normSurnameStakeholder2 = StakeholderHelper::normSHSurname('stakeholder');
                $fallback = DB::selectOne("
                    SELECT from_email FROM portal_emails
                    WHERE direction = 'inbound' AND {$normSurnameStakeholder2} = ?
                    AND from_email NOT LIKE '%sr-homes.at'
                    ORDER BY email_date DESC LIMIT 1
                ", [$p['surname_key']]);
                if ($fallback) $p['email'] = $fallback->from_email;
            }
        }
        unset($p);

        $personList = array_values($persons);
        usort($personList, fn($a, $b) => strcmp($b['last_date'], $a['last_date']));

        // Monthly counts
        $monthCounts = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $month = substr($r['activity_date'], 0, 7);
            $key = $r['surname_key'] . '|' . ($r['property_id'] ?: 0);
            $monthCounts[$month][$key] = true;
        }
        $monthlyData = [];
        foreach ($monthCounts as $month => $keys) {
            $monthlyData[] = ['month' => $month, 'count' => count($keys)];
        }
        usort($monthlyData, fn($a, $b) => strcmp($a['month'], $b['month']));

        $totalPersons = count($persons);
        $uniqueDeals  = 0;
        foreach ($persons as $p) { $uniqueDeals += count($p['properties']); }

        return response()->json([
            'total'        => $totalPersons,
            'total_deals'  => $uniqueDeals,
            'from'         => $from,
            'to'           => $to,
            'monthly'      => $monthlyData,
            'persons'      => $personList,
            'details'      => $personList, // legacy compat
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required']);
        }

        $data        = $request->json()->all();
        $propertyId  = intval($data['property_id'] ?? 0);
        $stakeholder = trim($data['stakeholder'] ?? '');
        $email       = trim($data['email'] ?? '');
        $phone       = trim($data['phone'] ?? '');
        $date        = $data['date'] ?? date('Y-m-d');
        $notes       = trim($data['notes'] ?? '');

        if (!$propertyId || !$stakeholder) {
            return response()->json(['error' => 'property_id and stakeholder required']);
        }

        $prop = DB::selectOne('SELECT ref_id, address FROM properties WHERE id = ?', [$propertyId]);
        $refId = $prop->ref_id ?? '';

        $activityText = 'Kaufanbot manuell eingetragen' . ($notes ? ": {$notes}" : '');
        $activityId = DB::table('activities')->insertGetId([
            'property_id'   => $propertyId,
            'activity_date' => $date,
            'stakeholder'   => $stakeholder,
            'activity'      => $activityText,
            'result'        => "Manuell erfasstes Kaufanbot für {$refId}",
            'category'      => 'kaufanbot',
        ]);

        // Create/update contact
        if ($email || $phone) {
            $existing = DB::selectOne('SELECT id FROM contacts WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1', [$stakeholder]);
            if ($existing) {
                $updates = [];
                $params  = [];
                if ($email) { $updates[] = 'email = ?'; $params[] = $email; }
                if ($phone) { $updates[] = 'phone = ?'; $params[] = $phone; }
                $updates[] = "property_ids = JSON_ARRAY_APPEND(COALESCE(property_ids, '[]'), '\$', ?)";
                $params[] = strval($propertyId);
                $params[] = $existing->id;
                DB::update('UPDATE contacts SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?', $params);
            } else {
                DB::insert('INSERT INTO contacts (full_name, email, phone, property_ids, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                    [$stakeholder, $email ?: null, $phone ?: null, json_encode([$propertyId]), 'manual_kaufanbot']);
            }
        }

        return response()->json(['success' => true, 'activity_id' => $activityId]);
    }

    /**
     * list_kaufanbote - Get all kaufanbot activities with property and status for Kanban board.
     * Uses AI-powered name clustering for deduplication.
     */
    public function listKaufanbote(Request $request): JsonResponse
    {
        $normSurname = StakeholderHelper::normSHSurname('a.stakeholder');

        // Fetch all kaufanbot activities
        $rows = DB::select("
            SELECT a.id, a.activity_date, a.stakeholder, a.activity, a.result,
                   a.kaufanbot_status, a.category,
                   p.id as property_id, p.ref_id, p.address, p.city,
                   c.full_name as contact_name, c.email as contact_email, c.phone as contact_phone,
                   {$normSurname} as surname_key
            FROM activities a
            LEFT JOIN properties p ON a.property_id = p.id
            LEFT JOIN contacts c ON " . StakeholderHelper::normSHSurname('c.full_name') . " = {$normSurname}
            WHERE a.category = 'kaufanbot'
            ORDER BY a.activity_date DESC
            LIMIT 500
        ");

        // Group by surname_key (reliable surname matching), keep latest per group
        $grouped = [];
        foreach ($rows as $r) {
            $key = $r->surname_key ?: strtolower(trim($r->stakeholder));
            if (!isset($grouped[$key])) {
                $grouped[$key] = (array)$r;
                // Use longest name as display name
                $grouped[$key]['_all_names'] = [$r->stakeholder];
            } else {
                $grouped[$key]['_all_names'][] = $r->stakeholder;
                // Keep the entry with more detail (longer stakeholder name)
                if (strlen($r->stakeholder) > strlen($grouped[$key]['stakeholder'])) {
                    $oldStatus = $grouped[$key]['kaufanbot_status'];
                    $oldId = $grouped[$key]['id'];
                    $grouped[$key] = array_merge((array)$r, [
                        '_all_names' => $grouped[$key]['_all_names'],
                        'kaufanbot_status' => $oldStatus ?: $r->kaufanbot_status,
                    ]);
                }
                // Keep newer contact info
                if ($r->contact_email && !$grouped[$key]['contact_email']) {
                    $grouped[$key]['contact_email'] = $r->contact_email;
                }
                if ($r->contact_phone && !$grouped[$key]['contact_phone']) {
                    $grouped[$key]['contact_phone'] = $r->contact_phone;
                }
            }
        }

        // Now use AI to find clusters among remaining groups that might be the same person
        // Only if there are enough entries to make it worthwhile
        if (count($grouped) > 1) {
            $names = array_map(fn($g) => $g['stakeholder'], $grouped);
            $nameList = implode("\n", array_values($names));
            
            try {
                $ai = app(\App\Services\AnthropicService::class);
                $result = $ai->chatJson(
                    "Du bist ein Namens-Matching-System. Identifiziere Gruppen von Namen die zur SELBEN Person gehören. Beachte: Titel (Dr., Mag., etc.), Abkürzungen, Tippfehler, fehlende Vornamen. Nur clustern wenn du SICHER bist dass es dieselbe Person ist.",
                    "Finde Duplikate in dieser Namensliste:\n{$nameList}\n\nAntworte als JSON-Array von Gruppen. Jede Gruppe enthält die Namen die zusammengehören. Einzelne Namen ohne Duplikat NICHT aufführen.\nBeispiel: [[\"Dr. Ernst Grubeck\", \"Grubeck\"], [\"H. Maier\", \"Hans Maier\"]]\n\nNur sichere Matches!",
                    500
                );
                
                if (is_array($result)) {
                    foreach ($result as $cluster) {
                        if (!is_array($cluster) || count($cluster) < 2) continue;
                        
                        // Find the grouped keys for these names
                        $clusterKeys = [];
                        foreach ($cluster as $name) {
                            foreach ($grouped as $key => $g) {
                                if (in_array($name, $g['_all_names']) || strcasecmp(trim($g['stakeholder']), trim($name)) === 0) {
                                    $clusterKeys[] = $key;
                                }
                            }
                        }
                        $clusterKeys = array_unique($clusterKeys);
                        
                        if (count($clusterKeys) > 1) {
                            // Merge: keep the one with most detail
                            usort($clusterKeys, fn($a, $b) => strlen($grouped[$b]['stakeholder']) - strlen($grouped[$a]['stakeholder']));
                            $primary = array_shift($clusterKeys);
                            foreach ($clusterKeys as $mergeKey) {
                                if (!isset($grouped[$mergeKey])) continue;
                                $merged = $grouped[$mergeKey];
                                if ($merged['contact_email'] && !$grouped[$primary]['contact_email']) {
                                    $grouped[$primary]['contact_email'] = $merged['contact_email'];
                                }
                                if ($merged['contact_phone'] && !$grouped[$primary]['contact_phone']) {
                                    $grouped[$primary]['contact_phone'] = $merged['contact_phone'];
                                }
                                if (!$grouped[$primary]['kaufanbot_status'] && $merged['kaufanbot_status']) {
                                    $grouped[$primary]['kaufanbot_status'] = $merged['kaufanbot_status'];
                                }
                                unset($grouped[$mergeKey]);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('AI name clustering failed: ' . $e->getMessage());
            }
        }

        // Clean up internal fields
        $items = array_values(array_map(function($g) {
            unset($g['_all_names'], $g['surname_key']);
            return $g;
        }, $grouped));

        return response()->json(['kaufanbote' => $items], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * update_kaufanbot_status - Update the Kanban status of a kaufanbot activity.
     */
    public function updateKaufanbotStatus(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data   = $request->json()->all();
        $id     = intval($data['id'] ?? 0);
        $status = trim($data['status'] ?? '');

        $validStatuses = ['', 'eingegangen', 'eigentuemer_informiert', 'in_verhandlung', 'finanzierung_pruefen', 'akzeptiert', 'abgelehnt'];
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }
        if (!in_array($status, $validStatuses)) {
            return response()->json(['error' => 'invalid status'], 400);
        }

        DB::table('activities')->where('id', $id)->update([
            'kaufanbot_status' => $status ?: null,
            'updated_at'       => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a kaufanbot activity (changes category to sonstiges).
     */
    public function deleteKaufanbot(Request $request): JsonResponse
    {
        $activityId = intval($request->json('activity_id', 0));
        if (!$activityId) return response()->json(['error' => 'activity_id required'], 400);

        DB::update("UPDATE activities SET category = 'sonstiges' WHERE id = ? AND category = 'kaufanbot'", [$activityId]);

        return response()->json(['ok' => true]);
    }

}