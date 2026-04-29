<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PropertyActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\AiChatController;

/**
 * Legacy admin API router.
 *
 * Maps ?action=xxx query parameters to the appropriate sub-controller,
 * preserving 100 % backward-compatible JSON responses.
 */
class AdminApiController extends Controller
{
    /**
     * Route the incoming request to the correct sub-controller.
     */
    public function handle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $action = $request->query('action', '');

        // TTS returns audio binary, not JSON - handle before match
        if ($action === 'ai_tts') {
            return (new AiChatController())->tts($request);
        }

        // Abgelaufene Session: die api.key-Middleware laesst Requests durch
        // sobald der statische Key stimmt, aber \Auth::id() ist null. Ohne
        // diesen Check sah ein User mit abgelaufener Session Daten
        // ANDERER Makler (Conversation-Scope gab ungescoped alles zurueck
        // wenn brokerId null war). Jetzt sauberes 401, der Client kann
        // den User zum Login umleiten.
        if (!\Auth::check()) {
            return response()->json([
                'error' => 'Nicht angemeldet',
                'auth_required' => true,
            ], 401);
        }

        // Role-based action guard
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $adminOnlyActions = [
            'website_content_list', 'website_content_save', 'website_content_delete',
            'website_content_upload', 'website_toggle_property', 'website_set_main_image', 'website_clear_cache',
            'save_email_account', 'delete_email_account', 'test_email_account',
            'create_broker', 'update_broker',
            'toggle_auto_reply',
            'create_portal_access', 'check_portal_access',
            'create_customer', 'update_customer', 'delete_customer',
            'update_portal_user', 'create_portal_user', 'delete_portal_user',
        ];
        if (in_array($action, $adminOnlyActions) && $userType !== 'admin') {
            return response()->json(['error' => 'Keine Berechtigung für diese Aktion'], 403);
        }

        // Makler: block write actions on properties they don't own
        $propertyWriteActions = [
            'update_property', 'delete_property', 'set_on_hold', 'set_inactive', 'reactivate_property',
            'upload_property_file', 'delete_property_file',
            'upload_property_image', 'update_property_image', 'delete_property_image',
            'upload_property_kaufanbot', 'update_property_kaufanbot', 'delete_property_kaufanbot', 'update_property_kaufanbot_status', 'update_kaufanbot_activity_status',
        ];
        if ($userType === 'makler' && in_array($action, $propertyWriteActions)) {
            $propId = intval($request->input('property_id', $request->query('property_id', 0)));
            if ($propId) {
                $propBroker = \DB::table('properties')->where('id', $propId)->value('broker_id');
                if ($propBroker && $propBroker != $brokerId) {
                    return response()->json(['error' => 'Keine Berechtigung: Objekt gehört einem anderen Makler'], 403);
                }
            }
        }

        try {
        return match ($action) {
            // Briefing & Follow-ups
            'briefing'                  => app(BriefingController::class)->index($request),
            // DEPRECATED: old followup actions replaced by conv_* actions (Task 10 cleanup)
            // 'followups'                 => app(FollowupController::class)->index($request),
            // 'followup_recommendation'   => app(FollowupController::class)->recommendation($request),
            // 'followup_draft'            => app(FollowupController::class)->draft($request),
            // 'followups_stage1'          => app(FollowupController::class)->index($request->merge(['mode' => 'stage1'])),
            // 'followup_draft_staged'     => app(FollowupController::class)->draft($request),
            // 'save_ai_feedback'          => app(FollowupController::class)->saveAiFeedback($request),
            // 'mark_called'               => app(FollowupController::class)->markCalled($request),

            // Auto-Nachfassen Settings
            'get_auto_followup_settings'  => $this->getAutoFollowupSettings(),
            'save_auto_followup_settings' => $this->saveAutoFollowupSettings(),

            // Settings
            'get_settings'              => app(SettingsController::class)->get($request),
            'auto_reply_recent'         => response()->json([
                'logs' => DB::table('auto_reply_log')
                    ->where('created_at', '>=', now()->subHours(24))
                    ->when(\Auth::id(), fn($q) => $q->whereIn('property_id', DB::table('properties')->where('broker_id', \Auth::id())->pluck('id')))
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get()
            ]),
            'save_settings'             => app(SettingsController::class)->save($request),
            'change_password'           => app(SettingsController::class)->changePassword($request),
            'upload_signature_image'    => app(SettingsController::class)->uploadSignatureImage($request),
            'delete_signature_image'    => app(SettingsController::class)->deleteSignatureImage($request),
            'list_inbox_rules'          => app(SettingsController::class)->listInboxRules($request),
            'save_inbox_rule'           => app(SettingsController::class)->saveInboxRule($request),
            'delete_inbox_rule'         => app(SettingsController::class)->deleteInboxRule($request),
            'signature_for_account'     => app(SettingsController::class)->signatureForAccount($request),
            'toggle_auto_reply'         => app(SettingsController::class)->toggleAutoReply($request),
            'reassign_email'            => $this->reassignEmail($request),
            'change_email_category'     => $this->changeEmailCategory($request),
            'list_global_files'         => response()->json(['files' => DB::table('global_files')->orderByDesc('created_at')->get()]),
            'upload_global_file'        => $this->uploadGlobalFile($request),
            'delete_global_file'        => $this->deleteGlobalFile($request),
            'list_customers'            => response()->json(['customers' => DB::table('customers')->orderBy('name')->get(['id','name','email','phone','address','city','zip','notes'])]),
            'create_customer'           => $this->createCustomer($request),
            'update_customer'           => $this->updateCustomer($request),
            'delete_customer'           => $this->deleteCustomer($request),
            'update_portal_user'        => $this->updatePortalUser($request),
            'create_portal_user'        => $this->createPortalUserForCustomer($request),
            'delete_portal_user'        => $this->deletePortalUser($request),
            'list_admin_users'          => response()->json(['users' => DB::table('users')->whereIn('user_type', ['admin','makler'])->orderBy('name')->get(['id','name','email'])]),
            'update_broker'             => (function() use ($request) {
                $d = $request->json()->all();
                DB::table('properties')->where('id', intval($d['property_id'] ?? 0))->update(['broker_id' => intval($d['broker_id'] ?? 1)]);
                return response()->json(['success' => true]);
            })(),
            'list_properties'           => (function() use ($brokerId, $userType) {
                $query = DB::table('properties')
                    ->select('id', 'ref_id', 'project_name', 'title', 'address', 'city', 'realty_status', 'property_category', 'customer_id', 'owner_name', 'owner_email', 'owner_phone', 'purchase_price', 'total_area', 'rooms_amount', 'object_type', 'broker_id', 'show_on_website', 'parent_id', 'property_history', 'created_at');

                // Makler sees only own properties, admin/backoffice/assistenz can see all.
                if ($userType === 'makler' && $brokerId) {
                    $query->where('broker_id', $brokerId);
                }

                return response()->json(['properties' => $query
                ->orderBy('id')
                ->get()
                ->map(function($p) {
                    $p->expose_count = DB::table('property_files')
                        ->where('property_id', $p->id)
                        ->where(function($q) {
                            $q->where('label', 'LIKE', '%xpos%')
                              ->orWhere('filename', 'LIKE', '%expose%')
                              ->orWhere('filename', 'LIKE', '%bab%');
                        })
                        ->count();
                    // Add unit_count for all properties
                    $p->unit_count = DB::table('property_units')
                        ->where('property_id', $p->id)
                        ->where('is_parking', 0)
                        ->count();
                    // Add total_volume for newbuild properties
                    if ($p->property_category === 'newbuild') {
                        $p->total_volume = DB::table('property_units')
                            ->where('property_id', $p->id)
                            ->where('is_parking', 0)
                            ->sum('price');
                    }
                    // Thumbnail: 1) property_images (title image priority), 2) fallback to property_files images
                    $img = DB::table('property_images')
                        ->where('property_id', $p->id)
                        ->where('is_public', 1)
                        ->orderByDesc('is_title_image')
                        ->orderBy('sort_order')
                        ->first();
                    if ($img) {
                        $p->thumbnail_url = url('/storage/' . $img->path);
                    } else { $p->thumbnail_url = null; }








                    return $p;
                })
            ], 200, [], JSON_UNESCAPED_UNICODE);
            })(),

            // Performance
            'performance'               => app(PerformanceController::class)->index($request),
            'market_intelligence'       => app(MarketIntelligenceController::class)->index($request),
            'refresh_market'            => app(MarketIntelligenceController::class)->refresh($request),

            // Conversations
            'conversations'             => app(ConversationController::class)->legacyIndex($request),

            // Conversation (new status-based API)
            'conv_list'                 => app(ConversationController::class)->list($request),
            'conv_detail'               => app(ConversationController::class)->detail($request),
            'conv_reply'                => app(ConversationController::class)->reply($request),
            'conv_followup'             => app(ConversationController::class)->followup($request),
            'conv_reply_all'              => app(ConversationController::class)->replyAll($request),
            'conv_done_batch'            => app(ConversationController::class)->doneBatch($request),
            'conv_done'                 => app(ConversationController::class)->done($request),
            'conv_set_property'         => app(ConversationController::class)->setProperty($request),
            'conv_read'                 => app(ConversationController::class)->read($request),
            'conv_draft'                => app(ConversationController::class)->updateDraft($request),
            'conv_regenerate_draft'     => app(ConversationController::class)->regenerateDraft($request),
            'conv_improve_draft'        => app(ConversationController::class)->improveDraft($request),
            'conv_followup_all'         => app(ConversationController::class)->followupAll($request),

            // AI Cross-Match
            'match_list'           => app(ConversationController::class)->matchList($request),
            'match_dismiss'        => app(ConversationController::class)->matchDismiss($request),
            'match_generate_draft' => app(ConversationController::class)->matchGenerateDraft($request),

            // Blog management
            'blog_list'             => app(BlogController::class)->list($request),
            'blog_get'              => app(BlogController::class)->get($request),
            'blog_save'             => app(BlogController::class)->save($request),
            'blog_delete'           => app(BlogController::class)->delete($request),
            'blog_publish'          => app(BlogController::class)->publish($request),
            'blog_unpublish'        => app(BlogController::class)->unpublish($request),
            'blog_generate_article' => app(BlogController::class)->generateArticle($request),
            'blog_generate_image'   => app(BlogController::class)->generateImage($request),
            'blog_upload_image'     => app(BlogController::class)->uploadImage($request),

            // Property
            'property_health'           => app(PropertyController::class)->health($request),
            'property_feedback'         => app(PropertyController::class)->feedback($request),
            // Property Settings (extended)
            'get_property_settings'     => app(PropertySettingsController::class)->getSettings($request),
            'save_property_settings'    => app(PropertySettingsController::class)->saveSettings($request),
            'save_full_property'        => app(PropertySettingsController::class)->saveFullProperty($request),

            // Property Images




            // Property Portals
            'list_property_portals' => (function() use ($request) {
                $propId = intval($request->query('property_id', 0));
                if (!$propId) return response()->json(['error' => 'property_id required'], 400);
                $portals = \DB::table('property_portals')->where('property_id', $propId)->get();
                return response()->json(['portals' => $portals]);
            })(),
            'save_property_portal' => (function() use ($request) {
                $data = $request->json()->all();
                $propId = intval($data['property_id'] ?? 0);
                $portalName = $data['portal_name'] ?? '';
                if (!$propId || !$portalName) return response()->json(['error' => 'property_id and portal_name required'], 400);
                $existing = \DB::table('property_portals')->where('property_id', $propId)->where('portal_name', $portalName)->first();
                $wasEnabled = $existing ? (bool) $existing->sync_enabled : false;
                $nowEnabled = (bool) ($data['sync_enabled'] ?? 0);
                $pd = ['property_id' => $propId, 'portal_name' => $portalName, 'sync_enabled' => $data['sync_enabled'] ?? 0, 'external_id' => $data['external_id'] ?? null, 'external_url' => $data['external_url'] ?? null, 'status' => $data['status'] ?? 'draft', 'updated_at' => now()];
                if ($existing) { \DB::table('property_portals')->where('id', $existing->id)->update($pd); } else { $pd['created_at'] = now(); \DB::table('property_portals')->insert($pd); }
                // If sr-homes portal, also update show_on_website
                if ($portalName === 'sr-homes') {
                    \DB::table('properties')->where('id', $propId)->update(['show_on_website' => ($data['sync_enabled'] ?? 0) ? 1 : 0]);
                    \Illuminate\Support\Facades\Cache::forget('website_properties');
                }

                // Kundensichtbare Aktivitaet bei Toggle-Change
                if ($wasEnabled !== $nowEnabled) {
                    $portalLbl = match ($portalName) {
                        'sr-homes'  => 'SR-Homes Website',
                        'immoji'    => 'Immoji',
                        'willhaben' => 'Willhaben',
                        'immowelt'  => 'Immowelt',
                        'immoscout' => 'ImmoScout',
                        'immo-sn'   => 'immo.sn.at',
                        'kurier'    => 'Kurier',
                        'dibeo'     => 'DIBEO',
                        'alleskralle' => 'Alleskralle',
                        default     => ucfirst($portalName),
                    };
                    $text = $nowEnabled
                        ? "Portal aktiviert: {$portalLbl}"
                        : "Portal deaktiviert: {$portalLbl}";
                    app(\App\Services\PropertyActivityLogger::class)->logEvent($propId, $text);
                }

                return response()->json(['success' => true]);
            })(),

            'immoji_connect' => (function() use ($request) {
                $email = $request->input('email');
                $password = $request->input('password');
                if (!$email || !$password) {
                    return response()->json(['success' => false, 'message' => 'Email und Passwort erforderlich'], 422);
                }

                try {
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $totalCount = $service->testConnection();
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()], 422);
                }

                $userId = \Auth::id();
                \DB::table('admin_settings')->updateOrInsert(
                    ['user_id' => $userId],
                    ['immoji_email' => \Illuminate\Support\Facades\Crypt::encryptString($email), 'immoji_password' => \Illuminate\Support\Facades\Crypt::encryptString($password), 'updated_at' => now()]
                );

                return response()->json(['success' => true, 'message' => "Verbindung erfolgreich. {$totalCount} Objekte in Immoji.", 'total_count' => $totalCount]);
            })(),

            'immoji_disconnect' => (function() use ($request) {
                $userId = \Auth::id();
                \DB::table('admin_settings')->where('user_id', $userId)->update([
                    'immoji_email' => null, 'immoji_password' => null, 'immoji_token' => null, 'updated_at' => now()
                ]);
                return response()->json(['success' => true]);
            })(),

            'immoji_status' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $hasCredentials = !empty($settings->immoji_email ?? null) && !empty($settings->immoji_password ?? null);
                return response()->json(['connected' => $hasCredentials]);
            })(),

            'immoji_push' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $propertyId = intval($request->input('property_id', 0));
                if (!$propertyId) return response()->json(['success' => false, 'message' => 'property_id fehlt'], 400);

                $property = \DB::table('properties')->where('id', $propertyId)->first();
                if (!$property) return response()->json(['success' => false, 'message' => 'Objekt nicht gefunden'], 404);

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $isNewbuild = $property->property_category === 'newbuild';

                    if ($isNewbuild) {
                        // Neubauprojekte: push only units, no master on immoji
                        $unitResults = $service->pushPropertyUnits((array) $property);
                        return response()->json([
                            'success' => true,
                            'action' => 'units_synced',
                            'message' => count($unitResults) . ' Einheit(en) synchronisiert',
                            'units' => $unitResults,
                        ]);
                    }

                    // Normal properties: push the property itself (diff-sync by default)
                    $forceFullSync = (bool) $request->input('force_full_sync', false);
                    $dryRun = (bool) $request->input('dry_run', false);
                    $result = $service->pushProperty((array) $property, $forceFullSync, $dryRun);

                    // Save the immoji_id back to the property if newly created
                    // (skipped for dry-run — no actual create happened)
                    if ($result['action'] === 'created' && !empty($result['immoji_id'])) {
                        \DB::table('properties')->where('id', $propertyId)->update([
                            'openimmo_id' => $result['immoji_id'],
                            'updated_at' => now()
                        ]);
                    }

                    // Update portal entry only on a real, non-skipped sync
                    if (!$dryRun && !in_array($result['action'], ['skipped', 'would_create', 'would_update'], true)) {
                        \DB::table('property_portals')->updateOrInsert(
                            ['property_id' => $propertyId, 'portal_name' => 'immoji'],
                            ['sync_enabled' => 1, 'status' => 'active', 'external_id' => $result['immoji_id'], 'last_synced_at' => now(), 'updated_at' => now()]
                        );
                    }

                    $sectionsSynced = $result['sections_synced'] ?? [];
                    $isFull = count($sectionsSynced) === count(\App\Services\ImmojiSyncStateService::SECTIONS);
                    $message = match ($result['action']) {
                        'created' => 'Objekt in Immoji erstellt',
                        'skipped' => 'Keine Änderungen — nichts zu syncen',
                        'would_create' => 'Objekt wird in Immoji neu angelegt',
                        'would_update' => $isFull
                            ? 'Folgende Bereiche werden aktualisiert: alle'
                            : 'Folgende Bereiche werden aktualisiert: ' . implode(', ', $sectionsSynced),
                        default => $isFull
                            ? 'Objekt in Immoji aktualisiert (alle Bereiche)'
                            : 'Objekt in Immoji aktualisiert (' . implode(', ', $sectionsSynced) . ')',
                    };

                    // Kundensichtbare Aktivitaet: Immoji-Sync als "Datenpflege"
                    // eintragen (nicht fuer dry_run / skipped). Debouncing im
                    // Logger verhindert, dass 3 schnelle Re-Syncs 3 Eintraege
                    // produzieren — wird gemergt.
                    if (!$dryRun && !in_array($result['action'], ['skipped', 'would_create', 'would_update'], true)) {
                        $text = $result['action'] === 'created'
                            ? 'Objekt auf Immoji-Portalen veröffentlicht'
                            : ($isFull
                                ? 'Inserat auf Immoji-Portalen aktualisiert'
                                : 'Inserat-Bereiche auf Immoji aktualisiert: ' . implode(', ', array_map(fn($s) => self::sectionLabel($s), $sectionsSynced)));
                        app(\App\Services\PropertyActivityLogger::class)->logEvent($propertyId, $text);
                    }

                    return response()->json([
                        'success' => true,
                        'action' => $result['action'],
                        'immoji_id' => $result['immoji_id'],
                        'sections_synced' => $sectionsSynced,
                        'force_full_sync' => $forceFullSync,
                        'dry_run' => $dryRun,
                        'message' => $message,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Immoji push failed', ['error' => $e->getMessage(), 'property_id' => $propertyId]);

                    // Update portal entry with error
                    \DB::table('property_portals')->updateOrInsert(
                        ['property_id' => $propertyId, 'portal_name' => 'immoji'],
                        ['last_sync_error' => $e->getMessage(), 'updated_at' => now()]
                    );

                    return response()->json(['success' => false, 'message' => 'Upload fehlgeschlagen: ' . $e->getMessage()], 500);
                }
            })(),

            'immoji_push_units' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $propertyId = intval($request->input('property_id', 0));
                if (!$propertyId) return response()->json(['success' => false, 'message' => 'property_id fehlt'], 400);

                $property = \DB::table('properties')->where('id', $propertyId)->first();
                if (!$property) return response()->json(['success' => false, 'message' => 'Objekt nicht gefunden'], 404);

                // Ensure master property has openimmo_id first
                $propArr = (array) $property;
                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);

                    // No master push — only units get synced to immoji
                    // Master data (description, address, images) is merged into each unit by pushUnit
                    $unitResults = $service->pushPropertyUnits($propArr);
                    return response()->json(['success' => true, 'units' => $unitResults]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Immoji push units failed', ['error' => $e->getMessage(), 'property_id' => $propertyId]);
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_push_single_unit' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $propertyId = intval($request->input('property_id', 0));
                $unitId = intval($request->input('unit_id', 0));
                if (!$propertyId || !$unitId) return response()->json(['success' => false, 'message' => 'property_id und unit_id erforderlich'], 400);

                $property = \DB::table('properties')->where('id', $propertyId)->first();
                $unit = \DB::table('property_units')->where('id', $unitId)->first();
                if (!$property || !$unit) return response()->json(['success' => false, 'message' => 'Nicht gefunden'], 404);

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);

                    $propArr = (array) $property;

                    // Push the single unit (pushUnit handles create vs update based on unit.immoji_id)
                    $unitArr = (array) $unit;
                    $result = $service->pushUnit($propArr, $unitArr);

                    // Save immoji_id back (for both created and updated)
                    if (!empty($result['immoji_id'])) {
                        \DB::table('property_units')->where('id', $unitId)->update(['immoji_id' => $result['immoji_id'], 'last_synced_at' => now()]);
                    }

                    // Set portal flags — handle double-encoded JSON
                    $exportsRaw = $unit->portal_exports ?? '{}';
                    $exports = json_decode($exportsRaw, true);
                    if (is_string($exports)) $exports = json_decode($exports, true); // double-encoded
                    if (!empty($result['immoji_id']) && is_array($exports)) {
                        $portalMap = \App\Services\ImmojiUploadService::portalFieldMap();
                        $portalFlags = [];
                        foreach ($exports as $key => $enabled) {
                            if ($key === 'immoji') continue;
                            if (isset($portalMap[$key])) {
                                $portalFlags[$portalMap[$key]] = (bool) $enabled;
                            }
                        }
                        if (!empty($portalFlags)) {
                            try { $service->setPortalExports($result['immoji_id'], $portalFlags); } catch (\Exception $e) { /* ignore */ }
                        }
                    }

                    return response()->json(['success' => true, 'action' => $result['action'] ?? 'synced', 'immoji_id' => $result['immoji_id'] ?? null]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Immoji push single unit failed', ['error' => $e->getMessage()]);
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_set_unit_portals' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $immojiId = $request->input('immoji_id');
                if (!$immojiId) return response()->json(['success' => false, 'message' => 'immoji_id fehlt'], 400);

                $portalFlags = $request->input('portals', []);
                if (empty($portalFlags)) return response()->json(['success' => false, 'message' => 'Keine Portal-Daten'], 400);

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $service->setPortalExports($immojiId, $portalFlags);
                    return response()->json(['success' => true]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_portal_status' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $propertyId = intval($request->input('property_id', 0));
                if (!$propertyId) return response()->json(['success' => false, 'message' => 'property_id fehlt'], 400);

                $property = \DB::table('properties')->where('id', $propertyId)->first();
                if (!$property || empty($property->openimmo_id)) {
                    return response()->json(['success' => true, 'portals' => null, 'message' => 'Objekt nicht in Immoji']);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $portalData = $service->getPortalExportStatus($property->openimmo_id);
                    return response()->json(['success' => true, 'portals' => $portalData]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_set_portals' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $propertyId = intval($request->input('property_id', 0));
                if (!$propertyId) return response()->json(['success' => false, 'message' => 'property_id fehlt'], 400);

                $property = \DB::table('properties')->where('id', $propertyId)->first();
                if (!$property || empty($property->openimmo_id)) {
                    return response()->json(['success' => false, 'message' => 'Objekt nicht in Immoji. Bitte zuerst hochladen.'], 422);
                }

                $portalFlags = $request->input('portals', []);
                \Log::info('immoji_set_portals: raw input', ['portals' => $portalFlags, 'all' => $request->all(), 'openimmo_id' => $property->openimmo_id]);
                if (empty($portalFlags)) {
                    return response()->json(['success' => false, 'message' => 'Keine Portal-Daten'], 400);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $service->setPortalExports($property->openimmo_id, $portalFlags);
                    return response()->json(['success' => true, 'message' => 'Portal-Export aktualisiert']);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_capacity' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    $capacity = $service->getPortalCapacity();
                    return response()->json(['success' => true, 'capacity' => $capacity]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            // Manuelle Verknuepfung mit einem bestehenden Immoji-Listing.
            // Use case: User hat ein Objekt direkt im Immoji-Webportal angelegt
            // (oder unser Push ist beim Empfangen der Response in einen Timeout
            // gerannt, sodass die ID nicht persistiert wurde) — wir nehmen die
            // Immoji-URL/-UUID entgegen und speichern sie als openimmo_id.
            // Validiert wird die ID indem wir sie vor dem Save via getPortalExportStatus
            // anfragen — gibt Immoji 'not found' zurueck, brechen wir ab.
            'immoji_link_existing' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }
                $propertyId = (int) $request->input('property_id', 0);
                $raw = trim((string) $request->input('immoji_id_or_url', ''));
                if (!$propertyId || $raw === '') {
                    return response()->json(['success' => false, 'message' => 'property_id und immoji_id_or_url erforderlich'], 400);
                }
                // UUID rauslesen — entweder direkt eingegeben oder aus URL
                // (z.B. https://app.immoji.org/realty/<uuid>/...).
                if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $raw, $match)) {
                    $immojiId = strtolower($match[0]);
                } else {
                    return response()->json(['success' => false, 'message' => 'Konnte keine Immoji-ID erkennen. Bitte komplette Listing-URL oder die UUID eingeben.'], 422);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);
                    // Dry-Validation: Portal-Status fuer diese ID abfragen — wenn die
                    // Realty nicht existiert oder unserem Account nicht gehoert,
                    // wirft Immoji eine Exception.
                    $portalData = $service->getPortalExportStatus($immojiId);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Immoji konnte das Listing nicht finden oder dein Account hat keinen Zugriff: ' . $e->getMessage(),
                    ], 422);
                }

                // OK, ID validiert — speichern.
                \DB::table('properties')->where('id', $propertyId)->update([
                    'openimmo_id' => $immojiId,
                    'updated_at' => now(),
                ]);
                \DB::table('property_portals')->updateOrInsert(
                    ['property_id' => $propertyId, 'portal_name' => 'immoji'],
                    [
                        'sync_enabled' => 1,
                        'status' => 'active',
                        'external_id' => $immojiId,
                        'last_synced_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Mit Immoji-Listing verknüpft',
                    'immoji_id' => $immojiId,
                    'portals' => $portalData,
                ]);
            })(),

            'bulk_sync_immoji' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);

                    $properties = \DB::table('properties')->get();
                    $results = [];

                    foreach ($properties as $prop) {
                        try {
                            $propArray = (array) $prop;
                            $result = $service->pushProperty($propArray);

                            // Save openimmo_id back if newly created
                            if ($result['action'] === 'created' && !empty($result['immoji_id'])) {
                                \DB::table('properties')->where('id', $prop->id)->update(['openimmo_id' => $result['immoji_id']]);
                            }

                            $results[] = [
                                'id' => $prop->id,
                                'ref_id' => $prop->ref_id,
                                'status' => 'ok',
                                'action' => $result['action'],
                                'immoji_id' => $result['immoji_id'],
                            ];
                        } catch (\Exception $e) {
                            $results[] = [
                                'id' => $prop->id,
                                'ref_id' => $prop->ref_id,
                                'status' => 'error',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }

                    $ok = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
                    $fail = count(array_filter($results, fn($r) => $r['status'] === 'error'));

                    return response()->json([
                        'success' => true,
                        'message' => "$ok Objekte synchronisiert, $fail Fehler",
                        'results' => $results,
                    ]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),

            'immoji_bulk_portal_status' => (function() use ($request) {
                $userId = \Auth::id();
                $settings = \DB::table('admin_settings')->where('user_id', $userId)->first();
                $encEmail = $settings->immoji_email ?? null;
                $encPassword = $settings->immoji_password ?? null;
                if (!$encEmail || !$encPassword) {
                    return response()->json(['success' => false, 'message' => 'Nicht mit Immoji verbunden'], 422);
                }

                $properties = \DB::table('properties')
                    ->whereNotNull('openimmo_id')
                    ->select('id', 'openimmo_id')
                    ->get();

                if ($properties->isEmpty()) {
                    return response()->json(['success' => true, 'portal_status' => []]);
                }

                try {
                    $email = \Illuminate\Support\Facades\Crypt::decryptString($encEmail);
                    $password = \Illuminate\Support\Facades\Crypt::decryptString($encPassword);
                    $token = \App\Services\ImmojiUploadService::signIn($email, $password);
                    $service = new \App\Services\ImmojiUploadService($token);

                    $result = [];
                    foreach ($properties as $prop) {
                        try {
                            $status = $service->getPortalExportStatus($prop->openimmo_id);
                            $result[$prop->id] = $status;
                        } catch (\Exception $e) {
                            $result[$prop->id] = ['error' => $e->getMessage()];
                        }
                    }

                    return response()->json(['success' => true, 'portal_status' => $result]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
                }
            })(),



            'save_property_unit'        => app(PropertySettingsController::class)->saveUnit($request),
            'delete_property_unit'      => app(PropertySettingsController::class)->deleteUnit($request),
            'bulk_import_units'         => app(PropertySettingsController::class)->bulkImportUnits($request),
            'parse_expose'              => app(PropertySettingsController::class)->parseExpose($request),
            'parse_property_fields'     => $this->handleParsePropertyFields($request),
            'parse_units'               => $this->handleParseUnits($request),
            'generate_property_description' => $this->handleGeneratePropertyDescription($request),
            'polish_property_description' => $this->handlePolishPropertyDescription($request),
            'link_offer_to_unit'        => app(PropertySettingsController::class)->linkOfferToUnit($request),
            'bulk_create_parking'       => app(PropertySettingsController::class)->bulkCreateParking($request),
            'upload_kaufanbot_pdf'      => app(PropertySettingsController::class)->uploadKaufanbotPdf($request),
            'remove_kaufanbot_pdf'      => app(PropertySettingsController::class)->removeKaufanbotPdf($request),
            'get_sales_volume'          => app(PropertySettingsController::class)->getSalesVolume($request),
            'get_commission_summary'    => app(PropertySettingsController::class)->getCommissionSummary($request),
            'get_kaufanbot_pdfs'       => app(PropertySettingsController::class)->getKaufanbotPdfs($request),
            'create_portal_access'     => app(PropertySettingsController::class)->createPortalAccess($request),
            'check_portal_access'      => app(PropertySettingsController::class)->checkPortalAccess($request),
            'generate_analysis'         => app(PropertyController::class)->generateAnalysis($request),
            'generate_vermarktungsbericht' => app(PropertyController::class)->generateVermarktungsbericht($request),
            'get_vermarktungsbericht'     => app(PropertyController::class)->getVermarktungsbericht($request),
            'export_vermarktungsbericht_pdf' => app(PropertyController::class)->exportVermarktungsberichtPdf($request),
            'set_on_hold'               => app(PropertyController::class)->setOnHold($request),
            'fix_activity'              => app(PropertyController::class)->fixActivity($request),
            'fix_expose_categories'     => app(PropertyController::class)->fixExposeCategories($request),
            'create_property'           => app(PropertyController::class)->create($request),
            'delete_property'           => app(PropertyController::class)->delete($request),
            'set_inactive'              => app(PropertyController::class)->setInactive($request),
            'reactivate_property'       => app(PropertyController::class)->reactivate($request),
            'analyze_file'              => $this->analyzeFile($request),

            // Emails
            'email_context'             => app(EmailController::class)->context($request),
            'ai_reply'                  => app(EmailController::class)->aiReply($request),
            'improve_text'              => app(EmailController::class)->improveText($request),
            'mark_handled'              => app(EmailController::class)->markHandled($request),
            'send_email'                => app(EmailController::class)->send($request),
            'email_history'             => app(EmailController::class)->history($request),
            'mark_read'                 => app(EmailController::class)->markRead($request),
            'trash_emails'              => app(EmailController::class)->trash($request),
            'restore_emails'            => app(EmailController::class)->restore($request),
            'download_attachment'       => app(EmailController::class)->downloadAttachment($request),
            'save_attachment_to_property' => app(EmailController::class)->saveAttachmentToProperty($request),
            'unmatched_emails'          => app(EmailController::class)->unmatched($request),
            'property_contacts'         => $this->propertyContacts($request),
            'assign_email'              => app(EmailController::class)->assign($request),
            'save_draft'                => app(EmailController::class)->saveDraft($request),
            'list_drafts'               => app(EmailController::class)->listDrafts($request),
            'delete_draft'              => app(EmailController::class)->deleteDraft($request),

            // Contacts
            'contacts'                  => app(ContactController::class)->index($request),
            'contact_search'            => $this->contactSearch($request),
            'contact_timeline'          => app(ContactController::class)->timeline($request),
            'list_owners'               => app(ContactController::class)->listOwners($request),
            'contact_create'            => app(ContactController::class)->create($request),
            'contact_update'            => app(ContactController::class)->update($request),
            'update_recipient_email'    => app(ContactController::class)->updateRecipientEmail($request),
            'contact_delete'            => app(ContactController::class)->delete($request),
            'contact_add_alias'         => app(ContactController::class)->addAlias($request),
            'get_lead_data'             => app(ContactController::class)->getLeadData($request),
            'update_lead_data'          => app(ContactController::class)->updateLeadData($request),
            'create_contact'            => $this->createContact($request),

            // Followup snooze
            'snooze_followup'           => $this->snoozeFollowup($request),

            // Tagesbriefing (KI-Tageszusammenfassung fürs Dashboard)
            'briefing_get'              => $this->briefingGet($request),
            'briefing_regenerate'       => $this->briefingRegenerate($request),

            // Hausverwaltung (Phase 1 — Core CRUD + Assignment)
            'list_property_managers'    => $this->listPropertyManagers($request),
            'create_property_manager'   => $this->createPropertyManager($request),
            'update_property_manager'   => $this->updatePropertyManager($request),
            'delete_property_manager'   => $this->deletePropertyManager($request),
            'assign_property_manager'   => $this->assignPropertyManager($request),
            'quick_create_and_assign_property_manager' => $this->quickCreateAndAssignPropertyManager($request),
            'upload_ava'                => $this->uploadAva($request),
            'mark_file_as_ava'          => $this->markFileAsAva($request),
            'rename_property_file'      => $this->renamePropertyFile($request),

            // Hausverwaltung (Phase 2 — Contact Flows)
            'contact_property_manager'  => $this->contactPropertyManager($request),
            'send_to_manager'           => $this->sendToManager($request),

            // Eigentuemer-Kontakt (Property-Detail Uebersicht → Schnell-Templates)
            'send_to_owner'             => $this->sendToOwner($request),

            // Kaeufer-Kontakt (Property-Detail Kaufangebote-Tab → einzeln + bulk)
            'send_to_buyer'             => $this->sendToBuyer($request),

            // Geocoding (OpenStreetMap Nominatim)
            'geocode_address'           => $this->geocodeAddress($request),
            'geocode_autocomplete'      => $this->geocodeAutocomplete($request),

            // Aufnahmeprotokoll
            'intake_protocol_draft_save'   => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftSave($request),
            'intake_protocol_draft_load'   => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftLoad($request),
            'intake_protocol_draft_list'   => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftList($request),
            'intake_protocol_draft_delete' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->draftDelete($request),
            'intake_protocol_submit' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->submit($request),
            'intake_protocol_preview_mail' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->previewMail($request),
            'intake_protocol_get_pdf' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->getPdf($request),
            'intake_protocol_resend_email' => app(\App\Http\Controllers\Admin\IntakeProtocolController::class)->resendEmail($request),

            // Tasks
            'getTasks'                  => app(TaskController::class)->index($request),
            'addTask'                   => app(TaskController::class)->store($request),
            'doneTask'                  => app(TaskController::class)->done($request),
            'generateTodos'             => app(TaskController::class)->generate($request),
            'update_task'               => $this->updateTask($request),
            'delete_task'               => $this->deleteTask($request),
            'delegate_task'             => app(TaskController::class)->delegate($request),

            // Kaufanbote
            'kaufanbote_stats'          => app(KaufanbotController::class)->stats($request),
            'add_kaufanbot'             => app(KaufanbotController::class)->store($request),
            'list_kaufanbote'           => app(KaufanbotController::class)->listKaufanbote($request),
            'update_kaufanbot_status'   => app(KaufanbotController::class)->updateKaufanbotStatus($request),
            'delete_kaufanbot'          => app(KaufanbotController::class)->deleteKaufanbot($request),

            // Property-level Kaufanbote (property_kaufanbote table)
            'list_property_kaufanbote'       => $this->listPropertyKaufanbote($request),
            'upload_property_kaufanbot'      => $this->uploadPropertyKaufanbot($request),
            'update_property_kaufanbot'      => $this->updatePropertyKaufanbot($request),
            'delete_property_kaufanbot'      => $this->deletePropertyKaufanbot($request),
            'update_property_kaufanbot_status' => $this->updatePropertyKaufanbotStatus($request),
            'update_kaufanbot_activity_status' => $this->updateKaufanbotActivityStatus($request),

            // Email Accounts
            'email_accounts'            => app(EmailAccountController::class)->index($request),
            'get_email_accounts_select' => app(EmailAccountController::class)->select($request),
            'save_email_account'        => app(EmailAccountController::class)->save($request),
            'delete_email_account'      => app(EmailAccountController::class)->delete($request),
            'test_email_account'        => app(EmailAccountController::class)->test($request),

            // Email Templates
            'list_templates'            => app(EmailTemplateController::class)->index($request),
            'save_template'             => app(EmailTemplateController::class)->save($request),
            'delete_template'           => app(EmailTemplateController::class)->delete($request),

            // Knowledge Base
            'list_knowledge'            => app(KnowledgeController::class)->index($request),
            'add_knowledge'             => app(KnowledgeController::class)->store($request),
            'update_knowledge'          => app(KnowledgeController::class)->update($request),
            'delete_knowledge'          => app(KnowledgeController::class)->destroy($request),
            'delete_knowledge_permanent'=> app(KnowledgeController::class)->destroyPermanent($request),
            'knowledge_summary'         => app(KnowledgeController::class)->summary($request),
            'ai_categorize_knowledge'   => app(KnowledgeController::class)->aiCategorize($request),
            'extract_file_text'         => app(KnowledgeController::class)->extractFileText($request),
            'ai_bulk_categorize'        => app(KnowledgeController::class)->aiBulkCategorize($request),
            'ai_extract_from_file'      => app(KnowledgeController::class)->aiExtractFromFile($request),
            'list_activities'           => $this->listActivities($request),
            'update_activity'           => $this->updateActivity($request),
            'delete_activity'           => $this->deleteActivity($request),
            'ingest_document'           => app(KnowledgeController::class)->ingestDocument($request),
            'bulk_extract_knowledge'    => app(KnowledgeController::class)->bulkExtract($request),

            // Intelligence & Alerts
            'pending_viewings'          => app(EmailController::class)->pendingViewings($request),
            'dismiss_viewing_alert'     => app(EmailController::class)->dismissViewingAlert($request),
            'cross_property_matches'    => $this->crossPropertyMatches($request),
            'accept_match'              => $this->acceptMatch($request),
            'proactive_alerts'          => $this->proactiveAlerts($request),

            // Property file uploads (Exposé, Nebenkosten)
            'upload_property_file'      => $this->uploadPropertyFile($request),
            'delete_property_file'      => $this->deletePropertyFile($request),
            'get_property_files'        => $this->getPropertyFiles($request),
            'toggle_website_download'   => $this->toggleWebsiteDownload($request),
            'get_property'              => $this->getProperty($request),
            'update_property'           => $this->updateProperty($request),

            // Parent-Child Hierarchy
            'set_parent_property'       => $this->setParentProperty($request),
            'remove_parent_property'    => $this->removeParentProperty($request),
            'create_child_property'     => $this->createChildProperty($request),
            'get_unit_categories'       => $this->getUnitCategories($request),
            'create_children_from_categories' => $this->createChildrenFromCategories($request),
            'get_units'                     => $this->getUnits($request),

            // Portal Documents (admin uploads for owner)
            'upload_portal_document'    => $this->uploadPortalDocument($request),
            'list_portal_documents'     => $this->listPortalDocuments($request),
            'delete_portal_document'    => $this->deletePortalDocument($request),

            // Project Groups
            'list_project_groups'       => $this->listProjectGroups($request),
            'create_project_group'      => $this->createProjectGroup($request),
            'update_project_group'      => $this->updateProjectGroup($request),
            'delete_project_group'      => $this->deleteProjectGroup($request),
            'assign_to_project_group'   => $this->assignToProjectGroup($request),
            'remove_from_project_group' => $this->removeFromProjectGroup($request),

            // Portal Messages (admin replies to owner)
            'list_portal_messages'      => $this->listPortalMessages($request),
            'send_portal_message'       => $this->sendPortalMessage($request),
            'delete_portal_message'     => $this->deletePortalMessage($request),
            'portal_message_count'      => $this->portalMessageCount($request),

            // Calendar
            'calendar_events'           => app(CalendarController::class)->listEvents($request),
            'calendar_create'           => app(CalendarController::class)->createEvent($request),
            'calendar_update'           => app(CalendarController::class)->updateEvent($request),
            'calendar_delete'           => app(CalendarController::class)->deleteEvent($request),
            'calendar_sync'             => app(CalendarController::class)->syncCalendar($request),
            'calendar_upcoming'         => app(CalendarController::class)->upcoming($request),
            'calendar_status'           => app(CalendarController::class)->status($request),
            'calendar_property_viewings'=> app(CalendarController::class)->propertyViewings($request),
            'google_oauth_start'        => app(CalendarController::class)->oauthStart($request),
            'google_oauth_callback'     => app(CalendarController::class)->oauthCallback($request),

            // Quick Activity Add
            'add_activity'              => app(ActivityController::class)->add($request),

            'import_expose'             => $this->importExpose($request),
            'list_brokers'              => $this->listBrokers($request),
            'broker_ranking'            => $this->brokerRanking($request),
            'create_broker'             => $this->createBroker($request),
            'update_broker'             => $this->updateBroker($request),
            'realtime_session'          => $this->realtimeSession($request),
            'execute_tool'              => app(AiChatController::class)->executeToolApi($request),
            'ai_chat' => response()->json((new AiChatController())->chat($request), 200, [], JSON_UNESCAPED_UNICODE),
// ai_tts handled above

            // Website CMS
            'website_content_list'      => $this->websiteContentList($request),
            'website_content_save'      => $this->websiteContentSave($request),
            'website_content_delete'    => $this->websiteContentDelete($request),
            'website_content_upload'    => $this->websiteContentUpload($request),
            'website_toggle_property'   => $this->websiteToggleProperty($request),
            'website_set_main_image'    => $this->websiteSetMainImage($request),
            'website_clear_cache'       => $this->websiteClearCache(),

            // Property Images
            'list_property_images' => (function() use ($request) {
                $propId = intval($request->query('property_id', 0));
                if (!$propId) return response()->json(['error' => 'property_id required'], 400);
                $images = DB::table('property_images')
                    ->where('property_id', $propId)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->map(function($img) {
                        $img->url = '/storage/property_images/' . $img->property_id . '/' . $img->filename;
                        return $img;
                    });
                return response()->json(['images' => $images]);
            })(),

            'upload_property_image' => (function() use ($request) {
                if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
                $propId = intval($request->input('property_id', 0));
                if (!$propId) return response()->json(['error' => 'property_id required'], 400);
                $files = $request->file('images', []);
                if (!is_array($files)) $files = [$files];
                $uploaded = [];
                $dir = storage_path('app/public/property_images/' . $propId);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $maxSort = DB::table('property_images')->where('property_id', $propId)->max('sort_order') ?? -1;
                foreach ($files as $file) {
                    if (!$file || !$file->isValid()) continue;
                    $ext = strtolower($file->getClientOriginalExtension());
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) continue;
                    $originalName = $file->getClientOriginalName();
                    $safeName = time() . '_' . preg_replace('/[^\w.\-]/u', '_', $originalName);
                    $file->move($dir, $safeName);
                    $maxSort++;
                    $id = DB::table('property_images')->insertGetId([
                        'property_id' => $propId,
                        'filename' => $safeName,
                        'original_name' => $originalName,
                        'path' => 'property_images/' . $propId . '/' . $safeName,
                        'mime_type' => $file->getClientMimeType() ?: 'image/' . $ext,
                        'file_size' => filesize($dir . '/' . $safeName),
                        'sort_order' => $maxSort,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $uploaded[] = (object) [
                        'id' => $id,
                        'filename' => $safeName,
                        'original_name' => $originalName,
                        'url' => '/storage/property_images/' . $propId . '/' . $safeName,
                        'is_title_image' => 0,
                        'sort_order' => $maxSort,
                    ];
                }
                if (!empty($uploaded)) {
                    $n = count($uploaded);
                    $text = $n === 1 ? 'Neues Foto hinzugefügt' : "Neue Fotos hinzugefügt ({$n})";
                    app(PropertyActivityLogger::class)->logEvent($propId, $text);
                }
                return response()->json(['images' => $uploaded, 'count' => count($uploaded)]);
            })(),

            'update_property_image' => (function() use ($request) {
                $data = $request->json()->all();
                $id = intval($data['id'] ?? 0);
                if (!$id) return response()->json(['error' => 'id required'], 400);
                $update = [];
                $titleFlipped = false;
                $propertyIdForLog = null;
                if (isset($data['is_title_image']) && $data['is_title_image']) {
                    $img = DB::table('property_images')->where('id', $id)->first();
                    if ($img) {
                        DB::table('property_images')->where('property_id', $img->property_id)->update(['is_title_image' => 0]);
                        $update['is_title_image'] = 1;
                        $titleFlipped = true;
                        $propertyIdForLog = (int) $img->property_id;
                    }
                }
                if (isset($data['category'])) $update['category'] = $data['category'];
                if (isset($data['title'])) $update['title'] = $data['title'];
                if (isset($data['sort_order'])) $update['sort_order'] = intval($data['sort_order']);
                if ($update) {
                    $update['updated_at'] = now();
                    DB::table('property_images')->where('id', $id)->update($update);
                }
                // Nur echte "Titelbild geaendert"-Events loggen, nicht jede Drag-&-Drop-
                // Umsortierung (sort_order wird waehrend Drag dutzende Male aufgerufen).
                if ($titleFlipped && $propertyIdForLog) {
                    app(PropertyActivityLogger::class)->logEvent($propertyIdForLog, 'Titelbild geändert');
                }
                return response()->json(['success' => true]);
            })(),

            'delete_property_image' => (function() use ($request) {
                $data = $request->json()->all();
                $id = intval($data['id'] ?? 0);
                if (!$id) return response()->json(['error' => 'id required'], 400);
                $img = DB::table('property_images')->where('id', $id)->first();
                if ($img) {
                    $filePath = storage_path('app/public/' . $img->path);
                    if (file_exists($filePath)) @unlink($filePath);
                    DB::table('property_images')->where('id', $id)->delete();
                    app(PropertyActivityLogger::class)->logEvent((int) $img->property_id, 'Foto entfernt');
                }
                return response()->json(['success' => true]);
            })(),

            default => response()->json([
                'error'     => 'Unknown action',
                'available' => [
                    'briefing','performance','conversations', // 'followups' removed - deprecated
                    'conv_list','conv_detail','conv_reply','conv_followup','conv_reply_all'              => app(ConversationController::class)->replyAll($request),
            'conv_done_batch'            => app(ConversationController::class)->doneBatch($request),
            'conv_done','conv_read','conv_draft','conv_regenerate_draft','conv_improve_draft','conv_followup_all',
                    'property_health','email_context','ai_reply','improve_text','mark_handled',
                    'send_email','email_history','mark_read','create_portal_access','check_portal_access','toggle_auto_reply','trash_emails','restore_emails','pending_viewings','dismiss_viewing_alert',
                    'download_attachment','save_attachment_to_property','unmatched_emails','assign_email','property_contacts',
                    'save_draft','list_drafts','delete_draft',
                    'contacts','contact_search','contact_create','contact_update','contact_delete','contact_add_alias',
                    'getTasks','addTask','doneTask','generateTodos','update_task','delete_task',
                    'create_customer','update_customer','delete_customer',
                    'update_portal_user','create_portal_user','delete_portal_user',
                    'create_contact','snooze_followup',
                    'kaufanbote_stats','add_kaufanbot','delete_kaufanbot',
                    'list_property_kaufanbote','upload_property_kaufanbot','update_property_kaufanbot','delete_property_kaufanbot','update_property_kaufanbot_status','send_to_buyer',
                    'immoji_connect','immoji_disconnect','immoji_status','immoji_push','immoji_push_units','immoji_push_single_unit','immoji_set_unit_portals','immoji_portal_status','immoji_set_portals','immoji_capacity','bulk_sync_immoji','immoji_bulk_portal_status',
                    'email_accounts','get_email_accounts_select','save_email_account',
                    'delete_email_account','test_email_account',
                    'list_templates','save_template','delete_template',
                    'set_on_hold','fix_activity','fix_expose_categories','create_property','delete_property','set_inactive','reactivate_property','analyze_file',
                    'list_knowledge','add_knowledge','update_knowledge','delete_knowledge',
                    'delete_knowledge_permanent','knowledge_summary','ai_categorize_knowledge','extract_file_text','ai_bulk_categorize','ai_extract_from_file','list_activities','update_activity','delete_activity',
                    'ingest_document','bulk_extract_knowledge','parse_property_fields','parse_units','generate_property_description','polish_property_description',
                    // 'followup_recommendation',  // deprecated
                    'cross_property_matches','proactive_alerts',
                    'upload_portal_document','list_portal_documents','delete_portal_document',
                    'list_portal_messages','send_portal_message','delete_portal_message','portal_message_count',
                    'add_activity','ai_chat','ai_tts',
                ],
            ], 400),
        };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminAPI [' . $action . ']: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function updateTask(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $update = ['updated_at' => now()];
        if (isset($input['title'])) $update['title'] = trim($input['title']);
        if (isset($input['due_date'])) $update['due_date'] = $input['due_date'] ?: null;
        if (isset($input['priority']) && in_array($input['priority'], ['low','medium','high','critical'])) {
            $update['priority'] = $input['priority'];
        }
        if (isset($input['property_id'])) $update['property_id'] = $input['property_id'] ?: null;
        if (array_key_exists("assigned_to", $input)) $update["assigned_to"] = $input["assigned_to"] ? intval($input["assigned_to"]) : null;

        DB::table('tasks')->where('id', $id)->update($update);

        $task = DB::selectOne("SELECT t.*, p.ref_id, p.address FROM tasks t LEFT JOIN properties p ON t.property_id = p.id WHERE t.id = ?", [$id]);
        return response()->json(['success' => true, 'task' => $task]);
    }

    private function deleteTask(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id required'], 400);

        DB::table('tasks')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    // ===== CUSTOMER CRUD =====

    private function createCustomer(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $name = trim($d['name'] ?? '');
        $email = trim($d['email'] ?? '');
        if (!$name) return response()->json(['error' => 'Name erforderlich'], 400);

        // Check for duplicate email
        if ($email) {
            $existing = DB::table('customers')->where('email', $email)->first();
            if ($existing) {
                return response()->json(['error' => 'Ein Eigentümer mit dieser E-Mail existiert bereits: ' . $existing->name, 'existing_id' => $existing->id], 409);
            }
        }

        $id = DB::table('customers')->insertGetId([
            'name' => $name,
            'email' => $email ?: null,
            'phone' => trim($d['phone'] ?? '') ?: null,
            'address' => trim($d['address'] ?? '') ?: null,
            'city' => trim($d['city'] ?? '') ?: null,
            'zip' => trim($d['zip'] ?? '') ?: null,
            'notes' => trim($d['notes'] ?? '') ?: null,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->where('id', $id)->first();
        return response()->json(['success' => true, 'customer' => $customer]);
    }

    private function updateCustomer(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $id = intval($d['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id erforderlich'], 400);

        $update = ['updated_at' => now()];
        foreach (['name', 'email', 'phone', 'address', 'city', 'zip', 'notes'] as $field) {
            if (array_key_exists($field, $d)) {
                $update[$field] = trim($d[$field]) ?: null;
            }
        }

        // Check for duplicate email (if email changed)
        if (!empty($update['email'])) {
            $existing = DB::table('customers')->where('email', $update['email'])->where('id', '!=', $id)->first();
            if ($existing) {
                return response()->json(['error' => 'Diese E-Mail wird bereits verwendet von: ' . $existing->name], 409);
            }
        }

        DB::table('customers')->where('id', $id)->update($update);

        // Sync back to all linked properties
        $linkedProps = DB::table('properties')->where('customer_id', $id)->pluck('id');
        if ($linkedProps->count() > 0) {
            $propUpdate = [];
            if (!empty($update['name'])) $propUpdate['owner_name'] = $update['name'];
            if (array_key_exists('email', $update)) $propUpdate['owner_email'] = $update['email'];
            if (array_key_exists('phone', $update)) $propUpdate['owner_phone'] = $update['phone'];
            if (!empty($propUpdate)) {
                DB::table('properties')->whereIn('id', $linkedProps)->update($propUpdate);
            }
        }

        $customer = DB::table('customers')->where('id', $id)->first();
        return response()->json(['success' => true, 'customer' => $customer]);
    }

    private function deleteCustomer(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $id = intval($d['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id erforderlich'], 400);

        // Check if linked to properties
        $linkedCount = DB::table('properties')->where('customer_id', $id)->count();
        if ($linkedCount > 0) {
            return response()->json(['error' => "Eigentümer ist mit {$linkedCount} Objekt(en) verknüpft. Bitte zuerst Zuordnung entfernen."], 409);
        }

        DB::table('customers')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    // ===== PORTAL USER CRUD =====

    private function createPortalUserForCustomer(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $customerId = intval($d['customer_id'] ?? 0);
        $password = trim($d['password'] ?? '');
        if (!$customerId || !$password) return response()->json(['error' => 'customer_id und password erforderlich'], 400);

        $customer = DB::table('customers')->where('id', $customerId)->first();
        if (!$customer) return response()->json(['error' => 'Eigentümer nicht gefunden'], 404);
        if (empty($customer->email)) return response()->json(['error' => 'Eigentümer hat keine E-Mail-Adresse'], 400);

        // Check if portal user already exists
        $existing = DB::table('users')->where('email', $customer->email)->first();
        if ($existing) {
            // Link customer_id if missing
            if (!$existing->customer_id) {
                DB::table('users')->where('id', $existing->id)->update(['customer_id' => $customerId]);
            }
            return response()->json(['success' => true, 'user' => (array) $existing, 'message' => 'Portalzugang existiert bereits']);
        }

        $userId = DB::table('users')->insertGetId([
            'name' => $customer->name,
            'email' => $customer->email,
            'password' => bcrypt($password),
            'user_type' => 'eigentuemer',
            'customer_id' => $customerId,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email', 'created_at']);
        return response()->json(['success' => true, 'user' => (array) $user]);
    }

    private function updatePortalUser(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $userId = intval($d['user_id'] ?? 0);
        if (!$userId) return response()->json(['error' => 'user_id erforderlich'], 400);

        $update = ['updated_at' => now()];
        if (!empty($d['password'])) $update['password'] = bcrypt($d['password']);
        if (!empty($d['name'])) $update['name'] = trim($d['name']);
        if (!empty($d['email'])) {
            $emailConflict = DB::table('users')->where('email', $d['email'])->where('id', '!=', $userId)->exists();
            if ($emailConflict) return response()->json(['error' => 'E-Mail wird bereits von einem anderen User verwendet'], 409);
            $update['email'] = trim($d['email']);
        }

        DB::table('users')->where('id', $userId)->update($update);
        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email', 'created_at']);
        return response()->json(['success' => true, 'user' => (array) $user]);
    }

    private function deletePortalUser(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->json()->all();
        $userId = intval($d['user_id'] ?? 0);
        if (!$userId) return response()->json(['error' => 'user_id erforderlich'], 400);

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user || !in_array($user->user_type, ['eigentuemer', ''])) {
            return response()->json(['error' => 'Kein Eigentümer-User'], 400);
        }

        DB::table('users')->where('id', $userId)->delete();
        return response()->json(['success' => true]);
    }

    private function snoozeFollowup(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        $days = intval($input['days'] ?? 0);

        if (!$id || !$days) return response()->json(['error' => 'id and days required'], 400);

        $snoozeUntil = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        DB::table('activities')->where('id', $id)->update(['snooze_until' => $snoozeUntil]);

        return response()->json(['success' => true, 'snooze_until' => $snoozeUntil]);
    }

    private function createContact(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $name = trim($input['full_name'] ?? $input['name'] ?? '');
        if (!$name) return response()->json(['error' => 'name required'], 400);

        $role = $input['role'] ?? 'kunde';
        if (!in_array($role, ['kunde','partner','bautraeger','intern','makler','eigentuemer'])) {
            $role = 'kunde';
        }

        $id = DB::table('contacts')->insertGetId([
            'full_name'  => $name,
            'email'      => trim($input['email'] ?? '') ?: null,
            'phone'      => trim($input['phone'] ?? '') ?: null,
            'notes'      => trim($input['notes'] ?? '') ?: null,
            'role'       => $role,
            'aliases'    => '[]',
            'property_ids' => '[]',
            'source'     => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contact = DB::selectOne('SELECT * FROM contacts WHERE id = ?', [$id]);
        return response()->json(['success' => true, 'contact' => $contact], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Human-friendly German label fuer Immoji-Sync-Sections (Kundenansicht).
     */
    private static function sectionLabel(string $section): string
    {
        return match ($section) {
            'general'      => 'Allgemeines',
            'costs'        => 'Kosten',
            'areas'        => 'Flächen',
            'descriptions' => 'Beschreibungen',
            'building'     => 'Gebäudedetails',
            'files'        => 'Fotos & Dokumente',
            default        => $section,
        };
    }

    private function listActivities(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $activities = DB::table('activities')
            ->where('property_id', $propertyId)
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->get(['id', 'property_id', 'activity_date', 'stakeholder', 'activity', 'result', 'category', 'duration', 'source_email_id', 'created_at']);

        return response()->json(['activities' => $activities], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function updateActivity(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $update = [];
        if (isset($input['stakeholder'])) $update['stakeholder'] = $input['stakeholder'];
        if (isset($input['activity'])) $update['activity'] = $input['activity'];
        if (isset($input['result'])) $update['result'] = $input['result'];
        if (isset($input['category'])) $update['category'] = $input['category'];
        if (isset($input['activity_date'])) $update['activity_date'] = $input['activity_date'];

        if (empty($update)) return response()->json(['error' => 'nothing to update'], 400);

        DB::table('activities')->where('id', $id)->update($update);
        return response()->json(['success' => true]);
    }

    private function deleteActivity(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if (!$id) return response()->json(['error' => 'id required'], 400);

        DB::table('activities')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }


    private function crossPropertyMatches(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        // Get active properties (broker-scoped)
        $brokerId = \Auth::id();
        $q = DB::table('properties')
            ->whereNotIn('realty_status', ['verkauft', 'vermietet', 'inaktiv'])
            ->where('on_hold', 0);
        if ($brokerId && \Auth::user() && \Auth::user()->user_type !== 'admin') {
            $q->where('broker_id', $brokerId);
        }
        $properties = $q->get(['id', 'ref_id', 'address', 'city', 'object_type', 'purchase_price']);

        $propertyMap = $properties->keyBy('id');

        // Get contacts who inquired (activities with category anfrage or email-in) in last 30 days
        $cutoff = now()->subDays(30)->format('Y-m-d');
        $sysFilter = \App\Helpers\StakeholderHelper::systemStakeholderFilter('stakeholder');
        // Only include stakeholders that exist in the contacts table (= have email)
        $recentInquiries = DB::table('activities')
            ->whereIn('category', ['anfrage', 'email-in'])
            ->where('activity_date', '>=', $cutoff)
            ->whereNotNull('stakeholder')
            ->where('stakeholder', '!=', '')
            ->whereRaw($sysFilter)
            ->whereIn('stakeholder', DB::table('contacts')->pluck('full_name'))
            ->select('property_id', \DB::raw('MAX(stakeholder) as stakeholder'), \DB::raw('MAX(activity_date) as last_contact'))
            ->groupBy('property_id', \DB::raw(\App\Helpers\StakeholderHelper::normSH('stakeholder')))
            ->get();

        $matches = [];

        foreach ($recentInquiries as $inquiry) {
            $sourceProperty = $propertyMap->get($inquiry->property_id);
            if (!$sourceProperty) continue;

            foreach ($properties as $candidate) {
                // Skip same property
                if ($candidate->id === $inquiry->property_id) continue;

                // Same city
                if (strtolower(trim($candidate->city)) !== strtolower(trim($sourceProperty->city))) continue;

                // Similar type (if both set)
                if ($sourceProperty->object_type && $candidate->object_type) {
                    if (strtolower($sourceProperty->object_type) !== strtolower($candidate->object_type)) continue;
                }

                // Similar price (±30%)
                if ($sourceProperty->purchase_price && $candidate->purchase_price) {
                    $ratio = $candidate->purchase_price / $sourceProperty->purchase_price;
                    if ($ratio < 0.7 || $ratio > 1.3) continue;
                }

                // Build match reason
                $reasons = [];
                $reasons[] = 'Selbe Stadt (' . $candidate->city . ')';
                if ($sourceProperty->object_type && $candidate->object_type && strtolower($sourceProperty->object_type) === strtolower($candidate->object_type)) {
                    $reasons[] = 'Selber Typ (' . $candidate->object_type . ')';
                }
                if ($sourceProperty->purchase_price && $candidate->purchase_price) {
                    $priceDiff = abs($candidate->purchase_price - $sourceProperty->purchase_price);
                    $pctDiff = round($priceDiff / $sourceProperty->purchase_price * 100);
                    if ($pctDiff <= 10) $reasons[] = 'Ähnlicher Preis (±' . $pctDiff . '%)';
                    else $reasons[] = 'Preis ±' . $pctDiff . '%';
                }

                $matches[] = [
                    'contact_name'        => $inquiry->stakeholder,
                    'original_property'   => [
                        'id'      => $sourceProperty->id,
                        'ref_id'  => $sourceProperty->ref_id,
                        'address' => $sourceProperty->address,
                        'purchase_price'   => $sourceProperty->purchase_price,
                        'type'    => $sourceProperty->object_type,
                    ],
                    'suggested_property'  => [
                        'id'      => $candidate->id,
                        'ref_id'  => $candidate->ref_id,
                        'address' => $candidate->address,
                        'purchase_price'   => $candidate->purchase_price,
                        'type'    => $candidate->object_type,
                    ],
                    'last_contact'        => $inquiry->last_contact,
                    'match_reasons'       => $reasons,
                ];
            }
        }

        // Deduplicate: one suggestion per contact+property pair
        $seen = [];
        $unique = [];
        foreach ($matches as $m) {
            $key = $m['contact_name'] . '|' . $m['suggested_property']['id'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $m;
            }
        }

        // Limit to 20 most recent
        usort($unique, fn($a, $b) => strcmp($b['last_contact'], $a['last_contact']));
        $unique = array_slice($unique, 0, 20);

        return response()->json(['matches' => $unique], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function proactiveAlerts(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $alerts = [];
        $now = now();

        // 1. Properties with 0 new leads in last 14 days (broker-scoped)
        $brokerId = \Auth::id();
        $q = DB::table('properties')
            ->whereNotIn('realty_status', ['verkauft', 'vermietet', 'inaktiv'])
            ->where('on_hold', 0);
        if ($brokerId && \Auth::user() && \Auth::user()->user_type !== 'admin') {
            $q->where('broker_id', $brokerId);
        }
        $activeProperties = $q->get(['id', 'ref_id', 'address', 'city']);
        $propertyMap = $activeProperties->keyBy('id');

        $cutoff14 = $now->copy()->subDays(14)->format('Y-m-d');
        foreach ($activeProperties as $prop) {
            $recentLeads = DB::table('activities')
                ->where('property_id', $prop->id)
                ->whereIn('category', ['anfrage', 'email-in'])
                ->where('activity_date', '>=', $cutoff14)
                ->count();

            if ($recentLeads === 0) {
                // Check if property has any leads at all (skip brand new listings)
                $totalLeads = DB::table('activities')
                    ->where('property_id', $prop->id)
                    ->whereIn('category', ['anfrage', 'email-in'])
                    ->count();

                if ($totalLeads > 0) {
                    $alerts[] = [
                        'id'       => 'no_leads_' . $prop->id,
                        'type'     => 'no_leads',
                        'severity' => 'warning',
                        'title'    => 'Keine Anfragen seit 2 Wochen',
                        'message'  => $prop->address . ' (' . $prop->ref_id . ') hat seit 14 Tagen keine neuen Anfragen.',
                        'action'   => ['label' => 'Objekt anschauen', 'property_id' => $prop->id],
                    ];
                }
            }
        }

        // 2. Response time > 48h this week
        $cutoffWeek = $now->copy()->subDays(7)->format('Y-m-d');
        $inquiries = DB::table('activities')
            ->where('category', 'anfrage')
            ->where('activity_date', '>=', $cutoffWeek)
            ->get(['property_id', 'activity_date']);

        if ($inquiries->count() > 0) {
            $totalHours = 0;
            $counted = 0;
            foreach ($inquiries as $inq) {
                // Find next outbound activity for same property after inquiry
                $nextOut = DB::table('activities')
                    ->where('property_id', $inq->property_id)
                    ->whereIn('category', ['anruf', 'email-out', 'besichtigung'])
                    ->where('activity_date', '>', $inq->activity_date)
                    ->orderBy('activity_date')
                    ->value('activity_date');

                if ($nextOut) {
                    $diff = (strtotime($nextOut) - strtotime($inq->activity_date)) / 3600;
                    if ($diff < 168) { // within a week
                        $totalHours += $diff;
                        $counted++;
                    }
                }
            }

            if ($counted > 0) {
                $avgHours = $totalHours / $counted;
                if ($avgHours > 48) {
                    $alerts[] = [
                        'id'       => 'response_time_high',
                        'type'     => 'response_time',
                        'severity' => 'urgent',
                        'title'    => 'Antwortzeit gestiegen',
                        'message'  => 'Durchschnittliche Antwortzeit diese Woche: ' . round($avgHours, 1) . 'h (Ziel: <48h).',
                        'action'   => ['label' => 'Prioritäten öffnen', 'tab' => 'priorities'],
                    ];
                }
            }
        }

        // 3. Declining momentum: leads this week < 50% of last week
        $cutoffThisWeek = $now->copy()->subDays(7)->format('Y-m-d');
        $cutoffLastWeek = $now->copy()->subDays(14)->format('Y-m-d');

        foreach ($activeProperties as $prop) {
            $thisWeek = DB::table('activities')
                ->where('property_id', $prop->id)
                ->whereIn('category', ['anfrage', 'email-in'])
                ->where('activity_date', '>=', $cutoffThisWeek)
                ->count();

            $lastWeek = DB::table('activities')
                ->where('property_id', $prop->id)
                ->whereIn('category', ['anfrage', 'email-in'])
                ->whereBetween('activity_date', [$cutoffLastWeek, $cutoffThisWeek])
                ->count();

            if ($lastWeek >= 3 && $thisWeek < ($lastWeek * 0.5)) {
                $alerts[] = [
                    'id'       => 'declining_' . $prop->id,
                    'type'     => 'declining',
                    'severity' => 'warning',
                    'title'    => 'Lead-Rückgang',
                    'message'  => $prop->address . ': ' . $thisWeek . ' Anfragen diese Woche vs. ' . $lastWeek . ' letzte Woche.',
                    'action'   => ['label' => 'Objekt anschauen', 'property_id' => $prop->id],
                ];
            }
        }

        // 4. Hot leads gone silent: 3+ interactions, WE wrote last, customer hasn't responded in 7+ days
        $cutoff7 = $now->copy()->subDays(7)->format('Y-m-d');
        $cutoff60 = $now->copy()->subDays(60)->format('Y-m-d');
        $hotContacts = DB::select("
            SELECT conv.property_id, conv.stakeholder, conv.cnt, conv.last_date, conv.last_cat
            FROM (
                SELECT a.property_id, MAX(a.stakeholder) as stakeholder, 
                    COUNT(*) as cnt, 
                    MAX(a.activity_date) as last_date,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.category ORDER BY a.activity_date DESC, a.id DESC), ',', 1) as last_cat
                FROM activities a
                WHERE a.stakeholder IS NOT NULL AND a.stakeholder != ''
                AND a.stakeholder NOT LIKE '%SR-Homes%' AND a.stakeholder NOT LIKE '%System%' 
                AND a.stakeholder NOT LIKE '%Immobilienscout%' AND a.stakeholder NOT LIKE '%willhaben%'
                AND a.stakeholder NOT LIKE '%import%'
                GROUP BY a.property_id, " . \App\Helpers\StakeholderHelper::normSH('a.stakeholder') . "
                HAVING cnt >= 3 
                AND last_date < ? AND last_date >= ?
                AND last_cat IN ('email-out', 'expose', 'anruf')
            ) conv
        ", [$cutoff7, $cutoff60]);

        foreach ($hotContacts as $hc) {
            $hc = (array) $hc;
            $prop = $propertyMap[$hc['property_id']] ?? null;
            if (!$prop) continue;
            // Skip on_hold properties
            $propLabel = $prop->address . ' (' . $prop->ref_id . ')';
            $alerts[] = [
                'id'       => 'hot_silent_' . $hc['property_id'] . '_' . md5($hc['stakeholder']),
                'type'     => 'hot_silent',
                'severity' => 'urgent',
                'title'    => 'Hot Lead verstummt',
                'message'  => $hc['stakeholder'] . ' (' . $propLabel . ') hatte ' . $hc['cnt'] . ' Interaktionen, ist aber seit ' . ceil((time() - strtotime($hc['last_date'])) / 86400) . ' Tagen still.',
                'action'   => ['label' => 'Kontakt nachfassen', 'property_id' => $hc['property_id']],
            ];
        }

        // 5. Stale price/market knowledge (>90 days old)
        $cutoff90 = $now->copy()->subDays(90)->format('Y-m-d H:i:s');
        $staleKb = DB::table('property_knowledge')
            ->where('category', 'preis_markt')
            ->where('is_active', 1)
            ->where('updated_at', '<', $cutoff90)
            ->get(['id', 'property_id', 'title', 'updated_at']);

        foreach ($staleKb as $kb) {
            $prop = $propertyMap[$kb->property_id] ?? null;
            $propLabel = $prop ? $prop->address . ' (' . $prop->ref_id . ')' : 'Objekt #' . $kb->property_id;
            $daysOld = ceil((time() - strtotime($kb->updated_at)) / 86400);
            $alerts[] = [
                'id'       => 'stale_kb_' . $kb->id,
                'type'     => 'stale_knowledge',
                'severity' => 'info',
                'title'    => 'Preisinfo veraltet',
                'message'  => '"' . $kb->title . '" für ' . $propLabel . ' wurde seit ' . $daysOld . ' Tagen nicht aktualisiert.',
                'action'   => ['label' => 'Objekt anschauen', 'property_id' => $kb->property_id],
            ];
        }

        return response()->json(['alerts' => $alerts], 200, [], JSON_UNESCAPED_UNICODE);
    }


    // ─── Portal Documents ────────────────────────────────────────────────────

    private function uploadPortalDocument(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return response()->json(['error' => 'File upload required'], 400);
        }

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
        if (!in_array($ext, $allowed)) {
            return response()->json(['error' => 'File type not allowed'], 400);
        }

        $originalName = $file->getClientOriginalName();
        $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $dir          = storage_path('app/public/documents/' . $propertyId);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $safeName);

        $id = DB::table('portal_documents')->insertGetId([
            'property_id'   => $propertyId,
            'filename'      => $safeName,
            'original_name' => $originalName,
            'file_size'     => filesize($dir . '/' . $safeName),
            'mime_type'     => mime_content_type($dir . '/' . $safeName),
            'uploaded_by'   => 'admin',
            'realty_description'   => trim($request->input('realty_description', '')),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id, 'filename' => $safeName]);
    }

    private function listPortalDocuments(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $docs = DB::table('portal_documents')
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function($d) {
                $d->file_url = '/storage/documents/' . $d->property_id . '/' . $d->filename;
                return $d;
            });

        return response()->json(['documents' => $docs, 'count' => $docs->count()]);
    }

    private function deletePortalDocument(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $doc = DB::table('portal_documents')->where('id', $id)->first();
        if ($doc) {
            $path = storage_path('app/public/documents/' . $doc->property_id . '/' . $doc->filename);
            if (file_exists($path)) @unlink($path);
            DB::table('portal_documents')->where('id', $id)->delete();
        }

        return response()->json(['success' => true]);
    }

    // ─── Portal Messages ─────────────────────────────────────────────────────

    private function listPortalMessages(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $messages = DB::table('portal_messages')
            ->where('property_id', $propertyId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages, 'count' => $messages->count()]);
    }

    private function sendPortalMessage(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input      = $request->json()->all();
        $propertyId = intval($input['property_id'] ?? 0);
        $message    = trim($input['message'] ?? '');
        $isPinned   = intval($input['is_pinned'] ?? 0);

        if (!$propertyId || !$message) {
            return response()->json(['error' => 'property_id and message required'], 400);
        }

        $id = DB::table('portal_messages')->insertGetId([
            'property_id' => $propertyId,
            'author_name' => \Auth::user()->name ?? 'SR-Homes',
            'author_role' => 'admin',
            'message'     => $message,
            'is_pinned'   => $isPinned,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $msg = DB::table('portal_messages')->where('id', $id)->first();
        return response()->json(['success' => true, 'message' => $msg]);
    }

    private function deletePortalMessage(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? $request->query('id', 0));
        if (!$id) return response()->json(['error' => 'id required'], 400);

        DB::table('portal_messages')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    private function portalMessageCount(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        // Count unread customer messages (messages from 'customer' role - admin hasn't replied yet)
        $properties = DB::table('properties')->pluck('id')->toArray();

        $unread = 0;
        if (!empty($properties)) {
            // Count properties that have customer messages with no subsequent admin reply
            $unread = DB::table('portal_messages')
                ->where('author_role', 'customer')
                ->whereIn('property_id', $properties)
                ->whereNotExists(function($q) {
                    $q->from('portal_messages as pm2')
                        ->whereColumn('pm2.property_id', 'portal_messages.property_id')
                        ->where('pm2.author_role', 'admin')
                        ->whereColumn('pm2.created_at', '>', 'portal_messages.created_at');
                })
                ->count();
        }

        return response()->json(['unread_count' => $unread]);
    }

    private function propertyContacts(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) return response()->json(['contacts' => []]);

        $norm = \App\Helpers\StakeholderHelper::normSH('stakeholder');
        $sysFilter = \App\Helpers\StakeholderHelper::systemStakeholderFilter('stakeholder');

        $contacts = DB::select("
            SELECT MAX(stakeholder) as stakeholder, COUNT(*) as count,
                   MAX(activity_date) as last_date,
                   GROUP_CONCAT(DISTINCT category) as categories
            FROM activities
            WHERE property_id = ? AND {$sysFilter}
            GROUP BY {$norm}
            ORDER BY last_date DESC
        ", [$propertyId]);

        return response()->json(['contacts' => array_map(fn($c) => (array)$c, $contacts)]);
    }

    private function acceptMatch(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $input = $request->json()->all();
        $contactName    = $input['contact_name'] ?? '';
        $propertyId     = intval($input['property_id'] ?? 0);
        $mergeStakeholder = $input['merge_stakeholder'] ?? '';
        $note           = $input['note'] ?? '';

        if (!$contactName || !$propertyId) {
            return response()->json(['error' => 'contact_name and property_id required'], 400);
        }

        // Use merge_stakeholder if provided (merge into existing contact)
        $stakeholder = $mergeStakeholder ?: $contactName;

        \DB::insert("
            INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category, created_at)
            VALUES (?, NOW(), ?, ?, ?, 'anfrage', NOW())
        ", [
            $propertyId,
            $stakeholder,
            'Cross-Property Match: ' . $contactName . ' zeigt Interesse an ähnlichem Objekt',
            $note ?: 'Zugeordnet basierend auf Suchmuster',
        ]);

        return response()->json(['success' => true]);
    }


    // Dead code removed: getSettings(), saveSettings(), changePassword() - dispatched to SettingsController


    private function uploadPropertyFile(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
        $propertyId = intval($request->input('property_id', 0));
        $label = trim($request->input('label', 'Dokument'));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return response()->json(['error' => 'File required'], 400);
        }
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'])) {
            return response()->json(['error' => 'Nur PDF, DOC, DOCX, XLS, XLSX, JPG, PNG erlaubt'], 400);
        }
        $dir = storage_path('app/public/property_files/' . $propertyId);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $originalName = $file->getClientOriginalName();
        $safeName = preg_replace('/[^\w.\-äöüÄÖÜß ()\[\]]/u', '_', $originalName);
        if (file_exists($dir . '/' . $safeName)) {
            $base = pathinfo($safeName, PATHINFO_FILENAME);
            $safeName = $base . '_' . time() . '.' . $ext;
        }
        $file->move($dir, $safeName);
        $relPath = 'property_files/' . $propertyId . '/' . $safeName;

        $maxSort = DB::table('property_files')->where('property_id', $propertyId)->max('sort_order') ?? -1;

        $id = DB::table('property_files')->insertGetId([
            'property_id' => $propertyId,
            'label' => $label,
            'filename' => $safeName,
            'path' => $relPath,
            'mime_type' => $file->getClientMimeType() ?? mime_content_type($dir . '/' . $safeName),
            'file_size' => filesize($dir . '/' . $safeName),
            'sort_order' => $maxSort + 1,
            'created_at' => now(),
        ]);

        $displayLabel = $label ?: $originalName;
        app(PropertyActivityLogger::class)->logEvent($propertyId, "Dokument hinzugefügt: {$displayLabel}");

        return response()->json([
            'success' => true,
            'file' => [
                'id' => $id,
                'label' => $label,
                'filename' => $safeName,
                'path' => $relPath,
                'url' => '/storage/' . $relPath,
                'mime_type' => mime_content_type($dir . '/' . $safeName),
                'file_size' => filesize($dir . '/' . $safeName),
            ],
        ]);
    }

    private function deletePropertyFile(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
        $data = $request->json()->all();
        $fileId = intval($data['file_id'] ?? 0);
        if (!$fileId) {
            return response()->json(['error' => 'file_id required'], 400);
        }
        $file = DB::table('property_files')->where('id', $fileId)->first();
        if ($file) {
            $path = $file->path;
            $lbl = $file->label ?: $file->filename;
            $propId = (int) $file->property_id;

            // DB-Eintrag IMMER loeschen — dann pruefen ob die physische Datei
            // noch von anderen DB-Zeilen referenziert wird, bevor wir sie
            // vom Disk werfen. PropertyLinkController legt fuer globale
            // Dokumente property_files-Zeilen mit dem gleichen path wie in
            // global_files an — ein blindes unlink wuerde die globale Datei
            // mit-loeschen und alle anderen verknuepften property_files +
            // die /storage/global_files/-URL kaputt machen.
            DB::table('property_files')->where('id', $fileId)->delete();

            $stillReferenced = DB::table('property_files')->where('path', $path)->exists()
                || DB::table('global_files')->where('path', $path)->exists();

            if (!$stillReferenced) {
                $full = storage_path('app/public/' . $path);
                if (file_exists($full)) @unlink($full);
            }

            app(PropertyActivityLogger::class)->logEvent($propId, "Dokument entfernt: {$lbl}");
        }
        return response()->json(['success' => true]);
    }

    private function toggleWebsiteDownload(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
        $data = $request->json()->all();
        $fileId = intval($data['file_id'] ?? 0);
        if (!$fileId) return response()->json(['error' => 'file_id required'], 400);

        $file = DB::table('property_files')->where('id', $fileId)->first();
        if (!$file) return response()->json(['error' => 'File not found'], 404);

        $newValue = !($file->is_website_download ?? false);
        DB::table('property_files')->where('id', $fileId)->update([
            'is_website_download' => $newValue,
        ]);

        // Clear website cache so changes appear immediately
        \Illuminate\Support\Facades\Cache::forget('website_properties');

        return response()->json([
            'success' => true,
            'is_website_download' => $newValue,
        ]);
    }

    private function getProperty(Request $request): JsonResponse
    {
        $id = intval($request->query('property_id', 0));
        if (!$id) return response()->json(['error' => 'property_id required'], 400);
        $prop = DB::table('properties')->where('id', $id)->first();
        if (!$prop) return response()->json(['error' => 'Not found'], 404);
        $prop->children_count = DB::table('properties')->where('parent_id', $id)->count();
        $prop->unit_count = DB::table('property_units')->where('property_id', $id)->where('is_parking', 0)->count();

        // properties.owner_name / owner_email / owner_phone are cached at
        // assign-time. If the owner is linked via customer_id, always serve
        // the live values from the customers table so edits in the Kontakte
        // tab are reflected immediately in the property detail view.
        if (!empty($prop->customer_id)) {
            $customer = DB::table('customers')->where('id', $prop->customer_id)->first();
            if ($customer) {
                $prop->owner_name = $customer->name ?? $prop->owner_name;
                $prop->owner_email = $customer->email ?? $prop->owner_email;
                $prop->owner_phone = $customer->phone ?? $prop->owner_phone;
            }
        }

        // Neuestes Aufnahmeprotokoll mitliefern, damit das PropertyDetailPage
        // einen Warn-Banner fuer uebersprungene Felder anzeigen kann.
        $intakeProtocol = null;
        $latestProto = DB::table('intake_protocols')
            ->where('property_id', $id)
            ->orderByDesc('id')
            ->first();
        if ($latestProto) {
            $openFields = [];
            if (!empty($latestProto->open_fields)) {
                $decoded = json_decode($latestProto->open_fields, true);
                if (is_array($decoded)) $openFields = $decoded;
            }
            // Customer-E-Mail (falls verlinkt) fuer Mail-Status mitliefern
            $customerEmail = null;
            if (!empty($latestProto->customer_id)) {
                $cust = DB::table('customers')->where('id', $latestProto->customer_id)->first();
                if ($cust) $customerEmail = $cust->email ?? null;
            }
            $intakeProtocol = [
                'id' => $latestProto->id,
                'created_at' => $latestProto->created_at,
                'signed_at' => $latestProto->signed_at ?? null,
                'open_fields' => $openFields,
                'owner_email_sent_at' => $latestProto->owner_email_sent_at ?? null,
                'owner_email' => $customerEmail,
            ];
        }

        return response()->json([
            'property' => $prop,
            'intake_protocol' => $intakeProtocol,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function updateProperty(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
        $data = $request->json()->all();
        $id = intval($data['property_id'] ?? 0);
        if (!$id) return response()->json(['error' => 'property_id required'], 400);

        $allowed = ['address', 'city', 'zip', 'object_type', 'purchase_price', 'total_area', 'rooms_amount', 'construction_year',
                    'heating', 'realty_description', 'highlights', 'realty_status', 'living_area', 'free_area',
                    'year_renovated', 'platforms', 'project_group_id', 'project_name', 'parent_id', 'property_history'];
        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (empty($update)) return response()->json(['error' => 'No fields to update'], 400);
        
        // Validate project_group_id: property must have same customer_id as group
        if (array_key_exists('project_group_id', $update) && $update['project_group_id']) {
            $group = DB::table('project_groups')->where('id', $update['project_group_id'])->first();
            $property = DB::table('properties')->where('id', $id)->first();
            if ($group && $property && intval($property->customer_id) !== intval($group->customer_id)) {
                return response()->json(['error' => 'Nur Objekte mit demselben Eigentuemer koennen gruppiert werden.'], 400);
            }
        }
        
        // Auto-set sold_at when status changes to verkauft
        if (isset($update['realty_status']) && strtolower($update['realty_status']) === 'verkauft') {
            $current = DB::table('properties')->where('id', $id)->value('sold_at');
            if (!$current) {
                $update['sold_at'] = now();
            }
        }
        // Clear sold_at if status changes away from verkauft
        if (isset($update['realty_status']) && strtolower($update['realty_status']) !== 'verkauft') {
            $update['sold_at'] = null;
        }

        $update['updated_at'] = now();

        // Altzustand laden, Update ausfuehren, dann kundensichtbare Aktivitaet loggen.
        $oldRow = (array) (DB::table('properties')->where('id', $id)->first() ?: []);
        DB::table('properties')->where('id', $id)->update($update);
        app(PropertyActivityLogger::class)->logFieldChanges($id, $oldRow, $update);

        return response()->json(['success' => true]);
    }

    private function getPropertyFiles(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        // Property files (Expose, BaB etc.)
        $files = DB::table('property_files')
            ->where('property_id', $propertyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $result = [];
        foreach ($files as $f) {
            $result[] = [
                'id' => $f->id,
                'label' => $f->label,
                'filename' => $f->filename,
                'path' => $f->path,
                'url' => '/storage/' . $f->path,
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
                'is_website_download' => (bool) ($f->is_website_download ?? false),
                'is_ava' => (bool) ($f->is_ava ?? false),
                'source' => 'property_files',
            ];
        }

        // Portal documents (Nebenkosten, allgemeine Dokumente etc.)
        $docs = DB::table('portal_documents')
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->get();
        foreach ($docs as $d) {
            $result[] = [
                'id' => 'doc_' . $d->id,
                'label' => $d->description ?: $d->original_name,
                'filename' => $d->original_name,
                'path' => 'documents/' . $d->property_id . '/' . $d->filename,
                'url' => '/storage/documents/' . $d->property_id . '/' . $d->filename,
                'mime_type' => $d->mime_type,
                'file_size' => $d->file_size,
                'source' => 'portal_documents',
            ];
        }


        // Global files (Allgemeine Dokumente - available for all properties)
        $globalFiles = DB::table('global_files')->orderBy('id')->get();
        foreach ($globalFiles as $g) {
            $result[] = [
                'id' => 'global_' . $g->id,
                'label' => $g->label ?: ($g->original_name ?: $g->filename),
                'filename' => $g->original_name ?: $g->filename,
                'path' => $g->path,
                'url' => '/storage/' . $g->path,
                'mime_type' => $g->mime_type,
                'file_size' => $g->file_size,
                'source' => 'global_files',
            ];
        }
        return response()->json(['files' => $result]);
    }



    private function contactSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim($request->query("q", ""));
        if (strlen($q) < 2) return response()->json(["contacts" => []]);

        $s = "%{$q}%";
        $contacts = DB::select("
            SELECT id, full_name, email, phone, aliases
            FROM contacts
            WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR aliases LIKE ?
            ORDER BY full_name ASC
            LIMIT 10
        ", [$s, $s, $s, $s]);

        $results = array_map(function($c) {
            $c = (array) $c;
            $c["aliases"] = json_decode($c["aliases"] ?? "[]", true) ?: [];
            return $c;
        }, $contacts);

        return response()->json(["contacts" => $results], 200, [], JSON_UNESCAPED_UNICODE);
    }



    private function getAutoFollowupSettings(): \Illuminate\Http\JsonResponse
    {
        $settings = DB::table('settings')
            ->whereIn('key', ['auto_followup_stage1_enabled', 'auto_followup_stage2_enabled', 'auto_followup_account_id'])
            ->pluck('value', 'key');

        $accounts = DB::table('email_accounts')
            ->select('id', 'email_address', 'from_name')
            ->get();

        return response()->json([
            'stage1_enabled' => ($settings['auto_followup_stage1_enabled'] ?? '0') === '1',
            'stage2_enabled' => ($settings['auto_followup_stage2_enabled'] ?? '0') === '1',
            'account_id'     => (int)($settings['auto_followup_account_id'] ?? 0),
            'accounts'       => $accounts,
        ]);
    }

    private function saveAutoFollowupSettings(): \Illuminate\Http\JsonResponse
    {
        $input = request()->json()->all();

        DB::table('settings')->upsert([
            ['key' => 'auto_followup_stage1_enabled', 'value' => !empty($input['stage1_enabled']) ? '1' : '0'],
            ['key' => 'auto_followup_stage2_enabled', 'value' => !empty($input['stage2_enabled']) ? '1' : '0'],
            ['key' => 'auto_followup_account_id',     'value' => (string)($input['account_id'] ?? '')],
        ], ['key'], ['value']);

        return response()->json(['success' => true]);
    }

    /**
     * Multi-User: Resolve the authenticated admin user.
     * Prefers Auth::user() (session from browser). Falls back to first admin if called externally.
     */
    private function resolveAdminUser(): ?object
    {
        $user = \Auth::user();
        if ($user) return $user;

        // Fallback fuer externe API-Aufrufe ohne Session (Email Manager, MCP)
        return DB::table('users')->where('user_type', 'admin')->first();
    }

    /**
     * Import a single expose PDF: AI extracts property data, creates property + stores file.
     */

    /**
     * Analyze a file with AI and return extracted data WITHOUT creating a property.
     * Used by the "Neues Objekt" wizard to pre-fill fields from an uploaded expose.
     */
    private function analyzeFile(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'file required'], 400);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xlsx', 'xls'])) {
            return response()->json(['error' => 'Nur PDF, DOC, DOCX, XLS, XLSX, JPG, PNG erlaubt'], 400);
        }

        $tempPath = $file->getPathname();
        $ai = app(\App\Services\AnthropicService::class);


        // Build the prompt (same as importExpose but more complete)
        $fieldsJson = json_encode(\App\Http\Controllers\Admin\PropertySettingsController::getFieldLabels(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $prompt = "Analysiere dieses Immobilien-Expose und extrahiere ALLE Daten.\n\n";
        $prompt .= "ERLAUBTE FELD-KEYS:\n{$fieldsJson}\n\n";
        $prompt .= "STRIKTE REGELN:\n";
        $prompt .= "- Verwende AUSSCHLIEssLICH die gelisteten Feld-Keys\n";
        $prompt .= "- BESCHREIBUNGEN: Den VOLLSTAENDIGEN Originaltext uebernehmen\n";
        $prompt .= "- Numerische Felder: NUR Zahlen (z.B. 85.5 statt 85,5 m2)\n";
        $prompt .= "- Boolean-Felder (has_*): true oder false\n";
        $prompt .= "- property_category: newbuild|house|apartment|land\n";
        $prompt .= "- ENERGIEWERTE NIEMALS leer lassen wenn vorhanden: energy_hwb, energy_fgee, energy_class, heating\n";
        $prompt .= "- operating_costs, reserve_fund, year_built als Zahlen\n";
        $prompt .= "- Kontakt: contact_person, contact_phone, contact_email\n";
        $prompt .= "- Bautraeger: builder_company, property_manager\n";
        $prompt .= "- Einzelflaechen: area_balcony, area_terrace, area_garden\n";
        $prompt .= "- Suche SEHR GRUENDLICH im gesamten Dokument!\n\n";
        $prompt .= "Bei Neubauprojekten: Erkenne ALLE Wohnungen/Einheiten:\n";
        $prompt .= "- Jede Einheit: unit_number, unit_type, floor, area_m2, rooms, price, status, balcony_terrace_m2, garden_m2\n";
        $prompt .= "- Durchgestrichene Preise = VERKAUFT\n";
        $prompt .= "- parking: Stellplaetze separat mit unit_number, unit_type, price\n\n";
        $prompt .= "Antworte NUR mit JSON:\n";
        $prompt .= "{\n  \"fields\": { ... },\n  \"units\": [ ... ],\n  \"parking\": [ ... ],\n  \"confidence\": \"high|medium|low\"\n}";

        // Try Vision API for PDFs and images
        $images = [];
        if ($ext === 'pdf') {
            $tmpDir = '/tmp/analyze_file_' . time();
            @mkdir($tmpDir, 0755, true);
            $pageCount = intval(shell_exec('pdfinfo ' . escapeshellarg($tempPath) . ' 2>/dev/null | grep "^Pages:" | awk "{print \$2}"') ?: 25);
            exec('pdftoppm -png -r 120 -l ' . min($pageCount, 30) . ' ' . escapeshellarg($tempPath) . ' ' . $tmpDir . '/page 2>/dev/null');
            $pageFiles = glob("$tmpDir/page-*.png");
            sort($pageFiles);
            // Select pages: first 2 + from 40% onward (where data tables usually are)
            $selected = [];
            $total = count($pageFiles);
            for ($i = 0; $i < min(2, $total); $i++) $selected[] = $pageFiles[$i];
            $startFrom = max(2, intval($total * 0.4));
            for ($i = $startFrom; $i < $total; $i++) $selected[] = $pageFiles[$i];
            foreach ($selected as $pf) {
                $imgData = base64_encode(file_get_contents($pf));
                $images[] = ['data' => $imgData, 'media_type' => 'image/png'];
            }
            array_map('unlink', glob("$tmpDir/*"));
            @rmdir($tmpDir);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $imgData = base64_encode(file_get_contents($tempPath));
            $mt = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $images[] = ['data' => $imgData, 'media_type' => $mt];
        } elseif (in_array($ext, ['xlsx', 'xls', 'doc', 'docx'])) {
            // Convert Excel/Word to text via Python
            if (in_array($ext, ['xlsx', 'xls'])) {
                $pyScript = <<<'PY'
import sys, openpyxl
wb = openpyxl.load_workbook(sys.argv[1], data_only=True)
for sheet in wb.sheetnames:
    ws = wb[sheet]
    print(f"=== {sheet} ===")
    for row in ws.iter_rows(values_only=True):
        vals = [str(v) if v is not None else "" for v in row]
        if any(vals):
            print("\t".join(vals))
PY;
                $pyTmp = tempnam('/tmp', 'xlsx_') . '.py';
                file_put_contents($pyTmp, $pyScript);
                $excelText = shell_exec('python3 ' . escapeshellarg($pyTmp) . ' ' . escapeshellarg($tempPath) . ' 2>/dev/null') ?: '';
                @unlink($pyTmp);
            } else {
                // DOC/DOCX: try libreoffice or antiword
                $excelText = shell_exec('libreoffice --headless --convert-to txt --outdir /tmp ' . escapeshellarg($tempPath) . ' 2>/dev/null && cat /tmp/' . pathinfo($tempPath, PATHINFO_FILENAME) . '.txt 2>/dev/null') ?: '';
            }
            if (strlen(trim($excelText)) > 20) {
                $textPrompt = "TABELLENINHALT:\n" . mb_substr($excelText, 0, 15000) . "\n\n" . $prompt;
                $result = $ai->chatJson("Du bist ein praeziser Immobilien-Datenextraktions-Agent. Analysiere diese Tabelle/Dokument sehr gruendlich.", $textPrompt, 8000);
            }
        }

        $images = array_slice($images, 0, 20);

        


        try {
            if (!isset($result)) $result = null;

            if ($result) {
                // Already parsed by Excel/Word handler above
            } elseif (count($images) > 0) {
                $result = $ai->chatWithImagesJson(
                    "Du bist ein praeziser Immobilien-Datenextraktions-Agent fuer den oesterreichischen Markt.",
                    $prompt,
                    $images,
                    16000
                );
            }

            // Fallback: pdftotext
            if (!$result && $ext === 'pdf') {
                $pdfText = shell_exec("pdftotext " . escapeshellarg($tempPath) . " - 2>/dev/null") ?: '';
                if (strlen(trim($pdfText)) > 100) {
                    $textPrompt = "EXPOSE-TEXT:\n" . mb_substr($pdfText, 0, 12000) . "\n\n" . $prompt;
                    $result = $ai->chatJson("Du bist ein praeziser Immobilien-Datenextraktions-Agent.", $textPrompt, 8000);
                }
            }

            if (!$result) {
                return response()->json(['error' => 'KI konnte keine Daten extrahieren. Bitte ein text-basiertes PDF verwenden.'], 400);
            }

            return response()->json([
                'success' => true,
                'fields' => $result['fields'] ?? [],
                'units' => $result['units'] ?? [],
                'parking' => $result['parking'] ?? [],
                'confidence' => $result['confidence'] ?? 'unknown',
                'filename' => $file->getClientOriginalName(),
            ]);

        } catch (\Throwable $e) {
            \Log::error("analyze_file failed: " . $e->getMessage());
            return response()->json(['error' => 'KI-Analyse fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    private function importExpose(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('expose')) {
            return response()->json(['error' => 'expose file required'], 400);
        }

        $file = $request->file('expose');
        $customName = trim($request->input('custom_name', ''));
        $brokerId = \Auth::id() ?: 1;

        // Validate file
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xlsx', 'xls'])) {
            return response()->json(['error' => 'Nur PDF, DOC, DOCX, XLS, XLSX, JPG, PNG erlaubt'], 400);
        }

        $tempPath = $file->getPathname();
        $ai = app(\App\Services\AnthropicService::class);

        // Build comprehensive prompt
        $prompt = "Analysiere dieses Immobilien-Dokument und extrahiere ALLE Daten.\n\n";
        $prompt .= "Antworte NUR mit validem JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"project_name\": \"Projektname/Marketingname\",\n";
        $prompt .= "  \"title\": \"Inseratstitel\",\n";
        $prompt .= "  \"address\": \"Strasse + Hausnummer\",\n";
        $prompt .= "  \"city\": \"Stadt/Ort\",\n";
        $prompt .= "  \"zip\": \"PLZ\",\n";
        $prompt .= "  \"type\": \"Eigentumswohnung|Haus|Neubauprojekt|Grundstueck|Sonstiges\",\n";
        $prompt .= "  \"property_category\": \"apartment|house|newbuild|land\",\n";
        $prompt .= "  \"purchase_price\": null,\n";
        $prompt .= "  \"total_purchase_price\": null,\n";
        $prompt .= "  \"object_subtype\": null,\n";
        $prompt .= "  \"marketing_type\": \"kauf|miete\",\n";
        $prompt .= "  \"rooms_amount\": null,\n";
        $prompt .= "  \"total_area\": null,\n";
        $prompt .= "  \"living_area\": null,\n";
        $prompt .= "  \"free_area\": null,\n";
        $prompt .= "  \"construction_year\": null,\n";
        $prompt .= "  \"year_renovated\": null,\n";
        $prompt .= "  \"heating\": null,\n";
        $prompt .= "  \"energy_certificate\": null,\n";
        $prompt .= "  \"heating_demand_value\": null,\n";
        $prompt .= "  \"energy_type\": null,\n";
        $prompt .= "  \"heating_demand_class\": null,\n";
        $prompt .= "  \"energy_efficiency_value\": null,\n";
        $prompt .= "  \"operating_costs\": null,\n";
        $prompt .= "  \"maintenance_reserves\": null,\n";
        $prompt .= "  \"realty_description\": \"VOLLSTAENDIGER Beschreibungstext - JEDES WORT uebernehmen!\",\n";
        $prompt .= "  \"location_description\": \"Lagebeschreibung vollstaendig\",\n";
        $prompt .= "  \"equipment_description\": \"Ausstattungsbeschreibung vollstaendig\",\n";
        $prompt .= "  \"other_description\": \"Sonstige Beschreibung\",\n";
        $prompt .= "  \"highlights\": \"Besondere Merkmale, kommasepariert\",\n";
        $prompt .= "  \"total_units\": null,\n";
        $prompt .= "  \"owner_name\": null,\n";
        $prompt .= "  \"owner_email\": null,\n";
        $prompt .= "  \"owner_phone\": null,\n";
        $prompt .= "  \"contact_person\": null,\n";
        $prompt .= "  \"contact_phone\": null,\n";
        $prompt .= "  \"contact_email\": null,\n";
        $prompt .= "  \"commission_percent\": null,\n";
        $prompt .= "  \"commission_note\": null,\n";
        $prompt .= "  \"buyer_commission_percent\": null,\n";
        $prompt .= "  \"buyer_commission_text\": null,\n";
        $prompt .= "  \"builder_company\": null,\n";
        $prompt .= "  \"property_manager\": null,\n";
        $prompt .= "  \"available_from\": null,\n";
        $prompt .= "  \"has_balcony\": false,\n";
        $prompt .= "  \"has_terrace\": false,\n";
        $prompt .= "  \"has_garden\": false,\n";
        $prompt .= "  \"has_elevator\": false,\n";
        $prompt .= "  \"has_basement\": false,\n";
        $prompt .= "  \"has_fitted_kitchen\": false,\n";
        $prompt .= "  \"has_barrier_free\": false,\n";
        $prompt .= "  \"parking_type\": null,\n";
        $prompt .= "  \"parking_spaces\": null,\n";
        $prompt .= "  \"garage_spaces\": null,\n";
        $prompt .= "  \"area_balcony\": null,\n";
        $prompt .= "  \"area_terrace\": null,\n";
        $prompt .= "  \"area_garden\": null,\n";
        $prompt .= "  \"floor_number\": null,\n";
        $prompt .= "  \"floor_count\": null,\n";
        $prompt .= "  \"ref_id_suggestion\": \"Kau-Typ-Ort-01\",\n";
        $prompt .= "  \"units\": [{\n";
        $prompt .= "    \"unit_number\": \"TOP 1\",\n";
        $prompt .= "    \"unit_type\": \"2-Zimmer Wohnung\",\n";
        $prompt .= "    \"rooms\": 2,\n";
        $prompt .= "    \"area_m2\": 55.5,\n";
        $prompt .= "    \"floor\": 0,\n";
        $prompt .= "    \"price\": 250000,\n";
        $prompt .= "    \"status\": \"frei|reserviert|verkauft\",\n";
        $prompt .= "    \"balcony_terrace_m2\": 12.5,\n";
        $prompt .= "    \"garden_m2\": 0\n";
        $prompt .= "  }],\n";
        $prompt .= "  \"parking\": [{\n";
        $prompt .= "    \"unit_number\": \"Stellplatz 1\",\n";
        $prompt .= "    \"unit_type\": \"Tiefgarage|Carport|Freistellplatz\",\n";
        $prompt .= "    \"price\": 25000\n";
        $prompt .= "  }]\n";
        $prompt .= "}\n\n";
        $prompt .= "KRITISCHE REGELN:\n";
        $prompt .= "- BESCHREIBUNGEN: Den VOLLSTAENDIGEN Originaltext uebernehmen. NIEMALS kuerzen oder zusammenfassen!\n";
        $prompt .= "- ENERGIEWERTE: HWB (heating_demand_value als Zahl), fGEE (energy_efficiency_value als Zahl), Energieklasse (heating_demand_class), Heizung (heating). NIEMALS leer lassen wenn im Dokument vorhanden!\n";
        $prompt .= "- purchase_price: Bei Einzelobjekt = Kaufpreis. Bei Neubauprojekt = NIEDRIGSTER Einheitspreis.\n";
        $prompt .= "- total_purchase_price: Bei Neubauprojekt = Gesamtkaufpreis ALLER Einheiten zusammen (Summe). Bei Einzelobjekt = null.\n";
        $prompt .= "- Bei Neubauprojekten ALLE Einheiten einzeln mit TOP-Nummer, Zimmer, Flaeche, Stockwerk, Preis, Status.\n";
        $prompt .= "- Durchgestrichene Preise/Einheiten = status 'verkauft'\n";
        $prompt .= "- parking: Stellplaetze/Garagen SEPARAT auflisten\n";
        $prompt .= "- Numerische Felder: NUR Zahlen (z.B. 85.5, nicht '85,5 m2')\n";
        $prompt .= "- property_category: 'newbuild' wenn Neubauprojekt mit mehreren Einheiten!\n";
        $prompt .= "- Felder die nicht im Dokument vorkommen: null setzen\n";

        // Extract content based on file type
        $result = null;
        $images = [];

        try {
            if (in_array($ext, ['xlsx', 'xls'])) {
                // EXCEL: Extract via Python openpyxl
                $pyScript = <<<'PY'
import sys, openpyxl
wb = openpyxl.load_workbook(sys.argv[1], data_only=True)
for sheet in wb.sheetnames:
    ws = wb[sheet]
    print(f"=== {sheet} ===")
    for row in ws.iter_rows(values_only=True):
        vals = [str(v) if v is not None else "" for v in row]
        if any(vals):
            print("\t".join(vals))
PY;
                $pyTmp = tempnam('/tmp', 'xlsx_') . '.py';
                file_put_contents($pyTmp, $pyScript);
                $excelText = shell_exec('python3 ' . escapeshellarg($pyTmp) . ' ' . escapeshellarg($tempPath) . ' 2>/dev/null') ?: '';
                @unlink($pyTmp);

                if (strlen(trim($excelText)) > 20) {
                    $textPrompt = "TABELLENINHALT (Excel-Datei):\n" . mb_substr($excelText, 0, 15000) . "\n\n" . $prompt;
                    $result = $ai->chatJson(
                        "Du bist ein praeziser Immobilien-Datenextraktions-Agent fuer den oesterreichischen Markt. Analysiere diese Excel-Tabelle sehr gruendlich und extrahiere ALLE Daten inkl. Einheiten, Energiewerte, Beschreibungen.",
                        $textPrompt, 16000
                    );
                    \Log::info("importExpose: Excel parsed, text length=" . strlen($excelText));
                } else {
                    \Log::warning("importExpose: Excel file too short or empty");
                }

            } elseif ($ext === 'pdf') {
                // PDF: Try Vision API first (handles image-based PDFs)
                $tmpDir = '/tmp/import_expose_' . time();
                @mkdir($tmpDir, 0755, true);
                $pageCount = intval(shell_exec('pdfinfo ' . escapeshellarg($tempPath) . ' 2>/dev/null | grep "^Pages:" | awk "{print \$2}"') ?: 25);
                exec('pdftoppm -png -r 120 -l ' . min($pageCount, 30) . ' ' . escapeshellarg($tempPath) . ' ' . $tmpDir . '/page 2>/dev/null');
                $pageFiles = glob("$tmpDir/page-*.png");
                sort($pageFiles);

                $selected = [];
                $total = count($pageFiles);
                // Take first 3 pages + everything from 30% onward (where data tables usually are)
                for ($i = 0; $i < min(3, $total); $i++) $selected[] = $pageFiles[$i];
                $startFrom = max(3, intval($total * 0.3));
                for ($i = $startFrom; $i < $total; $i++) $selected[] = $pageFiles[$i];

                foreach ($selected as $pf) {
                    $imgData = base64_encode(file_get_contents($pf));
                    $images[] = ['data' => $imgData, 'media_type' => 'image/png'];
                }
                \Log::info("importExpose: PDF {$total} pages, {$i} selected for vision");
                array_map('unlink', glob("$tmpDir/*"));
                @rmdir($tmpDir);

                if (count($images) > 0) {
                    $images = array_slice($images, 0, 20);
                    $result = $ai->chatWithImagesJson(
                        "Du bist ein praeziser Immobilien-Datenextraktions-Agent fuer den oesterreichischen Markt. Analysiere das Expose sehr gruendlich.",
                        $prompt, $images, 16000
                    );
                    \Log::info("importExpose: Vision API used with " . count($images) . " pages");
                }

                // Fallback: pdftotext
                if (!$result) {
                    $pdfText = shell_exec("pdftotext " . escapeshellarg($tempPath) . " - 2>/dev/null") ?: '';
                    if (strlen(trim($pdfText)) > 100) {
                        $textPrompt = "EXPOSE-TEXT:\n" . mb_substr($pdfText, 0, 15000) . "\n\n" . $prompt;
                        $result = $ai->chatJson(
                            "Du bist ein praeziser Immobilien-Datenextraktions-Agent.",
                            $textPrompt, 16000
                        );
                        \Log::info("importExpose: pdftotext fallback used");
                    }
                }

            } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                // Image: direct vision
                $imgData = base64_encode(file_get_contents($tempPath));
                $mt = $ext === 'png' ? 'image/png' : 'image/jpeg';
                $images[] = ['data' => $imgData, 'media_type' => $mt];
                $result = $ai->chatWithImagesJson(
                    "Du bist ein praeziser Immobilien-Datenextraktions-Agent fuer den oesterreichischen Markt.",
                    $prompt, $images, 16000
                );

            } elseif (in_array($ext, ['doc', 'docx'])) {
                // Word: convert to text
                $docText = shell_exec('libreoffice --headless --convert-to txt --outdir /tmp ' . escapeshellarg($tempPath) . ' 2>/dev/null && cat /tmp/' . pathinfo($tempPath, PATHINFO_FILENAME) . '.txt 2>/dev/null') ?: '';
                if (strlen(trim($docText)) > 50) {
                    $textPrompt = "DOKUMENT-TEXT:\n" . mb_substr($docText, 0, 15000) . "\n\n" . $prompt;
                    $result = $ai->chatJson(
                        "Du bist ein praeziser Immobilien-Datenextraktions-Agent.",
                        $textPrompt, 16000
                    );
                }
            }

            if (!$result || !is_array($result)) {
                \Log::warning("importExpose: No result from AI for file " . $file->getClientOriginalName());
                return response()->json(['error' => 'KI konnte keine Daten aus dem Dokument extrahieren. Bitte eine andere Datei verwenden.'], 400);
            }

            $data = $result;
            \Log::info("importExpose: AI returned " . count($data) . " top-level keys, units=" . count($data['units'] ?? []) . ", parking=" . count($data['parking'] ?? []));

        } catch (\Throwable $e) {
            \Log::error("importExpose AI failed: " . $e->getMessage());
            return response()->json(['error' => 'KI-Analyse fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        // Create property
        $refId = $data['ref_id_suggestion'] ?? ('Import-' . time());
        $address = $data['address'] ?? 'Unbekannt';
        $city = $data['city'] ?? '';

        // Check if property with same address already exists
        $existing = DB::selectOne("SELECT id, ref_id FROM properties WHERE address = ? AND city = ?", [$address, $city]);
        if ($existing) {
            $propertyId = $existing->id;
            $refId = $existing->ref_id;
        } else {
            $propertyId = DB::table('properties')->insertGetId([
                'ref_id' => $refId,
                'project_name' => $data['project_name'] ?? $customName ?: null,
                'title' => $data['title'] ?? $customName ?: null,
                'address' => $address,
                'city' => $city,
                'zip' => $data['zip'] ?? null,
                'object_type' => $data['type'] ?? 'Sonstiges',
                'property_category' => $data['property_category'] ?? null,
                'object_subtype' => $data['object_subtype'] ?? null,
                'marketing_type' => $data['marketing_type'] ?? 'kauf',
                'purchase_price' => $data['purchase_price'] ?? $data['price'] ?? null,
                'total_units' => $data['total_units'] ?? null,
                'total_area' => $data['total_area'] ?? $data['size_m2'] ?? null,
                'living_area' => $data['living_area'] ?? $data['area_living'] ?? null,
                'free_area' => $data['free_area'] ?? $data['area_land'] ?? null,
                'rooms_amount' => $data['rooms_amount'] ?? $data['rooms'] ?? null,
                'construction_year' => $data['construction_year'] ?? $data['year_built'] ?? null,
                'year_renovated' => $data['year_renovated'] ?? null,
                'heating' => $data['heating'] ?? null,
                'energy_certificate' => $data['energy_certificate'] ?? null,
                'heating_demand_value' => $data['heating_demand_value'] ?? $data['energy_hwb'] ?? null,
                'energy_type' => $data['energy_type'] ?? null,
                'heating_demand_class' => $data['heating_demand_class'] ?? $data['energy_class'] ?? null,
                'energy_efficiency_value' => $data['energy_efficiency_value'] ?? $data['energy_fgee'] ?? null,
                'operating_costs' => $data['operating_costs'] ?? null,
                'maintenance_reserves' => $data['maintenance_reserves'] ?? null,
                'realty_description' => $data['realty_description'] ?? $data['description'] ?? null,
                'location_description' => $data['location_description'] ?? null,
                'equipment_description' => $data['equipment_description'] ?? null,
                'other_description' => $data['other_description'] ?? null,
                'highlights' => $data['highlights'] ?? null,
                'owner_name' => $data['owner_name'] ?? null,
                'owner_email' => $data['owner_email'] ?? null,
                'owner_phone' => $data['owner_phone'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'commission_percent' => $data['commission_percent'] ?? $data['commission_total'] ?? null,
                'commission_note' => $data['commission_note'] ?? null,
                'buyer_commission_percent' => $data['buyer_commission_percent'] ?? null,
                'buyer_commission_text' => $data['buyer_commission_text'] ?? null,
                'builder_company' => $data['builder_company'] ?? null,
                'property_manager' => $data['property_manager'] ?? null,
                'available_from' => $data['available_from'] ?? null,
                'has_balcony' => !empty($data['has_balcony']) ? 1 : 0,
                'has_terrace' => !empty($data['has_terrace']) ? 1 : 0,
                'has_garden' => !empty($data['has_garden']) ? 1 : 0,
                'has_elevator' => !empty($data['has_elevator']) ? 1 : 0,
                'has_basement' => !empty($data['has_basement']) ? 1 : 0,
                'has_fitted_kitchen' => !empty($data['has_fitted_kitchen']) ? 1 : 0,
                'has_barrier_free' => !empty($data['has_barrier_free']) ? 1 : 0,
                'parking_type' => $data['parking_type'] ?? null,
                'parking_spaces' => $data['parking_spaces'] ?? null,
                'garage_spaces' => $data['garage_spaces'] ?? null,
                'area_balcony' => $data['area_balcony'] ?? null,
                'area_terrace' => $data['area_terrace'] ?? null,
                'area_garden' => $data['area_garden'] ?? null,
                'floor_number' => $data['floor_number'] ?? null,
                'floor_count' => $data['floor_count'] ?? null,
                'realty_status' => 'aktiv',
                'broker_id' => $brokerId,
                'customer_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \Log::info("importExpose: Created property {$propertyId} ({$refId})");
        }

        // Store file
        $dir = 'property_files/' . $propertyId;
        $filename = $customName
            ? preg_replace('/[^a-zA-Z0-9_\-\.\x{00C0}-\x{024F}]/u', '_', $customName) . '.' . $ext
            : $file->getClientOriginalName();
        $storedPath = $file->storeAs($dir, $filename, 'public');

        DB::table('property_files')->insert([
            'property_id' => $propertyId,
            'filename' => $filename,
            'path' => $storedPath,
            'label' => in_array($ext, ['xlsx', 'xls']) ? 'Preisliste' : 'Expose',
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'created_at' => now(),
        ]);

        DB::table('properties')->where('id', $propertyId)->update([
            'expose_path' => $storedPath,
        ]);

        // Create units
        $unitsCreated = 0;
        if (!empty($data['units']) && is_array($data['units'])) {
            foreach ($data['units'] as $unit) {
                $unitNumber = $unit['unit_number'] ?? null;
                if (!$unitNumber) continue;
                $existingUnit = DB::selectOne("SELECT id FROM property_units WHERE property_id = ? AND unit_number = ?", [$propertyId, $unitNumber]);
                if (!$existingUnit) {
                    DB::table('property_units')->insert([
                        'property_id' => $propertyId,
                        'unit_number' => $unitNumber,
                        'unit_type' => $unit['unit_type'] ?? null,
                        'rooms' => $unit['rooms'] ?? $unit['rooms_amount'] ?? null,
                        'area_m2' => $unit['area_m2'] ?? null,
                        'floor' => $unit['floor'] ?? 0,
                        'price' => $unit['price'] ?? null,
                        'status' => $unit['status'] ?? 'frei',
                        'balcony_terrace_m2' => $unit['balcony_terrace_m2'] ?? null,
                        'garden_m2' => $unit['garden_m2'] ?? null,
                        'is_parking' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $unitsCreated++;
                }
            }
        }

        // Create parking
        $parkingCreated = 0;
        if (!empty($data['parking']) && is_array($data['parking'])) {
            foreach ($data['parking'] as $p) {
                $pNum = $p['unit_number'] ?? ('Stellplatz ' . ($parkingCreated + 1));
                $existingP = DB::selectOne("SELECT id FROM property_units WHERE property_id = ? AND unit_number = ?", [$propertyId, $pNum]);
                if (!$existingP) {
                    DB::table('property_units')->insert([
                        'property_id' => $propertyId,
                        'unit_number' => $pNum,
                        'unit_type' => $p['unit_type'] ?? 'Stellplatz',
                        'price' => $p['price'] ?? null,
                        'status' => $p['status'] ?? 'frei',
                        'is_parking' => 1,
                        'floor' => -1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $parkingCreated++;
                }
            }
        }

        // If newbuild with total_units but no units were extracted, create placeholder units
        if (($data['property_category'] ?? '') === 'newbuild' && $unitsCreated === 0) {
            $totalUnits = intval($data['total_units'] ?? 0);
            if ($totalUnits > 0 && $totalUnits <= 100) {
                $objectType = $data['type'] ?? $data['object_type'] ?? 'Wohnung';
                $unitLabel = 'Einheit';
                if (stripos($objectType, 'Reihenhaus') !== false || stripos($objectType, 'Haus') !== false) $unitLabel = 'Haus';
                elseif (stripos($objectType, 'Wohnung') !== false || stripos($objectType, 'Eigentum') !== false) $unitLabel = 'TOP';

                for ($i = 1; $i <= $totalUnits; $i++) {
                    $unitNum = $unitLabel . ' ' . $i;
                    $existing2 = DB::selectOne("SELECT id FROM property_units WHERE property_id = ? AND unit_number = ?", [$propertyId, $unitNum]);
                    if (!$existing2) {
                        DB::table('property_units')->insert([
                            'property_id' => $propertyId,
                            'unit_number' => $unitNum,
                            'unit_type' => $objectType,
                            'status' => 'frei',
                            'is_parking' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $unitsCreated++;
                    }
                }
            }
        }

        // Recalc unit stats
        $totalUnitsCount = DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->count();
        DB::table('properties')->where('id', $propertyId)->update(['total_units' => $totalUnitsCount ?: ($data['total_units'] ?? null)]);

        // Store knowledge base entry
        $knowledgeCount = 0;
        $knowledgeText = '';
        if (!empty($data['realty_description'])) $knowledgeText .= $data['realty_description'] . "\n\n";
        if (!empty($data['location_description'])) $knowledgeText .= "Lage: " . $data['location_description'] . "\n\n";
        if (!empty($data['equipment_description'])) $knowledgeText .= "Ausstattung: " . $data['equipment_description'] . "\n\n";
        if (!empty($data['highlights'])) $knowledgeText .= "Highlights: " . $data['highlights'] . "\n";
        if (strlen(trim($knowledgeText)) > 20) {
            DB::table('property_knowledge')->insert([
                'property_id' => $propertyId,
                'source_type' => 'file',
                'source_name' => $filename,
                'content' => trim($knowledgeText),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $knowledgeCount = 1;
        }

        \Log::info("importExpose: Done. property={$propertyId}, units={$unitsCreated}, parking={$parkingCreated}, knowledge={$knowledgeCount}");

        return response()->json([
            'success' => true,
            'property_id' => $propertyId,
            'ref_id' => $refId,
            'type' => $data['type'] ?? null,
            'purchase_price' => $data['purchase_price'] ?? $data['price'] ?? null,
            'rooms_amount' => $data['rooms_amount'] ?? $data['rooms'] ?? null,
            'total_area' => $data['total_area'] ?? $data['size_m2'] ?? null,
            'units_created' => $unitsCreated,
            'parking_created' => $parkingCreated,
            'expose_file' => $filename,
            'extracted_data' => $data,
            'is_existing' => !!$existing,
            'knowledge_entries' => $knowledgeCount,
        ]);
    }


    // ------
    private function brokerRanking(Request $request): \Illuminate\Http\JsonResponse
    {
        $period = $request->query('period', '30');
        $since = date('Y-m-d', strtotime("-{$period} days"));
        $normSurname = \App\Helpers\StakeholderHelper::normSHSurname('a.stakeholder');
        $partnerExclude = \App\Helpers\StakeholderHelper::partnerExcludeFilter('a.stakeholder');

        $users = DB::select("SELECT id, name FROM users WHERE user_type IN ('admin','makler') ORDER BY id");
        $ranking = [];

        foreach ($users as $u) {
            $propIds = DB::table('properties')->where('broker_id', $u->id)->pluck('id')->toArray();
            $propPlaceholders = count($propIds) ? implode(',', array_fill(0, count($propIds), '?')) : '0';

            // Anfragen
            $anfragen = (int) DB::selectOne("
                SELECT COUNT(*) as cnt FROM portal_emails pe
                JOIN email_accounts ea ON ea.id = pe.account_id
                WHERE ea.user_id = ? AND pe.direction = 'inbound'
                AND pe.category IN ('anfrage','besichtigung','kaufanbot','email-in')
                AND pe.email_date >= ?
            ", [$u->id, $since])->cnt;

            // Kaufanbote: same logic as KaufanbotController (unique surname, partner excluded)
            $kaufanbote = 0;
            if (count($propIds)) {
                $kaufRows = DB::select("
                    SELECT DISTINCT {$normSurname} as skey FROM activities a
                    WHERE a.property_id IN ({$propPlaceholders})
                    AND a.category = 'kaufanbot' AND {$partnerExclude}
                ", $propIds);
                $kaufanbote = count($kaufRows);
            }

            // Besichtigungen
            $besichtigungen = 0;
            if (count($propIds)) {
                $besichtigungen = (int) DB::selectOne("
                    SELECT COUNT(*) as cnt FROM activities a
                    WHERE a.property_id IN ({$propPlaceholders})
                    AND a.category = 'besichtigung' AND a.activity_date >= ?
                ", array_merge($propIds, [$since]))->cnt;
            }

            // Verkaufsvolumen
            $verkaufsvolumen = 0;
            if (count($propIds)) {
                $verkaufsvolumen = (float) DB::selectOne("
                    SELECT COALESCE(SUM(price), 0) as vol FROM property_units
                    WHERE property_id IN ({$propPlaceholders}) AND status = 'verkauft'
                ", $propIds)->vol;
            }

            // Objekte (nicht inaktiv)
            $objekte = DB::table('properties')->where('broker_id', $u->id)
                ->where(function($q) { $q->where('realty_status', '!=', 'inaktiv')->orWhereNull('realty_status'); })
                ->count();

            // Gesendet
            $gesendet = (int) DB::selectOne("
                SELECT COUNT(*) as cnt FROM portal_emails pe
                JOIN email_accounts ea ON ea.id = pe.account_id
                WHERE ea.user_id = ? AND pe.direction = 'outbound' AND pe.email_date >= ?
            ", [$u->id, $since])->cnt;

            // Antwortzeit
            $antwortzeit = DB::selectOne("
                SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, pe.email_date, (
                    SELECT MIN(pe2.email_date) FROM portal_emails pe2
                    JOIN email_accounts ea2 ON ea2.id = pe2.account_id
                    WHERE ea2.user_id = ? AND pe2.direction = 'outbound'
                    AND pe2.email_date > pe.email_date AND pe2.stakeholder = pe.stakeholder
                ))), 1) as avg_h
                FROM portal_emails pe
                JOIN email_accounts ea ON ea.id = pe.account_id
                WHERE ea.user_id = ? AND pe.direction = 'inbound' AND pe.email_date >= ?
                AND pe.category IN ('anfrage','besichtigung','kaufanbot','email-in')
            ", [$u->id, $u->id, $since]);

            $ranking[] = [
                'id' => $u->id, 'name' => $u->name,
                'anfragen' => $anfragen, 'kaufanbote' => $kaufanbote,
                'besichtigungen' => $besichtigungen, 'verkaufsvolumen' => $verkaufsvolumen,
                'objekte' => $objekte, 'gesendet' => $gesendet,
                'avg_antwortzeit_h' => $antwortzeit->avg_h ?? null,
            ];
        }

        return response()->json(['ranking' => $ranking, 'period' => $period, 'since' => $since]);
    }

    /** List all brokers/makler (admin only).
     */
    private function listBrokers(Request $request): \Illuminate\Http\JsonResponse
    {
        $brokers = DB::select("
            SELECT u.id, u.name, u.email, u.phone, u.user_type, u.profile_image, u.signature_title, u.created_at,
                   (SELECT COUNT(*) FROM properties p WHERE p.broker_id = u.id) as property_count,
                   (SELECT GROUP_CONCAT(ea.email_address) FROM email_accounts ea WHERE ea.user_id = u.id) as email_accounts
            FROM users u
            WHERE u.user_type IN ('admin', 'makler', 'assistenz')
            ORDER BY u.id
        ");

        return response()->json(['brokers' => $brokers]);
    }

    /**
     * Create a new broker/makler (admin only).
     */
    private function createBroker(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $phone = trim($data['phone'] ?? '');

        if (!$name || !$email || !$password) {
            return response()->json(['error' => 'Name, E-Mail und Passwort sind erforderlich'], 400);
        }

        // Check if email already exists
        $exists = DB::selectOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($exists) {
            return response()->json(['error' => 'E-Mail-Adresse bereits vergeben'], 400);
        }

        // Create user
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt($password),
            'phone' => $phone ?: null,
            'user_type' => in_array($data['user_type'] ?? '', ['makler', 'assistenz']) ? $data['user_type'] : 'makler',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create admin_settings for this user
        DB::table('admin_settings')->insert([
            'user_id' => $userId,
            'signature_name' => $name,
            'signature_company' => 'SR-Homes Immobilien GmbH',
            'signature_phone' => $phone ?: null,
            'signature_website' => 'www.sr-homes.at',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create email account if IMAP data provided
        $imapHost = trim($data['imap_host'] ?? '');
        $imapUser = trim($data['imap_username'] ?? '');
        $imapPass = trim($data['imap_password'] ?? '');
        $smtpHost = trim($data['smtp_host'] ?? '');
        $smtpUser = trim($data['smtp_username'] ?? '');
        $smtpPass = trim($data['smtp_password'] ?? '');
        $emailAddress = trim($data['email_address'] ?? $email);

        if ($imapHost && $imapUser && $imapPass) {
            // Determine current max UID so only NEW mails get imported (start fresh)
            $lastUid = 0;
            try {
                $imap = imap_open(
                    '{' . $imapHost . ':' . intval($data['imap_port'] ?? 993) . '/imap/' . ($data['imap_encryption'] ?? 'ssl') . '}INBOX',
                    $imapUser, $imapPass, 0, 1
                );
                if ($imap) {
                    $check = imap_check($imap);
                    if ($check && $check->Nmsgs > 0) {
                        $overview = imap_fetch_overview($imap, $check->Nmsgs . ':' . $check->Nmsgs, 0);
                        if ($overview && isset($overview[0]->uid)) {
                            $lastUid = $overview[0]->uid;
                        }
                    }
                    imap_close($imap);
                    \Log::info("New email account {$emailAddress}: set last_uid to {$lastUid} (start fresh)");
                }
            } catch (\Throwable $e) {
                \Log::warning("Could not determine last_uid for {$emailAddress}: " . $e->getMessage());
            }

            DB::table('email_accounts')->insert([
                'label' => $name,
                'email_address' => $emailAddress,
                'from_name' => $name,
                'imap_host' => $imapHost,
                'imap_port' => intval($data['imap_port'] ?? 993),
                'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
                'imap_username' => $imapUser,
                'imap_password' => $imapPass,
                'smtp_host' => $smtpHost ?: $imapHost,
                'smtp_port' => intval($data['smtp_port'] ?? 587),
                'smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
                'smtp_username' => $smtpUser ?: $imapUser,
                'smtp_password' => $smtpPass ?: $imapPass,
                'is_active' => 1,
                'user_id' => $userId,
                'last_uid' => $lastUid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign properties if provided
        $propertyIds = $data['property_ids'] ?? [];
        if (!empty($propertyIds)) {
            $ids = array_map('intval', $propertyIds);
            DB::table('properties')->whereIn('id', $ids)->update(['broker_id' => $userId]);
        }

        return response()->json([
            'success' => true,
            'user_id' => $userId,
            'message' => "Makler {$name} wurde erstellt.",
        ]);
    }

    /**
     * Update a broker/makler (admin only).
     */
    private function updateBroker(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $brokerId = intval($data['broker_id'] ?? 0);
        if (!$brokerId) return response()->json(['error' => 'broker_id required'], 400);

        $user = DB::selectOne("SELECT * FROM users WHERE id = ? AND user_type IN ('admin','makler','assistenz')", [$brokerId]);
        if (!$user) return response()->json(['error' => 'Makler nicht gefunden'], 404);

        // Update user fields
        $update = [];
        if (isset($data['name'])) $update['name'] = trim($data['name']);
        if (isset($data['phone'])) $update['phone'] = trim($data['phone']);
        if (isset($data['user_type']) && in_array($data['user_type'], ['makler', 'assistenz'])) {
            $update['user_type'] = $data['user_type'];
        }
        if (isset($data['password']) && trim($data['password'])) {
            $update['password'] = bcrypt(trim($data['password']));
        }
        if (!empty($update)) {
            $update['updated_at'] = now();
            DB::table('users')->where('id', $brokerId)->update($update);
        }

        // Update property assignments
        if (isset($data['property_ids'])) {
            // Remove old assignments
            DB::table('properties')->where('broker_id', $brokerId)->update(['broker_id' => null]);
            // Set new assignments
            $ids = array_map('intval', array_filter($data['property_ids']));
            if (!empty($ids)) {
                DB::table('properties')->whereIn('id', $ids)->update(['broker_id' => $brokerId]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Makler aktualisiert']);
    }

    /**
     * Upload a global (property-independent) file.
     */
    private function uploadGlobalFile(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'file required'], 400);
        }

        $file = $request->file('file');
        $customName = trim($request->input('label', ''));
        $brokerId = \Auth::id() ?: 1;

        $originalName = $file->getClientOriginalName();
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $originalName);
        $path = $file->storeAs('global_files', $filename, 'public');

        $id = DB::table('global_files')->insertGetId([
            'filename' => $filename,
            'original_name' => $originalName,
            'path' => $path,
            'label' => $customName ?: pathinfo($originalName, PATHINFO_FILENAME),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $brokerId,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'file' => DB::table('global_files')->where('id', $id)->first(),
        ]);
    }

    /**
     * Delete a global file.
     */
    private function deleteGlobalFile(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->json('id', 0));
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $file = DB::table('global_files')->where('id', $id)->first();
        if ($file) {
            $fullPath = storage_path('app/public/' . $file->path);
            if (file_exists($fullPath)) @unlink($fullPath);
            DB::table('global_files')->where('id', $id)->delete();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Reassign an email + its activities to a different property.
     */
    private function reassignEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        $emailId = intval($request->json('email_id', 0));
        $activityId = intval($request->json('activity_id', 0));
        $newPropertyId = intval($request->json('property_id', 0));
        $stakeholder = $request->json('stakeholder', '');

        if ((!$emailId && !$activityId) || !$newPropertyId) {
            return response()->json(['error' => 'email_id/activity_id and property_id required'], 400);
        }

        $prop = DB::table('properties')->where('id', $newPropertyId)->first(['id', 'ref_id', 'address']);
        if (!$prop) return response()->json(['error' => 'Property not found'], 404);

        $updated = 0;

        if ($emailId) {
            // Update the email
            DB::table('portal_emails')->where('id', $emailId)->update([
                'property_id' => $newPropertyId,
                'matched_ref_id' => $prop->ref_id,
            ]);
            // Update all activities linked to this email
            $updated += DB::table('activities')->where('source_email_id', $emailId)->update([
                'property_id' => $newPropertyId,
            ]);
        }

        if ($activityId) {
            DB::table('activities')->where('id', $activityId)->update([
                'property_id' => $newPropertyId,
            ]);
            $updated++;
            // Also update linked email if exists
            $act = DB::table('activities')->where('id', $activityId)->first();
            if ($act && $act->source_email_id) {
                DB::table('portal_emails')->where('id', $act->source_email_id)->update([
                    'property_id' => $newPropertyId,
                    'matched_ref_id' => $prop->ref_id,
                ]);
            }
        }

        // If stakeholder given, update ALL activities of this stakeholder without property
        if ($stakeholder) {
            DB::table('activities')
                ->where('stakeholder', $stakeholder)
                ->whereNull('property_id')
                ->update(['property_id' => $newPropertyId]);
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'property' => ['id' => $prop->id, 'ref_id' => $prop->ref_id, 'address' => $prop->address],
        ]);
    }

    /**
     * Change the category of an email + its activities.
     */
    private function changeEmailCategory(Request $request): \Illuminate\Http\JsonResponse
    {
        $emailId = intval($request->json('email_id', 0));
        $activityId = intval($request->json('activity_id', 0));
        $newCategory = $request->json('category', '');

        $validCategories = ['anfrage', 'email-in', 'email-out', 'nachfassen', 'expose', 'besichtigung',
            'kaufanbot', 'absage', 'update', 'sonstiges', 'eigentuemer', 'partner', 'bounce', 'intern', 'makler'];

        if (!in_array($newCategory, $validCategories)) {
            return response()->json(['error' => 'Invalid category'], 400);
        }

        if ($emailId) {
            DB::table('portal_emails')->where('id', $emailId)->update(['category' => $newCategory]);
            DB::table('activities')->where('source_email_id', $emailId)->update(['category' => $newCategory]);
        }

        if ($activityId) {
            DB::table('activities')->where('id', $activityId)->update(['category' => $newCategory]);
            $act = DB::table('activities')->where('id', $activityId)->first();
            if ($act && $act->source_email_id) {
                DB::table('portal_emails')->where('id', $act->source_email_id)->update(['category' => $newCategory]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Create ephemeral session token for OpenAI Realtime API.
     */
    private function realtimeSession(Request $request): \Illuminate\Http\JsonResponse
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'OpenAI API key not configured'], 500);
        }

        // Build system prompt (compact for voice)
        $properties = DB::table('properties')
            ->where('realty_status', '!=', 'verkauft')
            ->get(['id', 'ref_id', 'address', 'city', 'realty_status', 'purchase_price', 'object_type']);
        
        $propList = '';
        foreach ($properties as $p) {
            $price = $p->purchase_price ? number_format($p->purchase_price, 0, ',', '.') . ' EUR' : 'k.A.';
            $propList .= "- {$p->ref_id}: {$p->address}, {$p->city} ({$p->object_type}, {$price}, Status: {$p->realty_status}, ID: {$p->id})\n";
        }

        $instructions = "Du bist Sherlock, der KI-Sprachassistent von SR-Homes Immobilien GmbH (Maximilian Hoelzl, Salzburg). "
            . "Du sprichst IMMER Deutsch (oesterreichisch). Du antwortest kurz, praegnant und professionell. "
            . "Du hast Zugriff auf alle Immobiliendaten, Kontakte, Aktivitaeten und E-Mails ueber die bereitgestellten Tools. "
            . "Nutze die Tools aktiv — sage NIEMALS du haettest keinen Zugriff. "
            . "Bei Neubauprojekten: KEINE Besichtigungen anbieten (Wohnungen existieren noch nicht), stattdessen Beratungsgespraech. "
            . "Sage NIEMALS du haettest keinen Zugriff auf Daten. Verwende IMMER die verfuegbaren Tools.\n\n"
            . "AKTIVE OBJEKTE:\n{$propList}";

        // Build tools array for Realtime API format
        $tools = [
            ['type' => 'function', 'name' => 'search_properties', 'realty_description' => 'Suche Immobilien nach Stichwort', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'realty_description' => 'Suchbegriff']], 'required' => ['query']]],
            ['type' => 'function', 'name' => 'get_property_details', 'realty_description' => 'Details einer Immobilie abrufen inkl. Provision, Eigentuemer, Einheiten', 'parameters' => ['type' => 'object', 'properties' => ['property_id' => ['type' => 'integer', 'realty_description' => 'Property-ID']], 'required' => ['property_id']]],
            ['type' => 'function', 'name' => 'get_unit_details', 'realty_description' => 'Details einer Einheit abrufen', 'parameters' => ['type' => 'object', 'properties' => ['unit_id' => ['type' => 'integer']], 'required' => ['unit_id']]],
            ['type' => 'function', 'name' => 'search_activities', 'realty_description' => 'Aktivitaeten suchen', 'parameters' => ['type' => 'object', 'properties' => ['property_id' => ['type' => 'integer'], 'stakeholder' => ['type' => 'string'], 'limit' => ['type' => 'integer']], 'required' => []]],
            ['type' => 'function', 'name' => 'add_activity', 'realty_description' => 'Aktivitaet anlegen. Frage IMMER nach Datum bevor du eine Aktivitaet erstellst.', 'parameters' => ['type' => 'object', 'properties' => ['property_id' => ['type' => 'integer'], 'stakeholder' => ['type' => 'string'], 'activity' => ['type' => 'string'], 'category' => ['type' => 'string', 'realty_description' => 'besichtigung, eigentuemer, anfrage, kaufanbot, expose, nachfassen, email-in, email-out'], 'activity_date' => ['type' => 'string', 'realty_description' => 'YYYY-MM-DD'], 'activity_time' => ['type' => 'string'], 'result' => ['type' => 'string']], 'required' => ['property_id', 'stakeholder', 'activity', 'category', 'activity_date']]],
            ['type' => 'function', 'name' => 'search_contacts', 'realty_description' => 'Kontakte suchen', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
            ['type' => 'function', 'name' => 'search_emails', 'realty_description' => 'E-Mails durchsuchen', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'property_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer']], 'required' => []]],
            ['type' => 'function', 'name' => 'send_email', 'realty_description' => 'Echte E-Mail senden', 'parameters' => ['type' => 'object', 'properties' => ['to' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'property_id' => ['type' => 'integer'], 'stakeholder' => ['type' => 'string']], 'required' => ['to', 'subject', 'body']]],
            ['type' => 'function', 'name' => 'get_tasks', 'realty_description' => 'Aufgaben abrufen', 'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]],
            ['type' => 'function', 'name' => 'add_task', 'realty_description' => 'Aufgabe erstellen', 'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'property_id' => ['type' => 'integer'], 'priority' => ['type' => 'string']], 'required' => ['text']]],
            ['type' => 'function', 'name' => 'get_briefing', 'realty_description' => 'Tagesbriefing mit Zusammenfassung aller offenen Punkte', 'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]],
            ['type' => 'function', 'name' => 'get_unanswered', 'realty_description' => 'Unbeantwortete E-Mails abrufen', 'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]],
        ];

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/realtime/sessions', [
                'model' => 'gpt-4o-mini-realtime-preview-2024-12-17',
                'voice' => 'ash',
                'instructions' => $instructions,
                'tools' => $tools,
                'input_audio_transcription' => ['model' => 'whisper-1'],
                'turn_detection' => [
                    'type' => 'server_vad',
                    'threshold' => 0.5,
                    'prefix_padding_ms' => 300,
                    'silence_duration_ms' => 500,
                ],
                'modalities' => ['text', 'audio'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'client_secret' => $data['client_secret'] ?? null,
                    'session_id' => $data['id'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'tools' => $tools,
                    'instructions' => $instructions,
                ]);
            } else {
                \Log::error('Realtime session error: ' . $response->body());
                return response()->json(['error' => 'OpenAI Realtime session creation failed', 'details' => $response->json()], 500);
            }
        } catch (\Throwable $e) {
            \Log::error('Realtime session exception: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Property-level Kaufanbote (property_kaufanbote table) ──────────────────

    private function listPropertyKaufanbote(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $rows = DB::table('property_kaufanbote')
            ->where('property_id', $propertyId)
            ->orderByDesc('kaufanbot_date')
            ->get();

        $allUnits = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->get()
            ->keyBy('id');

        $kaufanbote = $rows->map(function ($r) use ($allUnits) {
            $unitIds = json_decode($r->unit_ids ?? '[]', true) ?: [];
            $parkingIds = json_decode($r->parking_ids ?? '[]', true) ?: [];

            $unitNames = [];
            foreach ($unitIds as $uid) {
                if (isset($allUnits[$uid])) $unitNames[] = $allUnits[$uid]->unit_number;
            }
            $parkingNames = [];
            foreach ($parkingIds as $pid) {
                if (isset($allUnits[$pid])) $parkingNames[] = $allUnits[$pid]->unit_number;
            }

            return [
                'id' => $r->id,
                'buyer_name' => $r->buyer_name,
                'buyer_email' => $r->buyer_email ?? null,
                'buyer_phone' => $r->buyer_phone ?? null,
                'amount' => $r->amount,
                'kaufanbot_date' => $r->kaufanbot_date,
                'status' => $r->status,
                'pdf_path' => $r->pdf_path,
                'pdf_filename' => $r->pdf_filename,
                'unit_ids' => $unitIds,
                'parking_ids' => $parkingIds,
                'unit_names' => $unitNames,
                'parking_names' => $parkingNames,
                'notes' => $r->notes,
                'created_at' => $r->created_at,
            ];
        })->values();

        return response()->json(['kaufanbote' => $kaufanbote], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function uploadPropertyKaufanbot(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        $buyerName  = trim($request->input('buyer_name', ''));
        $buyerEmail = trim((string) $request->input('buyer_email', ''));
        $buyerPhone = trim((string) $request->input('buyer_phone', ''));

        if (!$propertyId || !$buyerName) {
            return response()->json(['error' => 'property_id and buyer_name required'], 400);
        }
        if (!$request->hasFile('pdf')) {
            return response()->json(['error' => 'PDF file required'], 400);
        }
        if ($buyerEmail !== '' && !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Ungueltige E-Mail-Adresse'], 422);
        }

        $unitIds = json_decode($request->input('unit_ids', '[]'), true) ?: [];
        $parkingIds = json_decode($request->input('parking_ids', '[]'), true) ?: [];

        if (empty($unitIds)) {
            return response()->json(['error' => 'Mindestens eine Einheit muss zugeordnet werden.'], 400);
        }

        $file     = $request->file('pdf');
        $dir      = 'kaufanbote/' . $propertyId;
        $filename = 'KA_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $buyerName) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path     = $file->storeAs($dir, $filename, 'public');

        $id = DB::table('property_kaufanbote')->insertGetId([
            'property_id'    => $propertyId,
            'buyer_name'     => $buyerName,
            'buyer_email'    => $buyerEmail !== '' ? $buyerEmail : null,
            'buyer_phone'    => $buyerPhone !== '' ? $buyerPhone : null,
            'amount'         => $request->input('amount') ? floatval($request->input('amount')) : null,
            'kaufanbot_date' => $request->input('kaufanbot_date') ?: now()->toDateString(),
            'status'         => 'akzeptiert',
            'pdf_path'       => $path,
            'pdf_filename'   => $filename,
            'unit_ids'       => json_encode($unitIds),
            'parking_ids'    => json_encode($parkingIds),
            'notes'          => trim($request->input('notes', '')) ?: null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Mark units as verkauft
        DB::table('property_units')
            ->whereIn('id', $unitIds)
            ->update([
                'status' => 'verkauft',
                'buyer_name' => $buyerName,
                'kaufanbot_pdf' => $path,
                'updated_at' => now(),
            ]);

        // Mark parking as verkauft
        if (!empty($parkingIds)) {
            DB::table('property_units')
                ->whereIn('id', $parkingIds)
                ->update([
                    'status' => 'verkauft',
                    'buyer_name' => $buyerName,
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['success' => true, 'id' => $id, 'path' => $path]);
    }

    /**
     * Updatet ein bestehendes Kaufanbot. PDF ist optional (nur ersetzen wenn ein
     * neues hochgeladen wurde). Erlaubt Bearbeitung von buyer_name/email/phone,
     * Datum, zugewiesene Einheiten + Stellplaetze. Wenn sich die Einheiten-IDs
     * aendern, werden alte Einheiten freigegeben und neue als verkauft markiert.
     */
    private function updatePropertyKaufanbot(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->input('kaufanbot_id', 0));
        if (!$id) return response()->json(['error' => 'kaufanbot_id required'], 400);

        $existing = DB::table('property_kaufanbote')->where('id', $id)->first();
        if (!$existing) return response()->json(['error' => 'Kaufanbot not found'], 404);

        $buyerName  = trim($request->input('buyer_name', $existing->buyer_name));
        $buyerEmail = trim((string) $request->input('buyer_email', ''));
        $buyerPhone = trim((string) $request->input('buyer_phone', ''));

        if ($buyerName === '') {
            return response()->json(['error' => 'buyer_name required'], 400);
        }
        if ($buyerEmail !== '' && !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Ungueltige E-Mail-Adresse'], 422);
        }

        $unitIds    = json_decode($request->input('unit_ids', json_encode(json_decode($existing->unit_ids ?? '[]', true) ?: [])), true) ?: [];
        $parkingIds = json_decode($request->input('parking_ids', json_encode(json_decode($existing->parking_ids ?? '[]', true) ?: [])), true) ?: [];

        if (empty($unitIds)) {
            return response()->json(['error' => 'Mindestens eine Einheit muss zugeordnet werden.'], 400);
        }

        $update = [
            'buyer_name'     => $buyerName,
            'buyer_email'    => $buyerEmail !== '' ? $buyerEmail : null,
            'buyer_phone'    => $buyerPhone !== '' ? $buyerPhone : null,
            'kaufanbot_date' => $request->input('kaufanbot_date') ?: $existing->kaufanbot_date,
            'amount'         => $request->input('amount') !== null && $request->input('amount') !== '' ? floatval($request->input('amount')) : $existing->amount,
            'unit_ids'       => json_encode($unitIds),
            'parking_ids'    => json_encode($parkingIds),
            'notes'          => trim((string) $request->input('notes', $existing->notes ?? '')) ?: null,
            'updated_at'     => now(),
        ];

        // PDF nur ersetzen, wenn ein neues hochgeladen wurde.
        if ($request->hasFile('pdf')) {
            $file     = $request->file('pdf');
            $dir      = 'kaufanbote/' . $existing->property_id;
            $filename = 'KA_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $buyerName) . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path     = $file->storeAs($dir, $filename, 'public');

            // Altes PDF entfernen
            if ($existing->pdf_path) {
                $oldPath = storage_path('app/public/' . $existing->pdf_path);
                if (is_file($oldPath)) @unlink($oldPath);
            }

            $update['pdf_path']     = $path;
            $update['pdf_filename'] = $filename;
        }

        DB::table('property_kaufanbote')->where('id', $id)->update($update);

        // Einheiten-Zuordnung syncen: Alte freigeben, neue als verkauft markieren.
        $oldUnitIds    = json_decode($existing->unit_ids ?? '[]', true) ?: [];
        $oldParkingIds = json_decode($existing->parking_ids ?? '[]', true) ?: [];

        $removedUnits   = array_diff($oldUnitIds, $unitIds);
        $removedParking = array_diff($oldParkingIds, $parkingIds);

        if (!empty($removedUnits)) {
            DB::table('property_units')
                ->whereIn('id', $removedUnits)
                ->update(['status' => 'frei', 'buyer_name' => null, 'kaufanbot_pdf' => null, 'updated_at' => now()]);
        }
        if (!empty($removedParking)) {
            DB::table('property_units')
                ->whereIn('id', $removedParking)
                ->update(['status' => 'frei', 'buyer_name' => null, 'updated_at' => now()]);
        }

        // Aktuelle Einheiten als verkauft markieren (mit ggf. neuem PDF-Pfad).
        $effectivePdfPath = $update['pdf_path'] ?? $existing->pdf_path;
        DB::table('property_units')
            ->whereIn('id', $unitIds)
            ->update([
                'status'        => 'verkauft',
                'buyer_name'    => $buyerName,
                'kaufanbot_pdf' => $effectivePdfPath,
                'updated_at'    => now(),
            ]);
        if (!empty($parkingIds)) {
            DB::table('property_units')
                ->whereIn('id', $parkingIds)
                ->update([
                    'status'     => 'verkauft',
                    'buyer_name' => $buyerName,
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function deletePropertyKaufanbot(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->input('kaufanbot_id', 0));
        if (!$id) return response()->json(['error' => 'kaufanbot_id required'], 400);

        $ka = DB::table('property_kaufanbote')->where('id', $id)->first();
        if (!$ka) return response()->json(['error' => 'Kaufanbot not found'], 404);

        // Free linked units
        $unitIds = json_decode($ka->unit_ids ?? '[]', true) ?: [];
        $parkingIds = json_decode($ka->parking_ids ?? '[]', true) ?: [];

        if (!empty($unitIds)) {
            DB::table('property_units')
                ->whereIn('id', $unitIds)
                ->update(['status' => 'frei', 'buyer_name' => null, 'kaufanbot_pdf' => null, 'updated_at' => now()]);
        }
        if (!empty($parkingIds)) {
            DB::table('property_units')
                ->whereIn('id', $parkingIds)
                ->update(['status' => 'frei', 'buyer_name' => null, 'updated_at' => now()]);
        }

        // Delete PDF file
        if ($ka->pdf_path) {
            $filePath = storage_path('app/public/' . $ka->pdf_path);
            if (file_exists($filePath)) @unlink($filePath);
        }

        DB::table('property_kaufanbote')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }
    private function updatePropertyKaufanbotStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $id     = intval($request->json('id', 0));
        $status = trim($request->json('status', ''));

        if (!$id) return response()->json(['error' => 'id required'], 400);

        $valid = ['eingegangen','akzeptiert','abgelehnt','zurueckgezogen'];
        if (!in_array($status, $valid)) {
            return response()->json(['error' => 'Invalid status. Valid: ' . implode(', ', $valid)], 400);
        }

        DB::table('property_kaufanbote')->where('id', $id)->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function updateKaufanbotActivityStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $id     = intval($request->input('activity_id', 0));
        $status = trim($request->input('kaufanbot_status', ''));

        if (!$id) return response()->json(['error' => 'activity_id required'], 400);

        DB::table('activities')->where('id', $id)->update([
            'kaufanbot_status' => $status ?: null,
            'updated_at'       => now(),
        ]);

        return response()->json(['success' => true]);
    }



    // ── Property Portals ──────────────────────────────────────────
    private function listPropertyPortals(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $portals = \App\Models\PropertyPortal::where('property_id', $propertyId)->get();
        return response()->json(['portals' => $portals]);
    }

    private function savePropertyPortal(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->json('property_id', 0));
        $portalName = $request->json('portal_name', '');
        if (!$propertyId || !$portalName) {
            return response()->json(['error' => 'property_id and portal_name required'], 400);
        }

        $data = $request->only([
            'external_id', 'external_url', 'status', 'sync_enabled', 'portal_config'
        ]);
        $data['property_id'] = $propertyId;
        $data['portal_name'] = $portalName;

        $portal = \App\Models\PropertyPortal::updateOrCreate(
            ['property_id' => $propertyId, 'portal_name' => $portalName],
            $data
        );

        return response()->json(['success' => true, 'portal' => $portal]);
    }

    // ── Full Property Save ────────────────────────────────────────
    private function saveFullProperty(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->json('id', 0));
        $data = $request->json()->all();

        // Remove non-fillable
        unset($data['id'], $data['action'], $data['key'], $data['created_at'], $data['updated_at']);

        if ($id) {
            $property = \App\Models\Property::find($id);
            if (!$property) return response()->json(['error' => 'Property not found'], 404);
            $property->update($data);
        } else {
            // Create new
            if (empty($data['ref_id'])) {
                $data['ref_id'] = 'NEW-' . strtoupper(uniqid());
            }
            if (empty($data['address'])) {
                return response()->json(['error' => 'address is required'], 400);
            }
            $property = \App\Models\Property::create($data);
        }

        // Reload with relations
        $property->load(['images', 'portals']);

        return response()->json(['success' => true, 'property' => $property]);
    }


    // ── Website CMS Methods ───────────────────────────────────────

    private function websiteContentList(Request $request): \Illuminate\Http\JsonResponse
    {
        $section = $request->input('section');
        $query = \DB::table('website_content')->orderBy('section')->orderBy('sort_order');
        if ($section) $query->where('section', $section);
        $items = $query->get();
        foreach ($items as &$item) {
            if ($item->content_type === 'json') {
                $item->content_value = json_decode($item->content_value, true);
            }
        }
        return response()->json(['success' => true, 'items' => $items]);
    }

    private function websiteContentSave(Request $request): \Illuminate\Http\JsonResponse
    {
        $section = $request->input('section');
        $contentKey = $request->input('content_key');
        if (!$section || !$contentKey) {
            return response()->json(['error' => 'section and content_key required'], 400);
        }

        // Handle deletion
        if ($request->boolean('_delete')) {
            \DB::table('website_content')
                ->where('section', $section)
                ->where('content_key', $contentKey)
                ->delete();
            \Cache::forget('website_content');
            return response()->json(['success' => true, 'deleted' => true]);
        }

        $contentType = $request->input('content_type', 'text');
        $contentValue = $request->input('content_value', '');

        // Upsert by section + content_key
        $existing = \DB::table('website_content')
            ->where('section', $section)
            ->where('content_key', $contentKey)
            ->first();

        $data = [
            'section' => $section,
            'content_key' => $contentKey,
            'content_type' => $contentType,
            'content_value' => $contentValue,
            'sort_order' => intval($request->input('sort_order', 0)),
            'is_active' => 1,
            'updated_at' => now(),
        ];

        if ($existing) {
            \DB::table('website_content')->where('id', $existing->id)->update($data);
            $id = $existing->id;
        } else {
            $data['created_at'] = now();
            $id = \DB::table('website_content')->insertGetId($data);
        }

        \Cache::forget('website_content');
        return response()->json(['success' => true, 'id' => $id]);
    }

    private function websiteContentDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->input('id'));
        \DB::table('website_content')->where('id', $id)->delete();
        \Cache::forget('website_content');
        return response()->json(['success' => true]);
    }

    private function websiteContentUpload(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['file' => 'required|file|max:102400']); // 100MB
        $file = $request->file('file');
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs('website', $filename, 'public');
        $url = url(\Storage::disk('public')->url($path));

        $section = $request->input('section');
        $key = $request->input('content_key');
        $contentType = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image';

        if ($section && $key) {
            \DB::table('website_content')->updateOrInsert(
                ['section' => $section, 'content_key' => $key],
                ['content_value' => $url, 'content_type' => $contentType, 'updated_at' => now()]
            );
            \Cache::forget('website_content');
        }

        return response()->json(['success' => true, 'url' => $url, 'content_type' => $contentType]);
    }

    private function websiteToggleProperty(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id'));
        $show = $request->boolean('show_on_website') ? 1 : 0;
        \DB::table('properties')->where('id', $propertyId)->update(['show_on_website' => $show]);
        \Cache::forget('website_properties');
        return response()->json(['success' => true, 'show_on_website' => $show]);
    }

    private function websiteSetMainImage(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id'));
        $imageId = intval($request->input('image_id'));
        \DB::table('properties')->where('id', $propertyId)->update(['main_image_id' => $imageId]);
        \Cache::forget('website_properties');
        return response()->json(['success' => true]);
    }

    private function websiteClearCache(): \Illuminate\Http\JsonResponse
    {
        \Cache::forget('website_properties');
        \Cache::forget('website_content');
        return response()->json(['success' => true, 'message' => 'Website cache cleared']);
    }


    // ═══════════════════════════════════════
    // PROJECT GROUPS
    // ═══════════════════════════════════════

    private function listProjectGroups(Request $request): \Illuminate\Http\JsonResponse
    {
        $groups = \DB::table('project_groups')->orderBy('name')->get();
        // Add customer name for display
        foreach ($groups as $g) {
            $customer = $g->customer_id ? \DB::table('customers')->where('id', $g->customer_id)->first() : null;
            $g->customer_name = $customer ? $customer->name : null;
        }
        foreach ($groups as $g) {
            $g->properties = \DB::table('properties')
                ->where('project_group_id', $g->id)
                ->select('id', 'title', 'ref_id', 'city', 'address')
                ->get();
        }
        return response()->json(['success' => true, 'groups' => $groups]);
    }

    private function createProjectGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $name = $request->input('name');
        if (!$name) return response()->json(['success' => false, 'error' => 'Name required']);
        
        $customerId = $request->input('customer_id') ?: null;
        if (!$customerId) return response()->json(['success' => false, 'error' => 'Eigentuemer (customer_id) erforderlich fuer Projektgruppen']);
        
        $id = \DB::table('project_groups')->insertGetId([
            'name' => $name,
            'customer_id' => $customerId,
            'realty_description' => $request->input('realty_description') ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $group = \DB::table('project_groups')->where('id', $id)->first();
        return response()->json(['success' => true, 'group' => $group]);
    }

    private function updateProjectGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->input('group_id'));
        $update = [];
        if ($request->has('name')) $update['name'] = $request->input('name');
        if ($request->has('realty_description')) $update['realty_description'] = $request->input('realty_description');
        if ($request->has('customer_id')) $update['customer_id'] = $request->input('customer_id') ?: null;
        $update['updated_at'] = now();
        
        \DB::table('project_groups')->where('id', $id)->update($update);
        return response()->json(['success' => true]);
    }

    private function deleteProjectGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = intval($request->input('group_id'));
        \DB::table('properties')->where('project_group_id', $id)->update(['project_group_id' => null]);
        \DB::table('project_groups')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    private function assignToProjectGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $groupId = intval($request->input('group_id'));
        $propertyIds = $request->input('property_ids', []);
        if (!is_array($propertyIds)) $propertyIds = [$propertyIds];
        
        // Validate: all properties must have same customer_id as the group
        $group = \DB::table('project_groups')->where('id', $groupId)->first();
        if (!$group) return response()->json(['success' => false, 'error' => 'Gruppe nicht gefunden']);
        
        $properties = \DB::table('properties')->whereIn('id', $propertyIds)->get();
        foreach ($properties as $prop) {
            if (intval($prop->customer_id) !== intval($group->customer_id)) {
                return response()->json([
                    'success' => false, 
                    'error' => 'Nur Objekte mit demselben Eigentuemer koennen gruppiert werden. "' . ($prop->address ?: $prop->project_name) . '" hat einen anderen Eigentuemer.'
                ]);
            }
        }
        
        \DB::table('properties')->whereIn('id', $propertyIds)->update(['project_group_id' => $groupId]);
        return response()->json(['success' => true]);
    }

    private function removeFromProjectGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyIds = $request->input('property_ids', []);
        if (!is_array($propertyIds)) $propertyIds = [$propertyIds];

        \DB::table('properties')->whereIn('id', $propertyIds)->update(['project_group_id' => null]);
        return response()->json(['success' => true]);
    }

    private function setParentProperty(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $propertyId = intval($data['property_id'] ?? 0);
        $parentId = intval($data['parent_id'] ?? 0);
        if (!$propertyId || !$parentId) {
            return response()->json(['error' => 'property_id und parent_id erforderlich'], 400);
        }
        if ($propertyId === $parentId) {
            return response()->json(['error' => 'Ein Objekt kann nicht sein eigenes Unterobjekt sein'], 400);
        }
        // Prevent circular: parent must not be a child of this property
        $current = $parentId;
        $visited = [];
        while ($current) {
            if (in_array($current, $visited)) break;
            $visited[] = $current;
            $parent = DB::table('properties')->where('id', $current)->value('parent_id');
            if ($parent == $propertyId) {
                return response()->json(['error' => 'Zirkulaere Hierarchie nicht erlaubt'], 400);
            }
            $current = $parent;
        }
        DB::table('properties')->where('id', $propertyId)->update(['parent_id' => $parentId, 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

    private function removeParentProperty(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $propertyId = intval($data['property_id'] ?? 0);
        if (!$propertyId) {
            return response()->json(['error' => 'property_id erforderlich'], 400);
        }
        DB::table('properties')->where('id', $propertyId)->update(['parent_id' => null, 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

    private function getUnits(Request $request)
    {
        $data = $request->all();
        $propertyId = intval($data['property_id'] ?? 0);
        if (!$propertyId) {
            return response()->json(['error' => 'property_id erforderlich'], 400);
        }
        $units = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->orderBy('is_parking')
            ->orderBy('unit_number')
            ->get()
            ->toArray();
        return response()->json(['units' => $units]);
    }

    private function createChildProperty(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $parentId = intval($data['parent_id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$parentId || !$title) {
            return response()->json(['error' => 'parent_id und title erforderlich'], 400);
        }
        $parent = DB::table('properties')->where('id', $parentId)->first();
        if (!$parent) {
            return response()->json(['error' => 'Master-Objekt nicht gefunden'], 404);
        }
        // Create child with inherited base data from parent
        $childId = DB::table('properties')->insertGetId([
            'parent_id'          => $parentId,
            'customer_id'        => $parent->customer_id,
            'broker_id'          => $parent->broker_id,
            'project_group_id'   => $parent->project_group_id,
            'project_name'       => $parent->project_name,
            'title'              => $title,
            'address'            => $parent->address,
            'city'               => $parent->city,
            'zip'                => $parent->zip,
            'latitude'           => $parent->latitude,
            'longitude'          => $parent->longitude,
            'owner_name'         => $parent->owner_name,
            'owner_phone'        => $parent->owner_phone,
            'owner_email'        => $parent->owner_email,
            'contact_person'     => $parent->contact_person,
            'contact_phone'      => $parent->contact_phone,
            'contact_email'      => $parent->contact_email,
            'object_type'        => $parent->object_type,
            'property_category'  => $parent->property_category,
            'marketing_type'     => $parent->marketing_type,
            'realty_status'      => 'aktiv',
            'ref_id'             => $parent->ref_id . '-' . strtolower(str_replace(' ', '', substr($title, 0, 10))),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        return response()->json([
            'success' => true,
            'child_id' => $childId,
            'message' => 'Unterobjekt erstellt',
        ]);
    }


    private function getUnitCategories(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id erforderlich'], 400);
        }
        $categories = DB::select("
            SELECT
                rooms,
                COUNT(*) as unit_count,
                SUM(CASE WHEN status = 'frei' THEN 1 ELSE 0 END) as frei,
                SUM(CASE WHEN status = 'reserviert' THEN 1 ELSE 0 END) as reserviert,
                SUM(CASE WHEN status = 'verkauft' THEN 1 ELSE 0 END) as verkauft,
                MIN(price) as min_price,
                MAX(price) as max_price,
                MIN(area_m2) as min_area,
                MAX(area_m2) as max_area
            FROM property_units
            WHERE property_id = ? AND is_parking = 0 AND rooms IS NOT NULL
            GROUP BY rooms
            ORDER BY rooms
        ", [$propertyId]);

        $existingChildren = DB::select("
            SELECT title FROM properties WHERE parent_id = ?
        ", [$propertyId]);
        $existingTitles = array_map(fn($c) => $c->title, $existingChildren);

        return response()->json([
            'success' => true,
            'categories' => $categories,
            'existing_children' => $existingTitles,
        ]);
    }

    private function createChildrenFromCategories(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->json()->all();
        $parentId = intval($data['parent_id'] ?? 0);
        $categories = $data['categories'] ?? [];
        if (!$parentId || empty($categories)) {
            return response()->json(['error' => 'parent_id und categories erforderlich'], 400);
        }
        $parent = DB::table('properties')->where('id', $parentId)->first();
        if (!$parent) {
            return response()->json(['error' => 'Master-Objekt nicht gefunden'], 404);
        }

        $created = [];
        foreach ($categories as $cat) {
            $rooms = $cat['rooms'] ?? null;
            $title = trim($cat['title'] ?? '');
            $minPrice = floatval($cat['min_price'] ?? 0);
            $minArea = floatval($cat['min_area'] ?? 0);
            if (!$rooms || !$title) continue;

            $roomsInt = intval(floatval($rooms));
            $refSuffix = $roomsInt . 'zi';

            $childId = DB::table('properties')->insertGetId([
                'parent_id'          => $parentId,
                'customer_id'        => $parent->customer_id,
                'broker_id'          => $parent->broker_id,
                'project_group_id'   => $parent->project_group_id,
                'project_name'       => $parent->project_name,
                'title'              => $title,
                'address'            => $parent->address,
                'city'               => $parent->city,
                'zip'                => $parent->zip,
                'latitude'           => $parent->latitude,
                'longitude'          => $parent->longitude,
                'owner_name'         => $parent->owner_name,
                'owner_phone'        => $parent->owner_phone,
                'owner_email'        => $parent->owner_email,
                'contact_person'     => $parent->contact_person,
                'contact_phone'      => $parent->contact_phone,
                'contact_email'      => $parent->contact_email,
                'object_type'        => $parent->object_type,
                'property_category'  => $parent->property_category,
                'marketing_type'     => $parent->marketing_type,
                'realty_status'      => $parent->realty_status ?? 'aktiv',
                'ref_id'             => $parent->ref_id . '-' . $refSuffix,
                'rooms_amount'       => $rooms,
                'purchase_price'     => $minPrice,
                'living_area'        => $minArea > 0 ? $minArea : null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // Copy descriptions from parent
            DB::table('properties')->where('id', $childId)->update([
                'realty_description'    => $parent->realty_description,
                'highlights'            => $parent->highlights,
                'location_description'  => $parent->location_description,
                'equipment_description' => $parent->equipment_description,
                'other_description'     => $parent->other_description,
                'energy_certificate'    => $parent->energy_certificate,
                'heating_demand_value'  => $parent->heating_demand_value,
                'energy_type'           => $parent->energy_type,
                'heating_demand_class'  => $parent->heating_demand_class,
                'energy_efficiency_value' => $parent->energy_efficiency_value,
                'energy_primary_source' => $parent->energy_primary_source,
                'energy_valid_until'    => $parent->energy_valid_until,
                'heating'               => $parent->heating,
                'construction_year'     => $parent->construction_year,
                'year_renovated'        => $parent->year_renovated,
                'available_from'        => $parent->available_from,
                'available_text'        => $parent->available_text,
            ]);

            // Copy images from parent
            $parentImages = DB::table('property_images')->where('property_id', $parentId)->get();
            foreach ($parentImages as $img) {
                $srcPath = storage_path('app/public/' . $img->path);
                if (file_exists($srcPath)) {
                    $newDir = storage_path('app/public/property_images/' . $childId);
                    if (!is_dir($newDir)) mkdir($newDir, 0755, true);
                    copy($srcPath, $newDir . '/' . $img->filename);
                    DB::table('property_images')->insert([
                        'property_id' => $childId,
                        'filename' => $img->filename,
                        'original_name' => $img->original_name,
                        'path' => 'property_images/' . $childId . '/' . $img->filename,
                        'mime_type' => $img->mime_type,
                        'file_size' => $img->file_size,
                        'width' => $img->width,
                        'height' => $img->height,
                        'category' => $img->category,
                        'title' => $img->title,
                        'is_title_image' => $img->is_title_image,
                        'sort_order' => $img->sort_order,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            $created[] = ['id' => $childId, 'title' => $title, 'rooms' => $rooms];
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'message' => count($created) . ' Unterobjekt(e) erstellt',
        ]);
    }


    /**
     * Handle parse_property_fields API action.
     */
    private function handleParsePropertyFields(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', $request->query('property_id', 0)));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $fileIds = $request->input('file_ids', []);
        $service = app(\App\Services\DocumentParserService::class);
        $result = $service->parsePropertyFields($propertyId, $fileIds);

        if (isset($result['error'])) {
            return response()->json($result, 400);
        }
        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle parse_units API action.
     */
    private function handleParseUnits(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', $request->query('property_id', 0)));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $fileIds = $request->input('file_ids', []);
        $service = app(\App\Services\DocumentParserService::class);
        $result = $service->parseUnits($propertyId, $fileIds);

        if (isset($result['error'])) {
            return response()->json($result, 400);
        }
        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate an AI-written Objekt- or Lagebeschreibung for a property.
     *
     * Input:
     *   - property_id: int
     *   - type: 'objekt' | 'lage'
     *   - file_ids: int[]  (only used for type=objekt)
     *
     * Output: { success: bool, text?: string, error?: string }
     */
    private function handleGeneratePropertyDescription(Request $request): \Illuminate\Http\JsonResponse
    {
        $propertyId = intval($request->input('property_id', $request->query('property_id', 0)));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $type = (string) $request->input('type', 'objekt');
        if (!in_array($type, ['objekt', 'lage'], true)) {
            return response()->json(['error' => 'type must be objekt or lage'], 400);
        }

        $fileIds = (array) $request->input('file_ids', []);

        /** @var \App\Services\PropertyDescriptionService $service */
        $service = app(\App\Services\PropertyDescriptionService::class);

        try {
            $result = $type === 'lage'
                ? $service->generateLage($propertyId)
                : $service->generateObjekt($propertyId, $fileIds);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('generate_property_description failed', [
                'property_id' => $propertyId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'KI-Generierung fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        $status = !empty($result['success']) ? 200 : 422;
        return response()->json($result, $status, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Polish an existing Objekt- or Lagebeschreibung:
     *   - fix formatting / paragraph breaks / weird copy-paste line wrapping
     *   - clean up wording without inventing facts
     *   - enforce the same topic bans as generate (no PLZ, no prices, etc.)
     *
     * Input:
     *   - type: 'objekt' | 'lage'
     *   - text: current draft
     *
     * Output: { success: bool, text?: string, error?: string }
     */
    private function handlePolishPropertyDescription(Request $request): \Illuminate\Http\JsonResponse
    {
        $type = (string) $request->input('type', 'objekt');
        if (!in_array($type, ['objekt', 'lage'], true)) {
            return response()->json(['success' => false, 'error' => 'type must be objekt or lage'], 400);
        }

        $text = (string) $request->input('text', '');
        if (trim($text) === '') {
            return response()->json(['success' => false, 'error' => 'Kein Text zum Verbessern'], 400);
        }

        /** @var \App\Services\PropertyDescriptionService $service */
        $service = app(\App\Services\PropertyDescriptionService::class);

        try {
            $result = $service->polish($type, $text);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('polish_property_description failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'Fehler: ' . $e->getMessage()], 500);
        }

        $status = !empty($result['success']) ? 200 : 422;
        return response()->json($result, $status, [], JSON_UNESCAPED_UNICODE);
    }

    // ===== Tagesbriefing =====

    private function briefingGet(Request $request): JsonResponse
    {
        $userId = (int) \Auth::id();
        if (!$userId) return response()->json(['error' => 'Nicht angemeldet'], 401);

        $date = $request->query('date') ?: now()->toDateString();

        try {
            $service = app(\App\Services\DailyBriefingService::class);
            $briefing = $service->generate($userId, $date);
            return response()->json(['success' => true, 'briefing' => $briefing]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('briefingGet failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'Briefing konnte nicht geladen werden: ' . $e->getMessage()], 500);
        }
    }

    private function briefingRegenerate(Request $request): JsonResponse
    {
        $userId = (int) \Auth::id();
        if (!$userId) return response()->json(['error' => 'Nicht angemeldet'], 401);

        // Rate-limit: max 1× pro 60s pro User
        $cacheKey = 'briefing_regen_' . $userId;
        if (\Illuminate\Support\Facades\Cache::get($cacheKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Bitte warte einen Moment bevor du erneut regenerierst',
                'rate_limited' => true,
            ], 200);
        }
        \Illuminate\Support\Facades\Cache::put($cacheKey, 1, 60);

        try {
            $service = app(\App\Services\DailyBriefingService::class);
            $briefing = $service->generate($userId, now()->toDateString(), forceRefresh: true);
            return response()->json(['success' => true, 'briefing' => $briefing]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('briefingRegenerate failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'Regenerierung fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    // ===== Hausverwaltung (Phase 1) =====

    private function listPropertyManagers(Request $request): JsonResponse
    {
        $search = trim($request->query('search', ''));

        $q = \DB::table('property_managers as pm')
            ->leftJoin('properties as p', 'p.property_manager_id', '=', 'pm.id')
            ->select([
                'pm.id', 'pm.company_name', 'pm.address_street', 'pm.address_zip', 'pm.address_city',
                'pm.email', 'pm.phone', 'pm.contact_person', 'pm.notes', 'pm.created_at',
                \DB::raw('COUNT(p.id) as property_count'),
            ])
            ->groupBy('pm.id', 'pm.company_name', 'pm.address_street', 'pm.address_zip', 'pm.address_city',
                      'pm.email', 'pm.phone', 'pm.contact_person', 'pm.notes', 'pm.created_at')
            ->orderBy('pm.company_name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('pm.company_name', 'like', "%{$search}%")
                  ->orWhere('pm.email', 'like', "%{$search}%")
                  ->orWhere('pm.contact_person', 'like', "%{$search}%");
            });
        }

        $managers = $q->get()->map(fn($r) => [
            'id' => (int) $r->id,
            'company_name' => $r->company_name,
            'address_street' => $r->address_street,
            'address_zip' => $r->address_zip,
            'address_city' => $r->address_city,
            'email' => $r->email,
            'phone' => $r->phone,
            'contact_person' => $r->contact_person,
            'notes' => $r->notes,
            'property_count' => (int) $r->property_count,
            'created_at' => $r->created_at,
        ])->all();

        return response()->json(['success' => true, 'managers' => $managers]);
    }

    private function createPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($companyName === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'company_name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'email format invalid'], 422);
        }

        $manager = \App\Models\PropertyManager::create([
            'company_name' => $companyName,
            'email' => $email,
            'address_street' => trim((string) ($data['address_street'] ?? '')) ?: null,
            'address_zip' => trim((string) ($data['address_zip'] ?? '')) ?: null,
            'address_city' => trim((string) ($data['address_city'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'contact_person' => trim((string) ($data['contact_person'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_by' => \Auth::id(),
        ]);

        // Cache invalidieren: neue HV-Domain greift sofort im Nachfass-Filter
        \Illuminate\Support\Facades\Cache::forget('property_manager_domains');

        return response()->json(['success' => true, 'manager_id' => $manager->id, 'manager' => $manager]);
    }

    private function updatePropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $id = (int) ($data['id'] ?? 0);
        if (!$id) return response()->json(['success' => false, 'error' => 'id required'], 400);

        $manager = \App\Models\PropertyManager::find($id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'not found'], 404);

        foreach (['company_name', 'email', 'address_street', 'address_zip', 'address_city', 'phone', 'contact_person', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $manager->$field = $value !== '' ? $value : null;
            }
        }
        if (!$manager->company_name || !$manager->email) {
            return response()->json(['success' => false, 'error' => 'company_name and email cannot be empty'], 422);
        }
        $manager->save();

        // Legacy-Sync: alle zugeordneten Properties bekommen den neuen Namen ins String-Feld
        \DB::table('properties')
            ->where('property_manager_id', $manager->id)
            ->update(['property_manager' => $manager->company_name]);

        // Cache invalidieren — Email-Domain koennte sich geaendert haben
        \Illuminate\Support\Facades\Cache::forget('property_manager_domains');

        return response()->json(['success' => true, 'manager' => $manager]);
    }

    private function deletePropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $id = (int) ($data['id'] ?? 0);
        if (!$id) return response()->json(['success' => false, 'error' => 'id required'], 400);

        $manager = \App\Models\PropertyManager::find($id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'not found'], 404);

        $assignedCount = \DB::table('properties')->where('property_manager_id', $id)->count();
        if ($assignedCount > 0) {
            return response()->json([
                'success' => false,
                'error' => "Hausverwaltung ist noch {$assignedCount} Objekt(en) zugewiesen. Zuerst umhängen oder entfernen.",
                'assigned_count' => $assignedCount,
            ], 409);
        }

        $manager->delete();

        // Cache invalidieren — Domain ist nicht mehr geschuetzt
        \Illuminate\Support\Facades\Cache::forget('property_manager_domains');

        return response()->json(['success' => true]);
    }

    private function assignPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $managerId = isset($data['property_manager_id']) && $data['property_manager_id'] !== '' && $data['property_manager_id'] !== null
            ? (int) $data['property_manager_id']
            : null;

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung: Objekt gehört einem anderen Makler'], 403);
            }
        }

        $managerName = null;
        if ($managerId) {
            $mgr = \DB::table('property_managers')->where('id', $managerId)->first();
            if (!$mgr) return response()->json(['success' => false, 'error' => 'Hausverwaltung nicht gefunden'], 404);
            $managerName = $mgr->company_name;
        }

        \DB::table('properties')->where('id', $propertyId)->update([
            'property_manager_id' => $managerId,
            'property_manager' => $managerName,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'property_id' => $propertyId, 'property_manager_id' => $managerId]);
    }

    private function quickCreateAndAssignPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $companyName = trim((string) ($data['company_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if ($companyName === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'company_name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'email format invalid'], 422);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $result = \DB::transaction(function () use ($data, $propertyId, $companyName, $email, $userId) {
            $manager = \App\Models\PropertyManager::create([
                'company_name' => $companyName,
                'email' => $email,
                'address_street' => trim((string) ($data['address_street'] ?? '')) ?: null,
                'address_zip' => trim((string) ($data['address_zip'] ?? '')) ?: null,
                'address_city' => trim((string) ($data['address_city'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'contact_person' => trim((string) ($data['contact_person'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $userId,
            ]);

            \DB::table('properties')->where('id', $propertyId)->update([
                'property_manager_id' => $manager->id,
                'property_manager' => $manager->company_name,
                'updated_at' => now(),
            ]);

            return $manager;
        });

        return response()->json(['success' => true, 'manager_id' => $result->id, 'manager' => $result, 'property_id' => $propertyId]);
    }

    private function uploadAva(Request $request): JsonResponse
    {
        $propertyId = (int) $request->input('property_id', 0);
        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'error' => 'file required'], 400);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $file = $request->file('file');
        $dir = 'property_files/' . $propertyId;
        $filename = 'AVA_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $filename, 'public');

        \DB::transaction(function () use ($propertyId, $file, $path) {
            \DB::table('property_files')
                ->where('property_id', $propertyId)
                ->where('is_ava', 1)
                ->update(['is_ava' => 0]);

            \DB::table('property_files')->insert([
                'property_id' => $propertyId,
                'label' => 'Alleinvermittlungsauftrag',
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_ava' => 1,
                'is_website_download' => 0,
                'created_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'path' => $path]);
    }

    private function renamePropertyFile(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $fileId = (int) ($data['file_id'] ?? 0);
        $newLabel = trim((string) ($data['label'] ?? ''));

        if (!$fileId) return response()->json(['success' => false, 'error' => 'file_id required'], 400);
        if ($newLabel === '') return response()->json(['success' => false, 'error' => 'label cannot be empty'], 422);
        if (mb_strlen($newLabel) > 200) return response()->json(['success' => false, 'error' => 'label too long (max 200)'], 422);

        $file = \DB::table('property_files')->where('id', $fileId)->first();
        if (!$file) return response()->json(['success' => false, 'error' => 'file not found'], 404);

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $file->property_id)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        \DB::table('property_files')->where('id', $fileId)->update([
            'label' => $newLabel,
        ]);

        return response()->json(['success' => true, 'label' => $newLabel]);
    }

    private function markFileAsAva(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $fileId = (int) ($data['file_id'] ?? 0);
        $isAva = (bool) ($data['is_ava'] ?? true);

        if (!$fileId) return response()->json(['success' => false, 'error' => 'file_id required'], 400);

        $file = \DB::table('property_files')->where('id', $fileId)->first();
        if (!$file) return response()->json(['success' => false, 'error' => 'file not found'], 404);

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $file->property_id)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        \DB::transaction(function () use ($file, $isAva) {
            if ($isAva) {
                // Alle anderen AVAs derselben Property entmarkieren
                \DB::table('property_files')
                    ->where('property_id', $file->property_id)
                    ->where('id', '!=', $file->id)
                    ->where('is_ava', 1)
                    ->update(['is_ava' => 0]);
            }
            \DB::table('property_files')->where('id', $file->id)->update(['is_ava' => $isAva ? 1 : 0]);
        });

        return response()->json(['success' => true, 'is_ava' => $isAva]);
    }

    // ===== Hausverwaltung (Phase 2 — Contact Flows) =====

    private function contactPropertyManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $templateKind = (string) ($data['template_kind'] ?? '');
        $sourceEmailId = isset($data['source_email_id']) && $data['source_email_id'] ? (int) $data['source_email_id'] : null;

        if (!$propertyId) return response()->json(['success' => false, 'error' => 'property_id required'], 400);
        if (!in_array($templateKind, ['unterlagen', 'mieter_meldung', 'freitext'], true)) {
            return response()->json(['success' => false, 'error' => 'invalid template_kind'], 400);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property) return response()->json(['success' => false, 'error' => 'property not found'], 404);
        if (!$property->property_manager_id) {
            return response()->json(['success' => false, 'error' => 'Keine Hausverwaltung zugeordnet', 'needs_manager' => true], 422);
        }

        $manager = \App\Models\PropertyManager::find($property->property_manager_id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'property_manager_id verweist auf nicht existierende HV'], 500);

        $sourceEmail = $sourceEmailId ? \App\Models\PortalEmail::find($sourceEmailId) : null;
        if ($templateKind === 'mieter_meldung' && !$sourceEmail) {
            return response()->json(['success' => false, 'error' => 'mieter_meldung template requires source_email_id'], 400);
        }

        $maklerUser = \Auth::user();

        try {
            $svc = app(\App\Services\PropertyManagerContactService::class);
            $draft = $svc->buildDraft($property, $manager, $templateKind, $sourceEmail, $maklerUser);
        } catch (\Throwable $e) {
            \Log::error('contactPropertyManager failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Entwurf-Generierung fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'draft' => [
                'to' => $manager->email,
                'to_name' => $manager->company_name,
                'subject' => $draft['subject'],
                'body' => $draft['body'],
                'attachments' => $draft['attachments'],
            ],
            'ava_missing' => $draft['ava_missing'],
            'manager' => [
                'id' => $manager->id,
                'company_name' => $manager->company_name,
                'email' => $manager->email,
            ],
        ]);
    }

    private function sendToManager(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $attachmentFileIds = is_array($data['attachment_file_ids'] ?? null) ? $data['attachment_file_ids'] : [];
        $sourceEmailId = isset($data['source_email_id']) && $data['source_email_id'] ? (int) $data['source_email_id'] : null;

        if (!$propertyId || $subject === '' || $body === '') {
            return response()->json(['success' => false, 'error' => 'property_id, subject, body required'], 400);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property || !$property->property_manager_id) {
            return response()->json(['success' => false, 'error' => 'Property oder HV nicht gefunden'], 404);
        }
        $manager = \App\Models\PropertyManager::find($property->property_manager_id);
        if (!$manager) return response()->json(['success' => false, 'error' => 'HV nicht gefunden'], 404);

        // Attachment-Pfade aus property_files holen
        $attachmentPaths = [];
        if (!empty($attachmentFileIds)) {
            $files = \DB::table('property_files')
                ->whereIn('id', $attachmentFileIds)
                ->where('property_id', $propertyId)
                ->get();
            foreach ($files as $f) {
                $absPath = storage_path('app/public/' . $f->path);
                if (is_file($absPath)) {
                    $attachmentPaths[] = $absPath;
                }
            }
        }

        // Account fuer Versand: erstes aktives Account des Users
        $accountId = \DB::table('email_accounts')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->value('id');
        if (!$accountId) {
            return response()->json(['success' => false, 'error' => 'Kein aktives E-Mail-Konto fuer diesen User'], 500);
        }

        try {
            $emailService = app(\App\Services\EmailService::class);
            $result = $emailService->send(
                (int) $accountId,
                $manager->email,
                $subject,
                $body,
                $propertyId,
                $manager->company_name,
                null, // cc
                null, // bcc
                $attachmentPaths,
                null, // inReplyToMessageId
                null, // references
                'email-out',
                null
            );
        } catch (\Throwable $e) {
            \Log::error('sendToManager failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Versand fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        $sentEmailId = $result['email_id'] ?? null;

        try {
            \DB::table('activities')->insert([
                'property_id' => $propertyId,
                'activity_date' => now()->toDateString(),
                'stakeholder' => $manager->company_name,
                'activity' => 'An Hausverwaltung gesendet: ' . mb_substr($subject, 0, 200),
                'category' => 'hausverwaltung',
                'source_email_id' => $sentEmailId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('sendToManager activity log failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'email_id' => $sentEmailId]);
    }

    /**
     * Sendet einen Entwurf vom Makler an die Eigentuemer:in des Objekts.
     * Wird vom OwnerComposeDialog aufgerufen; Templates kommen aus dem
     * ContactOwnerSheet im Uebersicht-Tab. Multipart-Request, weil Anhaenge
     * direkt aus dem Browser hochgeladen werden koennen (Exposé-PDF o. a.).
     */
    private function sendToOwner(Request $request): JsonResponse
    {
        $propertyId = (int) $request->input('property_id', 0);
        $to         = trim((string) $request->input('to', ''));
        $subject    = trim((string) $request->input('subject', ''));
        $body       = trim((string) $request->input('body', ''));

        if (!$propertyId || $to === '' || $subject === '' || $body === '') {
            return response()->json(['success' => false, 'error' => 'property_id, to, subject, body required'], 400);
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'Ungueltige Empfaenger-Adresse'], 422);
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property) {
            return response()->json(['success' => false, 'error' => 'Property nicht gefunden'], 404);
        }

        $toName = trim((string) ($property->owner_name ?? '')) ?: null;

        // Anhaenge sammeln: zwei Quellen
        //   1. Frische Uploads ($request->file('attachments'))
        //   2. Aus property_files / portal_documents / global_files ausgewaehlte
        //      IDs (file_ids, doc_ids, global_ids als CSV oder Array).
        // EmailService erwartet einen Array von Pfaden (basename = Datei-
        // name beim Empfaenger), oder UploadedFile-Objekte (preserve original
        // name + mime). Wir nutzen UploadedFile fuer Uploads (loest den
        // "owner_att_xxx ohne Endung"-Bug) und Pfade fuer DB-Files.
        $attachments = [];
        $tmpFiles = [];

        // (1) Uploads: UploadedFile-Objekte direkt durchreichen.
        $uploads = $request->file('attachments') ?? [];
        if (!is_array($uploads)) $uploads = [$uploads];
        foreach ($uploads as $file) {
            if (!$file || !$file->isValid()) continue;
            if ($file->getSize() > 20 * 1024 * 1024) {
                return response()->json(['success' => false, 'error' => 'Anhang zu gross (max 20 MB)'], 422);
            }
            $attachments[] = $file;
        }

        // (2) Bestehende Property-Files (Exposé, BaB etc.) per ID.
        $rawFileIds = $request->input('file_ids', []);
        if (is_string($rawFileIds)) {
            $rawFileIds = array_filter(array_map('trim', explode(',', $rawFileIds)));
        }
        $fileIds = array_values(array_filter(array_map('intval', (array) $rawFileIds)));
        if (!empty($fileIds)) {
            $rows = \DB::table('property_files')
                ->whereIn('id', $fileIds)
                ->where('property_id', $propertyId)
                ->get();
            foreach ($rows as $row) {
                $absPath = storage_path('app/public/' . $row->path);
                if (!is_file($absPath)) continue;
                // Falls der DB-Dateiname von dem Path-basename abweicht
                // (z.B. wegen sanitizing beim Upload), kopieren wir die Datei
                // in einen Temp-Ordner mit dem gewuenschten DB-`filename`,
                // damit der Empfaenger den lesbaren Originalnamen sieht.
                $desiredName = trim((string) ($row->filename ?? '')) ?: basename($row->path);
                if (basename($row->path) === $desiredName) {
                    $attachments[] = $absPath;
                } else {
                    $tmpDir = sys_get_temp_dir() . '/owner-att-' . uniqid('', true);
                    @mkdir($tmpDir, 0755, true);
                    $renamed = $tmpDir . '/' . preg_replace('#[/\\\\]+#', '_', $desiredName);
                    if (@copy($absPath, $renamed)) {
                        $attachments[] = $renamed;
                        $tmpFiles[] = $renamed;
                    } else {
                        $attachments[] = $absPath;
                    }
                }
            }
        }

        // (3) Portal-Documents (Nebenkosten/Allgemeine Dokumente, mit `doc_`-Prefix
        // aus dem File-Picker, oder als reine ID).
        $rawDocIds = $request->input('doc_ids', []);
        if (is_string($rawDocIds)) {
            $rawDocIds = array_filter(array_map('trim', explode(',', $rawDocIds)));
        }
        $docIds = array_values(array_filter(array_map('intval', (array) $rawDocIds)));
        if (!empty($docIds)) {
            $rows = \DB::table('portal_documents')
                ->whereIn('id', $docIds)
                ->where('property_id', $propertyId)
                ->get();
            foreach ($rows as $row) {
                $absPath = storage_path('app/public/documents/' . $row->property_id . '/' . $row->filename);
                if (!is_file($absPath)) continue;
                $desiredName = trim((string) ($row->original_name ?? '')) ?: basename($absPath);
                $tmpDir = sys_get_temp_dir() . '/owner-att-' . uniqid('', true);
                @mkdir($tmpDir, 0755, true);
                $renamed = $tmpDir . '/' . preg_replace('#[/\\\\]+#', '_', $desiredName);
                if (@copy($absPath, $renamed)) {
                    $attachments[] = $renamed;
                    $tmpFiles[] = $renamed;
                } else {
                    $attachments[] = $absPath;
                }
            }
        }

        // Konto fuer Versand: erstes aktives Konto des Users
        $accountId = \DB::table('email_accounts')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->value('id');
        if (!$accountId) {
            return response()->json(['success' => false, 'error' => 'Kein aktives E-Mail-Konto'], 500);
        }

        $result = null;
        try {
            $emailService = app(\App\Services\EmailService::class);
            $result = $emailService->send(
                (int) $accountId,
                $to,
                $subject,
                $body,
                $propertyId,
                $toName,
                null, // cc
                null, // bcc
                $attachments,
                null, // inReplyToMessageId
                null, // references
                'email-out',
                null
            );
        } catch (\Throwable $e) {
            \Log::error('sendToOwner failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Versand fehlgeschlagen: ' . $e->getMessage()], 500);
        } finally {
            // Tmp-Kopien (umbenannte property_files / portal_documents) wieder
            // entfernen. Uploads sind UploadedFile-Objekte und werden von
            // PHP automatisch beim Request-Ende geraeumt.
            foreach ($tmpFiles as $p) {
                if (is_file($p)) @unlink($p);
                $dir = dirname($p);
                if (is_dir($dir) && @scandir($dir) === ['.', '..']) @rmdir($dir);
            }
        }

        $sentEmailId = $result['email_id'] ?? null;

        try {
            \DB::table('activities')->insert([
                'property_id' => $propertyId,
                'activity_date' => now()->toDateString(),
                'stakeholder' => $toName ?: $to,
                'activity' => 'An Eigentuemer:in gesendet: ' . mb_substr($subject, 0, 200),
                'category' => 'email-out',
                'source_email_id' => $sentEmailId ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('sendToOwner activity log failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'email_id' => $sentEmailId]);
    }

    /**
     * Sendet eine Mail an einen oder mehrere Kaeufer eines Objekts. Wird vom
     * BuyerComposeDialog im Kaufangebote-Tab aufgerufen. Bei mehreren
     * Empfaengern wird pro Empfaenger eine eigene Mail versandt (kein BCC), so
     * dass jeder eine individuelle Aktivitaet bekommt und keine E-Mail-Adressen
     * untereinander offengelegt werden.
     *
     * Request:
     *   - property_id (required)
     *   - to[] oder to (single)              -> Empfaenger-Adressen
     *   - to_names[] (optional, parallel zu to[]) -> Anzeigename pro Empfaenger
     *   - subject, body
     *   - attachments[], file_ids[], doc_ids[] (wie sendToOwner)
     */
    private function sendToBuyer(Request $request): JsonResponse
    {
        $propertyId = (int) $request->input('property_id', 0);
        $subject    = trim((string) $request->input('subject', ''));
        $body       = trim((string) $request->input('body', ''));

        // Empfaenger-Liste normalisieren.
        $rawTo = $request->input('to', []);
        if (is_string($rawTo)) {
            $rawTo = preg_split('/[,;]+/', $rawTo);
        }
        $toList = array_values(array_filter(array_map('trim', (array) $rawTo)));

        $rawNames = $request->input('to_names', []);
        if (is_string($rawNames)) $rawNames = [$rawNames];
        $nameList = (array) $rawNames;

        if (!$propertyId || empty($toList) || $subject === '' || $body === '') {
            return response()->json(['success' => false, 'error' => 'property_id, to, subject, body required'], 400);
        }
        foreach ($toList as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['success' => false, 'error' => 'Ungueltige Empfaenger-Adresse: ' . $addr], 422);
            }
        }

        $userId = (int) \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if (!in_array($userType, ['assistenz', 'backoffice'], true)) {
            $propBroker = \DB::table('properties')->where('id', $propertyId)->value('broker_id');
            if ($propBroker && $propBroker != $userId) {
                return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
            }
        }

        $property = \App\Models\Property::find($propertyId);
        if (!$property) {
            return response()->json(['success' => false, 'error' => 'Property nicht gefunden'], 404);
        }

        // Anhaenge sammeln (wie sendToOwner: Uploads als UploadedFile, DB-IDs als Pfade).
        $attachments = [];
        $tmpFiles = [];

        $uploads = $request->file('attachments') ?? [];
        if (!is_array($uploads)) $uploads = [$uploads];
        foreach ($uploads as $file) {
            if (!$file || !$file->isValid()) continue;
            if ($file->getSize() > 20 * 1024 * 1024) {
                return response()->json(['success' => false, 'error' => 'Anhang zu gross (max 20 MB)'], 422);
            }
            $attachments[] = $file;
        }

        $rawFileIds = $request->input('file_ids', []);
        if (is_string($rawFileIds)) {
            $rawFileIds = array_filter(array_map('trim', explode(',', $rawFileIds)));
        }
        $fileIds = array_values(array_filter(array_map('intval', (array) $rawFileIds)));
        if (!empty($fileIds)) {
            $rows = \DB::table('property_files')
                ->whereIn('id', $fileIds)
                ->where('property_id', $propertyId)
                ->get();
            foreach ($rows as $row) {
                $absPath = storage_path('app/public/' . $row->path);
                if (!is_file($absPath)) continue;
                $desiredName = trim((string) ($row->filename ?? '')) ?: basename($row->path);
                if (basename($row->path) === $desiredName) {
                    $attachments[] = $absPath;
                } else {
                    $tmpDir = sys_get_temp_dir() . '/buyer-att-' . uniqid('', true);
                    @mkdir($tmpDir, 0755, true);
                    $renamed = $tmpDir . '/' . preg_replace('#[/\\\\]+#', '_', $desiredName);
                    if (@copy($absPath, $renamed)) {
                        $attachments[] = $renamed;
                        $tmpFiles[] = $renamed;
                    } else {
                        $attachments[] = $absPath;
                    }
                }
            }
        }

        $rawDocIds = $request->input('doc_ids', []);
        if (is_string($rawDocIds)) {
            $rawDocIds = array_filter(array_map('trim', explode(',', $rawDocIds)));
        }
        $docIds = array_values(array_filter(array_map('intval', (array) $rawDocIds)));
        if (!empty($docIds)) {
            $rows = \DB::table('portal_documents')
                ->whereIn('id', $docIds)
                ->where('property_id', $propertyId)
                ->get();
            foreach ($rows as $row) {
                $absPath = storage_path('app/public/documents/' . $row->property_id . '/' . $row->filename);
                if (!is_file($absPath)) continue;
                $desiredName = trim((string) ($row->original_name ?? '')) ?: basename($absPath);
                $tmpDir = sys_get_temp_dir() . '/buyer-att-' . uniqid('', true);
                @mkdir($tmpDir, 0755, true);
                $renamed = $tmpDir . '/' . preg_replace('#[/\\\\]+#', '_', $desiredName);
                if (@copy($absPath, $renamed)) {
                    $attachments[] = $renamed;
                    $tmpFiles[] = $renamed;
                } else {
                    $attachments[] = $absPath;
                }
            }
        }

        $accountId = \DB::table('email_accounts')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->value('id');
        if (!$accountId) {
            return response()->json(['success' => false, 'error' => 'Kein aktives E-Mail-Konto'], 500);
        }

        $emailService = app(\App\Services\EmailService::class);

        $sent = [];
        $failed = [];

        try {
            foreach ($toList as $idx => $to) {
                $toName = trim((string) ($nameList[$idx] ?? '')) ?: null;
                try {
                    $result = $emailService->send(
                        (int) $accountId,
                        $to,
                        $subject,
                        $body,
                        $propertyId,
                        $toName,
                        null, // cc
                        null, // bcc
                        $attachments,
                        null, null,
                        'email-out',
                        null
                    );
                    $sentEmailId = $result['email_id'] ?? null;
                    $sent[] = ['to' => $to, 'email_id' => $sentEmailId];

                    try {
                        \DB::table('activities')->insert([
                            'property_id'      => $propertyId,
                            'activity_date'    => now()->toDateString(),
                            'stakeholder'      => $toName ?: $to,
                            'activity'         => 'An Kaeufer:in gesendet: ' . mb_substr($subject, 0, 200),
                            'category'         => 'email-out',
                            'source_email_id'  => $sentEmailId ?: null,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    } catch (\Throwable $e) {
                        \Log::warning('sendToBuyer activity log failed', ['error' => $e->getMessage()]);
                    }
                } catch (\Throwable $e) {
                    \Log::error('sendToBuyer send failed', ['to' => $to, 'error' => $e->getMessage()]);
                    $failed[] = ['to' => $to, 'error' => $e->getMessage()];
                }
            }
        } finally {
            foreach ($tmpFiles as $p) {
                if (is_file($p)) @unlink($p);
                $dir = dirname($p);
                if (is_dir($dir) && @scandir($dir) === ['.', '..']) @rmdir($dir);
            }
        }

        if (empty($sent) && !empty($failed)) {
            return response()->json([
                'success' => false,
                'error'   => 'Versand fehlgeschlagen',
                'failed'  => $failed,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }

    /**
     * Geocode eine Adresse via OpenStreetMap Nominatim.
     * Ergebnisse werden fuer 30 Tage gecacht (Nominatim Usage Policy: 1 req/s, cache empfohlen).
     * Erwartet: address (Strasse + Hausnummer), zip, city (oder freies q).
     * Liefert: lat, lng, display_name.
     */
    private function geocodeAddress(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $address = trim((string) ($data['address'] ?? ''));
        $houseNumber = trim((string) ($data['house_number'] ?? ''));
        $zip = trim((string) ($data['zip'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $query = trim((string) ($data['q'] ?? ''));

        // Zusammenbauen wenn keine explizite query.
        if ($query === '') {
            $parts = [];
            if ($address !== '') $parts[] = trim($address . ' ' . $houseNumber);
            if ($zip !== '' || $city !== '') $parts[] = trim($zip . ' ' . $city);
            $parts[] = 'Austria';  // Scope: Oesterreich
            $query = implode(', ', array_filter($parts));
        }

        if ($query === '') {
            return response()->json(['success' => false, 'error' => 'Adresse leer'], 422);
        }

        $cacheKey = 'geocode:' . md5(mb_strtolower($query));
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) return response()->json(['success' => true] + $cached + ['cached' => true]);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'SR-Homes Immobilien (kundenportal.sr-homes.at)',
                'Accept-Language' => 'de',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'at',
                'addressdetails' => 0,
            ]);

            if (!$response->ok()) {
                return response()->json(['success' => false, 'error' => 'Nominatim: ' . $response->status()], 502);
            }
            $results = $response->json();
            if (!is_array($results) || count($results) === 0) {
                return response()->json(['success' => false, 'error' => 'Keine Ergebnisse für "' . $query . '"'], 404);
            }

            $first = $results[0];
            $payload = [
                'lat' => isset($first['lat']) ? (float) $first['lat'] : null,
                'lng' => isset($first['lon']) ? (float) $first['lon'] : null,
                'display_name' => $first['display_name'] ?? null,
                'query' => $query,
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, now()->addDays(30));
            return response()->json(['success' => true] + $payload);
        } catch (\Throwable $e) {
            \Log::warning('geocode_address failed', ['error' => $e->getMessage(), 'query' => $query]);
            return response()->json(['success' => false, 'error' => 'Geocoding fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Live-Autocomplete fuer Adresseingabe via Nominatim.
     * Liefert bis zu 5 Vorschlaege mit Adress-Details zum direkten Einfuellen.
     * Cache: 7 Tage pro Query (Nominatim Usage Policy: max 1 req/s).
     */
    private function geocodeAutocomplete(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('q') ?? $request->query('q') ?? ''));
        if (mb_strlen($query) < 3) {
            return response()->json(['success' => true, 'results' => []]);
        }

        $cacheKey = 'geocode_ac:' . md5(mb_strtolower($query));
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) return response()->json(['success' => true, 'results' => $cached, 'cached' => true]);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'SR-Homes Immobilien (kundenportal.sr-homes.at)',
                'Accept-Language' => 'de',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 5,
                'countrycodes' => 'at',
                'addressdetails' => 1,
            ]);

            if (!$response->ok()) {
                return response()->json(['success' => false, 'error' => 'Nominatim: ' . $response->status()], 502);
            }
            $raw = $response->json() ?: [];
            $results = [];
            foreach ($raw as $r) {
                $addr = $r['address'] ?? [];
                $street = $addr['road']
                    ?? $addr['pedestrian']
                    ?? $addr['footway']
                    ?? $addr['path']
                    ?? '';
                $results[] = [
                    'display_name' => $r['display_name'] ?? '',
                    'lat'          => isset($r['lat']) ? (float) $r['lat'] : null,
                    'lng'          => isset($r['lon']) ? (float) $r['lon'] : null,
                    'street'       => $street,
                    'house_number' => $addr['house_number'] ?? '',
                    'zip'          => $addr['postcode'] ?? '',
                    'city'         => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? '',
                ];
            }
            \Illuminate\Support\Facades\Cache::put($cacheKey, $results, now()->addDays(7));
            return response()->json(['success' => true, 'results' => $results]);
        } catch (\Throwable $e) {
            \Log::warning('geocode_autocomplete failed', ['error' => $e->getMessage(), 'q' => $query]);
            return response()->json(['success' => false, 'error' => 'Autocomplete fehlgeschlagen'], 500);
        }
    }
}