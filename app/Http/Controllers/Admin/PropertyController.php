<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\KaufanbotHelper;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PropertyController extends Controller
{
    /**
     * Property health score and stats.
     */
    public function health(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        // Multi-User: broker_id pruefen
        $brokerId = \Auth::id();
        $brokerSql = $brokerId ? " AND p.broker_id = ?" : "";

        $property = (array) DB::selectOne("
            SELECT p.*, c.name as owner_name, c.email as owner_email,
                   DATEDIFF(NOW(), p.inserat_since) as days_on_market
            FROM properties p
            JOIN customers c ON c.id = p.customer_id
            WHERE p.id = ? {$brokerSql}
        ", $brokerId ? [$propertyId, $brokerId] : [$propertyId]);

        if (empty($property) || !$property['id']) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $aStats = (array) DB::selectOne("
            SELECT
                COUNT(*) as total_activities,
                COUNT(DISTINCT CASE WHEN category IN ('anfrage','email-in') THEN stakeholder END) as unique_leads,
                SUM(category IN ('email-out','expose')) as outbound,
                SUM(category='besichtigung') as viewing_requests,
                SUM(category='kaufanbot') as offers,
                SUM(category='absage') as cancellations,
                MIN(CASE WHEN category IN ('anfrage','email-in') THEN activity_date END) as first_inquiry,
                MAX(CASE WHEN category IN ('anfrage','email-in') THEN activity_date END) as last_inquiry
            FROM activities WHERE property_id = ?
        ", [$propertyId]);

        $eStats = [
            'total_emails'      => $aStats['total_activities'] ?? 0,
            'inbound'           => $aStats['unique_leads'] ?? 0,
            'outbound'          => $aStats['outbound'] ?? 0,
            'viewing_requests'  => $aStats['viewing_requests'] ?? 0,
            'offers'            => KaufanbotHelper::count($propertyId),
            'cancellations'     => $aStats['cancellations'] ?? 0,
            'first_inquiry'     => $aStats['first_inquiry'] ?? null,
            'last_inquiry'      => $aStats['last_inquiry'] ?? null,
        ];

        $viewingStats = ['total' => 0, 'done' => 0, 'upcoming' => 0];
        try {
            $viewingStats = (array) DB::selectOne("
                SELECT COUNT(*) as total,
                    SUM(status='durchgefuehrt') as done,
                    SUM(status IN ('geplant','bestaetigt') AND viewing_date >= CURDATE()) as upcoming
                FROM viewings WHERE property_id = ?
            ", [$propertyId]);
        } catch (\Exception $e) {
            \Log::warning('viewing_stats query failed', ['property_id' => $propertyId, 'error' => $e->getMessage()]);
        }

        $norm = StakeholderHelper::normSH('stakeholder');
        $openConvs = (int) DB::selectOne("
            SELECT COUNT(*) as cnt FROM (
                SELECT {$norm} as norm_name,
                    SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY activity_date DESC), ',', 1) as last_cat
                FROM activities WHERE property_id = ?
                GROUP BY norm_name
                HAVING last_cat IN ('anfrage', 'email-in', 'besichtigung', 'kaufanbot')
            ) sub
        ", [$propertyId])->cnt;

        // Health score
        $score   = 50;
        $leads   = (int)($aStats['unique_leads'] ?? 0);
        $outbound = (int)($eStats['outbound'] ?? 0);
        $dom     = (int)($property['days_on_market'] ?? 0);

        if ($leads > 15) $score += 15;
        elseif ($leads > 8) $score += 10;
        elseif ($leads > 3) $score += 5;
        elseif ($leads < 2) $score -= 10;

        if ($outbound > 0 && $leads > 0) {
            $ratio = $outbound / $leads;
            if ($ratio > 1.0) $score += 10;
            elseif ($ratio < 0.5) $score -= 10;
        }

        if ((int)($eStats['offers'] ?? 0) > 0) $score += 15;
        if ((int)($viewingStats['done'] ?? 0) > 3) $score += 10;
        if ($dom > 90) $score -= 15;
        elseif ($dom > 60) $score -= 5;

        $score = max(0, min(100, $score));

        return response()->json([
            'property'           => $property,
            'email_stats'        => $eStats,
            'viewing_stats'      => $viewingStats,
            'activity_count'     => (int)$aStats['total_activities'],
            'open_conversations' => $openConvs,
            'health_score'       => $score,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Set/unset property on-hold.
     */
    public function setOnHold(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $input      = $request->json()->all();
        $propertyId = intval($input['property_id'] ?? 0);
        $onHold     = intval($input['on_hold'] ?? 1);
        $note       = trim($input['note'] ?? '');

        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $brokerId = \Auth::id();
        $property = $brokerId
            ? DB::selectOne('SELECT id, ref_id, address FROM properties WHERE id = ? AND broker_id = ?', [$propertyId, $brokerId])
            : DB::selectOne('SELECT id, ref_id, address FROM properties WHERE id = ?', [$propertyId]);
        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        if ($onHold) {
            DB::update('UPDATE properties SET on_hold = 1, on_hold_note = ?, on_hold_since = NOW() WHERE id = ?', [$note ?: null, $propertyId]);
            $actNote = $note ? "On Hold: {$note}" : 'On Hold gesetzt';
            DB::insert('INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category, created_at) VALUES (?, NOW(), ?, ?, ?, ?, NOW())',
                [$propertyId, 'SR-Homes', $actNote, 'On Hold', 'update']);
            return response()->json(['success' => true, 'message' => 'Property on hold gesetzt', 'property_id' => $propertyId]);
        } else {
            DB::update('UPDATE properties SET on_hold = 0, on_hold_note = NULL, on_hold_since = NULL WHERE id = ?', [$propertyId]);
            DB::insert('INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category, created_at) VALUES (?, NOW(), ?, ?, ?, ?, NOW())',
                [$propertyId, 'SR-Homes', 'On Hold aufgehoben', 'Aktiv', 'update']);
            return response()->json(['success' => true, 'message' => 'On Hold aufgehoben', 'property_id' => $propertyId]);
        }
    }

    /**
     * Fix/update an activity record.
     */
    public function fixActivity(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $sets   = [];
        $params = [];
        foreach (['stakeholder', 'activity', 'result', 'category', 'activity_date'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $sets[]   = "{$field} = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($sets)) {
            return response()->json(['error' => 'nothing to update']);
        }

        $params[] = $id;
        DB::update('UPDATE activities SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        // Also fix portal_emails stakeholder if needed
        if (isset($input['stakeholder'])) {
            DB::update('UPDATE portal_emails SET stakeholder = ? WHERE id = (SELECT source_email_id FROM activities WHERE id = ?)',
                [$input['stakeholder'], $id]);
        }

        return response()->json(['success' => true, 'updated_id' => $id]);
    }

    /**
     * Fix expose categories.
     */
    public function fixExposeCategories(Request $request): JsonResponse
    {
        $mode = $request->query('mode', 'realty_status');

        if ($mode === 'revert_all') {
            $actReverted = DB::update("UPDATE activities SET category = 'expose' WHERE category = 'email-out' AND (activity LIKE '%xposé%' OR activity LIKE '%xpose%')");
            $emailReverted = DB::update("UPDATE portal_emails pe INNER JOIN activities a ON a.source_email_id = pe.id SET pe.category = 'expose' WHERE a.category = 'expose' AND pe.category = 'email-out'");
            return response()->json(['success' => true, 'mode' => 'revert_all', 'activities_reverted' => $actReverted, 'emails_reverted' => $emailReverted]);
        } elseif ($mode === 'fix_with_attachment') {
            $emailsFixed = DB::update("UPDATE portal_emails SET category = 'email-out' WHERE category = 'expose' AND (has_attachment = 0 OR has_attachment IS NULL)");
            $actFixed = DB::update("UPDATE activities a INNER JOIN portal_emails pe ON pe.id = a.source_email_id SET a.category = 'email-out' WHERE a.category = 'expose' AND (pe.has_attachment = 0 OR pe.has_attachment IS NULL)");
            return response()->json(['success' => true, 'mode' => 'fix_with_attachment', 'emails_fixed' => $emailsFixed, 'activities_fixed' => $actFixed]);
        } else {
            $r  = DB::select('SELECT category, COUNT(*) as cnt FROM portal_emails GROUP BY category ORDER BY cnt DESC');
            $r2 = DB::select('SELECT category, COUNT(*) as cnt FROM activities GROUP BY category ORDER BY cnt DESC');
            return response()->json(['portal_emails' => $r, 'activities' => $r2]);
        }
    }

    /**
     * Generate or retrieve KI analysis for a property.
     */
    public function generateAnalysis(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        $stored = $request->query('stored', '0');

        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $brokerId = \Auth::id();
        $brokerSql = $brokerId ? " AND p.broker_id = ?" : "";
        $property = (array) DB::selectOne("
            SELECT p.*, c.name as owner_name
            FROM properties p
            LEFT JOIN customers c ON c.id = p.customer_id
            WHERE p.id = ? {$brokerSql}
        ", $brokerId ? [$propertyId, $brokerId] : [$propertyId]);

        if (empty($property) || !$property['id']) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $propertyAddress = trim(($property['address'] ?? '') . ', ' . ($property['city'] ?? ''), ', ');

        // Check for stored analysis
        if ($stored === '1') {
            $cached = DB::selectOne("
                SELECT analysis_json, created_at FROM property_analyses
                WHERE property_id = ? ORDER BY created_at DESC LIMIT 1
            ", [$propertyId]);
            if ($cached) {
                $data = json_decode($cached->analysis_json, true);
                if ($data) {
                    $data['generatedAt'] = $cached->created_at;
                    return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
                }
            }
            return response()->json(['empty' => true]);
        }

        // Gather stats
        $stats = (array) DB::selectOne("
            SELECT
                COUNT(*) as total_activities,
                COUNT(DISTINCT CASE WHEN category IN ('anfrage','email-in') THEN stakeholder END) as unique_leads,
                SUM(category IN ('email-out','expose')) as outbound,
                SUM(category='besichtigung') as viewings,
                SUM(category='kaufanbot') as offers,
                SUM(category='absage') as cancellations,
                DATEDIFF(NOW(), MIN(activity_date)) as days_active
            FROM activities WHERE property_id = ?
        ", [$propertyId]);

        // Override offers count with KaufanbotHelper
        $stats['offers'] = KaufanbotHelper::count($propertyId);

        $activities = array_map(fn($a) => (array) $a, DB::select("
            SELECT activity_date, category, stakeholder, activity, result
            FROM activities WHERE property_id = ?
            ORDER BY activity_date DESC LIMIT 30
        ", [$propertyId]));

        // Generate via AI
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            $result = $anthropic->generateDashboardAnalysis($stats, $activities, $propertyAddress);

            if (!$result) {
                return response()->json(['error' => 'KI-Analyse konnte nicht generiert werden'], 502);
            }

            // Try to parse as JSON
            $parsed = json_decode($result, true);
            if (!$parsed) {
                // Extract JSON from text
                if (preg_match('/\{[\s\S]*\}/', $result, $m)) {
                    $parsed = json_decode($m[0], true);
                }
            }

            if (!$parsed) {
                $parsed = [
                    'summary' => $result,
                    'realty_status' => 'yellow',
                    'headline' => 'Analyse für ' . $propertyAddress,
                ];
            }

            $parsed['generatedAt'] = now()->toDateTimeString();

            // Store the analysis
            try {
                DB::table('property_analyses')->insert([
                    'property_id' => $propertyId,
                    'analysis_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                // Table might not exist, create it
                try {
                    DB::statement("CREATE TABLE IF NOT EXISTS property_analyses (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        property_id INT NOT NULL,
                        analysis_json MEDIUMTEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX(property_id)
                    )");
                    DB::table('property_analyses')->insert([
                        'property_id' => $propertyId,
                        'analysis_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                } catch (\Exception $e2) { /* ignore storage errors */ }
            }

            return response()->json($parsed, 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json(['error' => 'AI request failed: ' . $e->getMessage()], 502);
        }
    }



    /**
     * Create a new property.
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'ref_id' => 'required|string|unique:properties,ref_id',
                'address' => 'required|string',
                'city' => 'nullable|string',
                'zip' => 'nullable|string',
                'type' => 'nullable|string',
                'purchase_price' => 'nullable|numeric',
                'total_area' => 'nullable|numeric',
                'rooms_amount' => 'nullable|numeric',
                'realty_status' => 'nullable|string',
                'customer_id' => 'nullable|integer',
                'customer_name' => 'nullable|string',
                'customer_email' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => implode(', ', collect($e->errors())->flatten()->toArray())], 422);
        }

        // Customer must be selected from existing list (no auto-create)
        $customerId = $data['customer_id'] ?? null;
        if (!$customerId) {
            return response()->json(['error' => 'Bitte wähle einen Eigentümer aus der Liste. Lege ihn zuerst unter Eigentümer an.'], 422);
        }

        try {
            $property = \App\Models\Property::create([
                'customer_id' => $customerId,
                'ref_id' => $data['ref_id'],
                'address' => $data['address'],
                'city' => $data['city'] ?? null,
                'zip' => $data['zip'] ?? null,
                'type' => $data['type'] ?? null,
                'purchase_price' => $data['purchase_price'] ?? null,
                'total_area' => $data['total_area'] ?? null,
                'rooms_amount' => $data['rooms_amount'] ?? null,
                'realty_status' => $data['realty_status'] ?? 'aktiv',
                'broker_id' => \Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'property' => $property,
                'message' => 'Objekt erfolgreich angelegt',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a property and all related data.
     */
    public function delete(Request $request): JsonResponse
    {
        $propertyId = $request->input('property_id');
        if (!$propertyId) {
            return response()->json(['error' => 'property_id erforderlich'], 422);
        }

        // Broker-Scoping: Admin + Office-Rollen (assistenz, backoffice) duerfen
        // JEDES Objekt loeschen. Makler nur die eigenen (broker_id=user id).
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $canDeleteAny = in_array($userType, ['admin', 'assistenz', 'backoffice'], true);

        $query = \App\Models\Property::where('id', $propertyId);
        if ($brokerId && !$canDeleteAny) {
            $query->where('broker_id', $brokerId);
        }
        $property = $query->first();
        if (!$property) {
            return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        }

        // Count related data
        $activityCount = \DB::table('activities')->where('property_id', $propertyId)->count();
        $emailCount = \DB::table('portal_emails')->where('property_id', $propertyId)->count();
        $kbCount = \DB::table('property_knowledge')->where('property_id', $propertyId)->count();

        // Check portal access
        $hasPortalAccess = $property->customer_id && \DB::table('users')
            ->where('customer_id', $property->customer_id)
            ->where('user_type', 'customer')
            ->exists();

        // If property has portal access or activities, set to inaktiv instead of deleting
        if ($hasPortalAccess || $activityCount > 0) {
            $confirm = $request->input('confirm', false);
            if (!$confirm) {
                $reasons = [];
                if ($hasPortalAccess) $reasons[] = 'aktivem Kundenportal-Zugang';
                if ($activityCount > 0) $reasons[] = "{$activityCount} Aktivitaeten";
                return response()->json([
                    'confirm_required' => true,
                    'force_inaktiv' => true,
                    'property' => ['ref_id' => $property->ref_id, 'address' => $property->address],
                    'related_data' => ['activities' => $activityCount, 'emails' => $emailCount, 'knowledge' => $kbCount],
                    'message' => "Objekt {$property->ref_id} kann nicht geloescht werden (". implode(', ', $reasons) ."). Stattdessen auf \"inaktiv\" setzen?",
                ]);
            }
            // User confirmed -> set to inaktiv
            $property->update(['realty_status' => 'inaktiv']);
            Cache::forget('website_properties');
            return response()->json([
                'success' => true,
                'set_inaktiv' => true,
                'message' => "Objekt {$property->ref_id} wurde auf inaktiv gesetzt",
            ]);
        }

        // No portal/activities -> normal delete with confirmation
        $confirm = $request->input('confirm', false);
        if (!$confirm) {
            return response()->json([
                'confirm_required' => true,
                'property' => ['ref_id' => $property->ref_id, 'address' => $property->address],
                'related_data' => ['activities' => $activityCount, 'emails' => $emailCount, 'knowledge' => $kbCount],
                'message' => "Objekt {$property->ref_id} ({$property->address}) endgueltig loeschen?",
            ]);
        }

        \DB::transaction(function () use ($propertyId, $property) {
            \DB::table('property_knowledge')->where('property_id', $propertyId)->delete();
            \DB::table('activities')->where('property_id', $propertyId)->delete();
            \DB::table('portal_emails')->where('property_id', $propertyId)->update(['property_id' => null]);
            \DB::table('property_files')->where('property_id', $propertyId)->delete();
            \DB::table('property_units')->where('property_id', $propertyId)->delete();
            if (\Schema::hasTable('property_images')) \DB::table('property_images')->where('property_id', $propertyId)->delete();
            \DB::table('portal_messages')->where('property_id', $propertyId)->delete();
            \DB::table('portal_documents')->where('property_id', $propertyId)->delete();
            \DB::table('property_analyses')->where('property_id', $propertyId)->delete();
            \DB::table('calendar_events')->where('property_id', $propertyId)->delete();
            \DB::table('viewings')->where('property_id', $propertyId)->delete();
            $property->delete();
        });

        Cache::forget('website_properties');

        return response()->json([
            'success' => true,
            'message' => "Objekt {$property->ref_id} und alle zugehoerigen Daten geloescht",
        ]);
    }

    public function setInactive(Request $request): JsonResponse
    {
        $propertyId = $request->input('property_id');
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $canAny = in_array($userType, ['admin', 'assistenz', 'backoffice'], true);
        $query = \App\Models\Property::where('id', $propertyId);
        if ($brokerId && !$canAny) $query->where('broker_id', $brokerId);
        $property = $query->firstOrFail();
        $property->update(['realty_status' => 'inaktiv']);
        Cache::forget('website_properties');
        return response()->json(['success' => true, 'message' => "Objekt {$property->ref_id} auf inaktiv gesetzt"]);
    }

    public function reactivate(Request $request): JsonResponse
    {
        $propertyId = $request->input('property_id');
        $newStatus = $request->input('realty_status', 'aktiv');
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $canAny = in_array($userType, ['admin', 'assistenz', 'backoffice'], true);
        $query = \App\Models\Property::where('id', $propertyId);
        if ($brokerId && !$canAny) $query->where('broker_id', $brokerId);
        $property = $query->firstOrFail();
        $property->update(['realty_status' => $newStatus]);
        Cache::forget('website_properties');
        return response()->json(['success' => true, 'message' => "Objekt {$property->ref_id} reaktiviert (Status: {$newStatus})"]);
    }

    /**
     * Kundenfeedback pro Objekt: Absagen, Gründe, Besichtigungs-Feedback, aktive Interessenten.
     */
    public function feedback(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        // Multi-User: pruefen ob Property dem Makler gehoert
        $brokerId = \Auth::id();
        if ($brokerId) {
            $owns = DB::selectOne("SELECT id FROM properties WHERE id = ? AND broker_id = ?", [$propertyId, $brokerId]);
            if (!$owns) return response()->json(['error' => 'Kein Zugriff auf dieses Objekt'], 403);
        }

        // ── 1. Stats ─────────────────────────────────────────────────────────────
        $catStats = DB::select("
            SELECT category, COUNT(*) as cnt
            FROM activities
            WHERE property_id = ?
              AND category IN ('anfrage','besichtigung','kaufanbot','absage',
                               'feedback_positiv','feedback_negativ','feedback_besichtigung')
            GROUP BY category
        ", [$propertyId]);
        $statMap = collect($catStats)->pluck('cnt', 'category')->toArray();

        $totalInq = DB::selectOne("
            SELECT COUNT(*) as cnt FROM activities
            WHERE property_id = ? AND category IN ('anfrage','email-in')
        ", [$propertyId]);

        // ── 2. Absagen ────────────────────────────────────────────────────────────
        $absagenRaw = DB::select("
            SELECT
                a.id,
                a.activity_date,
                a.stakeholder,
                a.result         AS ai_summary,
                a.category,
                pe.ai_summary    AS email_ai_summary,
                pe.body_text     AS email_body
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.property_id = ?
              AND a.category IN ('absage','feedback_negativ')
            ORDER BY a.activity_date DESC
        ", [$propertyId]);

        // Zusätzlich: portal_emails mit category=absage ohne passende Activity
        $emailAbsagen = DB::select("
            SELECT
                pe.id,
                DATE(pe.email_date) AS activity_date,
                pe.stakeholder,
                pe.ai_summary,
                pe.body_text,
                pe.from_name
            FROM portal_emails pe
            WHERE pe.property_id = ?
              AND pe.category = 'absage'
              AND pe.id NOT IN (
                  SELECT COALESCE(source_email_id, 0) FROM activities
                  WHERE property_id = ? AND source_email_id IS NOT NULL
              )
            ORDER BY pe.email_date DESC
        ", [$propertyId, $propertyId]);

        $absagen = collect($absagenRaw)->map(fn($a) => (array)$a)->toArray();
        foreach ($emailAbsagen as $ea) {
            $absagen[] = [
                'id'               => 'pe_' . $ea->id,
                'activity_date'    => $ea->activity_date,
                'stakeholder'      => $ea->stakeholder ?: $ea->from_name,
                'ai_summary'       => $ea->ai_summary,
                'category'         => 'absage',
                'email_ai_summary' => $ea->ai_summary,
                'email_body'       => $ea->body_text,
            ];
        }

        // ── 3. Keyword-Analyse: häufigste Absagegründe ───────────────────────────
        $gruendeMap = [
            'Preis'                  => ['preis','teuer','zu hoch','überteuert','preislich','kostet','budget'],
            'Lage & Umgebung'        => ['lage','standort','verkehr','anbindung','infrastruktur','umgebung','entfernung','pendel'],
            'Größe & Aufteilung'     => ['zu klein','zu groß','größe','zimmer','aufteilung','grundriss','wohnfläche','nutzfläche'],
            'Zustand & Renovierung'  => ['zustand','renovier','sanier','modernisier','renovierungsbedürftig','sanierungsbedürftig'],
            'Anderweitig entschieden'=> ['anderweit','andere immobilie','anders entschied','bereits gefunden','anderes objekt'],
            'Budget & Finanzierung'  => ['finanzier','kredit','bank','förder','eigenkapital','kein budget'],
            'Außenbereich'           => ['garten','terrasse','balkon','außen','freifläche','stellplatz','garage'],
            'Kein Interesse mehr'    => ['kein interesse','nicht mehr interes','suche beendet','suche eingestellt'],
        ];
        $gruendeCounts = array_fill_keys(array_keys($gruendeMap), 0);

        foreach ($absagen as &$abs) {
            $text = mb_strtolower(
                ($abs['email_ai_summary'] ?? '') . ' ' .
                ($abs['ai_summary'] ?? '') . ' ' .
                mb_substr($abs['email_body'] ?? '', 0, 600)
            );
            $matched = [];
            foreach ($gruendeMap as $label => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($text, $kw) !== false) {
                        $matched[] = $label;
                        $gruendeCounts[$label]++;
                        break;
                    }
                }
            }
            $abs['gruende'] = array_unique($matched) ?: ['Nicht spezifiziert'];
            $abs['summary'] = $abs['email_ai_summary'] ?: $abs['ai_summary'] ?: '';
            unset($abs['email_ai_summary'], $abs['ai_summary'], $abs['email_body']);
        }
        unset($abs);

        arsort($gruendeCounts);
        $gruendeList = array_values(
            array_map(fn($label, $cnt) => ['grund' => $label, 'count' => $cnt],
                array_keys(array_filter($gruendeCounts)),
                array_filter($gruendeCounts)
            )
        );

        // ── 4. Besichtigungs-Feedback ─────────────────────────────────────────────
        $bFeedback = DB::select("
            SELECT activity_date, stakeholder, result AS summary, category, activity
            FROM activities
            WHERE property_id = ?
              AND category IN ('besichtigung','feedback_besichtigung','feedback_positiv','feedback_negativ')
            ORDER BY activity_date DESC
            LIMIT 30
        ", [$propertyId]);

        // ── 5. Aktive Interessenten (nicht abgesagt) ──────────────────────────────
        $aktive = DB::select("
            SELECT DISTINCT a.stakeholder, MAX(a.activity_date) AS last_contact, MAX(a.category) AS last_cat
            FROM activities a
            WHERE a.property_id = ?
              AND a.stakeholder != ''
              AND a.stakeholder NOT IN (
                  SELECT DISTINCT stakeholder FROM activities
                  WHERE property_id = ? AND category IN ('absage','feedback_negativ') AND stakeholder != ''
              )
              AND a.category NOT IN ('email-out','expose','update','intern')
            GROUP BY a.stakeholder
            ORDER BY last_contact DESC
            LIMIT 20
        ", [$propertyId, $propertyId]);

        return response()->json([
            'stats' => [
                'anfragen'       => (int)($totalInq->cnt ?? 0),
                'besichtigungen' => (int)(($statMap['besichtigung'] ?? 0) + ($statMap['feedback_besichtigung'] ?? 0)),
                'kaufanbote'     => KaufanbotHelper::count($propertyId),
                'absagen'        => (int)(($statMap['absage'] ?? 0) + ($statMap['feedback_negativ'] ?? 0)),
                'positiv'        => (int)($statMap['feedback_positiv'] ?? 0),
            ],
            'absagen'              => $absagen,
            'gruende'              => $gruendeList,
            'besichtigungen'       => collect($bFeedback)->groupBy(fn($b) => mb_strtolower(trim($b->stakeholder ?: 'unbekannt')))->map(function($items, $name) {
                $first = $items->first();
                $summaries = $items->pluck('summary')->filter()->unique()->values()->toArray();
                return [
                    'stakeholder' => $first->stakeholder ?: 'Unbekannt',
                    'activity_date' => $items->max('activity_date'),
                    'category' => $first->category,
                    'summary' => implode(' | ', array_slice($summaries, 0, 3)),
                ];
            })->values()->toArray(),
            'aktive_interessenten' => collect($aktive)->map(fn($a) => (array)$a)->toArray(),
        ]);
    }


    /**
     * Generate a comprehensive Vermarktungsbericht for a property.
     */
    public function generateVermarktungsbericht(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        // Check property exists
        $property = DB::selectOne('SELECT id, address, city, ref_id FROM properties WHERE id = ?', [$propertyId]);
        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        try {
            // 1. Aggregate all data
            $aggregator = new \App\Services\ReportDataAggregator();
            $reportData = $aggregator->gather($propertyId);

            // 2. Generate AI report
            $ai = app(\App\Services\AnthropicService::class);
            $result = $ai->generateVermarktungsbericht($reportData, $propertyId);

            if (!$result) {
                return response()->json(['error' => 'KI-Bericht konnte nicht generiert werden. Bitte erneut versuchen.'], 502);
            }

            // 3. Store the report
            DB::table('property_analyses')->insert([
                'property_id'  => $propertyId,
                'report_type'  => 'vermarktungsbericht',
                'analysis_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_at'   => now(),
            ]);

            return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('Vermarktungsbericht generation failed', [
                'property_id' => $propertyId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Fehler bei der Berichtserstellung: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Get the latest stored Vermarktungsbericht for a property.
     */
    public function getVermarktungsbericht(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $report = DB::selectOne("
            SELECT analysis_json, created_at
            FROM property_analyses
            WHERE property_id = ? AND report_type = 'vermarktungsbericht'
            ORDER BY created_at DESC
            LIMIT 1
        ", [$propertyId]);

        if (!$report) {
            return response()->json(['empty' => true]);
        }

        $data = json_decode($report->analysis_json, true);
        if (!$data) {
            return response()->json(['empty' => true]);
        }

        $data['generatedAt'] = $report->created_at;
        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }


    /**
     * Export Vermarktungsbericht as PDF for the owner.
     */
    public function exportVermarktungsberichtPdf(Request $request)
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        // Get property
        $property = DB::selectOne('SELECT * FROM properties WHERE id = ?', [$propertyId]);
        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        // Get latest Vermarktungsbericht
        $report = DB::selectOne("
            SELECT analysis_json, created_at
            FROM property_analyses
            WHERE property_id = ? AND report_type = 'vermarktungsbericht'
            ORDER BY created_at DESC
            LIMIT 1
        ", [$propertyId]);

        if (!$report) {
            return response()->json(['error' => 'Kein Vermarktungsbericht vorhanden. Bitte zuerst einen generieren.'], 404);
        }

        $data = json_decode($report->analysis_json, true);
        if (!$data || !isset($data['owner'])) {
            return response()->json(['error' => 'Bericht-Daten unvollständig'], 500);
        }

        $owner = $data['owner'];
        $generatedAt = \Carbon\Carbon::parse($report->created_at)->format('d.m.Y, H:i') . ' Uhr';

                $broker = $data['broker'] ?? [];        $meta = $data['meta'] ?? [];        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.vermarktungsbericht-pdf', [
            'property'    => $property,
            'owner'       => $owner,
            'broker'      => $broker,
            'meta'        => $meta,
            'generatedAt' => $generatedAt,
            'logoBase64'  => $logoBase64,
        ]);

        $pdf->setPaper('A4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        $filename = 'Vermarktungsbericht_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $property->address ?? 'Objekt') . '_' . date('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }



}
