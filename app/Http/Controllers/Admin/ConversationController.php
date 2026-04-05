<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\StakeholderHelper;
use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\PropertyMatch;
use App\Models\Property;
use App\Models\Activity;
use App\Services\PropertyMatcherService;
use App\Services\AnthropicService;

class ConversationController extends Controller
{
    /**
     * conv_list — List conversations by status or view.
     */
    public function list(Request $request): JsonResponse
    {
        $status     = $request->query('status', '');
        $view       = $request->query('view', '');
        $propertyId = intval($request->query('property_id', 0));
        $search     = $request->query('search', '');
        $page       = max(1, intval($request->query('page', 1)));
        $perPage    = min(200, max(1, intval($request->query('per_page', 20))));

        $brokerId = Auth::id();
        $userType = Auth::user()->user_type ?? 'makler';

        // Fall through to existing email_history for view-based queries (backward compat)
        if ($view === 'posteingang' || $view === 'gesendet') {
            return app(EmailController::class)->history($request);
        }

        $query = Conversation::with('property:id,ref_id,address,city')
            ->forBroker($brokerId, $userType);

        if ($status === 'offen') {
            $query->offen()->orderBy('last_inbound_at', 'desc');
        } elseif ($status === 'nachfassen') {
            // Nachfassen-Stufen:
            // beantwortet (= erste Antwort gesendet) → nach 24h zeigen
            // nachfassen_1 (= 1. Nachfass gesendet) → nach 3 Tagen zeigen
            // nachfassen_2 (= 2. Nachfass gesendet) → nach 3 Tagen zeigen
            // nachfassen_3 → auto-erledigt (nicht mehr zeigen)
            $query->where(function ($q) {
                $q->where(function ($q2) {
                    // Stufe 1: beantwortet + 24h vergangen
                    $q2->where('status', 'beantwortet')
                        ->where('last_outbound_at', '<=', now()->subHours(24));
                })->orWhere(function ($q2) {
                    // Stufe 2: nachfassen_1 + 3 Tage vergangen
                    $q2->where('status', 'nachfassen_1')
                        ->where('last_outbound_at', '<=', now()->subDays(3));
                })->orWhere(function ($q2) {
                    // Stufe 3: nachfassen_2 + 3 Tage vergangen
                    $q2->where('status', 'nachfassen_2')
                        ->where('last_outbound_at', '<=', now()->subDays(3));
                });
            })
            ->orderBy('last_outbound_at', 'asc');
        } elseif ($status === 'erledigt') {
            $query->erledigt()->orderBy('last_activity_at', 'desc');
        } else {
            // Default: all non-erledigt
            $query->where('status', '!=', 'erledigt')
                ->orderBy('last_activity_at', 'desc');
        }

        if ($propertyId) {
            $query->where('property_id', $propertyId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('contact_email', 'like', "%{$search}%")
                    ->orWhere('stakeholder', 'like', "%{$search}%");
            });
        }

        if ($request->query('has_matches')) {
            $query->where('match_count', '>', 0)->where('match_dismissed', false);
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $conversations = collect($paginated->items())->map(function (Conversation $conv) {
            $prop = $conv->property;
            $item = [
                'id'               => $conv->id,
                'contact_email'    => $conv->contact_email,
                'stakeholder'      => $conv->stakeholder,
                'property_id'      => $conv->property_id,
                'status'           => $conv->status,
                'days_waiting'     => $conv->daysWaiting(),
                'first_contact_at' => $conv->first_contact_at?->toDateTimeString(),
                'last_inbound_at'  => $conv->last_inbound_at?->toDateTimeString(),
                'last_outbound_at' => $conv->last_outbound_at?->toDateTimeString(),
                'source_platform'  => $conv->source_platform,
                'category'         => $conv->category,
                'inbound_count'    => $conv->inbound_count,
                'outbound_count'   => $conv->outbound_count,
                'followup_count'   => $conv->followup_count,
                'draft_body'       => $conv->draft_body,
                'draft_dismissed'  => !$conv->draft_body && $conv->draft_generated_at !== null,
                'draft_subject'    => $conv->draft_subject,
                'draft_to'         => $conv->draft_to,
                'is_read'          => $conv->is_read,
                'ref_id'           => $prop?->ref_id,
                'address'          => $prop?->address,
                'from_name'        => $conv->stakeholder,
                'subject'          => '',
            ];

            // Get subject from last email
            if ($conv->last_email_id) {
                $lastEmail = DB::selectOne("SELECT subject FROM portal_emails WHERE id = ?", [$conv->last_email_id]);
                if ($lastEmail) $item['subject'] = $lastEmail->subject;
            }

            return $item;
        });

        // For nachfassen, group by status in response
        if ($status === 'nachfassen') {
            $grouped = $conversations->groupBy('status');
            return response()->json([
                'conversations' => $conversations->values(),
                'grouped'       => $grouped,
                'total'         => $paginated->total(),
                'page'          => $page,
                'per_page'      => $perPage,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'conversations' => $conversations->values(),
            'total'         => $paginated->total(),
            'page'          => $page,
            'per_page'      => $perPage,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * conv_detail — Single conversation with thread.
     */
    public function detail(Request $request): JsonResponse
    {
        $id = intval($request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::with('property:id,ref_id,address,city')->find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Mark as read
        app(ConversationService::class)->markRead($conv);

        // Load messages from portal_emails matched by contact_email + property_id
        $messages = DB::select("
            SELECT
                pe.id, pe.direction,
                CASE WHEN pe.direction = 'inbound' THEN pe.from_name ELSE pe.to_email END as from_name,
                pe.subject,
                SUBSTRING(pe.body_text, 1, 5000) as body_text,
                pe.body_html,
                pe.email_date,
                pe.category,
                pe.has_attachment,
                pe.attachment_names,
                a.followup_stage
            FROM portal_emails pe
            LEFT JOIN activities a ON a.source_email_id = pe.id
            WHERE (pe.property_id = ? OR (pe.property_id IS NULL AND ? IS NULL))
              AND (
                  LOWER(pe.from_email) = LOWER(?)
                  OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
                  OR LOWER(pe.stakeholder) = LOWER(?)
              )
            ORDER BY pe.email_date ASC
        ", [$conv->property_id, $conv->property_id, $conv->contact_email, $conv->contact_email, $conv->stakeholder]);

        $prop = $conv->property;
        $convData = [
            'id'               => $conv->id,
            'contact_email'    => $conv->contact_email,
            'stakeholder'      => $conv->stakeholder,
            'property_id'      => $conv->property_id,
            'status'           => $conv->status,
            'days_waiting'     => $conv->daysWaiting(),
            'first_contact_at' => $conv->first_contact_at?->toDateTimeString(),
            'last_inbound_at'  => $conv->last_inbound_at?->toDateTimeString(),
            'last_outbound_at' => $conv->last_outbound_at?->toDateTimeString(),
            'source_platform'  => $conv->source_platform,
            'category'         => $conv->category,
            'inbound_count'    => $conv->inbound_count,
            'outbound_count'   => $conv->outbound_count,
            'followup_count'   => $conv->followup_count,
            'draft_body'       => $conv->draft_body,
                'draft_dismissed'  => !$conv->draft_body && $conv->draft_generated_at !== null,
            'draft_subject'    => $conv->draft_subject,
            'draft_to'         => $conv->draft_to,
            'is_read'          => $conv->is_read,
            'ref_id'           => $prop?->ref_id,
            'address'          => $prop?->address,
            'from_name'        => $conv->stakeholder,
        ];

        // Add subject from latest message
        $convData['subject'] = !empty($messages) ? ($messages[count($messages) - 1]->subject ?? '') : '';
        $convData['from_name'] = $conv->stakeholder;

        return response()->json([
            'conversation' => $convData,
            'messages'     => $messages,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * conv_reply — Send reply email.
     */
    public function reply(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $body      = $input['body'] ?? '';
        $subject   = $input['subject'] ?? '';
        $to        = $input['to'] ?? $conv->contact_email;
        $accountId = $input['account_id'] ?? null;
        $fileIds   = $input['file_ids'] ?? [];

        if (!$body || !$subject || !$accountId) {
            return response()->json(['error' => 'body, subject, account_id required'], 400);
        }

        // Resolve file_ids to file paths for attachments
        $attachments = [];
        if (!empty($fileIds) && is_array($fileIds)) {
            foreach ($fileIds as $fid) {
                $file = DB::table('property_files')->where('id', intval($fid))->first(['path', 'filename']);
                if ($file && $file->path) {
                    $fullPath = storage_path('app/public/' . $file->path);
                    if (file_exists($fullPath)) {
                        $attachments[] = $fullPath;
                    }
                }
            }
        }

        // Send via EmailService
        try {
            $emailService = app(\App\Services\EmailService::class);
            \Log::info("=== EmailService::send() CALLED === from: " . __METHOD__ . " line " . __LINE__);
            $result = \Log::info("=== EmailService::send() CALLED === from: " . __METHOD__ . " line " . __LINE__);
                $emailService->send(
                (int) $accountId,
                $to,
                $subject,
                $body,
                $conv->property_id,
                $conv->stakeholder,
                null, null, $attachments,
                null, null,
                'email-out'
            );

            // Activity is already created by EmailService::send()

            // Update conversation: status -> beantwortet, clear draft
            $conv->status = 'beantwortet';
            $conv->draft_body = null;
        $conv->draft_generated_at = null;
            $conv->draft_subject = null;
            $conv->draft_to = null;
            $conv->draft_generated_at = null;
            $conv->last_outbound_at = now();
            $conv->outbound_count = ($conv->outbound_count ?? 0) + 1;
            $conv->save();

            // Create activities on cross-matched properties if any were selected
            $selectedMatches = PropertyMatch::where('conversation_id', $conv->id)
                ->where('status', 'selected')
                ->get();

            if ($selectedMatches->isNotEmpty()) {
                $originalRefId = $conv->property_id ? Property::where('id', $conv->property_id)->value('ref_id') : 'Unbekannt';
                foreach ($selectedMatches as $match) {
                    Activity::create([
                        'property_id' => $match->property_id,
                        'stakeholder' => $conv->stakeholder,
                        'category' => 'expose',
                        'activity' => "Cross-Match von {$originalRefId} — Exposé gesendet",
                        'activity_date' => now(),
                    ]);
                    $match->update(['status' => 'sent']);
                }
                $conv->update(['match_count' => 0, 'match_dismissed' => true]);
            }

            return response()->json(['success' => true, 'message' => 'Antwort gesendet']);
        } catch (\Exception $e) {
            Log::error('conv_reply failed', ['error' => $e->getMessage(), 'conv_id' => $id]);
            return response()->json(['error' => 'Senden fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    /**
     * conv_followup — Send followup email.
     */
    public function followup(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $body      = $input['body'] ?? '';
        $subject   = $input['subject'] ?? '';
        $to        = $input['to'] ?? $conv->contact_email;
        $accountId = $input['account_id'] ?? null;

        if (!$body || !$subject || !$accountId) {
            return response()->json(['error' => 'body, subject, account_id required'], 400);
        }

        try {
            $emailService = app(\App\Services\EmailService::class);
            $followupStage = ($conv->followup_count ?? 0) + 1;
            \Log::info("=== EmailService::send() CALLED === from: " . __METHOD__ . " line " . __LINE__);
            $result = \Log::info("=== EmailService::send() CALLED === from: " . __METHOD__ . " line " . __LINE__);
                $emailService->send(
                (int) $accountId,
                $to,
                $subject,
                $body,
                $conv->property_id,
                $conv->stakeholder,
                null, null, [],
                null, null,
                'nachfassen',
                $followupStage
            );

            // Create activity
            DB::table('activities')->insert([
                'property_id'    => $conv->property_id,
                'stakeholder'    => $conv->stakeholder,
                'activity_date'  => now(),
                'category'       => 'nachfassen',
                'activity'       => 'Nachfassen #' . $followupStage . ': ' . $subject,
                'result'         => mb_substr(strip_tags($body), 0, 500),
                'source_email_id' => $result['email_id'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Advance followup status
            app(ConversationService::class)->advanceFollowup($conv);

            return response()->json(['success' => true, 'message' => 'Nachfassen gesendet', 'stage' => $followupStage]);
        } catch (\Exception $e) {
            Log::error('conv_followup failed', ['error' => $e->getMessage(), 'conv_id' => $id]);
            return response()->json(['error' => 'Senden fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    /**
     * conv_done — Mark as done.
     */
    public function done(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        app(ConversationService::class)->markDone($conv);

        // Create handled activity
        DB::table('activities')->insert([
            'property_id'   => $conv->property_id,
            'stakeholder'   => $conv->stakeholder,
            'activity_date' => now(),
            'category'      => 'handled',
            'activity'      => 'Konversation als erledigt markiert',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Als erledigt markiert']);
    }

    
    /**
     * conv_reply_all — Send all pending drafts for offen conversations.
     */
    public function replyAll(Request $request): JsonResponse
    {
        // PERMANENTLY DISABLED
        return response()->json(['error' => 'Disabled'], 403);
    }

        /**
     * conv_done_batch — Mark multiple conversations as done.
     */
    public function doneBatch(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $ids = $input['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['error' => 'ids array required'], 400);
        }

        $convService = app(ConversationService::class);
        $done = 0;
        foreach ($ids as $id) {
            $conv = Conversation::find(intval($id));
            if ($conv) {
                $convService->markDone($conv);
                $done++;
            }
        }

        return response()->json(['success' => true, 'done' => $done]);
    }

    /**
     * conv_read — Mark as read.
     */
    public function read(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        app(ConversationService::class)->markRead($conv);

        return response()->json(['success' => true]);
    }

    /**
     * conv_draft — Update draft fields.
     */
    public function updateDraft(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $body    = $input['body'] ?? $conv->draft_body;
        $subject = $input['subject'] ?? $conv->draft_subject;
        $to      = $input['to'] ?? $conv->draft_to;

        app(ConversationService::class)->saveDraft($conv, $body, $subject, $to);

        return response()->json(['success' => true, 'message' => 'Entwurf gespeichert']);
    }

    /**
     * conv_regenerate_draft — Regenerate KI draft using full thread context + knowledge base.
     */
    public function regenerateDraft(Request $request): JsonResponse
    {
        \Log::info('=== conv_regenerate_draft CALLED === id=' . $request->query('id', $request->json('id', '?')));
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $stakeholder = $conv->stakeholder;
        $propertyId = $conv->property_id;
        $today = date('Y-m-d');

        // Build thread context from portal_emails (more reliable than activities)
        $thread = DB::select("
            SELECT pe.email_date as activity_date, pe.direction, pe.category, pe.subject,
                   SUBSTRING(pe.body_text, 1, 500) as body_snippet,
                   pe.from_name
            FROM portal_emails pe
            WHERE pe.property_id = ?
              AND (LOWER(pe.from_email) = LOWER(?) OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%') OR pe.stakeholder = ?)
            ORDER BY pe.email_date ASC
        ", [$propertyId, $conv->contact_email, $conv->contact_email, $stakeholder]);

        if (empty($thread)) {
            return response()->json(['error' => 'Kein Nachrichtenverlauf gefunden'], 404);
        }

        // Build thread context from emails
        $threadContext = '';
        $lastOutboundDate = '';
        $lastInboundText = '';
        $lastInboundDate = '';
        $hasUnansweredQuestion = false;

        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $isIn = strtolower($msg['direction'] ?? '') === 'inbound';
            $dir = $isIn ? 'KUNDE' : 'SR-HOMES';
            $cat = $msg['category'] ?? 'sonstiges';
            $summary = $msg['subject'] ?? '';
            if (!empty($msg['body_snippet'])) {
                $body = trim(preg_replace('/\s+/', ' ', strip_tags($msg['body_snippet'])));
                if (strlen($body) > 20) $summary .= ' — ' . mb_substr($body, 0, 200);
            }
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$cat}): {$summary}\n";

            if ($isIn) {
                $lastInboundText = $summary;
                $lastInboundDate = $msg['activity_date'];
            } else {
                $lastOutboundDate = $msg['activity_date'];
            }
        }

        if ($lastInboundDate && str_contains($lastInboundText, '?') && ($lastOutboundDate < $lastInboundDate || !$lastOutboundDate)) {
            $hasUnansweredQuestion = true;
        }

        $lastRow = (array) end($thread);
        $daysSinceLastContact = (int) floor((time() - strtotime($lastRow['activity_date'])) / 86400);

        // Append last sent/received email bodies for context
        $lastOutEmail = DB::selectOne("
            SELECT body_text, subject, email_date FROM portal_emails
            WHERE property_id = ? AND direction = 'outbound'
              AND (LOWER(to_email) = LOWER(?) OR stakeholder = ?)
              AND DATE(email_date) < CURDATE()
            ORDER BY email_date DESC LIMIT 1
        ", [$propertyId, $conv->contact_email, $stakeholder]);

        $lastInEmail = DB::selectOne("
            SELECT body_text, subject, from_name, email_date FROM portal_emails
            WHERE property_id = ? AND direction = 'inbound'
              AND (LOWER(from_email) = LOWER(?) OR stakeholder = ?)
              AND DATE(email_date) < CURDATE()
            ORDER BY email_date DESC LIMIT 1
        ", [$propertyId, $conv->contact_email, $stakeholder]);

        if ($lastOutEmail && $lastOutEmail->body_text) {
            $outBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastOutEmail->body_text))), 0, 1500);
            $threadContext .= "\n--- LETZTE GESENDETE NACHRICHT ({$lastOutEmail->email_date}) ---\nBetreff: {$lastOutEmail->subject}\n{$outBody}\n--- ENDE ---\n";
        }
        if ($lastInEmail && $lastInEmail->body_text) {
            $inBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastInEmail->body_text))), 0, 1000);
            $threadContext .= "\n--- LETZTE NACHRICHT VOM KUNDEN ({$lastInEmail->email_date}) ---\nVon: {$lastInEmail->from_name}\nBetreff: {$lastInEmail->subject}\n{$inBody}\n--- ENDE ---\n";
        }

        // Knowledge base context — prioritize verkaufsstatus and verhandlung entries
        $kbContext = '';
        $kbItems = DB::select("
            SELECT category, title, content FROM property_knowledge
            WHERE property_id = ? AND is_active = 1
              AND category NOT IN ('feedback_besichtigung', 'feedback_negativ', 'feedback_positiv')
            ORDER BY
                CASE WHEN category IN ('verhandlung','vermarktung') THEN 0 ELSE 1 END,
                confidence DESC
            LIMIT 15
        ", [$propertyId]);

        if (!empty($kbItems)) {
            $kbContext = "OBJEKTWISSEN:\n";
            $chars = 0;
            foreach ($kbItems as $k) {
                $k = (array) $k;
                $line = "- {$k['title']}: {$k['content']}\n";
                if ($chars + strlen($line) > 2500) break;
                $kbContext .= $line;
                $chars += strlen($line);
            }
        }

        // Add unit availability with prices for Neubauprojekte
        $freieUnits = DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->where('status', 'frei')->orderByRaw('CAST(REPLACE(unit_number, "TOP ", "") AS UNSIGNED)')->get(['unit_number','unit_type','area_m2','rooms','price']);
        $verkaufteNummern = DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->where('status', 'verkauft')->pluck('unit_number')->toArray();

        if ($freieUnits->count() + count($verkaufteNummern) > 0) {
            $kbContext .= "\n--- EINHEITEN + PREISE (LIVE-DATEN) ---\n";
            $kbContext .= "VERFUEGBAR:\n";
            foreach ($freieUnits as $u) {
                $kbContext .= $u->unit_number . ': ' . $u->rooms . '-Zi, ' . $u->area_m2 . 'm², ' . number_format($u->price, 0, ',', '.') . ' EUR\n';
            }
            if (!empty($verkaufteNummern)) {
                $kbContext .= "VERKAUFT (NICHT anbieten!): " . implode(', ', $verkaufteNummern) . "\n";
            }
            $kbContext .= "--- ENDE ---\n";
        }

        // Viewing status (prevents AI from hallucinating viewings)
        $hasViewing = DB::selectOne("SELECT COUNT(*) as cnt FROM activities WHERE property_id = ? AND category = 'besichtigung' AND stakeholder LIKE ?", [$propertyId, '%' . mb_substr($stakeholder, 0, 20) . '%']);
        if (($hasViewing->cnt ?? 0) === 0) {
            $threadContext .= "\n--- ABSOLUTES VERBOT ---\nEs hat KEINE Besichtigung mit diesem Interessenten stattgefunden.\nDu darfst die Woerter Besichtigung, Besichtigungstermin, Begehung, vor Ort angesehen, Eindruck vom Haus NICHT verwenden.\nAuch wenn die Wissensdatenbank Besichtigungstermine erwaehnt — das ist generelle Info zum Objekt, NICHT fuer diesen Kontakt.\n--- ENDE VERBOT ---\n";
        }

                // Explicit first-response hint when no outbound yet
        if (($conv->outbound_count ?? 0) === 0) {
            $threadContext .= "\n--- ERSTANTWORT ---\nDies ist eine NEUE Anfrage. Es wurde noch KEINE Antwort von SR-HOMES gesendet.\nDu schreibst die ALLERERSTE Nachricht an diesen Interessenten.\nBeziehe dich AUSSCHLIEßLICH auf die Anfrage des Kunden. Erfinde KEINE vorherigen Kontakte, Besichtigungen oder Gespraeche.\n--- ENDE ERSTANTWORT ---\n";
        }

        // Determine followup stage
        $followupCount = $conv->followup_count ?? 0;
        $isSecondFollowup = $followupCount >= 1 || in_array($conv->status, ['nachfassen_2', 'nachfassen_3']);

        // Contact phone
        $contact = DB::selectOne("SELECT phone, email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        $hasPhone = !empty($contact->phone ?? null);

        // Property info
        $prop = DB::selectOne("SELECT address, city, ref_id FROM properties WHERE id = ?", [$propertyId]);
        $propAddr = ($prop->address ?? '') . ', ' . ($prop->city ?? '');

        // Generate draft
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            $draft = $anthropic->generateFollowupDraft(
                $stakeholder, $propAddr, $threadContext, $kbContext,
                $hasPhone, 'professional', $daysSinceLastContact,
                $hasUnansweredQuestion, $today, $isSecondFollowup
            );
        } catch (\Throwable $e) {
            \Log::error('conv_regenerate_draft failed', ['conv_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'KI-Fehler: ' . $e->getMessage()], 500);
        }

        if ($draft && !empty($draft['email_body'])) {
            $subject = $draft['email_subject'] ?? 'Nachfrage: ' . $propAddr;

            \Log::info('conv_regenerate_draft: SAVING draft (NOT sending!) for conv ' . $id . ' subject: ' . $subject);
            app(ConversationService::class)->saveDraft($conv, $draft['email_body'], $subject, $conv->contact_email);

            return response()->json([
                'success'       => true,
                'draft_body'    => $draft['email_body'],
                'draft_subject' => $subject,
                'draft_to'      => $conv->contact_email,
                'lead_phase'    => $draft['lead_phase'] ?? null,
                'call_script'   => $draft['call_script'] ?? null,
            ]);
        }

        return response()->json(['error' => 'KI-Entwurf konnte nicht generiert werden'], 500);
    }

        /**
     * conv_improve_draft — Improve draft wording via existing improve_text.
     */
    public function improveDraft(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        $text = $input['text'] ?? '';
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Use provided text or current draft
        $textToImprove = $text ?: $conv->draft_body;
        if (!$textToImprove) {
            return response()->json(['error' => 'Kein Text zum Verbessern'], 400);
        }

        // Call existing improve_text internally
        $improveRequest = Request::create('/', 'POST', [], [], [], [], json_encode([
            'text' => $textToImprove,
        ]));
        $improveRequest->headers->set('Content-Type', 'application/json');

        $improveResponse = app(EmailController::class)->improveText($improveRequest);
        $improveData = json_decode($improveResponse->getContent(), true);

        if (!empty($improveData['improved_text'])) {
            app(ConversationService::class)->saveDraft(
                $conv,
                $improveData['improved_text'],
                $conv->draft_subject,
                $conv->draft_to
            );
            return response()->json([
                'success'    => true,
                'draft_body' => $improveData['improved_text'],
            ]);
        }

        return response()->json(['error' => $improveData['error'] ?? 'Verbesserung fehlgeschlagen'], 500);
    }

    /**
     * conv_followup_all — Send all pending drafts.
     */
    public function followupAll(Request $request): JsonResponse
    {
        // PERMANENTLY DISABLED
        return response()->json(['error' => 'Disabled'], 403);
    }

    // ========================================================
    // AI Cross-Match endpoints
    // ========================================================

    public function matchList(Request $request): JsonResponse
    {
        $convId = intval($request->input('conversation_id') ?: $request->query('conversation_id', 0));
        if (!$convId) return response()->json(['error' => 'conversation_id required'], 400);

        $matcher = app(PropertyMatcherService::class);
        $data = $matcher->getMatchesForConversation($convId);

        return response()->json($data);
    }

    public function matchDismiss(Request $request): JsonResponse
    {
        $convId = intval($request->input('conversation_id') ?: $request->query('conversation_id', 0));
        if (!$convId) return response()->json(['error' => 'conversation_id required'], 400);

        Conversation::where('id', $convId)->update(['match_dismissed' => true, 'match_count' => 0]);
        PropertyMatch::where('conversation_id', $convId)->where('status', 'pending')->update(['status' => 'dismissed']);

        return response()->json(['ok' => true]);
    }

    public function matchGenerateDraft(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $convId = intval($input['conversation_id'] ?? 0);
        $selectedIds = $input['property_ids'] ?? [];

        if (!$convId || empty($selectedIds)) {
            return response()->json(['error' => 'conversation_id and property_ids required'], 400);
        }

        $conv = Conversation::find($convId);
        if (!$conv) return response()->json(['error' => 'Conversation not found'], 404);

        // Mark selected matches
        PropertyMatch::where('conversation_id', $convId)
            ->whereIn('property_id', $selectedIds)
            ->update(['status' => 'selected']);

        // Load selected properties
        $properties = Property::whereIn('id', $selectedIds)->get();

        // Build thread context for draft generation
        $emailQuery = DB::table('portal_emails')
            ->where('contact_email', $conv->contact_email)
            ->orderByDesc("email_date")
            ->limit(5);

        if ($conv->property_id) {
            $emailQuery->where('property_id', $conv->property_id);
        } else {
            $emailQuery->whereNull('property_id');
        }

        $emails = $emailQuery->get();

        $threadLines = [];
        foreach ($emails->reverse() as $e) {
            $dir = $e->direction === 'inbound' ? 'KUNDE' : 'MAKLER';
            $threadLines[] = "[{$dir}] {$e->subject}\n" . mb_substr($e->body_text ?? '', 0, 400);
        }

        // Build property descriptions for the AI
        $propDescriptions = [];
        foreach ($properties as $i => $p) {
            $area = $p->living_area ?: $p->total_area;
            $propDescriptions[] = ($i + 1) . ". {$p->title} ({$p->address}, {$p->city}) — "
                . ($area ? $area . 'm\xc2\xb2, ' : '')
                . ($p->rooms_amount ? $p->rooms_amount . ' Zimmer, ' : '')
                . ($p->purchase_price ? '\xe2\x82\xac' . number_format($p->purchase_price, 0, ',', '.') : 'Preis auf Anfrage');
        }

        // Get broker name
        $brokerId = $conv->property_id ? Property::where('id', $conv->property_id)->value('broker_id') : null;
        $brokerName = $brokerId ? DB::table('users')->where('id', $brokerId)->value('name') : 'SR Homes';

        // AI draft generation
        $systemPrompt = <<<'PROMPT'
Du bist ein Immobilienmakler-Assistent. Schreibe eine professionelle Email an den Kunden.
Der Kunde hat sich ursprünglich für ein anderes Objekt interessiert. Du schlägst ihm jetzt zusätzliche passende Objekte vor.

Regeln:
- Formelle Anrede (Sehr geehrte/r)
- Beziehe dich auf den bisherigen Kontext (Anfrage/Absage)
- Stelle die Objekte als natürliche Empfehlungen vor, nicht als Werbung
- Erwähne dass Exposés beigefügt sind
- Kurz und professionell, max 150 Wörter
- Schließe mit "Mit freundlichen Grüßen" und dem Maklernamen

Antworte als JSON:
{"email_subject": "...", "email_body": "..."}
PROMPT;

        $userMessage = "Thread:\n" . implode("\n---\n", $threadLines)
            . "\n\nKunde: {$conv->stakeholder}\n\nVorzuschlagende Objekte:\n" . implode("\n", $propDescriptions)
            . "\n\nMaklername: {$brokerName}";

        $ai = app(AnthropicService::class);
        $draft = $ai->chatJson($systemPrompt, $userMessage, 800);

        if (!$draft) {
            return response()->json(['error' => 'AI draft generation failed'], 500);
        }

        // Collect expose file IDs for attachments
        $fileIds = [];
        foreach ($properties as $p) {
            if ($p->expose_path) {
                $file = DB::table('property_files')
                    ->where('property_id', $p->id)
                    ->where('path', $p->expose_path)
                    ->first();
                if ($file) {
                    $fileIds[] = $file->id;
                }
            }
        }

        // Save draft to conversation
        $convService = app(ConversationService::class);
        $convService->saveDraft(
            $conv,
            $draft['email_body'] ?? '',
            $draft['email_subject'] ?? 'Objektvorschl\xc3\xa4ge',
            $conv->contact_email
        );

        return response()->json([
            'draft_body' => $draft['email_body'] ?? '',
            'draft_subject' => $draft['email_subject'] ?? 'Objektvorschl\xc3\xa4ge',
            'draft_to' => $conv->contact_email,
            'file_ids' => $fileIds,
            'matched_property_ids' => $selectedIds,
        ]);
    }

    // ========================================================
    // Legacy method — keep for backward compat ("conversations" action)
    // ========================================================

    /**
     * Legacy thread-loading endpoint (backward compat).
     */
    public function legacyIndex(Request $request): JsonResponse
    {
        $propertyId  = intval($request->query('property_id', 0));
        $brokerId = Auth::id();
        $userType = Auth::user()->user_type ?? 'makler';
        $brokerConvFilter = ($brokerId && $userType !== 'assistenz') ? "AND a.property_id IN (SELECT id FROM properties WHERE broker_id = {$brokerId})" : '';
        $stakeholder = $request->query('stakeholder', '');
        $page    = max(1, intval($request->query('page', 1)));
        $perPage = min(200, max(1, intval($request->query('per_page', 20))));
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
