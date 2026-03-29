<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\StakeholderHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailController extends Controller
{
    /**
     * Email context for reply composer — with full thread.
     */
    public function context(Request $request): JsonResponse
    {
        $emailId = intval($request->query('email_id', 0));
        $type = $request->query('type', ''); // 'activity' to force activity lookup first
        if (!$emailId) {
            return response()->json(['error' => 'email_id required'], 400);
        }

        $email = null;

        // Activity lookup (used from unanswered/followup tabs where ID is an activity ID)
        if ($type === 'activity') {
            $act = DB::selectOne("SELECT a.*, p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                    p.object_type, p.realty_status as property_status, p.highlights, p.realty_description as property_desc,
                    c.name as owner_name
                FROM activities a
                LEFT JOIN properties p ON a.property_id = p.id
                LEFT JOIN customers c ON p.customer_id = c.id
                WHERE a.id = ?", [$emailId]);
            if ($act) {
                // Save original activity stakeholder for thread matching (avoids name order issues)
                $activityStakeholder = $act->stakeholder;
                // If activity has a source email, load the actual email data
                if ($act->source_email_id) {
                    $srcEmail = DB::selectOne("SELECT * FROM portal_emails WHERE id = ?", [$act->source_email_id]);
                    if ($srcEmail) {
                        $email = (array) $srcEmail;
                        $email['address'] = $act->address;
                        $email['city'] = $act->city;
                        $email['ref_id'] = $act->ref_id;
                        $email['purchase_price'] = $act->purchase_price;
                        $email['total_area'] = $act->total_area;
                        $email['rooms_amount'] = $act->rooms_amount;
                        $email['type'] = $act->object_type;
                        $email['property_status'] = $act->property_status;
                        $email['highlights'] = $act->highlights;
                        $email['property_desc'] = $act->property_desc;
                        $email['owner_name'] = $act->owner_name;
                        // Use activity stakeholder for thread matching (email from_name may be in different order)
                        $email['_activity_stakeholder'] = $activityStakeholder;
                    }
                }
                if (!$email) {
                    $ct = DB::selectOne("SELECT email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci", [$act->stakeholder]);
                    $email = [
                        'id' => $act->id,
                        'from_name' => $act->stakeholder,
                        'from_email' => $ct->email ?? '',
                        'subject' => $act->activity,
                        'body_text' => $act->result ?? '',
                        'email_date' => $act->activity_date,
                        'category' => $act->category,
                        'property_id' => $act->property_id,
                        'stakeholder' => $act->stakeholder,
                        'address' => $act->address,
                        'city' => $act->city,
                        'ref_id' => $act->ref_id,
                        'purchase_price' => $act->purchase_price,
                        'total_area' => $act->total_area,
                        'rooms_amount' => $act->rooms_amount,
                        'type' => $act->object_type,
                        'property_status' => $act->property_status,
                        'highlights' => $act->highlights,
                        'property_desc' => $act->property_desc,
                        'owner_name' => $act->owner_name,
                    ];
                }
            }
        }

        // Default: try portal_emails first
        if (!$email) {
            $email = (array) DB::selectOne("
                SELECT pe.*, p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                       p.object_type, p.realty_status as property_status, p.highlights, p.realty_description as property_desc,
                       c.name as owner_name
                FROM portal_emails pe
                LEFT JOIN properties p ON pe.property_id = p.id
                LEFT JOIN customers c ON p.customer_id = c.id
                WHERE pe.id = ?
            ", [$emailId]);
        }

        if (empty($email) || !$email['id']) {
            // Fallback to activities
            $email = (array) DB::selectOne("
                SELECT a.id, a.stakeholder as from_name,
                    COALESCE(pe.from_email, ct.email, '') as from_email,
                    a.activity as subject, a.result as body_text,
                    COALESCE(pe.body_text, '') as source_body_text,
                    a.source_email_id,
                    a.activity_date as email_date, a.category, a.property_id,
                    p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                    p.object_type, p.realty_status as property_status, p.highlights, p.realty_description as property_desc,
                    c.name as owner_name
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                LEFT JOIN contacts ct ON ct.full_name COLLATE utf8mb4_unicode_ci = a.stakeholder COLLATE utf8mb4_unicode_ci
                LEFT JOIN properties p ON a.property_id = p.id
                LEFT JOIN customers c ON p.customer_id = c.id
                WHERE a.id = ?
            ", [$emailId]);
        }

        if (empty($email) || !$email['id']) {
            return response()->json(['error' => 'Email not found'], 404);
        }

        // Full conversation thread - prefer activity stakeholder to avoid name-order mismatches
        $stakeholder = $email['_activity_stakeholder'] ?? $email['from_name'] ?? $email['stakeholder'] ?? '';
        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");
        $pid       = intval($email['property_id']);

        $thread = DB::select("
            SELECT
                a.id, a.activity_date, a.created_at,
                CASE WHEN a.category IN ('anfrage','email-in','besichtigung','kaufanbot','absage') THEN 'inbound' ELSE 'outbound' END as direction,
                a.stakeholder as from_name,
                a.activity as subject,
                a.result as ai_summary,
                a.category, a.duration,
                pe.body_text as full_body,
                pe.body_html as full_body_html,
                pe.from_email,
                pe.subject as email_subject
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            WHERE a.property_id = ? AND {$norm} = {$normInput}
            ORDER BY a.activity_date ASC, a.id ASC
        ", [$pid]);

        $lastThread = !empty($thread) ? (array) end($thread) : [];

        // Extract prospect email for noreply senders - check main body, source body, and html
        $bodyForExtraction = $email['body_text'] ?? '';
        if (empty(trim($bodyForExtraction)) && !empty($email['source_body_text'] ?? '')) {
            $bodyForExtraction = $email['source_body_text'];
        }
        if (empty(trim($bodyForExtraction)) && !empty($email['body_html'] ?? '')) {
            $bodyForExtraction = $email['body_html'];
        }
        $prospectEmail = $this->extractProspectEmail($email['from_email'] ?? null, $bodyForExtraction);
        if ($prospectEmail) {
            $email['prospect_email'] = $prospectEmail;
        }

        return response()->json([
            'email'               => $email,
            'thread'              => $thread,
            'prospect_email'      => $prospectEmail,
            'conversation_status' => in_array($lastThread['category'] ?? '', ['anfrage','email-in','besichtigung','kaufanbot']) ? 'open' : 'handled',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AI reply suggestion.
     */
    public function aiReply(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $input   = $request->json()->all();
        $emailId = intval($input['email_id'] ?? 0);
        $tone    = $input['tone'] ?? 'professional';
        $detailLevel = $input['detail_level'] ?? 'standard';
        $type    = $input['type'] ?? '';

        if (!$emailId) {
            return response()->json(['error' => 'email_id required'], 400);
        }

        $email = null;

        // If type=activity, look up activity first (avoids ID collision with portal_emails)
        if ($type === 'activity') {
            $act = DB::selectOne("SELECT a.*, p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                    p.object_type, p.highlights, p.realty_description as property_desc,
                    p.heating, p.construction_year, p.year_renovated, p.living_area, p.free_area
                FROM activities a
                LEFT JOIN properties p ON a.property_id = p.id
                WHERE a.id = ?", [$emailId]);
            if ($act) {
                if ($act->source_email_id) {
                    $srcEmail = DB::selectOne("SELECT pe.*, p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                            p.object_type, p.highlights, p.realty_description as property_desc,
                            p.heating, p.construction_year, p.year_renovated, p.living_area, p.free_area
                        FROM portal_emails pe
                        LEFT JOIN properties p ON pe.property_id = p.id
                        WHERE pe.id = ?", [$act->source_email_id]);
                    if ($srcEmail) {
                        $email = (array) $srcEmail;
                        $email['_activity_stakeholder'] = $act->stakeholder;
                        $email['_act_source_email_id'] = $act->source_email_id; // explicit carry-through
                    }
                }
                if (!$email) {
                    $ct = DB::selectOne("SELECT email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci", [$act->stakeholder]);
                    $email = [
                        'id' => $act->id, 'from_name' => $act->stakeholder,
                        'from_email' => $ct->email ?? '',
                        'subject' => $act->activity, 'body_text' => $act->result ?? '',
                        'body_html' => '', 'source_email_id' => $act->source_email_id,
                        'email_date' => $act->activity_date, 'category' => $act->category,
                        'property_id' => $act->property_id,
                        'address' => $act->address, 'city' => $act->city, 'ref_id' => $act->ref_id,
                        'purchase_price' => $act->purchase_price, 'total_area' => $act->total_area, 'rooms_amount' => $act->rooms_amount,
                        'type' => $act->object_type, 'highlights' => $act->highlights,
                        'property_desc' => $act->property_desc,
                    ];
                }
            }
        }

        // Default: try portal_emails, fallback to activities
        if (!$email) {
            $email = (array) DB::selectOne("
                SELECT pe.*, p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                       p.object_type, p.highlights, p.realty_description as property_desc,
                       p.heating, p.construction_year, p.year_renovated, p.living_area, p.free_area
                FROM portal_emails pe
                LEFT JOIN properties p ON pe.property_id = p.id
                WHERE pe.id = ?
            ", [$emailId]);
        }

        if (empty($email) || !$email['id']) {
            $email = (array) DB::selectOne("
                SELECT a.id, a.stakeholder as from_name,
                    COALESCE(pe.from_email, c.email, '') as from_email,
                    a.activity as subject, a.result as body_text,
                    COALESCE(pe.body_html, '') as body_html,
                    a.source_email_id,
                    a.activity_date as email_date, a.category, a.property_id,
                    p.address, p.city, p.ref_id, p.purchase_price, p.total_area, p.rooms_amount,
                    p.object_type, p.highlights, p.realty_description as property_desc
                FROM activities a
                LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
                LEFT JOIN contacts c ON c.full_name COLLATE utf8mb4_unicode_ci = a.stakeholder COLLATE utf8mb4_unicode_ci
                LEFT JOIN properties p ON a.property_id = p.id
                WHERE a.id = ?
            ", [$emailId]);
        }

        if (empty($email) || !$email['id']) {
            return response()->json(['error' => 'Email not found'], 404);
        }

        // Check for pre-generated draft in email_drafts (return instantly, no AI call needed)
        // Only for non-regenerate requests (standard detail level)
        if ($detailLevel === 'standard') {
            $sourceEmailId = $email['source_email_id'] ?? null;
            $cachedDraft = null;
            if ($sourceEmailId) {
                $cachedDraft = DB::table('email_drafts')
                    ->where('source_email_id', $sourceEmailId)
                    ->orderBy('id', 'desc')
                    ->first();
            }
            // Also check by activity id stored as source
            if (!$cachedDraft && $type === 'activity') {
                $cachedDraft = DB::table('email_drafts')
                    ->where('source_email_id', $emailId)
                    ->orderBy('id', 'desc')
                    ->first();
            }
            if ($cachedDraft && !empty($cachedDraft->body)) {
                $stakeholderTmp = $email['_activity_stakeholder'] ?? $email['from_name'] ?? $email['stakeholder'] ?? '';
                $prospectEmailTmp = $this->extractProspectEmail($email['from_email'] ?? null, '');
                return response()->json([
                    'reply_text'     => $cachedDraft->body,
                    'to'             => $cachedDraft->to_email ?: ($prospectEmailTmp ?: ($email['from_email'] ?? '')),
                    'prospect_email' => $prospectEmailTmp ?: '',
                    'to_name'        => $stakeholderTmp,
                    'subject'        => $cachedDraft->subject ?: ('Re: ' . ($email['subject'] ?? '')),
                    'tone'           => $tone,
                    'property_ref'   => $email['ref_id'] ?? '',
                    'thread_length'  => 0,
                    'from_cache'     => true,
                ], 200, [], JSON_UNESCAPED_UNICODE);
            }
        }

        // Build thread context
        $stakeholder = $email['_activity_stakeholder'] ?? $email['from_name'] ?? $email['stakeholder'] ?? '';
        $norm      = StakeholderHelper::normSH('a.stakeholder');
        $normInput = StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");
        $pid       = intval($email['property_id']);

        $thread = DB::select("
            SELECT a.activity_date, a.category, a.activity, a.result
            FROM activities a
            WHERE a.property_id = ? AND {$norm} = {$normInput}
            ORDER BY a.activity_date ASC, a.id ASC
        ", [$pid]);

        $threadContext = '';
        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $dir = in_array($msg['category'], ['anfrage','email-in','besichtigung','kaufanbot','absage']) ? 'EINGEHEND' : 'AUSGEHEND/AKTION';
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$msg['category']}): {$msg['activity']}";
            if ($msg['result']) $threadContext .= "\n  → {$msg['result']}";
            $threadContext .= "\n\n";
        }

        // Property info
        $propInfo = '';
        if ($email['address'] ?? null) {
            $propInfo = "Objekt: {$email['address']}, {$email['city']}";
            if ($email['ref_id'] ?? null) $propInfo .= " (Ref: {$email['ref_id']})";
            if ($email['purchase_price'] ?? null) $propInfo .= "\nPreis: € " . number_format($email['purchase_price'], 0, ',', '.');
            if ($email['total_area'] ?? null) $propInfo .= " | Fläche: {$email['total_area']} m²";
            if ($email['rooms_amount'] ?? null) $propInfo .= " | Zimmer: {$email['rooms_amount']}";
            if ($email['object_type'] ?? null) $propInfo .= "\nTyp: {$email['object_type']}";
            if ($email['heating'] ?? null) $propInfo .= "\nHeizung: {$email['heating']}";
            if ($email['construction_year'] ?? null) $propInfo .= " | Baujahr: {$email['construction_year']}";
            if ($email['year_renovated'] ?? null) $propInfo .= " | Renoviert: {$email['year_renovated']}";
            if ($email['living_area'] ?? null) $propInfo .= "\nWohnfläche: {$email['living_area']} m²";
            if ($email['free_area'] ?? null) $propInfo .= " | Grundfläche: {$email['free_area']} m²";
            if ($email['highlights'] ?? null) $propInfo .= "\nHighlights: {$email['highlights']}";
            if ($email['property_desc'] ?? null) $propInfo .= "\nBeschreibung: " . mb_substr($email['property_desc'], 0, 500);
        }

        // Knowledge base context — AI-powered 2-step search
        $knowledgeContext = '';
        if ($pid) {
            // Load all KB items for this property
            $allKb = DB::select("
                SELECT id, category, title, content, confidence, is_verified
                FROM property_knowledge
                WHERE property_id = ? AND is_active = 1
                ORDER BY is_verified DESC, confidence DESC, created_at DESC
            ", [$pid]);

            if (!empty($allKb)) {
                // Build a compact KB index (title + first 80 chars of content) for AI to scan
                $kbIndex = [];
                foreach ($allKb as $i => $k) {
                    $k = (array) $k;
                    $kbIndex[] = ['id' => $k['id'], 'cat' => $k['category'], 'title' => $k['title'], 'snippet' => mb_substr($k['content'], 0, 80)];
                }

                // Step 1: Ask AI to identify which KB entries are relevant to the customer email
                $bodyForKb = strip_tags($email['body_text'] ?? $email['ai_summary'] ?? '');
                $bodySnippet = mb_substr($bodyForKb, 0, 1000);

                $kbIndexJson = json_encode($kbIndex, JSON_UNESCAPED_UNICODE);
                // Limit index size — if too many entries, truncate
                if (strlen($kbIndexJson) > 15000) {
                    $kbIndex = array_slice($kbIndex, 0, 80);
                    $kbIndexJson = json_encode($kbIndex, JSON_UNESCAPED_UNICODE);
                }

                try {
                    $anthropic = app(\App\Services\AnthropicService::class);
                    $searchSystem = "Du bist ein Such-Assistent. Analysiere die Kundenanfrage und finde die relevanten Einträge aus der Wissensdatenbank. Antworte NUR mit einer JSON-Liste von IDs, z.B. [12, 45, 78]. Maximal 20 IDs. Wenn keine relevant sind, antworte mit [].";
                    $searchUser = "KUNDENANFRAGE:\n{$bodySnippet}\n\nWISSENSDATE" . "NBANK-INDEX:\n{$kbIndexJson}\n\nWelche Einträge (IDs) sind relevant um diese Anfrage zu beantworten? Denke an: direkte Fragen, erwähnte Themen (Zimmer, Preis, Fläche, Verfügbarkeit, Baustart, Besichtigung, etc.), und wichtige Einschränkungen/Bedingungen die der Kunde wissen sollte. Antworte NUR mit der JSON-ID-Liste.";

                    $searchResult = $anthropic->chat($searchSystem, $searchUser, 200);
                    $relevantIds = [];
                    if ($searchResult) {
                        // Extract JSON array from response
                        if (preg_match('/\[([\d,\s]+)\]/', $searchResult, $m)) {
                            $relevantIds = array_map('intval', explode(',', $m[1]));
                            $relevantIds = array_filter($relevantIds);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('AI KB search failed, falling back', ['error' => $e->getMessage()]);
                    $relevantIds = [];
                }

                // Build KB context from selected entries
                $kbMap = [];
                foreach ($allKb as $k) {
                    $k = (array) $k;
                    $kbMap[$k['id']] = $k;
                }

                $kbChars = 0;
                if (!empty($relevantIds)) {
                    $knowledgeContext .= "\nRELEVANTES OBJEKTWISSEN (von KI als relevant zur Anfrage identifiziert — MUSS berücksichtigt werden!):\n";
                    foreach ($relevantIds as $rid) {
                        if (!isset($kbMap[$rid])) continue;
                        $item = $kbMap[$rid];
                        $v = ($item['is_verified'] || $item['confidence'] === 'high') ? ' ✓' : '';
                        $line = '- ' . $item['title'] . ': ' . $item['content'] . $v . "\n";
                        if ($kbChars + strlen($line) > 3000) break;
                        $knowledgeContext .= $line;
                        $kbChars += strlen($line);
                    }
                }

                // Always add priority items that AI might have missed (constraints, legal, pricing)
                $priorityCats = ['feedback_besichtigung', 'verhandlung', 'rechtliches', 'eigentuemer_info'];
                $addedIds = $relevantIds;
                $prioSection = '';
                $prioChars = 0;
                foreach ($allKb as $k) {
                    $k = (array) $k;
                    if (in_array($k['id'], $addedIds)) continue;
                    if (!in_array($k['category'], $priorityCats)) continue;
                    $v = ($k['is_verified'] || $k['confidence'] === 'high') ? ' ✓' : '';
                    $line = '- ' . $k['title'] . ': ' . $k['content'] . $v . "\n";
                    if ($prioChars + strlen($line) > 1000) break;
                    $prioSection .= $line;
                    $prioChars += strlen($line);
                }
                if ($prioSection) {
                    $knowledgeContext .= "\nWICHTIGE RAHMENBEDINGUNGEN:\n" . $prioSection;
                }
            }
        }

        // Get clean body text - handle MIME garbage & empty activity results
        $rawBody = $email['body_text'] ?? '';

        // Detect raw MIME data (boundaries, base64 headers)
        $isMimeGarbage = preg_match('/^-{2,}(boundary|_[0-9]{3}_|=_NextPart|=_)/m', $rawBody)
                      || preg_match('/Content-Transfer-Encoding:\s*base64/i', $rawBody);

        if (empty(trim($rawBody)) || $isMimeGarbage) {
            // Try body_html first
            if (!empty($email['body_html'] ?? '')) {
                $rawBody = strip_tags($email['body_html']);
            }
            // If we have a source_email_id (from activity fallback), fetch source email body
            elseif (!empty($email['source_email_id'])) {
                $srcEmail = DB::selectOne("SELECT body_text, body_html FROM portal_emails WHERE id = ?", [$email['source_email_id']]);
                if ($srcEmail) {
                    $srcBody = $srcEmail->body_text ?? '';
                    $srcIsMime = preg_match('/^-{2,}(boundary|_[0-9]{3}_|=_NextPart|=_)/m', $srcBody)
                             || preg_match('/Content-Transfer-Encoding:\s*base64/i', $srcBody);
                    if (!empty($srcBody) && !$srcIsMime) {
                        $rawBody = $srcBody;
                    } elseif ($srcIsMime) {
                        // Try to decode base64 text/plain from MIME
                        if (preg_match('/Content-Type:\s*text\/plain.*?\n\n(.+?)(?:\n-{2,}|$)/si', $srcBody, $m)) {
                            $chunk = preg_replace('/\s+/', '', $m[1]);
                            $decoded = base64_decode($chunk);
                            if ($decoded && mb_detect_encoding($decoded, 'UTF-8', true)) $rawBody = $decoded;
                        }
                        if (empty(trim($rawBody)) && !empty($srcEmail->body_html)) {
                            $rawBody = strip_tags($srcEmail->body_html);
                        }
                    } elseif (!empty($srcEmail->body_html)) {
                        $rawBody = strip_tags($srcEmail->body_html);
                    }
                }
            }
        }

        $snippet = strip_tags($rawBody);
        $snippet = preg_replace('/\s+/', ' ', trim($snippet));
        if (mb_strlen($snippet) > 1500) $snippet = mb_substr($snippet, 0, 1500);

        $toneGuide = match($tone) {
            'friendly' => 'Freundlich und persönlich, aber professionell. Du-Form ist OK.',
            'short'    => 'Sehr kurz und direkt, maximal 3-4 Sätze. Per Sie.',
            default    => 'Professionell und verbindlich, per Sie. Gehoben aber nicht steif.',
        };

        // AI call via AnthropicService - combine all context
        $propertyAddress = trim(($email['address'] ?? '') . ', ' . ($email['city'] ?? ''), ', ');
        $fullContext = '';

        // Add explicit warning for Neubauprojekte
        $propertyType = $email['object_type'] ?? '';
        if (preg_match('/neubau|projekt|bauvorhaben/i', $propertyType)) {
            $fullContext .= "⚠️ ACHTUNG: Dies ist ein NEUBAUPROJEKT — das Gebäude existiert noch nicht! KEINE Besichtigungen anbieten! Stattdessen: Beratungsgespräch, Exposé, Planunterlagen, oder Treffen im Büro anbieten.\n\n";
        }

        if ($propInfo) {
            $fullContext .= "OBJEKTDATEN:
" . $propInfo . "

";
        }
        if ($knowledgeContext) {
            $fullContext .= $knowledgeContext . "

";
        }
        if ($threadContext) {
            $fullContext .= "BISHERIGER KOMMUNIKATIONSVERLAUF:
" . $threadContext;
        }
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            // Erstanfrage-Speziallogik: kurze Antwort mit Expose-Hinweis
            $isErstanfrage = in_array($email['category'] ?? '', ['anfrage']);
            $hasExpose = false;
            if ($isErstanfrage && $pid) {
                $hasExpose = DB::table('property_files')
                    ->where('property_id', $pid)
                    ->where(function($q) {
                        $q->where('label', 'LIKE', '%xpos%')
                          ->orWhere('filename', 'LIKE', '%expose%')
                          ->orWhere('filename', 'LIKE', '%bab%');
                    })
                    ->exists();
            }

            $replyText = $anthropic->generateAiReply(
                $snippet, $stakeholder, $propertyAddress, $fullContext, $tone, $detailLevel,
                $isErstanfrage, $hasExpose
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI request failed', 'message' => $e->getMessage()], 502);
        }

        // Extract prospect email for noreply senders - use cleaned snippet which has the actual body text
        $prospectEmail = $this->extractProspectEmail($email['from_email'] ?? null, $snippet);
        $replyTo = $prospectEmail ?: ($email['from_email'] ?? '');

        // Auto-save draft to email_drafts for instant loading next time
        $sourceId = $email['source_email_id'] ?? $email['_act_source_email_id'] ?? ($type === 'activity' ? $emailId : $emailId);
        if ($sourceId && !empty($replyText)) {
            $existsDraft = DB::table('email_drafts')->where('source_email_id', $sourceId)->exists();
            if (!$existsDraft) {
                DB::table('email_drafts')->insert([
                    'to_email'        => $replyTo,
                    'subject'         => 'Re: ' . ($email['subject'] ?? ''),
                    'body'            => $replyText,
                    'property_id'     => $email['property_id'] ?? null,
                    'stakeholder'     => $email['from_name'] ?? '',
                    'source_email_id' => $sourceId,
                    'tone'            => $tone,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                // Cleanup: keep max 300 drafts
                $cnt = DB::table('email_drafts')->count();
                if ($cnt > 300) {
                    $cutoff = DB::table('email_drafts')->orderBy('id', 'desc')->skip(300)->value('id');
                    if ($cutoff) DB::table('email_drafts')->where('id', '<', $cutoff)->delete();
                }
            }
        }

        return response()->json([
            'reply_text'    => $replyText,
            'to'            => $replyTo,
            'prospect_email' => $prospectEmail,
            'to_name'       => $email['from_name'] ?? '',
            'subject'       => 'Re: ' . ($email['subject'] ?? ''),
            'tone'          => $tone,
            'property_ref'  => $email['ref_id'] ?? '',
            'thread_length' => count($thread),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Mark conversation as handled.
     */
    public function markHandled(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $input       = $request->json()->all();
        $stakeholder = trim($input['stakeholder'] ?? '');
        $propertyId  = intval($input['property_id'] ?? 0);
        $note        = trim($input['note'] ?? 'Manuell als erledigt markiert');

        if (!$stakeholder || !$propertyId) {
            return response()->json(['error' => 'stakeholder and property_id required'], 400);
        }

        $activityId = DB::table('activities')->insertGetId([
            'property_id'   => $propertyId,
            'activity_date' => now()->format('Y-m-d'),
            'stakeholder'   => $stakeholder,
            'activity'      => $note,
            'result'        => 'Manuell erledigt im Cockpit',
            'category'      => 'update',
            'created_at'    => now(),
        ]);

        return response()->json([
            'success'     => true,
            'message'     => "Konversation mit '{$stakeholder}' als erledigt markiert",
            'activity_id' => $activityId,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send email via SMTP.
     * Placeholder — full implementation will use App\Services\EmailService.
     */
    public function send(Request $request): JsonResponse
    {
        $input = $request->isMethod('post') && $request->isJson()
            ? $request->json()->all()
            : $request->all();

        $accountId  = $input['account_id'] ?? null;
        $toEmail    = $input['to_email'] ?? '';
        $toName     = $input['to_name'] ?? '';
        $cc         = $input['cc'] ?? null;
        $bcc        = $input['bcc'] ?? null;
        $subject    = $input['subject'] ?? '';
        $bodyHtml   = $input['body_html'] ?? '';
        $bodyText   = $input['body_text'] ?? strip_tags($bodyHtml);
        $propertyId = $input['property_id'] ?? null;
        $inReplyTo  = $input['in_reply_to'] ?? null;
        $isFollowup    = !empty($input['is_followup']);
        $followupStage = isset($input['followup_stage']) ? intval($input['followup_stage']) : null;

        if (!$accountId || !$toEmail || !$subject) {
            return response()->json(['error' => 'Missing required fields: account_id, to_email, subject'], 400);
        }

        // Security: Makler can only send from their own account
        $brokerId = \Auth::id();
        $user = \Auth::user();
        if ($brokerId && $user && $user->user_type !== 'admin') {
            $account = \DB::table('email_accounts')->where('id', $accountId)->first();
            if (!$account || $account->user_id != $brokerId) {
                return response()->json(['error' => 'Nicht berechtigt, von diesem Konto zu senden'], 403);
            }
        }

        // Collect attachments from multipart form upload
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $attachments = $request->file('attachments');
            if (!is_array($attachments)) $attachments = [$attachments];
        }

        // Look up original email's message_id for reply threading
        $inReplyToMessageId = null;
        $references = null;
        if ($inReplyTo) {
            // Try portal_emails first
            $originalEmail = DB::selectOne("SELECT message_id FROM portal_emails WHERE id = ?", [$inReplyTo]);
            if ($originalEmail && $originalEmail->message_id) {
                $inReplyToMessageId = $originalEmail->message_id;
            } else {
                // Fallback: might be an activity ID — look up via source_email_id
                $activity = DB::selectOne("SELECT source_email_id FROM activities WHERE id = ?", [$inReplyTo]);
                if ($activity && $activity->source_email_id) {
                    $srcEmail = DB::selectOne("SELECT message_id FROM portal_emails WHERE id = ?", [$activity->source_email_id]);
                    if ($srcEmail && $srcEmail->message_id) {
                        $inReplyToMessageId = $srcEmail->message_id;
                    }
                }
            }
        }

        // Delegate to EmailService
        try {
            $emailService = app(\App\Services\EmailService::class);
            $outCategory = $isFollowup ? 'nachfassen' : 'email-out';
            $result = $emailService->send(
                (int) $accountId,
                $toEmail,
                $subject,
                $bodyHtml,
                $propertyId ? (int) $propertyId : null,
                $toName ?: null,
                $cc ?: null,
                $bcc ?: null,
                $attachments,
                $inReplyToMessageId,
                $references,
                $outCategory,
                $followupStage
            );
            return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Send failed: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Extract real prospect email from body when from_email is a noreply/platform address.
     */
    private function extractProspectEmail(?string $fromEmail, ?string $bodyText): ?string
    {
        if (!$fromEmail) return null;
        
        // Check if this is a platform/system email
        $isPlatform = preg_match('/noreply|no-reply|no\.reply|mailer|notification|followups|typeform|info@willhaben|info@immowelt|info@immobilienscout|info@scout24/i', $fromEmail);
        
        // Check if this is a forwarded inquiry from a business partner
        $partnerDomains = ['projekt-hoch3.at', 'projekt-hoch-3.at'];
        $isPartnerForward = false;
        if ($bodyText) {
            foreach ($partnerDomains as $pd) {
                if (stripos($fromEmail, $pd) !== false) {
                    // Check for forwarding patterns in body
                    if (preg_match('/(?:leite|sende).*(?:Anfrage|Interessenten)|Interessenten-Anfrage|Von:.*Gesendet:/is', $bodyText)) {
                        $isPartnerForward = true;
                    }
                    break;
                }
            }
        }
        
        if (!$isPlatform && !$isPartnerForward) {
            return null;
        }
        if (!$bodyText) return null;

        $flat = preg_replace('/\s+/', ' ', strip_tags($bodyText));
        $excludes = ['willhaben', 'immowelt', 'noreply', 'no-reply', 'typeform', 'followups', 'scout24', 'immobilienscout', 'sr-homes.at', 'projekt-hoch3.at', 'projekt-hoch-3.at', 'jimdo.de'];

        // Also exclude the sender address itself
        $excludes[] = strtolower($fromEmail);

        return self::findEmailInText($flat, $excludes);
    }


    /**
     * Extract a real email from text, handling Typeform-style concatenated bodies.
     * Uses 2-step: broad regex match, then TLD validation/trimming.
     */
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


    /**
     * Email history with filters and pagination.
     */
    public function history(Request $request): JsonResponse
    {
        $page     = max(1, intval($request->query('page', 1)));
        $perPage  = min(100, max(1, intval($request->query('per_page', 30))));
        $offset   = ($page - 1) * $perPage;

        $propertyId = intval($request->query('property_id', 0));
        $search     = trim($request->query('search', ''));
        $category   = trim($request->query('category', ''));
        $direction  = trim($request->query('direction', ''));
        $trash      = trim($request->query('trash', '0'));

        $where  = [$trash === '1' ? 'pe.is_deleted = 1' : '(pe.is_deleted = 0 OR pe.is_deleted IS NULL)'];
        $params = [];

        if ($propertyId) {
            $where[]  = 'pe.property_id = ?';
            $params[] = $propertyId;
        }
        if ($search !== '') {
            $where[] = '(pe.stakeholder LIKE ? OR pe.from_name LIKE ? OR pe.from_email LIKE ? OR pe.to_email LIKE ? OR pe.subject LIKE ?)';
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($category !== '') {
            $where[]  = 'pe.category = ?';
            $params[] = $category;
        }
        if ($direction !== '') {
            $where[]  = 'pe.direction = ?';
            $params[] = $direction;
        }

        $unmatched = trim($request->query('unmatched', ''));
        if ($unmatched === '1') $where[] = 'pe.property_id IS NULL';

        // Multi-User: nur Emails aus eigenen Properties zeigen
        $brokerId = \Auth::id();
        if ($brokerId) {
            $user = \Auth::user();
            // Strict account filter: only show emails from own email accounts
            $where[] = 'pe.account_id IN (SELECT id FROM email_accounts WHERE user_id = ?)';
            $params[] = $brokerId;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) DB::selectOne("SELECT COUNT(*) as cnt FROM portal_emails pe WHERE {$whereSql}", $params)->cnt;

        $emails = DB::select("
            SELECT
                pe.id, pe.direction, pe.from_email, pe.from_name, pe.to_email,
                pe.subject, pe.body_text, pe.body_html, pe.email_date,
                pe.category, pe.stakeholder, pe.ai_summary, pe.has_attachment,
                pe.attachment_names, pe.property_id, pe.matched_ref_id,
                pe.is_deleted, pe.deleted_at,
                p.address as property_address, p.ref_id as property_ref_id, p.city as property_city
            FROM portal_emails pe
            LEFT JOIN properties p ON pe.property_id = p.id
            WHERE {$whereSql}
            ORDER BY pe.email_date DESC
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        $stakeholders = array_column(
            DB::select("SELECT DISTINCT stakeholder FROM portal_emails WHERE stakeholder IS NOT NULL AND stakeholder != '' ORDER BY stakeholder"),
            'stakeholder'
        );

        $categories = array_column(
            DB::select("SELECT DISTINCT category FROM portal_emails WHERE category IS NOT NULL AND category != '' ORDER BY category"),
            'category'
        );

        // Add prospect_email for noreply platform emails
        $emails = array_map(function ($em) {
            $em = (array) $em;
            $em['prospect_email'] = $this->extractProspectEmail($em['from_email'] ?? null, $em['body_text'] ?? null);
            return (object) $em;
        }, $emails);

        // Group emails by (stakeholder, property_id) — show only the newest per conversation
        $grouped = [];
        $seenKeys = [];
        foreach ($emails as $em) {
            $sh = strtolower(trim($em->stakeholder ?? $em->from_name ?? $em->from_email ?? ''));
            $pid = $em->property_id ?? 0;
            $key = $sh . '|' . $pid;
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $grouped[] = $em;
            }
        }
        $emails = $grouped;

        return response()->json([
            'emails'      => $emails,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'filters'     => [
                'stakeholders' => $stakeholders,
                'categories'   => $categories,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Trash emails (soft-delete).
     */
    public function trash(Request $request): JsonResponse
    {
        $ids = $request->json('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['error' => 'ids required']);
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::update("UPDATE portal_emails SET is_deleted = 1, deleted_at = NOW() WHERE id IN ({$placeholders})", $ids);
        // Set category to 'update' so it disappears from Unbeantwortet (which filters out 'update')
        $count = DB::update("UPDATE activities SET category = 'update' WHERE source_email_id IN ({$placeholders}) AND category NOT IN ('update','email-out','nachfassen','expose')", $ids);

        return response()->json(['ok' => true, 'trashed' => count($ids)]);
    }

    /**
     * Restore trashed emails.
     */
    public function restore(Request $request): JsonResponse
    {
        $ids = $request->json('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['error' => 'ids required']);
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $count = DB::update("UPDATE portal_emails SET is_deleted = 0, deleted_at = NULL WHERE id IN ({$placeholders})", $ids);

        return response()->json(['ok' => true, 'restored' => $count]);
    }

    /**
     * On-demand IMAP attachment download.
     * Placeholder — full IMAP logic will be in EmailService.
     */
    public function downloadAttachment(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $emailId   = intval($request->query('email_id', 0));
        $fileIndex = intval($request->query('file_index', 0));
        $mode      = $request->query('dl_mode', 'download');

        if (!$emailId) {
            return response()->json(['error' => 'email_id required'], 400);
        }

        // Delegate to EmailService (placeholder)
        try {
            $emailService = app(\App\Services\EmailService::class);
            return $emailService->downloadAttachment($emailId, $fileIndex, $mode);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Attachment download failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Unmatched emails (no property assignment).
     */
    public function unmatched(Request $request): JsonResponse
    {
        $emails = DB::select("
            SELECT pe.id, pe.message_id, pe.direction, pe.from_email, pe.from_name,
                   pe.to_email, pe.subject, pe.email_date, pe.category, pe.stakeholder,
                   pe.ai_summary, pe.matched_ref_id, pe.is_processed
            FROM portal_emails pe
            WHERE pe.property_id IS NULL
            AND pe.direction = 'inbound'
            AND pe.category IN ('anfrage', 'email-in', 'besichtigung', 'kaufanbot', 'eigentuemer', 'partner')
            ORDER BY pe.email_date DESC
            LIMIT 100
        ");

        $properties = DB::select("SELECT id, ref_id, address, city FROM properties ORDER BY address");

        $emails = array_map(function ($em) use ($properties) {
            $em = (array) $em;
            $em['suggested_ref_id']     = null;
            $em['suggested_property_id'] = null;
            if (!empty($em['matched_ref_id']) && str_starts_with($em['matched_ref_id'], 'suggestion:')) {
                $sugRef = substr($em['matched_ref_id'], 11);
                $em['suggested_ref_id'] = $sugRef;
                foreach ($properties as $p) {
                    $p = (array) $p;
                    if ($p['ref_id'] === $sugRef) {
                        $em['suggested_property_id'] = $p['id'];
                        break;
                    }
                }
            }
            return $em;
        }, array_map(fn($r) => (array) $r, $emails));

        return response()->json([
            'emails'     => $emails,
            'properties' => $properties,
            'total'      => count($emails),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Assign email to property.
     */
    public function assign(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $input      = $request->json()->all();
        $emailId    = intval($input['email_id'] ?? 0);
        $propertyId = intval($input['property_id'] ?? 0);
        $mergeStakeholder = trim($input['merge_stakeholder'] ?? '');

        if (!$emailId || !$propertyId) {
            return response()->json(['error' => 'email_id and property_id required'], 400);
        }

        $email = DB::table('portal_emails')->where('id', $emailId)->first();
        if (!$email) {
            return response()->json(['error' => 'Email not found'], 404);
        }

        $prop = DB::table('properties')->where('id', $propertyId)->first();
        if (!$prop) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        DB::table('portal_emails')->where('id', $emailId)->update([
            'property_id'    => $propertyId,
            'matched_ref_id' => $prop->ref_id,
            'is_processed'   => 0,
        ]);

        $direction   = $email->direction;
        $stakeholder = $email->stakeholder ?: 'Unbekannt';
        if ($mergeStakeholder) {
            $stakeholder = $mergeStakeholder;
        }
        $subject     = $email->subject ?? '';

        if ($direction === 'outbound') {
            $actText  = 'Email-Antwort gesendet: ' . mb_substr($subject, 0, 120);
            $category = ($email->category === 'expose') ? 'expose' : 'email-out';
        } else {
            $category = $email->category ?: 'email-in';
            $actText  = match ($category) {
                'anfrage'      => 'Erstanfrage: ' . mb_substr($subject, 0, 120),
                'absage'       => 'Rückmeldung: ' . mb_substr($subject, 0, 120),
                'kaufanbot'    => 'Kaufanbot eingegangen: ' . mb_substr($subject, 0, 120),
                'besichtigung' => 'Besichtigungsanfrage: ' . mb_substr($subject, 0, 120),
                'sonstiges'    => 'Info: ' . mb_substr($subject, 0, 120),
                'eigentuemer'  => 'Eigentümer-Nachricht: ' . mb_substr($subject, 0, 120),
                default        => 'Anfrage erhalten: ' . mb_substr($subject, 0, 120),
            };
        }

        $resultText = $email->ai_summary ?: mb_substr(strip_tags($email->body_text ?? ''), 0, 200);
        $resultText = $resultText ?: 'Manuell zugeordnet';

        $activityId = DB::table('activities')->insertGetId([
            'property_id'     => $propertyId,
            'activity_date'   => substr($email->email_date, 0, 10),
            'stakeholder'     => $stakeholder,
            'activity'        => $actText,
            'result'          => $resultText,
            'duration'        => '',
            'category'        => $category,
            'source_email_id' => $emailId,
        ]);

        DB::table('portal_emails')->where('id', $emailId)->update(['is_processed' => 1]);

        return response()->json([
            'success'     => true,
            'message'     => "Email #{$emailId} wurde {$prop->ref_id} zugeordnet",
            'activity_id' => $activityId,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save draft.
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $input     = $request->json()->all();
        $id        = intval($input['id'] ?? 0);
        $accountId = !empty($input['account_id']) ? intval($input['account_id']) : null;

        $data = [
            'to_email'        => trim($input['to_email'] ?? ''),
            'subject'         => trim($input['subject'] ?? ''),
            'body'            => $input['body'] ?? '',
            'property_id'     => !empty($input['property_id']) ? intval($input['property_id']) : null,
            'stakeholder'     => trim($input['stakeholder'] ?? ''),
            'account_id'      => $accountId,
            'tone'            => $input['tone'] ?? 'professional',
            'source_email_id' => !empty($input['source_email_id']) ? intval($input['source_email_id']) : null,
        ];

        // IMAP sync placeholder — will be handled by EmailService
        $imapUid   = null;
        $imapFolder = '';

        if ($id > 0) {
            DB::table('email_drafts')->where('id', $id)->update(array_merge($data, [
                'imap_uid'   => $imapUid,
                'imap_folder'=> $imapFolder,
            ]));
            return response()->json(['ok' => true, 'id' => $id, 'imap_synced' => false]);
        }

        $newId = DB::table('email_drafts')->insertGetId(array_merge($data, [
            'imap_uid'    => $imapUid,
            'imap_folder' => $imapFolder,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]));

        return response()->json(['ok' => true, 'id' => $newId, 'imap_synced' => false]);
    }

    /**
     * List drafts.
     */
    public function listDrafts(Request $request): JsonResponse
    {
        $accountId = $request->query('account_id', '');
        $where     = '';
        $params    = [];

        if ($accountId && $accountId !== '0') {
            $where    = ' WHERE d.account_id = ?';
            $params[] = intval($accountId);
        }

        $rows = DB::select("
            SELECT d.*, p.ref_id AS property_ref_id, p.address AS property_address,
                   ea.label AS account_label, ea.email_address AS account_email
            FROM email_drafts d
            LEFT JOIN properties p ON d.property_id = p.id
            LEFT JOIN email_accounts ea ON d.account_id = ea.id
            {$where}
            ORDER BY d.updated_at DESC
        ", $params);

        return response()->json(['drafts' => $rows, 'count' => count($rows)]);
    }

    /**
     * Delete draft.
     */
    public function deleteDraft(Request $request): JsonResponse
    {
        $id = intval($request->json('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required']);
        }

        // IMAP cleanup placeholder — will be handled by EmailService
        DB::table('email_drafts')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Get besichtigung activities that need calendar entry.
     * Shows until manually dismissed (viewing_alert_dismissed = 1).
     */
    public function pendingViewings(Request $request): JsonResponse
    {
        $pending = DB::select("
            SELECT a.id, a.stakeholder, a.activity, a.activity_date, a.category,
                   a.source_email_id, p.ref_id, p.address,
                   LEFT(pe.body_text, 2000) as email_body
            FROM activities a
            LEFT JOIN portal_emails pe ON pe.id = a.source_email_id
            LEFT JOIN properties p ON a.property_id = p.id
            WHERE a.category = 'besichtigung'
              AND (a.viewing_alert_dismissed IS NULL OR a.viewing_alert_dismissed = 0)
              AND a.activity_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY a.activity_date DESC
            LIMIT 20
        ");

        return response()->json(['pending' => array_map(fn($r) => (array) $r, $pending)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Dismiss a viewing alert (user has entered the calendar event).
     */
    public function dismissViewingAlert(Request $request): JsonResponse
    {
        $activityId = intval($request->json('activity_id', 0));
        if (!$activityId) return response()->json(['error' => 'activity_id required'], 400);

        DB::update("UPDATE activities SET viewing_alert_dismissed = 1 WHERE id = ?", [$activityId]);

        return response()->json(['ok' => true]);
    }


    /**
     * Save an email attachment to a property's files.
     */
    public function saveAttachmentToProperty(Request $request): JsonResponse
    {
        $emailId    = intval($request->input('email_id', 0));
        $fileIndex  = intval($request->input('file_index', 0));
        $propertyId = intval($request->input('property_id', 0));
        $label      = $request->input('label', '');

        if (!$emailId || !$propertyId) {
            return response()->json(['error' => 'email_id and property_id required'], 400);
        }

        try {
            $emailService = app(\App\Services\EmailService::class);
            $result = $emailService->downloadAttachment($emailId, $fileIndex, 'base64');

            // Parse the JSON response
            $data = json_decode($result->getContent(), true);
            if (!$data || !($data['success'] ?? false)) {
                return response()->json(['error' => $data['error'] ?? 'Failed to fetch attachment'], 500);
            }

            $filename = $data['filename'];
            $mimeType = $data['mime_type'];
            $fileSize = $data['file_size'];
            $decoded  = base64_decode($data['data']);

            // Ensure directory exists
            $dir = storage_path('app/public/property_files/' . $propertyId);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Sanitize filename and avoid collisions
            $safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
            $targetPath = $dir . '/' . $safeFilename;
            if (file_exists($targetPath)) {
                $ext = pathinfo($safeFilename, PATHINFO_EXTENSION);
                $base = pathinfo($safeFilename, PATHINFO_FILENAME);
                $safeFilename = $base . '_' . time() . ($ext ? '.' . $ext : '');
                $targetPath = $dir . '/' . $safeFilename;
            }

            file_put_contents($targetPath, $decoded);

            // Determine sort_order
            $maxSort = DB::selectOne("SELECT COALESCE(MAX(sort_order), 0) as ms FROM property_files WHERE property_id = ?", [$propertyId]);

            DB::insert("INSERT INTO property_files (property_id, label, filename, path, mime_type, file_size, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())", [
                $propertyId,
                $label ?: pathinfo($filename, PATHINFO_FILENAME),
                $safeFilename,
                'property_files/' . $propertyId . '/' . $safeFilename,
                $mimeType,
                $fileSize,
                ($maxSort->ms ?? 0) + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Anhang als Datei zum Objekt gespeichert.',
                'filename' => $safeFilename,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('saveAttachmentToProperty failed: ' . $e->getMessage());
            return response()->json(['error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }
}
