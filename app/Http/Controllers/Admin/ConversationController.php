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
            $query->nachfassen()->orderBy('last_outbound_at', 'asc');
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

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $conversations = collect($paginated->items())->map(function (Conversation $conv) {
            $prop = $conv->property;
            return [
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
                'draft_subject'    => $conv->draft_subject,
                'draft_to'         => $conv->draft_to,
                'is_read'          => $conv->is_read,
                'ref_id'           => $prop?->ref_id,
                'address'          => $prop?->address,
                'from_name'        => $conv->stakeholder,
            ];
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
                pe.attachment_names
            FROM portal_emails pe
            WHERE pe.property_id = ?
              AND (
                  LOWER(pe.from_email) = LOWER(?)
                  OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
              )
            ORDER BY pe.email_date ASC
        ", [$conv->property_id, $conv->contact_email, $conv->contact_email]);

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
            'draft_subject'    => $conv->draft_subject,
            'draft_to'         => $conv->draft_to,
            'is_read'          => $conv->is_read,
            'ref_id'           => $prop?->ref_id,
            'address'          => $prop?->address,
            'from_name'        => $conv->stakeholder,
        ];

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
        $id = intval($input['id'] ?? 0);
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

        // Send via EmailService
        try {
            $emailService = app(\App\Services\EmailService::class);
            $result = $emailService->send(
                (int) $accountId,
                $to,
                $subject,
                $body,
                $conv->property_id,
                $conv->stakeholder,
                null, null, [],
                null, null,
                'email-out'
            );

            // Create activity
            DB::table('activities')->insert([
                'property_id'    => $conv->property_id,
                'stakeholder'    => $conv->stakeholder,
                'activity_date'  => now(),
                'category'       => 'email-out',
                'activity'       => 'E-Mail gesendet: ' . $subject,
                'result'         => mb_substr(strip_tags($body), 0, 500),
                'source_email_id' => $result['email_id'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Update conversation: status -> beantwortet, clear draft
            $conv->status = 'beantwortet';
            $conv->draft_body = null;
            $conv->draft_subject = null;
            $conv->draft_to = null;
            $conv->draft_generated_at = null;
            $conv->last_outbound_at = now();
            $conv->outbound_count = ($conv->outbound_count ?? 0) + 1;
            $conv->save();

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
        $id = intval($input['id'] ?? 0);
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
            $result = $emailService->send(
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
        $id = intval($input['id'] ?? 0);
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
     * conv_regenerate_draft — Regenerate KI draft via existing ai_reply.
     */
    public function regenerateDraft(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = Conversation::find($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Find the last inbound email to use as context for AI
        $lastEmail = DB::selectOne("
            SELECT id FROM portal_emails
            WHERE property_id = ?
              AND LOWER(from_email) = LOWER(?)
              AND direction = 'inbound'
            ORDER BY email_date DESC
            LIMIT 1
        ", [$conv->property_id, $conv->contact_email]);

        if (!$lastEmail) {
            return response()->json(['error' => 'Keine eingehende E-Mail gefunden fuer KI-Entwurf'], 404);
        }

        // Call existing ai_reply internally
        $aiRequest = Request::create('/', 'POST', [], [], [], [], json_encode([
            'email_id' => $lastEmail->id,
            'tone'     => 'professional',
        ]));
        $aiRequest->headers->set('Content-Type', 'application/json');

        $aiResponse = app(EmailController::class)->aiReply($aiRequest);
        $aiData = json_decode($aiResponse->getContent(), true);

        if (!empty($aiData['reply'])) {
            app(ConversationService::class)->saveDraft(
                $conv,
                $aiData['reply'],
                $aiData['subject'] ?? $conv->draft_subject ?? 'Re: Ihre Anfrage',
                $conv->contact_email
            );
            return response()->json([
                'success' => true,
                'draft_body'    => $aiData['reply'],
                'draft_subject' => $aiData['subject'] ?? 'Re: Ihre Anfrage',
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
        $id = intval($input['id'] ?? 0);
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
        $brokerId = Auth::id();
        $userType = Auth::user()->user_type ?? 'makler';

        $conversations = Conversation::withDraft()
            ->whereIn('status', ['beantwortet', 'nachfassen_1', 'nachfassen_2', 'nachfassen_3'])
            ->forBroker($brokerId, $userType)
            ->get();

        if ($conversations->isEmpty()) {
            return response()->json(['success' => true, 'sent' => 0, 'message' => 'Keine Entwuerfe vorhanden']);
        }

        // Determine account_id: use input or find default
        $accountId = $request->json('account_id');
        if (!$accountId) {
            $account = DB::table('email_accounts')
                ->where('is_active', 1)
                ->when($brokerId, fn($q) => $q->where('user_id', $brokerId))
                ->first();
            $accountId = $account?->id;
        }
        if (!$accountId) {
            return response()->json(['error' => 'Kein E-Mail-Konto gefunden'], 400);
        }

        $sent = 0;
        $errors = [];
        $emailService = app(\App\Services\EmailService::class);
        $convService = app(ConversationService::class);

        foreach ($conversations as $conv) {
            try {
                $followupStage = ($conv->followup_count ?? 0) + 1;
                $to = $conv->draft_to ?: $conv->contact_email;
                $subject = $conv->draft_subject ?: 'Nachfassen: Ihre Anfrage';
                $body = $conv->draft_body;

                $result = $emailService->send(
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

                DB::table('activities')->insert([
                    'property_id'    => $conv->property_id,
                    'stakeholder'    => $conv->stakeholder,
                    'activity_date'  => now(),
                    'category'       => 'nachfassen',
                    'activity'       => 'Nachfassen #' . $followupStage . ' (Bulk): ' . $subject,
                    'result'         => mb_substr(strip_tags($body), 0, 500),
                    'source_email_id' => $result['email_id'] ?? null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                $convService->advanceFollowup($conv);
                $sent++;
            } catch (\Exception $e) {
                Log::error('conv_followup_all: failed for conv ' . $conv->id, ['error' => $e->getMessage()]);
                $errors[] = ['id' => $conv->id, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'sent'    => $sent,
            'errors'  => $errors,
            'message' => "{$sent} Nachfassen gesendet",
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
