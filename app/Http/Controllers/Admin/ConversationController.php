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
    private function shouldHideFromAnfragen(array $item, array $rules): bool
    {
        if (empty($rules)) return false;

        $fromEmail = strtolower(trim((string) ($item['contact_email'] ?? '')));
        $fromName = strtolower(trim((string) ($item['from_name'] ?? '')));
        $stakeholder = strtolower(trim((string) ($item['stakeholder'] ?? '')));
        $haystack = $fromEmail . ' ' . $fromName . ' ' . $stakeholder;

        foreach ($rules as $rule) {
            $pattern = strtolower(trim((string) ($rule->pattern ?? '')));
            if ($pattern === '') continue;

            // Domain rule: @domain.tld or domain.tld
            if (str_starts_with($pattern, '@')) {
                if ($fromEmail !== '' && str_ends_with($fromEmail, $pattern)) return true;
                continue;
            }
            if (!str_contains($pattern, '@') && str_contains($pattern, '.')) {
                if ($fromEmail !== '' && str_ends_with($fromEmail, '@' . $pattern)) return true;
                continue;
            }

            // Exact email or substring fallback
            if (str_contains($pattern, '@')) {
                if ($fromEmail === $pattern) return true;
            } elseif (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the latest inbound non-trashed mail in the current user's
     * mailboxes for a given conversation, returning its display metadata.
     * Used to override stakeholder/subject/contact_email at list-render
     * time so a shared thread is labelled from the user's own perspective.
     *
     * Returns null when there's no user-visible inbound mail (e.g. all
     * inbound mails are in other colleagues' accounts or trashed).
     *
     * @param  int[]|null  $accountIds
     * @return array{from_name: ?string, from_email: ?string, subject: ?string, email_date: ?string, account_id: ?int}|null
     */
    private function resolveUserVisibleDisplay(Conversation $conv, ?array $accountIds): ?array
    {
        // No account filter (console/cron/assistenz with full scope) — the
        // globally stored stakeholder is correct, skip the override.
        if ($accountIds === null) return null;
        if (empty($accountIds)) return null;

        $contactEmail = strtolower((string) $conv->contact_email);
        $stakeholder  = (string) ($conv->stakeholder ?? '');

        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $params = [
            $conv->property_id,
            $contactEmail,
            $contactEmail,
            $stakeholder,
            $conv->last_email_id,
        ];
        foreach ($accountIds as $aid) $params[] = (int) $aid;

        $row = DB::selectOne("
            SELECT pe.from_name, pe.from_email, pe.subject, pe.email_date, pe.account_id
            FROM portal_emails pe
            WHERE pe.is_deleted = 0
              AND pe.direction = 'inbound'
              AND (pe.property_id = ? OR pe.property_id IS NULL)
              AND (
                  LOWER(pe.from_email) = LOWER(?)
                  OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
                  OR LOWER(pe.stakeholder) = LOWER(?)
                  OR pe.id = ?
              )
              AND pe.account_id IN ({$placeholders})
            ORDER BY pe.email_date DESC
            LIMIT 1
        ", $params);

        if (!$row) return null;

        return [
            'from_name'  => $row->from_name ?? null,
            'from_email' => $row->from_email ?? null,
            'subject'    => $row->subject ?? null,
            'email_date' => $row->email_date ?? null,
            'account_id' => isset($row->account_id) ? (int) $row->account_id : null,
        ];
    }

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
        $brokerFilterRaw = (string) $request->query('broker_filter', '');

        // Fall through to existing email_history for view-based queries (backward compat)
        if ($view === 'posteingang' || $view === 'gesendet') {
            return app(EmailController::class)->history($request);
        }

        $query = Conversation::with('property:id,ref_id,address,city')
            ->forBroker($brokerId, $userType);

        if ($status === 'offen') {
            $query->where('status', '!=', 'erledigt')->where(function($q) { $q->where(function($q2) { $q2->whereColumn('last_inbound_at', '>', 'last_outbound_at'); })->orWhereNull('last_outbound_at'); })->orderBy('last_inbound_at', 'desc');
        } elseif ($status === 'nachfassen') {
            // Nachfassen-Stufe basiert auf followup_count (nicht status),
            // weil status auf 'beantwortet' zurueckgesetzt wird wenn Kunde antwortet.
            // followup_count=0 → NF1 faellig (24h warten)
            // followup_count=1 → NF2 faellig (3 Tage warten)
            // followup_count>=2 → NF3 faellig (3 Tage warten)
            // followup_count>=3 → auto-erledigt (nicht mehr zeigen)
            $query->whereIn('status', ['beantwortet', 'nachfassen_1', 'nachfassen_2'])
                ->where('followup_count', '<', 3)
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        // Noch kein Nachfassen gesendet: nach 24h zeigen
                        $q2->where('followup_count', 0)
                            ->where('last_outbound_at', '<=', now()->subHours(24));
                    })->orWhere(function ($q2) {
                        // 1+ Nachfassen gesendet: nach 3 Tagen zeigen
                        $q2->where('followup_count', '>=', 1)
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

        // Account scope for the current user — used to build a per-user
        // display name / subject so a shared conversation (e.g. Susanne
        // forwarded a Baldinger mail to Max) shows the MAX-visible sender
        // in the list instead of the globally-stored stakeholder, which
        // would otherwise read "Baldinger Immobilien" even though the only
        // mail Max can see in that thread is from Susanne.
        $convService = app(ConversationService::class);
        $userAcctIds = $convService->currentUserAccountIds();
        $scopedAcctIds = $userAcctIds;
        $hasBrokerScopedFilter = false;

        if (in_array($userType, ['assistenz', 'backoffice'], true) && $brokerFilterRaw !== '') {
            $targetBrokerId = is_numeric($brokerFilterRaw) ? (int) $brokerFilterRaw : 0;
            if ($targetBrokerId > 0) {
                $scopedAcctIds = DB::table('email_accounts')
                    ->where('is_active', 1)
                    ->where('user_id', $targetBrokerId)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                $hasBrokerScopedFilter = true;
            }
        }

        $conversations = collect($paginated->items())->map(function (Conversation $conv) use ($scopedAcctIds, $hasBrokerScopedFilter) {
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
                'match_count'      => $conv->match_count,
                'match_dismissed'  => $conv->match_dismissed,
                'ref_id'           => $prop?->ref_id,
                'address'          => $prop?->address,
                'from_name'        => $conv->stakeholder,
                'subject'          => '',
                'account_id'       => null,
            ];

            // Per-user display: look up the latest non-trashed inbound mail
            // on this conversation that's in the current user's mailboxes,
            // and override the list title with that sender's name + subject.
            // Outbound mails don't count — the list shows who we talk TO.
            $displayOverride = $this->resolveUserVisibleDisplay($conv, $scopedAcctIds);
            if ($displayOverride) {
                if (!empty($displayOverride['from_name'])) {
                    $item['stakeholder'] = $displayOverride['from_name'];
                    $item['from_name']   = $displayOverride['from_name'];
                }
                // Only override contact_email when the stored value is a
                // known placeholder (legacy internal-sender pattern). For
                // real email addresses (e.g. a customer email extracted
                // from a Typeform/Willhaben/ImmoScout body by
                // ConversationService::resolveContactEmail) the stored
                // value is the authoritative reply target and must NOT
                // be clobbered with the latest inbound mail's from_email
                // (which is typically the platform notification address).
                if (!empty($displayOverride['from_email'])
                    && str_ends_with(strtolower((string) $item['contact_email']), '@placeholder.local')
                ) {
                    $item['contact_email'] = $displayOverride['from_email'];
                }
                if (!empty($displayOverride['subject'])) {
                    $item['subject'] = $displayOverride['subject'];
                }
                if (!empty($displayOverride['email_date'])) {
                    $item['last_inbound_at'] = $displayOverride['email_date'];
                }
                if (!empty($displayOverride['account_id'])) {
                    $item['account_id'] = (int) $displayOverride['account_id'];
                }
            } elseif ($hasBrokerScopedFilter) {
                return null;
            }

            // Fallback: global conv last_email_id subject (only when we
            // didn't get a per-user override)
            if ($item['subject'] === '' && $conv->last_email_id) {
                $lastEmail = DB::selectOne("SELECT subject FROM portal_emails WHERE id = ?", [$conv->last_email_id]);
                if ($lastEmail) $item['subject'] = $lastEmail->subject;
            }

            return $item;
        })->filter();

        // Inbox-Regel: bestimmte Absender in "Anfragen" ausblenden.
        if ($status === 'offen') {
            $rules = DB::table('inbox_sender_rules')
                ->where('user_id', Auth::id())
                ->where('enabled', 1)
                ->where('action', 'exclude_anfragen')
                ->get(['pattern']);

            if ($rules->isNotEmpty()) {
                $conversations = $conversations
                    ->filter(fn ($item) => !$this->shouldHideFromAnfragen($item, $rules->all()))
                    ->values();
            }
        }

        $totalCount = $hasBrokerScopedFilter ? $conversations->count() : $paginated->total();

        // For nachfassen, group by status in response
        if ($status === 'nachfassen') {
            $grouped = $conversations->groupBy('status');
            return response()->json([
                'conversations' => $conversations->values(),
                'grouped'       => $grouped,
                'total'         => $totalCount,
                'page'          => $page,
                'per_page'      => $perPage,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'conversations' => $conversations->values(),
            'total'         => $totalCount,
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

        // Load messages from portal_emails matched by contact_email + property_id.
        // DEFENSE: when contact_email is an internal SR-Homes address (legacy data
        // bug), avoid to_email wildcard matching and scope strictly by stakeholder.
        // Do NOT exclude internal senders here, otherwise legitimate internal
        // conversation messages (e.g. colleague replies) disappear from the thread.
        $isInternalContact = (bool) preg_match('/@(sr-homes\.at|bstf\.at)$/i', (string) $conv->contact_email);
        // Account scoping: when looking at a shared thread, only show mails
        // that landed in the CURRENT user's mailboxes. Makler see their own
        // accounts only; assistenz/backoffice see all. Without this a
        // forwarded mail (Susanne → Max) would drag Susanne's entire prior
        // correspondence into Max's view even though those mails were never
        // addressed to him.
        $convService = app(ConversationService::class);
        $acctIds = $convService->currentUserAccountIds();
        $acctFilter = '';
        $acctParams = [];
        if (is_array($acctIds) && count($acctIds) > 0) {
            $ph = implode(',', array_fill(0, count($acctIds), '?'));
            // Include NULL account rows as a safety net for legacy data
            // imported before account_id existed.
            $acctFilter = " AND (pe.account_id IN ({$ph}) OR pe.account_id IS NULL)";
            $acctParams = array_map('intval', $acctIds);
        }

        if ($isInternalContact) {
            $stakeholderEmail = filter_var($conv->stakeholder, FILTER_VALIDATE_EMAIL) ? strtolower($conv->stakeholder) : null;
            $messages = DB::select("
                SELECT
                    pe.id, pe.direction,
                    CASE
                        WHEN pe.direction = 'inbound' THEN pe.from_name
                        ELSE COALESCE(
                            sender_user.name,
                            sender_account.label,
                            sender_account.email_address,
                            sender_acct_from.label,
                            sender_acct_from.email_address,
                            pe.from_email,
                            pe.to_email
                        )
                    END as from_name,
                    pe.from_email,
                    pe.to_email,
                    pe.subject,
                    SUBSTRING(pe.body_text, 1, 5000) as body_text,
                    pe.body_html,
                    pe.email_date,
                    pe.category,
                    pe.has_attachment,
                    pe.attachment_names,
                    CASE
                        WHEN pe.direction = 'outbound' AND sender_user.profile_image IS NOT NULL AND TRIM(sender_user.profile_image) <> ''
                            THEN CONCAT('/storage/', sender_user.profile_image)
                        WHEN pe.direction = 'outbound'
                            AND sender_settings.signature_photo_path IS NOT NULL AND TRIM(sender_settings.signature_photo_path) <> ''
                            THEN CONCAT('/storage/', sender_settings.signature_photo_path)
                        ELSE NULL
                    END as sender_avatar_url,
                    a.followup_stage
                FROM portal_emails pe
                LEFT JOIN activities a ON a.source_email_id = pe.id
                LEFT JOIN email_accounts sender_account ON sender_account.id = pe.account_id
                LEFT JOIN email_accounts sender_acct_from ON pe.direction = 'outbound'
                    AND (
                        pe.account_id IS NULL OR pe.account_id = 0
                        OR sender_account.user_id IS NULL OR sender_account.user_id = 0
                    )
                    AND sender_acct_from.id = (
                        SELECT ea2.id FROM email_accounts ea2
                        WHERE ea2.is_active = 1
                          AND LOWER(TRIM(ea2.email_address)) = LOWER(TRIM(pe.from_email))
                        ORDER BY ea2.id ASC
                        LIMIT 1
                    )
                LEFT JOIN users sender_user ON sender_user.id = COALESCE(
                    NULLIF(sender_account.user_id, 0),
                    NULLIF(sender_acct_from.user_id, 0)
                )
                LEFT JOIN admin_settings sender_settings ON sender_settings.user_id = sender_user.id
                WHERE pe.is_deleted = 0
                  AND (pe.property_id = ? OR pe.property_id IS NULL)
                  AND (
                      LOWER(pe.stakeholder) = LOWER(?)
                      OR (
                          ? IS NOT NULL
                          AND (
                              (LOWER(pe.from_email) = LOWER(?) AND LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%'))
                              OR (LOWER(pe.from_email) = LOWER(?) AND LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%'))
                          )
                      )
                      OR pe.id = ?
                  )
                  {$acctFilter}
                ORDER BY pe.email_date ASC
            ", array_merge([
                $conv->property_id,
                $conv->stakeholder,
                $stakeholderEmail,
                $conv->contact_email,
                $stakeholderEmail,
                $stakeholderEmail,
                $conv->contact_email,
                $conv->last_email_id,
            ], $acctParams));
        } else {
            $messages = DB::select("
                SELECT
                    pe.id, pe.direction,
                    CASE
                        WHEN pe.direction = 'inbound' THEN pe.from_name
                        ELSE COALESCE(
                            sender_user.name,
                            sender_account.label,
                            sender_account.email_address,
                            sender_acct_from.label,
                            sender_acct_from.email_address,
                            pe.from_email,
                            pe.to_email
                        )
                    END as from_name,
                    pe.from_email,
                    pe.to_email,
                    pe.subject,
                    SUBSTRING(pe.body_text, 1, 5000) as body_text,
                    pe.body_html,
                    pe.email_date,
                    pe.category,
                    pe.has_attachment,
                    pe.attachment_names,
                    CASE
                        WHEN pe.direction = 'outbound' AND sender_user.profile_image IS NOT NULL AND TRIM(sender_user.profile_image) <> ''
                            THEN CONCAT('/storage/', sender_user.profile_image)
                        WHEN pe.direction = 'outbound'
                            AND sender_settings.signature_photo_path IS NOT NULL AND TRIM(sender_settings.signature_photo_path) <> ''
                            THEN CONCAT('/storage/', sender_settings.signature_photo_path)
                        ELSE NULL
                    END as sender_avatar_url,
                    a.followup_stage
                FROM portal_emails pe
                LEFT JOIN activities a ON a.source_email_id = pe.id
                LEFT JOIN email_accounts sender_account ON sender_account.id = pe.account_id
                LEFT JOIN email_accounts sender_acct_from ON pe.direction = 'outbound'
                    AND (
                        pe.account_id IS NULL OR pe.account_id = 0
                        OR sender_account.user_id IS NULL OR sender_account.user_id = 0
                    )
                    AND sender_acct_from.id = (
                        SELECT ea2.id FROM email_accounts ea2
                        WHERE ea2.is_active = 1
                          AND LOWER(TRIM(ea2.email_address)) = LOWER(TRIM(pe.from_email))
                        ORDER BY ea2.id ASC
                        LIMIT 1
                    )
                LEFT JOIN users sender_user ON sender_user.id = COALESCE(
                    NULLIF(sender_account.user_id, 0),
                    NULLIF(sender_acct_from.user_id, 0)
                )
                LEFT JOIN admin_settings sender_settings ON sender_settings.user_id = sender_user.id
                WHERE pe.is_deleted = 0
                  AND (pe.property_id = ? OR pe.property_id IS NULL)
                  AND (
                      LOWER(pe.from_email) = LOWER(?)
                      OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
                      OR LOWER(pe.stakeholder) = LOWER(?)
                      OR pe.id = ?
                      OR (
                          ? IS NOT NULL
                          AND LOWER(pe.from_email) LIKE '%noreply%'
                          AND LOWER(pe.body_text) LIKE CONCAT('%', LOWER(?), '%')
                      )
                  )
                  {$acctFilter}
                ORDER BY pe.email_date ASC
            ", array_merge([
                $conv->property_id,
                $conv->contact_email,
                $conv->contact_email,
                $conv->stakeholder,
                $conv->last_email_id,
                $conv->contact_email,
                $conv->contact_email,
            ], $acctParams));
        }

        // Normalize potential legacy/invalid encodings so JSON rendering does not
        // drop message body fields ("only Eingehend badge visible" issue).
        $messages = array_map(function ($msg) {
            foreach (['from_name', 'subject', 'body_text', 'body_html', 'category', 'attachment_names'] as $field) {
                if (!isset($msg->$field) || $msg->$field === null) {
                    continue;
                }
                $value = (string) $msg->$field;
                if ($value === '') {
                    continue;
                }
                $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                $msg->$field = $clean !== false ? $clean : mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            return $msg;
        }, $messages);

        $prop = $conv->property;
        $latestMessage = !empty($messages) ? $messages[count($messages) - 1] : null;
        $headerFromName = $conv->stakeholder
            ?: ($latestMessage->from_name ?? null)
            ?: $conv->contact_email;

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
            'from_name'        => $headerFromName,
        ];

        // Add subject from latest message
        $convData['subject'] = !empty($messages) ? ($messages[count($messages) - 1]->subject ?? '') : '';
        $convData['from_name'] = $headerFromName;

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

        $conv = $this->resolveConversation($id, 'conv_reply');
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $body      = $input['body'] ?? '';
        $subject   = $input['subject'] ?? '';
        $to        = $input['to'] ?? $conv->contact_email;
        $cc        = $input['cc'] ?? null;
        $accountId = $input['account_id'] ?? null;
        $fileIds   = $input['file_ids'] ?? [];

        // Normalise cc: empty string → null so EmailService::send doesn't
        // pass an empty header.
        if (is_string($cc)) {
            $cc = trim($cc);
            if ($cc === '') $cc = null;
        }

        if (!$body || !$subject || !$accountId) {
            return response()->json(['error' => 'body, subject, account_id required'], 400);
        }

        // Resolve file_ids to file paths for attachments (supports property_files, portal_documents, global_files)
        $attachments = [];
        if (!empty($fileIds) && is_array($fileIds)) {
            foreach ($fileIds as $fid) {
                $path = null;
                if (is_string($fid) && str_starts_with($fid, 'global_')) {
                    // Global file (Allgemeine Dokumente)
                    $gid = intval(str_replace('global_', '', $fid));
                    $file = DB::table('global_files')->where('id', $gid)->first(['path']);
                    if ($file) $path = $file->path;
                } elseif (is_string($fid) && str_starts_with($fid, 'doc_')) {
                    // Portal document
                    $did = intval(str_replace('doc_', '', $fid));
                    $doc = DB::table('portal_documents')->where('id', $did)->first(['property_id', 'filename']);
                    if ($doc) $path = 'documents/' . $doc->property_id . '/' . $doc->filename;
                } else {
                    // Property file
                    $file = DB::table('property_files')->where('id', intval($fid))->first(['path']);
                    if ($file) $path = $file->path;
                }
                if ($path) {
                    $fullPath = storage_path('app/public/' . $path);
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
                $cc, null, $attachments,
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
            $conv->last_outbound_at = now();
            $conv->outbound_count = ($conv->outbound_count ?? 0) + 1;
            $conv->save();

            // Auto-remove answered inbound emails from Posteingang
            // (they move to Papierkorb, reply is visible in Gesendet)
            $inboundIds = DB::table('portal_emails')
                ->where('property_id', $conv->property_id)
                ->where('direction', 'inbound')
                ->where(function ($q) use ($conv) {
                    $q->whereRaw('LOWER(from_email) = ?', [strtolower($conv->contact_email)])
                       ->orWhere(function ($q2) use ($conv) {
                           $q2->where('stakeholder', $conv->stakeholder)
                               ->where('stakeholder', '!=', '')
                               ->where('stakeholder', '!=', null);
                       });
                })
                ->where(function ($q) {
                    $q->where('is_deleted', 0)->orWhereNull('is_deleted');
                })
                ->pluck('id');

            if ($inboundIds->isNotEmpty()) {
                DB::table('portal_emails')
                    ->whereIn('id', $inboundIds)
                    ->update(['is_deleted' => 1, 'deleted_at' => now()]);

                // NOTE: We no longer re-categorize activities to 'update' — that destroys analytics.
                // Unbeantwortet now filters via portal_emails.is_deleted instead.

                Log::info('Auto-trashed inbound emails after conv reply', [
                    'conv_id' => $conv->id,
                    'trashed_count' => $inboundIds->count(),
                ]);
            }

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

        $conv = $this->resolveConversation($id, 'conv_followup');
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

        $conv = $this->resolveConversation($id, 'conv_done');
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        app(ConversationService::class)->markDone($conv);

        // Internes Audit-Activity nur anlegen wenn die Konversation einem
        // Objekt zugeordnet ist (activities.property_id ist NOT NULL) und
        // mit einer gueltigen Kategorie aus dem Enum — 'handled' gab's dort
        // nie, darum der Data-truncated/Integrity-Error in Prod. 'intern'
        // ist die passende Kategorie fuer Makler-interne Statuswechsel.
        if ($conv->property_id) {
            try {
                DB::table('activities')->insert([
                    'property_id'   => $conv->property_id,
                    'stakeholder'   => $conv->stakeholder ?: 'System',
                    'activity_date' => now(),
                    'category'      => 'intern',
                    'activity'      => 'Konversation als erledigt markiert',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } catch (\Throwable $e) {
                // Niemals den markDone-Request wegen einem Audit-Log sprengen.
                \Log::warning("conv_done audit-activity insert failed: " . $e->getMessage());
            }
        }

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

        $conv = $this->resolveConversation($id, 'conv_read');
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

        $conv = $this->resolveConversation($id, 'conv_draft');
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
    /**
     * Resolve ID zu Conversation. Versucht zuerst direkte PK-Suche; bei
     * Fehlschlag probiert es die ID als portal_emails.id zu interpretieren
     * und resolvt darueber zur passenden Conversation (property_id +
     * contact_email / stakeholder). Wird von reply / followup /
     * regenerateDraft gleichermaßen benutzt, damit der Inbox-Posteingang
     * (wo item.id = email_id ist) nicht mehr 'Conversation not found' wirft.
     */
    private function resolveConversation(int $id, string $caller = 'unknown'): ?Conversation
    {
        if (!$id) return null;
        $conv = Conversation::find($id);
        if ($conv) return $conv;

        $email = \DB::table('portal_emails')->where('id', $id)->first(['property_id', 'from_email', 'to_email', 'stakeholder']);
        if (!$email) return null;

        $contactEmail = strtolower($email->from_email ?? $email->to_email ?? '');
        if (preg_match('/<([^>]+)>/', $contactEmail, $m)) $contactEmail = $m[1];

        $q = Conversation::query();
        if ($email->property_id) $q->where('property_id', $email->property_id);
        if ($contactEmail) {
            $q->whereRaw('LOWER(contact_email) = ?', [$contactEmail]);
        } elseif (!empty($email->stakeholder)) {
            $q->where('stakeholder', $email->stakeholder);
        }
        $conv = $q->orderByDesc('id')->first();
        if ($conv) {
            \Log::info("{$caller}: resolved email id={$id} -> conv id={$conv->id} (property={$email->property_id})");
        }
        return $conv;
    }

    public function regenerateDraft(Request $request): JsonResponse
    {
        \Log::info('=== conv_regenerate_draft CALLED === id=' . $request->query('id', $request->json('id', '?')));
        $input = $request->json()->all();
        $id = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $conv = $this->resolveConversation($id, 'conv_regenerate_draft');
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Refuse draft generation for "zur Info" CC copies: the mail wasn't
        // addressed to us, so an AI reply would be nonsense. The UI should
        // hide the regenerate button for these threads, but guard here too
        // in case an old client still posts.
        if ($conv->category === 'info-cc') {
            return response()->json([
                'error' => 'Diese Nachricht wurde nur zur Info (CC) gesendet — kein Antwort-Entwurf erforderlich.',
            ], 400);
        }
        if ($conv->category === 'intern') {
            return response()->json([
                'error' => 'Interne Conversation — kein automatischer Entwurf.',
            ], 400);
        }

        $stakeholder = $conv->stakeholder
            ?: $conv->contact_email
            ?: 'Interessent';
        $propertyId = $conv->property_id;
        $today = date('Y-m-d');

        // Build thread context from portal_emails (more reliable than activities)
        $thread = DB::select("
            SELECT pe.email_date as activity_date, pe.direction, pe.category, pe.subject,
                   SUBSTRING(pe.body_text, 1, 2000) as body_snippet,
                   pe.from_name
            FROM portal_emails pe
            WHERE pe.property_id = ?
              AND (
                    LOWER(pe.from_email) = LOWER(?)
                    OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
                    OR (? IS NOT NULL AND pe.stakeholder = ?)
                    OR pe.id = ?
                    OR (
                        ? IS NOT NULL
                        AND LOWER(pe.from_email) LIKE '%noreply%'
                        AND LOWER(pe.body_text) LIKE CONCAT('%', LOWER(?), '%')
                    )
              )
            ORDER BY pe.email_date ASC
        ", [
            $propertyId,
            $conv->contact_email,
            $conv->contact_email,
            $stakeholder,
            $stakeholder,
            $conv->last_email_id,
            $conv->contact_email,
            $conv->contact_email,
        ]);

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
              AND (
                    LOWER(to_email) = LOWER(?)
                    OR (? IS NOT NULL AND stakeholder = ?)
                    OR id = ?
              )
              
            ORDER BY email_date DESC LIMIT 1
        ", [$propertyId, $conv->contact_email, $stakeholder, $stakeholder, $conv->last_email_id]);

        $lastInEmail = DB::selectOne("
            SELECT body_text, subject, from_name, email_date FROM portal_emails
            WHERE property_id = ? AND direction = 'inbound'
              AND (
                    LOWER(from_email) = LOWER(?)
                    OR (? IS NOT NULL AND stakeholder = ?)
                    OR id = ?
                    OR (
                        ? IS NOT NULL
                        AND LOWER(from_email) LIKE '%noreply%'
                        AND LOWER(body_text) LIKE CONCAT('%', LOWER(?), '%')
                    )
              )
              
            ORDER BY email_date DESC LIMIT 1
        ", [
            $propertyId,
            $conv->contact_email,
            $stakeholder,
            $stakeholder,
            $conv->last_email_id,
            $conv->contact_email,
            $conv->contact_email,
        ]);

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

        // Explicit followup hints
        $outboundCount = $conv->outbound_count ?? 0;
        $lastInbound = $conv->last_inbound_at ? strtotime($conv->last_inbound_at) : 0;
        $lastOutbound = $conv->last_outbound_at ? strtotime($conv->last_outbound_at) : 0;

        if ($outboundCount > 0 && $lastOutbound > $lastInbound && $followupCount === 0) {
            $threadContext .= "\n--- NACHFASSEN (STUFE 1) ---\nSR-HOMES hat bereits geantwortet (am " . date('d.m.Y', $lastOutbound) . "). Der Kunde hat NICHT reagiert.\nDies ist ein NACHFASSEN — KEINE Erstantwort. Du darfst NICHT nochmal das Expose anbieten, NICHT nochmal die Anfrage beantworten.\nSchreibe eine kurze Nachfass-Mail: Bezug auf die letzte Nachricht, kurze Frage ob Interesse besteht, konkreten naechsten Schritt anbieten.\nMaximal 3-4 Saetze.\n--- ENDE NACHFASSEN ---\n";
        } elseif ($outboundCount > 0 && $lastOutbound > $lastInbound && $isSecondFollowup) {
            $threadContext .= "\n--- NACHFASSEN (STUFE 2+) ---\nSR-HOMES hat bereits " . ($followupCount + 1) . " Mal geschrieben. Der Kunde hat NICHT reagiert.\nDies ist das " . ($followupCount + 1) . ". Nachfassen. Ton muss DIREKTER und ABSCHLIESSENDER sein.\n--- ENDE NACHFASSEN ---\n";
        }

        // Contact phone (case-insensitive lookup; tolerates DB drivers without utf8mb4 collation)
        try {
            $contact = DB::selectOne("SELECT phone, email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        } catch (\Throwable $e) {
            $contact = DB::selectOne("SELECT phone, email FROM contacts WHERE LOWER(full_name) = LOWER(?) LIMIT 1", [$stakeholder]);
        }
        $hasPhone = !empty($contact->phone ?? null);

        // Property info
        $prop = DB::selectOne("SELECT address, city, ref_id FROM properties WHERE id = ?", [$propertyId]);
        $propAddr = ($prop->address ?? '') . ', ' . ($prop->city ?? '');

        // ─── NACHFASS-TEMPLATE (deterministisch, keine KI) ────────────
        // User-Wunsch: Nachfass-Mails sollen NIEMALS auf die Immobilie
        // eingehen, keinen Link enthalten, keine Expose-Details wiederholen.
        // Nur generisches Reminder-Pattern mit Datums-Bezug — damit der User
        // nicht jede Mail einzeln kontrollieren muss.
        $isFollowupCase = ($outboundCount > 0 && $lastOutbound > $lastInbound);
        if ($isFollowupCase) {
            $lastOutboundDate = $lastOutbound ? date('d.m.Y', $lastOutbound) : 'vor einigen Tagen';
            // Heuristik fuer die Anrede: wenn der stakeholder mit 'Herr '
            // oder 'Frau ' beginnt, nutzen wir 'Sehr geehrter/Sehr geehrte'.
            // Sonst neutral 'Guten Tag {name}'.
            $sh = trim((string) $stakeholder);
            if (preg_match('/^Herr\s+/i', $sh)) {
                $anrede = 'Sehr geehrter ' . $sh;
            } elseif (preg_match('/^Frau\s+/i', $sh)) {
                $anrede = 'Sehr geehrte ' . $sh;
            } else {
                $anrede = $sh !== '' ? "Guten Tag {$sh}" : 'Guten Tag';
            }

            if ($followupCount === 0) {
                // NF1: Bezug auf erste Zusendung
                $body = $anrede . ",\n\n"
                    . "ich habe Ihnen am {$lastOutboundDate} Unterlagen zukommen lassen "
                    . "und wollte kurz nachfragen, ob die Immobilie grundsätzlich noch für Sie in Frage kommt.\n\n"
                    . "Über eine kurze Rückmeldung würde ich mich freuen.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Nachfrage';
            } elseif ($followupCount === 1) {
                // NF2: Bezug auf letztes Nachfassen
                $body = $anrede . ",\n\n"
                    . "ich habe Ihnen am {$lastOutboundDate} bereits eine Nachfrage geschickt "
                    . "und wollte mich heute noch einmal melden. Kommt die Immobilie grundsätzlich "
                    . "noch für Sie in Frage, oder haben Sie sich bereits anderweitig entschieden?\n\n"
                    . "Über eine kurze Rückmeldung würde ich mich freuen.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Erneute Nachfrage';
            } else {
                // NF3+: Abschliessende Nachfrage mit Feedback-Bitte
                $body = $anrede . ",\n\n"
                    . "ich habe mich nun bereits mehrfach bei Ihnen gemeldet und möchte höflich "
                    . "ein letztes Mal nachfragen: Ist die Immobilie grundsätzlich noch für Sie von Interesse?\n\n"
                    . "Falls nicht, wäre ich Ihnen für eine kurze Rückmeldung sehr dankbar — "
                    . "gerne auch mit dem Grund (z.B. Preis, Lage, Ausstattung, bereits anderweitig entschieden). "
                    . "Das hilft mir, Sie in Zukunft mit passenderen Angeboten zu kontaktieren.\n\n"
                    . "Sollte ich nichts mehr von Ihnen hören, gehe ich davon aus, dass kein Interesse mehr "
                    . "besteht, und melde mich nicht weiter.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Letzte Nachfrage';
            }

            $conversationService = app(ConversationService::class);
            $conversationService->saveDraft($conv, $body, $subject, $conv->contact_email);
            \Log::info("conv_regenerate_draft: template-based followup (NF" . ($followupCount + 1) . ") fuer conv {$id}");

            return response()->json([
                'success'       => true,
                'draft_body'    => $body,
                'draft_subject' => $subject,
                'draft_to'      => $conv->contact_email,
                'source'        => 'template',
            ]);
        }

        // Generate draft (AI-Pfad nur fuer Erstantworten / echte inhaltliche Replies)
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
            $conversationService = app(ConversationService::class);
            $draft['email_body'] = $conversationService->appendDefaultLinkForErstantwort($draft['email_body'], $conv);
            $subject = $draft['email_subject'] ?? 'Nachfrage: ' . $propAddr;

            \Log::info('conv_regenerate_draft: SAVING draft (NOT sending!) for conv ' . $id . ' subject: ' . $subject);
            $conversationService->saveDraft($conv, $draft['email_body'], $subject, $conv->contact_email);

            return response()->json([
                'success'       => true,
                'draft_body'    => $draft['email_body'],
                'draft_subject' => $subject,
                'draft_to'      => $conv->contact_email,
                'lead_phase'    => $draft['lead_phase'] ?? null,
                'call_script'   => $draft['call_script'] ?? null,
            ]);
        }

        \Log::error("conv_regenerate_draft: draft result was empty or invalid", ["conv_id" => $id, "draft" => $draft]);
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
     * conv_followup_all — Mass-send Nachfass-Mails per deterministischem
     * Template. Braucht keinen vorgenerierten Draft — baut das Template
     * per Konversation on-the-fly (gleiche Logik wie in regenerateDraft).
     * Stages filtern die Welle: [1] = nur NF1, [1,2] = NF1+NF2, etc.
     */
    public function followupAll(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $accountId = intval($input['account_id'] ?? 0);
        $stages = $input['stages'] ?? [1, 2, 3];
        if (!is_array($stages)) $stages = [1, 2, 3];
        $stages = array_map('intval', $stages);

        if (!$accountId) {
            return response()->json(['error' => 'account_id required'], 400);
        }

        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';

        // Kandidaten-Liste: gleiche Regel wie conv_list&status=nachfassen.
        $query = Conversation::forBroker($brokerId, $userType)
            ->whereIn('status', ['beantwortet', 'nachfassen_1', 'nachfassen_2'])
            ->where('followup_count', '<', 3)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('followup_count', 0)->where('last_outbound_at', '<=', now()->subHours(24));
                })->orWhere(function ($q2) {
                    $q2->where('followup_count', '>=', 1)->where('last_outbound_at', '<=', now()->subDays(3));
                });
            });

        $convs = $query->get();
        if ($convs->isEmpty()) {
            return response()->json(['success' => true, 'sent' => 0, 'total' => 0, 'message' => 'Keine faelligen Nachfass-Konversationen.']);
        }

        $emailService = app(\App\Services\EmailService::class);
        $sent = 0;
        $errors = [];

        foreach ($convs as $conv) {
            $fc = (int) ($conv->followup_count ?? 0);
            $stageOneBased = $fc + 1;  // NF1 = 1, NF2 = 2, NF3 = 3
            if (!in_array($stageOneBased, $stages, true)) continue;
            if (empty($conv->contact_email)) { $errors[] = "conv {$conv->id}: keine contact_email"; continue; }

            // Template (gleiche Logik wie in regenerateDraft)
            $lastOutbound = $conv->last_outbound_at ? strtotime($conv->last_outbound_at) : 0;
            $dateStr = $lastOutbound ? date('d.m.Y', $lastOutbound) : 'vor einigen Tagen';
            $sh = trim((string) ($conv->stakeholder ?: ''));
            if (preg_match('/^Herr\s+/i', $sh))      $anrede = 'Sehr geehrter ' . $sh;
            elseif (preg_match('/^Frau\s+/i', $sh))  $anrede = 'Sehr geehrte ' . $sh;
            else                                      $anrede = $sh !== '' ? "Guten Tag {$sh}" : 'Guten Tag';

            if ($fc === 0) {
                // NF1
                $body = $anrede . ",\n\n"
                    . "ich habe Ihnen am {$dateStr} Unterlagen zukommen lassen "
                    . "und wollte kurz nachfragen, ob die Immobilie grundsätzlich noch für Sie in Frage kommt.\n\n"
                    . "Über eine kurze Rückmeldung würde ich mich freuen.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Nachfrage';
            } elseif ($fc === 1) {
                // NF2
                $body = $anrede . ",\n\n"
                    . "ich habe Ihnen am {$dateStr} bereits eine Nachfrage geschickt "
                    . "und wollte mich heute noch einmal melden. Kommt die Immobilie grundsätzlich "
                    . "noch für Sie in Frage, oder haben Sie sich bereits anderweitig entschieden?\n\n"
                    . "Über eine kurze Rückmeldung würde ich mich freuen.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Erneute Nachfrage';
            } else {
                // NF3+: Abschluss mit Feedback-Bitte
                $body = $anrede . ",\n\n"
                    . "ich habe mich nun bereits mehrfach bei Ihnen gemeldet und möchte höflich "
                    . "ein letztes Mal nachfragen: Ist die Immobilie grundsätzlich noch für Sie von Interesse?\n\n"
                    . "Falls nicht, wäre ich Ihnen für eine kurze Rückmeldung sehr dankbar — "
                    . "gerne auch mit dem Grund (z.B. Preis, Lage, Ausstattung, bereits anderweitig entschieden). "
                    . "Das hilft mir, Sie in Zukunft mit passenderen Angeboten zu kontaktieren.\n\n"
                    . "Sollte ich nichts mehr von Ihnen hören, gehe ich davon aus, dass kein Interesse mehr "
                    . "besteht, und melde mich nicht weiter.\n\n"
                    . "Mit freundlichen Grüßen";
                $subject = 'Letzte Nachfrage';
            }

            try {
                $emailService->send(
                    $accountId,
                    $conv->contact_email,
                    $subject,
                    $body,
                    $conv->property_id,
                    $conv->stakeholder,
                    null, null, [],
                    null, null,
                    'nachfassen',
                    $stageOneBased
                );
                // Conversation-Status aktualisieren
                $conv->update([
                    'status' => 'nachfassen_' . $stageOneBased,
                    'last_outbound_at' => now(),
                    'outbound_count' => ($conv->outbound_count ?? 0) + 1,
                    'followup_count' => $fc + 1,
                    'draft_body' => null, 'draft_subject' => null, 'draft_to' => null,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                \Log::warning("conv_followup_all: conv {$conv->id} failed: " . $e->getMessage());
                $errors[] = "conv {$conv->id}: " . $e->getMessage();
            }
        }

        \Log::info("conv_followup_all: sent={$sent} errors=" . count($errors));
        return response()->json([
            'success' => true,
            'sent' => $sent,
            'total' => $convs->count(),
            'errors' => $errors,
        ]);
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

        // Mark selected matches (only if AI-matched, skip for manual offers)
        PropertyMatch::where('conversation_id', $convId)
            ->whereIn('property_id', $selectedIds)
            ->update(['status' => 'selected']);

        $isManualOffer = !PropertyMatch::where('conversation_id', $convId)->exists();

        // Load selected properties
        $properties = Property::whereIn('id', $selectedIds)->get();

        // Build thread context for draft generation
        // For proactive offers (no conv->property_id): load ALL recent emails from contact regardless of property
        // For original-property conversations: scope to that property
        $emailQuery = DB::table('portal_emails')
            ->where(function ($q) use ($conv) {
                $q->whereRaw("LOWER(from_email) = LOWER(?)", [$conv->contact_email])
                  ->orWhereRaw("LOWER(to_email) LIKE CONCAT('%', LOWER(?), '%')", [$conv->contact_email]);
            })
            ->orderByDesc("email_date")
            ->limit(8);

        if ($conv->property_id) {
            $emailQuery->where('property_id', $conv->property_id);
        }
        // No else: for proactive offers, we want the full conversation history with this contact

        $emails = $emailQuery->get();
        \Log::info('matchGenerateDraft: loading thread context', [
            'conv_id' => $conv->id,
            'contact_email' => $conv->contact_email,
            'property_id' => $conv->property_id,
            'email_count' => $emails->count(),
            'selected_property_count' => count($selectedIds),
        ]);

        $threadLines = [];
        foreach ($emails->reverse() as $e) {
            $dir = $e->direction === 'inbound' ? 'KUNDE' : 'MAKLER';
            $threadLines[] = "[{$dir}] {$e->subject}\n" . mb_substr($e->body_text ?? '', 0, 400);
        }

        // Load original property for context
        $originalProp = $conv->property_id ? Property::find($conv->property_id) : null;
        $originalDesc = '';
        if ($originalProp) {
            $oArea = $originalProp->living_area ?: $originalProp->total_area;
            $originalDesc = "{$originalProp->title} ({$originalProp->address}, {$originalProp->city})"
                . ($oArea ? ", {$oArea} m²" : '')
                . ($originalProp->purchase_price ? ", € " . number_format($originalProp->purchase_price, 0, ',', '.') : '');
        }

        // Build property descriptions for the AI
        $propDescriptions = [];
        foreach ($properties as $i => $p) {
            $area = $p->living_area ?: $p->total_area;
            $propDescriptions[] = ($i + 1) . ". {$p->title} ({$p->address}, {$p->city})"
                . ($area ? " — {$area} m²" : '')
                . ($p->rooms_amount ? ", {$p->rooms_amount} Zimmer" : '')
                . ($p->purchase_price ? ", € " . number_format($p->purchase_price, 0, ',', '.') : ', Preis auf Anfrage');
        }

        // Get broker ID for scoping
        $brokerId = $conv->property_id ? Property::where('id', $conv->property_id)->value('broker_id') : null;

        // Detect first-message intent (was the initial mail an inquiry, general contact, or different?)
        $firstInbound = $emails->reverse()->first(function ($e) { return $e->direction === 'inbound'; });
        $firstInboundText = $firstInbound ? mb_strtolower(mb_substr($firstInbound->body_text ?? '', 0, 300)) : '';
        $looksLikeInquiry = preg_match('/(anfrage|interesse|besichtig|expos|frag|info|kaufen|mieten|wohnung|haus|objekt)/u', $firstInboundText);

        // AI draft generation with full conversation context
        if ($originalProp) {
            $promptContext = "KONTEXT: Der Kunde hat ursprünglich eine Anfrage zum Objekt '{$originalProp->title}' gestellt. Du bietest ihm nun ZUSÄTZLICHE passende Immobilien an, weil das Original eventuell nicht genau passt oder du parallel weitere interessante Optionen hast.";
        } elseif ($looksLikeInquiry) {
            $promptContext = 'KONTEXT: Der Kunde hat sich mit einer Anfrage gemeldet (Interesse an Immobilien geäussert). Du bedankst dich für die Anfrage und bietest darüber hinaus konkrete Immobilien an, die zu seinem Suchprofil passen könnten.';
        } else {
            $promptContext = 'KONTEXT: Du kontaktierst den Kunden proaktiv mit passenden Immobilien-Vorschlägen aus deinem Portfolio. Es gibt eine bestehende Geschäftsbeziehung — knüpfe daran an.';
        }

        $systemPrompt = <<<PROMPT
Du bist ein erfahrener Immobilienmakler bei SR-Homes. Schreibe eine professionelle, persönliche Email an den Kunden.

{$promptContext}

AUFBAU DER EMAIL:
1. Formelle Anrede (Sehr geehrte/r Herr/Frau [Nachname] — Nachname aus Kundennamen ableiten)
2. Eröffnung passend zum KONTEXT oben:
   - Wenn Original-Objekt: kurz darauf Bezug nehmen, dann Brücke zu neuen Vorschlägen
   - Wenn Anfrage: "Vielen Dank für Ihre Anfrage. Ergänzend zu Ihrem Interesse möchte ich Ihnen weitere Objekte vorstellen, die gut passen könnten..."
   - Wenn proaktiv: "Im Zuge unseres laufenden Austauschs ist mir aufgefallen, dass folgende Immobilien aus unserem aktuellen Portfolio sehr gut zu Ihnen passen würden..."
3. Stelle jedes vorgeschlagene Objekt im Fliesstext kurz vor — Adresse, Eckdaten (Zimmer/Fläche), Preis. KEINE Aufzählung, sondern natürlich verbunden.
4. Wenn Exposés beigelegt sind: erwähne natürlich "die Exposés finden Sie im Anhang"
5. Biete ein unverbindliches Gespräch oder Besichtigung an
6. Schliesse mit "Mit freundlichen Grüssen" auf einer eigenen Zeile — KEINEN Namen dahinter (Signatur wird automatisch angehängt)

REGELN:
- KEIN generischer Werbetext — soll sich lesen als hätte der Makler persönlich nachgedacht
- Verwende "ich" nicht "wir" — persönlicher Ton
- 150–250 Wörter, kompakt aber konkret
- Keine Bullet-Points, keine Nummerierungen — fliessender Text
- Wenn du den Nachnamen nicht sicher kennst, verwende "Sehr geehrte Damen und Herren"
- WICHTIG: Antworte NUR mit gültigem JSON, kein Markdown, keine Code-Fences

Antwort-Format (genau dieses JSON-Schema):
{"email_subject": "Betreff hier", "email_body": "Mail-Text mit \n\n für Absätze"}
PROMPT;

        $userMessage = "KONVERSATIONSVERLAUF:\n" . ($threadLines ? implode("\n---\n", $threadLines) : '(Kein bisheriger Verlauf vorhanden)')
            . "\n\nKUNDE: {$conv->stakeholder}"
            . ($originalDesc ? "\nORIGINAL-OBJEKT: {$originalDesc}" : "\nORIGINAL-OBJEKT: (Keines — proaktives Angebot)")
            . "\n\nVORZUSCHLAGENDE OBJEKTE:\n" . implode("\n", $propDescriptions);

        \Log::info('matchGenerateDraft: calling AI', [
            'conv_id' => $conv->id,
            'has_original' => !empty($originalProp),
            'looks_like_inquiry' => (bool)$looksLikeInquiry,
            'prop_count' => count($propDescriptions),
            'thread_lines' => count($threadLines),
        ]);
        $ai = app(AnthropicService::class);
        $draft = $ai->chatJson($systemPrompt, $userMessage, 1500);

        if (!$draft || empty($draft['email_body'])) {
            \Log::warning('matchGenerateDraft: AI returned empty draft', [
                'conv_id' => $conv->id,
                'draft_keys' => $draft ? array_keys($draft) : null,
            ]);
            return response()->json([
                'error' => 'AI hat keinen Entwurf zurückgegeben. Bitte erneut versuchen.',
            ], 500);
        }

        // Collect expose files with property mapping
        $fileIds = [];
        $fileMap = [];
        foreach ($properties as $p) {
            // Search by path OR label -- many exposes have descriptive filenames
            // but are labeled "Exposé" / "Expose" in the database
            $exposeFiles = DB::table('property_files')
                ->where('property_id', $p->id)
                ->where(function ($q) {
                    $q->where('path', 'LIKE', '%expose%')
                      ->orWhere('label', 'LIKE', '%expos%');
                })
                ->orderByDesc('created_at')
                ->limit(1)
                ->get();

            if ($exposeFiles->isEmpty() && $p->expose_path) {
                $exposeFiles = DB::table('property_files')
                    ->where('property_id', $p->id)
                    ->where('path', $p->expose_path)
                    ->limit(1)
                    ->get();
            }

            foreach ($exposeFiles as $file) {
                $fileIds[] = $file->id;
                $propTitle = $p->title ?: ($p->address . ', ' . $p->city);
                $displayName = ($file->label ?: 'Exposé') . ' — ' . $propTitle . '.pdf';
                $fileMap[] = [
                    'file_id' => $file->id,
                    'property_id' => $p->id,
                    'property_title' => $propTitle,
                    'filename' => $displayName,
                ];
            }
        }

        // Save draft to conversation
        $convService = app(ConversationService::class);
        $convService->saveDraft(
            $conv,
            $draft['email_body'] ?? '',
            $draft['email_subject'] ?? 'Objektvorschläge',
            $conv->contact_email
        );

        return response()->json([
            'draft_body' => $draft['email_body'] ?? '',
            'draft_subject' => $draft['email_subject'] ?? 'Objektvorschläge',
            'draft_to' => $conv->contact_email,
            'file_ids' => $fileIds,
            'file_map' => $fileMap,
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
