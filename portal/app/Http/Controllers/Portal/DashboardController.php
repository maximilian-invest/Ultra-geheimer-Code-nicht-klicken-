<?php

namespace App\Http\Controllers\Portal;

use App\Helpers\KaufanbotHelper;
use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use App\Services\PhoneExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Admin/Makler should not see the customer portal — redirect to admin
        if (in_array($user->user_type ?? '', ['admin', 'makler'])) {
            return redirect('/admin');
        }

        $customer   = $this->resolveCustomer($user);
        $customer = $this->resolveCustomer($user);
        $customerId = $this->resolveCustomerId($user);

        $properties = [];
        $isBroker = in_array($user->user_type ?? '', ['makler', 'admin']);
        if ($customer || $isBroker) {
            $query = DB::table('properties');
            if ($customer && $customerId) {
                $query->where('customer_id', $customerId);
            } elseif ($isBroker) {
                // Makler sees their own properties (by broker_id = user_id)
                $query->where('broker_id', $user->id);
            }
            $properties = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($property) {
                    $activities = DB::table('activities')
                        ->where('property_id', $property->id)
                        ->get();

                    // Only count customer-relevant activities (not internal/system)
                    $customerActivities = $activities->whereNotIn('category', ['intern', 'bounce']);
                    $property->stats = (object) [
                        'activities' => $customerActivities->count(),
                        'viewings' => $activities->where('category', 'besichtigung')->count(),
                        'offers' => KaufanbotHelper::count($property->id),
                        'followups' => $activities->where('category', 'nachfassen')->count(),
                    ];

                    // Load units for newbuild properties (exclude parking)
                    $units = DB::table('property_units')
                        ->where('property_id', $property->id)
                        ->where(function($q) { $q->where('is_parking', 0)->orWhereNull('is_parking'); })
                        ->orderBy('floor')->orderBy('unit_number')
                        ->get();
                    if ($units->count()) {
                        $property->units_summary = (object) [
                            'total' => $units->count(),
                            'frei' => $units->where('status', 'frei')->count(),
                            'reserviert' => $units->where('status', 'reserviert')->count(),
                            'verkauft' => $units->where('status', 'verkauft')->count(),
                        ];
                    }

                    // Load broker (Ansprechpartner) per property
                    $property->broker = null;
                    if ($property->broker_id) {
                        $brokerUser = DB::table('users')->where('id', $property->broker_id)->first();
                        if ($brokerUser) {
                            $brokerSettings = DB::table('admin_settings')->where('user_id', $brokerUser->id)->first();
                            $bizEmail = DB::table('email_accounts')
                                ->where('user_id', $brokerUser->id)->where('is_active', 1)
                                ->value('email_address');
                            $property->broker = (object) [
                                'name' => $brokerSettings->signature_name ?? ($brokerUser->signature_name ?: $brokerUser->name),
                                'phone' => $brokerSettings->signature_phone ?? ($brokerUser->signature_phone ?: ''),
                                'email' => $bizEmail ?: ($brokerUser->email ?? ''),
                                'initials' => collect(explode(' ', $brokerSettings->signature_name ?? $brokerUser->name))->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
                            ];
                        }
                    }

                    return $property;
                })
                ->toArray();
        }

        $customerName = $customer
            ? (is_object($customer) ? ($customer->name ?? $user->name) : ($customer['name'] ?? $user->name))
            : $user->name;

        // Determine broker from first property (same logic as property detail page)
        $broker = null;
        if (!empty($properties)) {
            $firstProp = is_array($properties[0]) ? (object) $properties[0] : $properties[0];
            $brokerId = $firstProp->broker_id ?? null;
            if ($brokerId) {
                $brokerUser = DB::table('users')->where('id', $brokerId)->first();
                if ($brokerUser) {
                    $businessEmail = DB::table('email_accounts')
                        ->where('user_id', $brokerUser->id)
                        ->where('is_active', 1)
                        ->value('email_address');
                    $broker = (object) [
                        'name' => $brokerUser->signature_name ?: $brokerUser->name,
                        'email' => $businessEmail ?: ($brokerUser->signature_email ?? $brokerUser->email),
                        'phone' => $brokerUser->signature_phone ?: ($brokerUser->phone ?: ''),
                        'title' => $brokerUser->signature_title ?? '',
                        'company' => $brokerUser->signature_company ?? 'SR-Homes Immobilien GmbH',
                        'initials' => collect(explode(' ', $brokerUser->signature_name ?: $brokerUser->name))->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
                    ];
                }
            }
        }
        if (!$broker) {
            $adminUser = DB::table('users')->where('user_type', 'admin')->first();
            $broker = (object) [
                'name' => $adminUser->signature_name ?? 'SR-Homes',
                'email' => $adminUser->signature_email ?? 'hoelzl@sr-homes.at',
                'phone' => $adminUser->signature_phone ?? '',
                'title' => $adminUser->signature_title ?? '',
                'company' => $adminUser->signature_company ?? 'SR-Homes Immobilien GmbH',
                'initials' => 'SR',
            ];
        }

        // Parent-Child hierarchy: attach children to parents, remove children from top-level
        $propertiesById = [];
        foreach ($properties as $prop) {
            $p = is_array($prop) ? (object) $prop : $prop;
            $p->children = [];
            $propertiesById[$p->id] = $p;
        }
        $childIds = [];
        foreach ($properties as $prop) {
            $p = is_array($prop) ? (object) $prop : $prop;
            if (!empty($p->parent_id) && isset($propertiesById[$p->parent_id])) {
                $parent = $propertiesById[$p->parent_id];
                $parent->children[] = $p;
                // Aggregate child stats into parent
                if (isset($p->stats) && isset($parent->stats)) {
                    $parent->stats->activities += $p->stats->activities;
                    $parent->stats->viewings += $p->stats->viewings;
                    $parent->stats->offers += $p->stats->offers;
                    $parent->stats->followups += $p->stats->followups;
                }
                $childIds[] = $p->id;
            }
        }
        // Filter out children from top-level properties list
        $properties = array_values(array_filter($properties, function($prop) use ($childIds) {
            $p = is_array($prop) ? (object) $prop : $prop;
            return !in_array($p->id, $childIds);
        }));

        // Group properties by project_group_id for portal display
        $projectGroups = [];
        $ungroupedProperties = [];
        $groupedPropertyIds = [];

        foreach ($properties as $prop) {
            $p = is_array($prop) ? (object) $prop : $prop;
            if (!empty($p->project_group_id)) {
                $gid = $p->project_group_id;
                if (!isset($projectGroups[$gid])) {
                    $group = DB::table('project_groups')->where('id', $gid)->first();
                    $projectGroups[$gid] = (object) [
                        'id' => $gid,
                        'name' => $group->name ?? 'Projekt',
                        'description' => $group->description ?? null,
                        'properties' => [],
                        'stats' => (object) ['activities' => 0, 'viewings' => 0, 'offers' => 0, 'followups' => 0],
                        'is_project_group' => true,
                    ];
                }
                $projectGroups[$gid]->properties[] = $p;
                // Aggregate stats
                if (isset($p->stats)) {
                    $projectGroups[$gid]->stats->activities += $p->stats->activities;
                    $projectGroups[$gid]->stats->viewings += $p->stats->viewings;
                    $projectGroups[$gid]->stats->offers += $p->stats->offers;
                    $projectGroups[$gid]->stats->followups += $p->stats->followups;
                }
                $groupedPropertyIds[] = $p->id;
            } else {
                $ungroupedProperties[] = $prop;
            }
        }

        // Convert groups to array and merge with ungrouped
        $displayItems = array_merge(array_values($projectGroups), $ungroupedProperties);

        return Inertia::render('Portal/Dashboard', [
            'customer' => (object) [
                'id' => $customerId,
                'name' => $customerName,
            ],
            'properties' => $properties,
            'projectGroups' => array_values($projectGroups),
            'displayItems' => $displayItems,
            'broker' => $broker,
        ]);
    }

    public function property(Request $request, $propertyId)
    {
        $user = $request->user();

        $customer = $this->resolveCustomer($user);
        $customerId = $this->resolveCustomerId($user);

        $query = DB::table('properties')->where('id', $propertyId);
        if ($customerId && !($user->user_type === 'admin')) {
            $query->where('customer_id', $customerId);
        }
        $property = $query->first();

        if (!$property) {
            return redirect()->route('portal.dashboard');
        }

        // Load activities
        $activities = DB::table('activities')
            ->leftJoin('portal_emails', 'portal_emails.id', '=', 'activities.source_email_id')
            ->where('activities.property_id', $propertyId)
            ->select(
                'activities.*',
                DB::raw('DATE_FORMAT(activities.created_at, "%H:%i") as activity_time'),
                DB::raw('COALESCE(portal_emails.has_attachment, 0) as has_attachment'),
                'portal_emails.attachment_names',
                'portal_emails.id as email_id',
                DB::raw("CASE WHEN portal_emails.direction = 'outbound' THEN portal_emails.to_email ELSE portal_emails.from_email END as stakeholder_email")
            )
            ->orderBy('activities.activity_date', 'desc')
            ->orderBy('activities.created_at', 'desc')
            ->orderBy('activities.id', 'desc')
            ->get()
            ->toArray();

        // Filter activities for customer view (hide negative broker/firm feedback)
        $activities = $this->filterActivitiesForCustomer($activities, $propertyId);

        // Sanitize activity texts: replace email subjects/content with clean labels
        $activities = $this->sanitizeActivityTexts($activities);

        // Cluster stakeholders: resolve name variants to canonical names
        $activities = $this->clusterStakeholders($activities, $propertyId);

        // Load messages
        $messages = [];
        try {
            $messages = DB::table('portal_messages')
                ->where('property_id', $propertyId)
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'asc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {}

        // Load documents (portal_documents + property_files)
        $documents = [];
        try {
            // 1. Portal documents (uploaded via admin "Dokumente" section)
            $portalDocs = DB::table('portal_documents')
                ->where('property_id', $propertyId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($doc) {
                    $doc->file_url = '/portal/documents/download/' . $doc->id;
                    $doc->file_name = $doc->original_name ?? $doc->filename;
                    $doc->uploaded_at = $doc->created_at;
                    $doc->source = 'portal_documents';
                    return $doc;
                });

            // 2. Property files (uploaded via admin "Dateien" – Exposés, BaB, etc.)
            $propertyFiles = DB::table('property_files')
                ->where('property_id', $propertyId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function($file) {
                    $file->file_url = '/portal/files/download/' . $file->id;
                    $file->file_name = $file->label ?: $file->filename;
                    $file->uploaded_at = $file->created_at;
                    $file->source = 'property_files';
                    $file->original_name = $file->label ?: $file->filename;
                    return $file;
                });

            $documents = $propertyFiles->merge($portalDocs)->toArray();
        } catch (\Exception $e) {}

        // Load viewings
        $viewings = [];
        try {
            $viewings = DB::table('viewings')
                ->where('property_id', $propertyId)
                ->whereIn('status', ['geplant', 'bestaetigt', 'durchgefuehrt'])
                ->orderBy('viewing_date', 'desc')
                ->orderBy('viewing_time', 'desc')
                ->get()
                ->map(function($v) {
                    // Anonymize - remove personal contact details
                    unset($v->person_name, $v->person_email, $v->person_phone);
                    return $v;
                })
                ->toArray();
        } catch (\Exception $e) {}

        $customerName = $customer
            ? (is_object($customer) ? ($customer->name ?? $user->name) : ($customer['name'] ?? $user->name))
            : $user->name;

        // Load ALL items (units + parking) to calculate total_price with parking
        $allItems = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->orderBy('floor')->orderBy('unit_number')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        // Build parking lookup
        $parkingLookup = [];
        foreach ($allItems as $item) {
            if ($item['is_parking'] ?? 0) {
                $parkingLookup[$item['id']] = $item;
            }
        }

        // Enrich units with total_price (unit price + assigned parking prices)
        foreach ($allItems as &$item) {
            if (!($item['is_parking'] ?? 0)) {
                $parkingTotal = 0;
                $parkingDetails = [];
                if (!empty($item['assigned_parking'])) {
                    $pids = json_decode($item['assigned_parking'], true) ?: [];
                    foreach ($pids as $pid) {
                        if (isset($parkingLookup[$pid])) {
                            $p = $parkingLookup[$pid];
                            $parkingDetails[] = [
                                'id' => $p['id'],
                                'unit_number' => $p['unit_number'],
                                'unit_type' => $p['unit_type'],
                                'price' => $p['price'] ?? 0,
                            ];
                            $parkingTotal += floatval($p['price'] ?? 0);
                        }
                    }
                }
                $item['parking_details'] = $parkingDetails;
                $item['parking_total'] = $parkingTotal;
                $item['total_price'] = floatval($item['price'] ?? 0) + $parkingTotal;
            }
        }
        unset($item);

        // Filter to only non-parking units
        $units = collect(array_values(array_filter($allItems, fn($u) => !($u['is_parking'] ?? 0))))
            ->map(function($unit) use ($propertyId) {
                $unit = (object) $unit;
                // Find linked accepted Kaufanbot activity
                if ($unit->status === 'verkauft' && $unit->id) {
                    $kaufanbot = DB::table('activities')
                        ->where('unit_id', $unit->id)
                        ->where('category', 'kaufanbot')
                        ->where('kaufanbot_status', 'akzeptiert')
                        ->orderBy('activity_date', 'desc')
                        ->first();
                    if ($kaufanbot) {
                        $unit->kaufanbot = (object) [
                            'stakeholder' => $kaufanbot->stakeholder,
                            'date' => $kaufanbot->activity_date,
                            'activity' => $kaufanbot->activity,
                        ];
                    }
                    // Fallback: match by buyer_name if no unit_id link
                    if (!isset($unit->kaufanbot) && $unit->buyer_name) {
                        $kaufanbot = DB::table('activities')
                            ->where('property_id', $propertyId)
                            ->where('category', 'kaufanbot')
                            ->where('kaufanbot_status', 'akzeptiert')
                            ->where('stakeholder', 'like', '%' . explode('/', $unit->buyer_name)[0] . '%')
                            ->orderBy('activity_date', 'desc')
                            ->first();
                        if ($kaufanbot) {
                            $unit->kaufanbot = (object) [
                                'stakeholder' => $kaufanbot->stakeholder,
                                'date' => $kaufanbot->activity_date,
                                'activity' => $kaufanbot->activity,
                            ];
                        }
                    }
                }
                return $unit;
            })
            ->toArray();

        // Load broker (Ansprechpartner) from signature settings in admin panel
        $broker = null;
        if ($property->broker_id) {
            $brokerUser = DB::table('users')->where('id', $property->broker_id)->first();
            if ($brokerUser) {
                // Get email account for business email (not login email)
                $businessEmail = DB::table('email_accounts')
                    ->where('user_id', $brokerUser->id)
                    ->where('is_active', 1)
                    ->value('email_address');

                $broker = (object) [
                    'name' => $brokerUser->signature_name ?: $brokerUser->name,
                    'email' => $businessEmail ?: ($brokerUser->signature_email ?? $brokerUser->email),
                    'phone' => $brokerUser->signature_phone ?: ($brokerUser->phone ?: ''),
                    'title' => $brokerUser->signature_title ?? '',
                    'company' => $brokerUser->signature_company ?? 'SR-Homes Immobilien GmbH',
                    'initials' => collect(explode(' ', $brokerUser->signature_name ?: $brokerUser->name))->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
                ];
            }
        }
        if (!$broker) {
            // Fallback: load from first admin user's settings
            $adminUser = DB::table('users')->where('user_type', 'admin')->first();
            $broker = (object) [
                'name' => $adminUser->signature_name ?? 'SR-Homes',
                'email' => $adminUser->signature_email ?? 'hoelzl@sr-homes.at',
                'phone' => $adminUser->signature_phone ?? '',
                'title' => $adminUser->signature_title ?? '',
                'company' => $adminUser->signature_company ?? 'SR-Homes Immobilien GmbH',
                'initials' => 'SR',
            ];
        }

        // Add kaufanbot_count to property for frontend
        $property->kaufanbot_count = KaufanbotHelper::count($propertyId);

        return Inertia::render('Portal/Property', [
            'customer' => (object) [
                'id' => $customerId,
                'name' => $customerName,
            ],
            'property' => $property,
            'activities' => $activities,
            'messages' => $messages,
            'documents' => $documents,
            'viewings' => $viewings,
            'units' => array_values($units),
            'parking' => array_values(array_filter($allItems, fn($u) => ($u['is_parking'] ?? 0))),
            'broker' => $broker,
        ]);
    }

    /**
     * Generate KI-Analyse for a property (called via AJAX from the portal)
     */
    public function analysis(Request $request, $propertyId)
    {
        $user = $request->user();

        $customer = $this->resolveCustomer($user);
        $customerId = $this->resolveCustomerId($user);

        $query = DB::table('properties')->where('id', $propertyId);
        if ($customerId && !($user->user_type === 'admin')) {
            $query->where('customer_id', $customerId);
        }
        $property = $query->first();

        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        // Check for Vermarktungsbericht first
        $bericht = DB::table('property_analyses')
            ->where('property_id', $propertyId)
            ->where('report_type', 'vermarktungsbericht')
            ->orderByDesc('created_at')
            ->first();

        if ($bericht) {
            $berichtData = json_decode($bericht->analysis_json, true);
            if ($berichtData) {
                // Remove broker data — owner must NEVER see internal analysis
                unset($berichtData['broker']);
                $berichtData['generatedAt'] = $bericht->created_at;
                $berichtData['report_type'] = 'vermarktungsbericht';
                return response()->json($berichtData, 200, [], JSON_UNESCAPED_UNICODE);
            }
        }

        // Load activities for stats
        $activities = DB::table('activities')
            ->where('property_id', $propertyId)
            ->orderBy('activity_date', 'desc')
            ->get()
            ->toArray();

        // Build stats
        $totalActivities = count($activities);
        $viewings = collect($activities)->where('category', 'besichtigung')->count();
        $offers = KaufanbotHelper::count($property->id);
        $emailsIn = collect($activities)->whereIn('category', ['email-in', 'anfrage'])->count();
        $exposes = collect($activities)->where('category', 'expose')->count();

        $stats = [
            'total_activities' => $totalActivities,
            'viewings' => $viewings,
            'offers' => $offers,
            'emails_in' => $emailsIn,
            'exposes' => $exposes,
        ];

        $propertyAddress = trim(($property->address ?? '') . ' ' . ($property->zip ?? '') . ' ' . ($property->city ?? ''));

        // Convert activities to arrays for the AI service
        $activityArrays = array_map(function ($a) {
            return (array) $a;
        }, $activities);

        try {
            $ai = app(AnthropicService::class);
            $result = $ai->generateDashboardAnalysis($stats, $activityArrays, $propertyAddress);

            if ($result) {
                $decoded = json_decode($result, true);
                if ($decoded) {
                    return response()->json($decoded);
                }
            }
        } catch (\Exception $e) {
            Log::error('Analysis generation failed: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'green',
            'headline' => 'Vermarktung aktiv',
            'summary' => 'Die Vermarktung Ihrer Immobilie laueft planmaessig.',
            'kpis' => [
                ['label' => 'Anfragen', 'value' => (string) $emailsIn, 'trend' => 'stable'],
                ['label' => 'Besichtigungen', 'value' => (string) $viewings, 'trend' => 'stable'],
                ['label' => 'Kaufanbote', 'value' => (string) $offers, 'trend' => 'stable'],
            ],
            'highlights' => [],
            'recommendation' => '',
        ]);
    }

    /**
     * Owner sends a message (chat)
     */
    public function sendMessage(Request $request, $propertyId)
    {
        $user = $request->user();

        // Verify property ownership
        $customer = $this->resolveCustomer($user);
        $customerId = $this->resolveCustomerId($user);

        $query = DB::table('properties')->where('id', $propertyId);
        if ($customerId && !($user->user_type === 'admin')) {
            $query->where('customer_id', $customerId);
        }
        $property = $query->first();

        if (!$property) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $message = trim($request->input('message', ''));
        if (!$message || strlen($message) > 2000) {
            return response()->json(['error' => 'Message required (max 2000 chars)'], 422);
        }

        $customerName = $customer
            ? (is_object($customer) ? ($customer->name ?? $user->name) : ($customer['name'] ?? $user->name))
            : $user->name;

        $id = DB::table('portal_messages')->insertGetId([
            'property_id' => $propertyId,
            'author_name' => $customerName,
            'author_role' => 'customer',
            'message'     => $message,
            'is_pinned'   => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $msg = DB::table('portal_messages')->where('id', $id)->first();

        return response()->json(['success' => true, 'message' => $msg]);
    }

    /**
     * Download a document (portal authenticated)
     */
    public function downloadDocument(Request $request, $documentId)
    {
        $user = $request->user();

        $doc = DB::table('portal_documents')->where('id', $documentId)->first();
        if (!$doc) {
            abort(404);
        }

        // Verify ownership
        if ($user->user_type !== 'admin') {
            $customer = $this->resolveCustomer($user);
        $customerId = $this->resolveCustomerId($user);

            $property = DB::table('properties')
                ->where('id', $doc->property_id)
                ->where('customer_id', $customerId)
                ->first();

            if (!$property) {
                abort(403);
            }
        }

        $filePath = storage_path('app/public/documents/' . $doc->property_id . '/' . $doc->filename);

        if (!file_exists($filePath)) {
            abort(404, 'File not found');
        }
        // Prevent directory traversal
        $realPath = realpath($filePath);
        if (!$realPath || !str_starts_with($realPath, storage_path('app/public/documents'))) {
            abort(403);
        }

        return response()->download($filePath, $doc->original_name ?? $doc->filename);
    }

    /**
     * Download a property file (Exposé, BaB, etc.)
     */
    public function downloadPropertyFile(Request $request, $fileId)
    {
        $user = $request->user();
        $file = DB::table('property_files')->where('id', $fileId)->first();
        if (!$file) abort(404);

        if ($user->user_type !== 'admin') {
            $customerId = $this->resolveCustomerId($user);
            $property = DB::table('properties')
                ->where('id', $file->property_id)
                ->where('customer_id', $customerId)
                ->first();
            if (!$property) abort(403);
        }

        $filePath = storage_path('app/public/' . $file->path);
        if (!file_exists($filePath)) abort(404, 'File not found');
        // Prevent directory traversal
        $realPath = realpath($filePath);
        if (!$realPath || !str_starts_with($realPath, storage_path('app/public'))) {
            abort(403);
        }

        return response()->download($filePath, $file->label ?: $file->filename);
    }

    /**
     * Cluster stakeholder names: resolve variants to a single canonical name per person.
     *
     * Strategy (3 levels):
     * 1. Contacts DB lookup: match stakeholder against contacts.full_name, contacts.email, contacts.aliases
     * 2. Static normalization: strip platform suffixes, trim, normalize casing
     * 3. Cross-matching: if an email-like stakeholder matches a name-based stakeholder via the contacts table,
     *    unify them under the full_name from contacts.
     *
     * Each activity gets a 'canonical_name' field for frontend grouping.
     */
    private function clusterStakeholders(array $activities, int $propertyId): array
    {
        if (empty($activities)) {
            return $activities;
        }

        // Collect unique raw stakeholder names
        $rawNames = [];
        foreach ($activities as $act) {
            $sh = is_object($act) ? ($act->stakeholder ?? '') : ($act['stakeholder'] ?? '');
            if ($sh && !in_array($sh, $rawNames)) {
                $rawNames[] = $sh;
            }
        }

        if (empty($rawNames)) {
            foreach ($activities as &$act) {
                if (is_object($act)) $act->canonical_name = '(Unbekannt)';
                else $act['canonical_name'] = '(Unbekannt)';
            }
            return $activities;
        }

        // Load all contacts that could match this property
        $contacts = DB::table('contacts')->get();

        // Build mapping: raw_name -> canonical_name
        $mapping = [];

        // --- Step 1: Static normalization ---
        $normalized = [];
        foreach ($rawNames as $raw) {
            $clean = $raw;
            // Strip platform suffixes: "Name (willhaben)" → "Name"
            $clean = trim(preg_replace('/\s*\((?:willhaben|immowelt|immoscout24|ImmoScout24|scout24)\)\s*$/i', '', $clean));
            // Strip firm suffixes: "Name - RE/MAX" → "Name"
            $clean = trim(preg_replace('/\s*[-–]\s*(RE\/MAX|Raiffeisen|sReal|EHL|Engel).*$/i', '', $clean));
            // Strip couple suffixes: "Name / Partner" → "Name"
            $clean = trim(preg_replace('/\s*\/\s*\S+\s*$/', '', $clean));
            $normalized[$raw] = trim($clean);
        }

        // --- Step 2: Contacts DB lookup ---
        // Build lookup indexes from contacts
        $contactByNameLower = [];  // lowercase full_name → contact
        $contactByEmail = [];       // email → contact
        $contactByAlias = [];       // lowercase alias → contact

        foreach ($contacts as $c) {
            $fn = $c->full_name ?? '';
            if ($fn) {
                $contactByNameLower[mb_strtolower(trim($fn))] = $c;
            }
            $email = $c->email ?? '';
            if ($email) {
                $contactByEmail[mb_strtolower(trim($email))] = $c;
            }
            $aliases = json_decode($c->aliases ?? '[]', true);
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    if ($alias) {
                        $contactByAlias[mb_strtolower(trim($alias))] = $c;
                    }
                }
            }
        }

        // --- Step 2b: Build email lookup from portal_emails for this property ---
        // Maps stakeholder names to their known email addresses
        $stakeholderEmails = []; // lowercase stakeholder → email
        $emailToStakeholders = []; // email → [stakeholder names]
        
        // System emails that should NOT be used for cross-matching
        // (they forward messages on behalf of multiple different people)
        $systemEmailPatterns = [
            'notification', 'noreply', 'no-reply', 'mailer-daemon', 'postmaster',
            'typeform', 'followups.typeform', 'willhaben', 'immowelt', 'immoscout',
            'calendly', 'info@', 'office@', 'support@', 'system@'
        ];
        
        $emailRows = DB::select(
            "SELECT DISTINCT pe.stakeholder, pe.from_email, pe.to_email, pe.direction
             FROM portal_emails pe
             WHERE pe.property_id = ?
             AND pe.stakeholder IS NOT NULL AND pe.stakeholder != ''",
            [$propertyId]
        );
        foreach ($emailRows as $row) {
            $email = $row->direction === 'outbound' ? $row->to_email : $row->from_email;
            // Clean email: strip display name if present e.g. '"name" <email>'
            if (preg_match('/<([^>]+)>/', $email, $em)) {
                $email = $em[1];
            }
            $email = mb_strtolower(trim($email));
            
            // Skip system/notification emails for cross-matching
            $isSystemEmail = false;
            foreach ($systemEmailPatterns as $pattern) {
                if (stripos($email, $pattern) !== false) {
                    $isSystemEmail = true;
                    break;
                }
            }
            
            $shLower = mb_strtolower(trim($row->stakeholder));
            if ($email && $shLower && !$isSystemEmail) {
                $stakeholderEmails[$shLower] = $email;
                if (!isset($emailToStakeholders[$email])) {
                    $emailToStakeholders[$email] = [];
                }
                if (!in_array($row->stakeholder, $emailToStakeholders[$email])) {
                    $emailToStakeholders[$email][] = $row->stakeholder;
                }
            }
        }

        foreach ($rawNames as $raw) {
            $clean = $normalized[$raw];
            $lowerClean = mb_strtolower($clean);
            $lowerRaw = mb_strtolower($raw);

            // Try exact match on full_name
            if (isset($contactByNameLower[$lowerClean])) {
                $mapping[$raw] = $contactByNameLower[$lowerClean]->full_name;
                continue;
            }
            if (isset($contactByNameLower[$lowerRaw])) {
                $mapping[$raw] = $contactByNameLower[$lowerRaw]->full_name;
                continue;
            }

            // Try alias match
            if (isset($contactByAlias[$lowerClean])) {
                $mapping[$raw] = $contactByAlias[$lowerClean]->full_name;
                continue;
            }
            if (isset($contactByAlias[$lowerRaw])) {
                $mapping[$raw] = $contactByAlias[$lowerRaw]->full_name;
                continue;
            }

            // Try email match (stakeholder IS an email address)
            if (filter_var($raw, FILTER_VALIDATE_EMAIL) && isset($contactByEmail[$lowerRaw])) {
                $mapping[$raw] = $contactByEmail[$lowerRaw]->full_name;
                continue;
            }
            if (filter_var($clean, FILTER_VALIDATE_EMAIL) && isset($contactByEmail[$lowerClean])) {
                $mapping[$raw] = $contactByEmail[$lowerClean]->full_name;
                continue;
            }

            // Try partial email-to-name matching: extract name parts from email handle
            if (preg_match('/^([a-zA-Z]+)[._]([a-zA-Z]+)/', $raw, $m)) {
                // e.g. carmen.graf2@web.de → carmen, graf
                $firstName = mb_strtolower($m[1]);
                $lastName = mb_strtolower(preg_replace('/\d+$/', '', $m[2]));
                if (strlen($lastName) >= 3) {
                    foreach ($contactByNameLower as $cNameLower => $contact) {
                        $parts = preg_split('/\s+/', $cNameLower);
                        if (count($parts) >= 2) {
                            $cFirst = $parts[0];
                            $cLast = end($parts);
                            if ($cFirst === $firstName && $cLast === $lastName) {
                                $mapping[$raw] = $contact->full_name;
                                break;
                            }
                        }
                    }
                    if (isset($mapping[$raw])) continue;
                }
            }
        }

        // --- Step 2.9: Cross-match via portal_emails email addresses ---
        // If "cg" has email carmen.graf2@web.de in portal_emails, and "Carmen Graf" has the same email → merge
        $unmappedForEmail = array_diff($rawNames, array_keys($mapping));
        foreach ($unmappedForEmail as $raw) {
            $lowerRaw = mb_strtolower(trim($raw));
            // Find this stakeholder's email from portal_emails
            $rawEmail = $stakeholderEmails[$lowerRaw] ?? null;
            if (!$rawEmail && filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                $rawEmail = mb_strtolower($raw);
            }
            if ($rawEmail) {
                // Check if any already-mapped stakeholder shares this email
                foreach ($mapping as $mappedRaw => $canonical) {
                    $mappedLower = mb_strtolower(trim($mappedRaw));
                    $mappedEmail = $stakeholderEmails[$mappedLower] ?? null;
                    if (!$mappedEmail && filter_var($mappedRaw, FILTER_VALIDATE_EMAIL)) {
                        $mappedEmail = mb_strtolower($mappedRaw);
                    }
                    if ($mappedEmail && $mappedEmail === $rawEmail) {
                        $mapping[$raw] = $canonical;
                        break;
                    }
                }
                // If still unmapped, check if other RAW names share this email
                if (!isset($mapping[$raw]) && isset($emailToStakeholders[$rawEmail])) {
                    $candidates = $emailToStakeholders[$rawEmail];
                    // Pick the longest non-email name as canonical
                    $bestName = $raw;
                    $bestLen = 0;
                    foreach ($candidates as $cand) {
                        if (!filter_var($cand, FILTER_VALIDATE_EMAIL) && mb_strlen($cand) > $bestLen) {
                            $bestName = $cand;
                            $bestLen = mb_strlen($cand);
                        }
                    }
                    // Also check rawNames for non-email variants
                    foreach ($rawNames as $otherRaw) {
                        $otherEmail = $stakeholderEmails[mb_strtolower($otherRaw)] ?? null;
                        if ($otherEmail === $rawEmail && !filter_var($otherRaw, FILTER_VALIDATE_EMAIL) && mb_strlen($otherRaw) > $bestLen) {
                            $bestName = $normalized[$otherRaw] ?: $otherRaw;
                            $bestLen = mb_strlen($otherRaw);
                        }
                    }
                    if ($bestName !== $raw || $bestLen > 0) {
                        $mapping[$raw] = $bestName;
                        // Map all variants with same email
                        foreach ($rawNames as $otherRaw) {
                            $otherEmail = $stakeholderEmails[mb_strtolower($otherRaw)] ?? null;
                            if (!$otherEmail && filter_var($otherRaw, FILTER_VALIDATE_EMAIL)) {
                                $otherEmail = mb_strtolower($otherRaw);
                            }
                            if ($otherEmail === $rawEmail && !isset($mapping[$otherRaw])) {
                                $mapping[$otherRaw] = $bestName;
                            }
                        }
                    }
                }
            }
        }

        // --- Step 3: Cross-match emails against other raw stakeholder names ---
        // Even without contacts DB entry, match "carmen.graf2@web.de" → "Carmen Graf"
        $unmapped = array_diff($rawNames, array_keys($mapping));

        // Build a lookup of non-email raw names (normalized) for cross-matching
        $namePool = []; // lowercase name => best display form
        foreach ($rawNames as $raw) {
            if (!filter_var($raw, FILTER_VALIDATE_EMAIL) && !filter_var($normalized[$raw], FILTER_VALIDATE_EMAIL)) {
                $displayName = $normalized[$raw] ?: $raw;
                $key = mb_strtolower(trim($displayName));
                if (!isset($namePool[$key])) {
                    $namePool[$key] = $displayName;
                }
            }
        }

        foreach ($unmapped as $raw) {
            if (isset($mapping[$raw])) continue;

            // If this stakeholder is an email, extract name parts and match against namePool
            $toCheck = $raw;
            if (preg_match('/^([a-zA-ZäöüÄÖÜß]+)[._-]([a-zA-ZäöüÄÖÜß]+)\d*@/i', $toCheck, $m)) {
                $emailFirst = mb_strtolower($m[1]);
                $emailLast = mb_strtolower($m[2]);

                foreach ($namePool as $nameLower => $displayName) {
                    $parts = preg_split('/\s+/', $nameLower);
                    if (count($parts) >= 2) {
                        $nFirst = $parts[0];
                        $nLast = end($parts);
                        if ($nFirst === $emailFirst && $nLast === $emailLast) {
                            // Match found! Use the display name as canonical for both
                            $mapping[$raw] = $displayName;
                            // Also map the name-based raw entry if not yet mapped
                            foreach ($rawNames as $otherRaw) {
                                if (!isset($mapping[$otherRaw]) && mb_strtolower($normalized[$otherRaw]) === $nameLower) {
                                    $mapping[$otherRaw] = $displayName;
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }

        // --- Step 4: Cross-match unmapped names with mapped ones ---
        $unmapped = array_diff($rawNames, array_keys($mapping));
        $mapped = $mapping;

        foreach ($unmapped as $raw) {
            $clean = $normalized[$raw];
            $lowerClean = mb_strtolower($clean);

            foreach ($mapped as $mappedRaw => $canonical) {
                $canonLower = mb_strtolower($canonical);
                if (strlen($lowerClean) >= 4 && strlen($canonLower) >= 4) {
                    if (strpos($canonLower, $lowerClean) !== false || strpos($lowerClean, $canonLower) !== false) {
                        $mapping[$raw] = $canonical;
                        break;
                    }
                }
            }
        }

        // --- Step 5: Normalize remaining unmapped using simple string cleanup ---
        foreach ($rawNames as $raw) {
            if (!isset($mapping[$raw])) {
                $clean = $normalized[$raw];
                $mapping[$raw] = $clean ?: $raw;
            }
        }

        // --- Step 6: Unify variants that share the same normalized lowercase form ---
        $lowerGroups = [];
        foreach ($mapping as $raw => $canonical) {
            $key = mb_strtolower(preg_replace('/\s+/', '', $canonical));
            if (!isset($lowerGroups[$key])) {
                $lowerGroups[$key] = $canonical; // keep first (usually the nicest form)
            }
            $mapping[$raw] = $lowerGroups[$key];
        }

        // Build system email patterns for filtering
        $sysPatterns = ['notification', 'noreply', 'no-reply', 'typeform', 'followups.typeform', 'calendly', 'mailer-daemon', 'willhaben', 'immowelt', 'immoscout', 'info@immowelt'];

        // Build personal email + phone cache: stakeholder → personal email/phone (from contacts + activity text)
        $personalEmails = []; // canonical_name_lower → personal email
        $personalPhones = []; // canonical_name_lower → phone
        // From contacts table
        $contactData = DB::select("SELECT full_name, email, phone FROM contacts WHERE (email IS NOT NULL AND email != '') OR (phone IS NOT NULL AND phone != '')");
        foreach ($contactData as $ce) {
            $key = mb_strtolower(trim($ce->full_name));
            if ($ce->email) $personalEmails[$key] = $ce->email;
            if ($ce->phone) $personalPhones[$key] = $ce->phone;
        }

                // Build phone cache from portal_emails body_text (Typeform emails contain phone numbers)
        $phoneFromEmails = DB::select(
            "SELECT pe.stakeholder, pe.body_text FROM portal_emails pe
             WHERE pe.property_id = ? AND pe.direction = 'inbound' AND pe.body_text IS NOT NULL AND pe.body_text != ''
             AND (pe.body_text LIKE '%Phone number%' OR pe.body_text LIKE '%Telefon%' OR pe.body_text REGEXP '[+][0-9]{10,}')",
            [$propertyId]
        );
        foreach ($phoneFromEmails as $pe) {
            $peKey = mb_strtolower(trim($pe->stakeholder));
            $peCanon = mb_strtolower(trim($mapping[$pe->stakeholder] ?? $pe->stakeholder));
            if (!isset($personalPhones[$peKey]) && !isset($personalPhones[$peCanon])) {
                $bodyText = $pe->body_text;
                // Phone-Extraktion via zentralem PhoneExtractor Service (inkl. Längenprüfung + eigene Nummern)
                $phone = PhoneExtractor::extractFromText($bodyText);
                if ($phone) {
                    $personalPhones[$peKey] = $phone;
                    $personalPhones[$peCanon] = $phone;
                    // Persist to contacts table
                    $existingContact = DB::selectOne("SELECT id, phone FROM contacts WHERE LOWER(full_name) = ?", [$peKey]);
                    if ($existingContact && empty($existingContact->phone)) {
                        DB::table('contacts')->where('id', $existingContact->id)->update(['phone' => $phone, 'updated_at' => now()]);
                    } elseif (!$existingContact) {
                        $existingCanon = DB::selectOne("SELECT id, phone FROM contacts WHERE LOWER(full_name) = ?", [$peCanon]);
                        if ($existingCanon && empty($existingCanon->phone)) {
                            DB::table('contacts')->where('id', $existingCanon->id)->update(['phone' => $phone, 'updated_at' => now()]);
                        }
                    }
                }
            }
        }

// Apply mapping to activities + resolve email addresses
        foreach ($activities as &$act) {
            $sh = is_object($act) ? ($act->stakeholder ?? '') : ($act['stakeholder'] ?? '');
            $canonical = $mapping[$sh] ?? $sh ?: '(Unbekannt)';
            $email = is_object($act) ? ($act->stakeholder_email ?? null) : ($act['stakeholder_email'] ?? null);
            
            // Clean email: strip display name wrapper
            if ($email && preg_match('/<([^>]+)>/', $email, $cleanM)) {
                $email = $cleanM[1];
            }
            
            // Check if current email is a system email → need to find personal one
            $isSystemAddr = false;
            if ($email) {
                foreach ($sysPatterns as $sp) {
                    if (stripos($email, $sp) !== false) { $isSystemAddr = true; break; }
                }
            }
            
            if (!$email || $isSystemAddr) {
                $canonLower = mb_strtolower(trim($canonical));
                $shLower = mb_strtolower(trim($sh));
                
                // 1. Check contacts table
                $personalEmail = $personalEmails[$canonLower] ?? $personalEmails[$shLower] ?? null;
                
                // 2. Check stakeholderEmails from portal_emails (non-system)
                if (!$personalEmail) {
                    $personalEmail = $stakeholderEmails[$canonLower] ?? $stakeholderEmails[$shLower] ?? null;
                }
                
                // 3. Extract email from activity result/text via regex
                if (!$personalEmail) {
                    $resultText = is_object($act) ? ($act->result ?? '') : ($act['result'] ?? '');
                    $actText = is_object($act) ? ($act->activity ?? '') : ($act['activity'] ?? '');
                    $searchText = $resultText . ' ' . $actText;
                    if (preg_match_all('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $searchText, $foundEmails)) {
                        foreach ($foundEmails[0] as $fe) {
                            $feLower = strtolower($fe);
                            $isSys = false;
                            foreach ($sysPatterns as $sp) {
                                if (stripos($feLower, $sp) !== false) { $isSys = true; break; }
                            }
                            if (!$isSys && stripos($feLower, 'sr-homes') === false && stripos($feLower, 'hoelzl') === false) {
                                $personalEmail = $feLower;
                                // Cache for other activities of same person
                                $personalEmails[$canonLower] = $personalEmail;
                                break;
                            }
                        }
                    }
                }
                
                // 4. If raw stakeholder is an email, use it
                if (!$personalEmail && filter_var($sh, FILTER_VALIDATE_EMAIL)) {
                    $personalEmail = $sh;
                }
                
                $email = $personalEmail;
            }
            
            // Resolve phone number
            $canonLowerForPhone = mb_strtolower(trim($canonical));
            $shLowerForPhone = mb_strtolower(trim($sh));
            $phone = $personalPhones[$canonLowerForPhone] ?? $personalPhones[$shLowerForPhone] ?? null;
            
            // If no phone from contacts, try extracting from activity text
            if (!$phone) {
                $resultText = is_object($act) ? ($act->result ?? '') : ($act['result'] ?? '');
                $actText = is_object($act) ? ($act->activity ?? '') : ($act['activity'] ?? '');
                $searchText = $resultText . ' ' . $actText;
                // Match phone patterns: +43..., 0043..., 06..., +49...
                // More specific phone patterns for Austrian numbers
                if (preg_match('/(?:\+43|0043|\+49|0049)\s*[\d\s.\/\-]{7,14}/', $searchText, $phoneMatch) ||
                    preg_match('/0\d{1,4}[\s.\/\-]?\d{3,}[\s.\/\-]?\d{2,}/', $searchText, $phoneMatch)) {
                    $candidate = trim($phoneMatch[0]);
                    // Only accept if it looks like a real phone number (7+ digits)
                    $digitsOnly = preg_replace('/\D/', '', $candidate);
                    if (strlen($digitsOnly) >= 7 && strlen($digitsOnly) <= 15) {
                        // Skip our own phone numbers
                        if (strpos($candidate, '6642600930') !== false || strpos($candidate, '664 2600 93') !== false || strpos($candidate, '62459305') !== false) {
                            $phone = null;
                        } else {
                            $phone = $candidate;
                            $personalPhones[$canonLowerForPhone] = $phone;
                        }
                    }
                }
            }
            
            if (is_object($act)) {
                $act->canonical_name = $canonical;
                $act->stakeholder_email = $email;
                $act->stakeholder_phone = $phone;
            } else {
                $act['canonical_name'] = $canonical;
                $act['stakeholder_email'] = $email;
                $act['stakeholder_phone'] = $phone;
            }
        }

        return $activities;
    }

    /**
     * Filter activities for customer view.
     * Removes activities with negative broker/firm feedback using Haiku AI.
     */
    /**
     * Filter activities for customer view.
     * Removes activities with negative broker/firm feedback using Haiku AI.
     *
     * @param  int $propertyId  Wird für den Cache-Key verwendet (0 = kein Caching)
     */
    /**
     * Replace raw activity texts (email subjects, AI summaries) with clean customer-facing labels.
     * Customers should see "Erstanfrage erhalten", "Exposé gesendet" etc. — not email content.
     */
    private function sanitizeActivityTexts(array $activities): array
    {
        foreach ($activities as &$act) {
            $cat = $act->category ?? 'sonstiges';
            $stakeholder = $act->stakeholder ?? '';
            $firstName = explode(' ', trim($stakeholder))[0] ?: 'Interessent';

            // Map category to clean customer-facing label
            $act->activity = match($cat) {
                'anfrage'      => "Erstanfrage erhalten von {$firstName}",
                'email-in'     => "Nachricht erhalten von {$firstName}",
                'email-out'    => "Antwort gesendet an {$firstName}",
                'expose'       => "Exposé gesendet an {$firstName}",
                'nachfassen'   => "Follow-up gesendet an {$firstName}",
                'besichtigung' => "Besichtigungsanfrage von {$firstName}",
                'kaufanbot'    => "Kaufanbot eingegangen von {$firstName}",
                'absage'       => "Absage von {$firstName}",
                'eigentuemer'  => "Eigentümer-Nachricht",
                'update'       => "Status aktualisiert",
                'bounce'       => "E-Mail unzustellbar",
                'partner'      => "Partner-Nachricht",
                'intern'       => "Interne Notiz",
                'feedback_positiv'       => "Positives Feedback von {$firstName}",
                'feedback_negativ'       => "Feedback von {$firstName}",
                'feedback_besichtigung'  => "Besichtigungs-Feedback von {$firstName}",
                default        => "Aktivität",
            };

            // Remove detailed AI summaries / email content from result
            // Only keep result for specific categories where it adds value
            if (in_array($cat, ['absage'])) {
                // For absage: keep a short reason if available, but strip email content
                $result = $act->result ?? '';
                // Extract just the reason keyword if present
                $reasons = ['Preis', 'Lage', 'Größe', 'Zustand', 'Budget', 'Finanzierung', 'Anderweitig', 'Kein Interesse'];
                $foundReason = null;
                foreach ($reasons as $r) {
                    if (stripos($result, $r) !== false) {
                        $foundReason = $r;
                        break;
                    }
                }
                $act->result = $foundReason;
            } elseif ($cat === 'kaufanbot') {
                // Keep kaufanbot result (may contain price info)
                // But strip if it looks like an email summary
                if ($act->result && mb_strlen($act->result) > 100) {
                    $act->result = null;
                }
            } elseif ($cat === 'besichtigung') {
                // Keep viewing date/time info if short
                if ($act->result && mb_strlen($act->result) > 80) {
                    $act->result = null;
                }
            } else {
                // All other categories: no result text in customer portal
                $act->result = null;
            }
        }
        unset($act);
        return $activities;
    }

        private function filterActivitiesForCustomer(array $activities, int $propertyId = 0): array
    {
        if (empty($activities)) {
            return $activities;
        }

        // First pass: quick keyword filter for obviously problematic content
        $suspiciousIndices = [];
        $negativeKeywords = [
            'schlechter makler', 'unfaehiger makler', 'inkompetent', 'betrug',
            'abzocke', 'unverschaemt', 'frechheit', 'beschwerde', 'reklamation',
            'anwalt', 'rechtsanwalt', 'klage', 'schlecht beraten', 'nie wieder',
            'unprofessionell', 'unzuverlaessig', 'nicht erreichbar', 'ignoriert',
            'schlechte erfahrung', 'enttaeuschend', 'enttaeuscht', 'veraergert',
            'wuetend', 'sauer auf', 'vertrauensbruch', 'luege', 'gelogen',
            'ueberteuert provision', 'zu hohe provision', 'nicht zufrieden',
            'schlecht bewertet', 'negative bewertung', 'mangelhaft',
        ];

        foreach ($activities as $idx => $act) {
            $text = strtolower(
                ($act->activity ?? '') . ' ' . ($act->result ?? '')
            );
            // Convert umlauts for matching
            $text = str_replace(
                ['ä', 'ö', 'ü', 'ß'],
                ['ae', 'oe', 'ue', 'ss'],
                $text
            );

            foreach ($negativeKeywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    $suspiciousIndices[$idx] = true;
                    break;
                }
            }
        }

        // Keine verdächtigen Aktivitäten → kein KI-Call nötig
        if (empty($suspiciousIndices)) {
            return $activities;
        }

        // Cache-Key: property_id + sortierte IDs der verdächtigen Aktivitäten
        // Invalidiert automatisch wenn sich die verdächtigen Aktivitäten ändern
        $suspiciousIds = [];
        foreach (array_keys($suspiciousIndices) as $idx) {
            $act = $activities[$idx];
            $suspiciousIds[] = is_object($act) ? ($act->id ?? $idx) : ($act['id'] ?? $idx);
        }
        sort($suspiciousIds);
        $cacheKey = 'actfilter_' . $propertyId . '_' . implode('_', $suspiciousIds);

        // Gecachtes Ergebnis (Liste der zu versteckenden Activity-Indices) laden
        $cachedHideIndices = $propertyId > 0 ? Cache::get($cacheKey) : null;

        if ($cachedHideIndices !== null) {
            // Cache-Hit: KI-Call überspringen
            foreach ($cachedHideIndices as $hideIdx) {
                if (is_numeric($hideIdx) && isset($activities[$hideIdx])) {
                    unset($activities[$hideIdx]);
                }
            }
            return array_values($activities);
        }

        // Cache-Miss: KI-Call durchführen
        try {
            $ai = app(AnthropicService::class);
            $toCheck = [];
            foreach ($suspiciousIndices as $idx => $val) {
                $act = $activities[$idx];
                $toCheck[] = [
                    'idx' => $idx,
                    'text' => mb_substr(($act->activity ?? '') . ' ' . ($act->result ?? ''), 0, 200),
                ];
            }

            $itemsText = '';
            foreach ($toCheck as $item) {
                $itemsText .= $item['idx'] . ': ' . $item['text'] . "\n";
            }

            $system = 'Du bist ein Filter fuer ein Immobilien-Kundenportal. Der Eigentuemer sieht Aktivitaeten seiner Immobilie. Aktivitaeten die Unzufriedenheit mit dem Makler, der Firma SR-Homes, oder negative Bewertungen des Service enthalten, sollen NICHT angezeigt werden. Normale Absagen von Interessenten oder sachliches negatives Marktfeedback sind OK und duerfen angezeigt werden.';

            $userMsg = 'Pruefe diese Aktivitaeten. Antworte NUR als JSON-Array mit den Index-Nummern die VERSTECKT werden sollen (weil sie den Makler/die Firma negativ darstellen). Leeres Array [] wenn alle OK sind.' . "\n\n" . $itemsText;

            $result = $ai->chatJson($system, $userMsg, 500);

            if (is_array($result)) {
                // Ergebnis 1 Stunde cachen — wird bei neuen Aktivitäten durch den Cache-Key automatisch invalidiert
                if ($propertyId > 0) {
                    Cache::put($cacheKey, $result, 3600);
                }
                foreach ($result as $hideIdx) {
                    if (is_numeric($hideIdx) && isset($activities[$hideIdx])) {
                        unset($activities[$hideIdx]);
                    }
                }
                $activities = array_values($activities);
            }
        } catch (\Exception $e) {
            Log::error('Activity filtering failed: ' . $e->getMessage());
            // On failure, still remove keyword-matched activities
            foreach ($suspiciousIndices as $idx => $val) {
                unset($activities[$idx]);
            }
            $activities = array_values($activities);
        }

        return $activities;
    }

    /**
     * Customer-Lookup: Gibt das Customer-Objekt für den eingeloggten User zurück.
     * Zentralisiert den wiederholten DB::table('customers')->where('email',...)->first() Pattern.
     */
    private function resolveCustomer($user): mixed
    {
        if (method_exists($user, 'customer') && $user->customer) {
            return $user->customer;
        }
        return DB::table('customers')->where('email', $user->email)->first();
    }

    /**
     * Gibt die Customer-ID zurück, oder 0 wenn kein Customer gefunden.
     */
    private function resolveCustomerId($user): int
    {
        $customer = $this->resolveCustomer($user);
        if (!$customer) return 0;
        return is_object($customer) ? $customer->id : ($customer['id'] ?? 0);
    }

    /**
     * Download a professional PDF market report for banks/investors.
     */
    /**
     * Download the Vermarktungsbericht (property analysis) as PDF for the customer.
     * Reuses the same PDF export as the admin panel but with customer-appropriate framing.
     */
    public function downloadPropertyReport(Request $request, $propertyId)
    {
        $user = $request->user();
        $propertyId = intval($propertyId);

        // Security: ensure user owns this property
        $customerId = $this->resolveCustomerId($user);
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property || ($property->customer_id != $customerId && $user->user_type !== 'admin')) {
            abort(403, 'Zugriff verweigert');
        }

        // Get stored Vermarktungsbericht
        $report = DB::selectOne("
            SELECT analysis_json, created_at
            FROM property_analyses
            WHERE property_id = ? AND report_type = 'vermarktungsbericht'
            ORDER BY created_at DESC LIMIT 1
        ", [$propertyId]);

        if (!$report) {
            return back()->with('error', 'Noch kein Bericht vorhanden. Bitte versuchen Sie es später erneut.');
        }

        $data = json_decode($report->analysis_json, true);
        if (!$data) {
            return back()->with('error', 'Bericht konnte nicht geladen werden.');
        }

        // Reuse the existing Vermarktungsbericht PDF template
        $owner = $data['owner'] ?? [];
        $broker = $data['broker'] ?? [];
        $meta = $data['meta'] ?? [];
        $logoPath = public_path('assets/logo-full-orange.png');
        $logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.vermarktungsbericht-pdf', [
            'owner' => $owner,
            'broker' => $broker,
            'meta' => $meta,
            'property' => $property,
            'generatedAt' => $report->created_at,
            'logoBase64'  => $logoBase64,
        ]);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        $refId = $property->ref_id ?? 'Objekt';
        $filename = 'SR-Homes_Vermarktungsbericht_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $refId) . '_' . date('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Download Bankbericht (Vertriebsbericht) PDF for a Neubauprojekt.
     * Positive-only sales report intended for bank/financing presentations.
     */
    public function downloadBankReport(Request $request, $propertyId)
    {
        $user = $request->user();
        $propertyId = intval($propertyId);

        // Security: ensure user owns this property
        $customerId = $this->resolveCustomerId($user);
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property || ($property->customer_id != $customerId && $user->user_type !== 'admin')) {
            abort(403, 'Zugriff verweigert');
        }

        // Check if this is a Neubauprojekt (has units)
        $units = DB::table('property_units')->where('property_id', $propertyId)->get();
        if ($units->isEmpty()) {
            return back()->with('error', 'Vertriebsbericht ist nur für Neubauprojekte verfügbar.');
        }

        // --- Load broker (Ansprechpartner) for this property ---
        $broker = null;
        if ($property->broker_id) {
            $brokerUser = DB::table('users')->where('id', $property->broker_id)->first();
            if ($brokerUser) {
                $brokerSettings = DB::table('admin_settings')->where('user_id', $brokerUser->id)->first();
                $businessEmail = DB::table('email_accounts')
                    ->where('user_id', $brokerUser->id)->where('is_active', 1)
                    ->value('email_address');
                $broker = [
                    'name' => $brokerSettings->signature_name ?? ($brokerUser->signature_name ?: $brokerUser->name),
                    'title' => $brokerSettings->signature_title ?? ($brokerUser->signature_title ?? ''),
                    'phone' => $brokerSettings->signature_phone ?? ($brokerUser->signature_phone ?: ($brokerUser->phone ?? '')),
                    'email' => $businessEmail ?: ($brokerSettings->signature_email ?? $brokerUser->email),
                ];
            }
        }
        if (!$broker) {
            $broker = ['name' => 'SR-Homes Immobilien GmbH', 'title' => '', 'phone' => '', 'email' => 'office@sr-homes.at'];
        }

        // Logo as base64 for PDF embedding
        $logoPath = public_path('assets/logo-full-orange.png');
        $logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        // --- Gather all data ---

        // Units breakdown (exclude parking units)
        $realUnits = $units->where('is_parking', '!=', 1);
        $totalUnits = $realUnits->count();
        $soldUnits = $realUnits->where('status', 'verkauft');
        $reservedUnits = $realUnits->where('status', 'reserviert');
        $soldCount = $soldUnits->count();
        $reservedCount = $reservedUnits->count();

        // Sold volume: unit price + assigned parking prices (matches Portal Einheiten-Tab)
        $soldVolume = 0;
        foreach ($soldUnits as $u) {
            $soldVolume += $u->price ?? 0;
            $parkingIds = json_decode($u->assigned_parking ?? '[]', true) ?: [];
            if (!empty($parkingIds)) {
                $soldVolume += DB::table('property_units')->whereIn('id', $parkingIds)->sum('price');
            }
        }
        $totalVolume = $realUnits->sum('price');

        // Verkaufsquote: by area (m2) if available, else by count — matches Portal Einheiten-Tab logic
        $totalArea = $realUnits->sum(fn($u) => (float)($u->area_m2 ?? 0));
        $soldArea = $soldUnits->sum(fn($u) => (float)($u->area_m2 ?? 0));
        if ($totalArea > 0) {
            $verkaufsquote = round(($soldArea / $totalArea) * 100);
        } else {
            $verkaufsquote = $totalUnits > 0 ? round(($soldCount / $totalUnits) * 100) : 0;
        }

        // Price range: only SOLD units (unit price + assigned parking)
        $soldPricesWithParking = [];
        foreach ($soldUnits as $u) {
            $unitTotal = $u->price ?? 0;
            $parkingIds = json_decode($u->assigned_parking ?? '[]', true) ?: [];
            if (!empty($parkingIds)) {
                $unitTotal += DB::table('property_units')->whereIn('id', $parkingIds)->sum('price');
            }
            if ($unitTotal > 0) $soldPricesWithParking[] = $unitTotal;
        }
        $preisMin = !empty($soldPricesWithParking) ? min($soldPricesWithParking) : 0;
        $preisMax = !empty($soldPricesWithParking) ? max($soldPricesWithParking) : 0;

        // Average price per m2
        $unitsWithArea = $realUnits->filter(fn($u) => $u->price > 0 && $u->area_m2 > 0);
        $avgPricePerM2 = 0;
        if ($unitsWithArea->count() > 0) {
            $totalArea = $unitsWithArea->sum('area_m2');
            $totalPrice = $unitsWithArea->sum('price');
            $avgPricePerM2 = $totalArea > 0 ? round($totalPrice / $totalArea) : 0;
        }

        // Activities
        $activities = DB::table('activities')->where('property_id', $propertyId)->get();
        $anfragen = $activities->whereIn('category', ['anfrage', 'email-in']);
        $besichtigungen = $activities->where('category', 'besichtigung');
        $besichtigungenCount = $besichtigungen->count();
        $kaufanboteCount = KaufanbotHelper::count($propertyId);

        // Unique Interessenten
        $uniqueInteressenten = $anfragen->pluck('stakeholder')->filter()->unique()->count();
        if ($uniqueInteressenten < 1) $uniqueInteressenten = $anfragen->count();

        // Konversionsraten
        $konversionBesichtigung = $uniqueInteressenten > 0 ? round(($besichtigungenCount / $uniqueInteressenten) * 100) : 0;
        $konversionKaufanbot = $uniqueInteressenten > 0 ? round(($kaufanboteCount / $uniqueInteressenten) * 100) : 0;

        // Average inquiries per week
        $firstActivity = $activities->sortBy('activity_date')->first();
        $startDate = $firstActivity ? ($firstActivity->activity_date ?? $property->created_at) : $property->created_at;
        $daysSinceStart = max(1, now()->diffInDays($startDate));
        $weeksSinceStart = max(1, ceil($daysSinceStart / 7));
        $avgInquiriesPerWeek = round($uniqueInteressenten / $weeksSinceStart, 1);

        // Weekly inquiries (last 8 weeks)
        $weeklyInquiries = [];
        for ($i = 7; $i >= 0; $i--) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $weekEnd = now()->subWeeks($i)->endOfWeek();
            $kw = $weekStart->format('W/Y');
            $count = $anfragen->filter(function ($a) use ($weekStart, $weekEnd) {
                $date = $a->activity_date ?? ($a->created_at ? substr($a->created_at, 0, 10) : null);
                if (!$date) return false;
                return $date >= $weekStart->format('Y-m-d') && $date <= $weekEnd->format('Y-m-d');
            })->count();
            $weeklyInquiries[] = ['kw' => $kw, 'count' => $count];
        }

        // Platform distribution (based on activity text patterns)
        $platformCounts = ['willhaben' => 0, 'Social Media' => 0, 'Website' => 0, 'Empfehlung' => 0, 'Sonstige' => 0];
        foreach ($anfragen as $a) {
            $text = strtolower(($a->activity ?? '') . ' ' . ($a->stakeholder ?? ''));
            // Check source email for platform detection
            $sourceEmail = '';
            if ($a->source_email_id ?? null) {
                $se = DB::table('portal_emails')->where('id', $a->source_email_id)->value('from_email');
                $sourceEmail = strtolower($se ?? '');
            }
            if (str_contains($text, 'willhaben') || str_contains($sourceEmail, 'willhaben')) {
                $platformCounts['willhaben']++;
            } elseif (str_contains($text, 'typeform') || str_contains($sourceEmail, 'typeform') || str_contains($text, 'social') || str_contains($text, 'instagram') || str_contains($text, 'facebook') || str_contains($text, 'kampagne')) {
                $platformCounts['Social Media']++;
            } elseif (str_contains($text, 'website') || str_contains($text, 'homepage') || str_contains($text, 'sr-homes')) {
                $platformCounts['Website']++;
            } elseif (str_contains($text, 'empfehlung') || str_contains($text, 'bekannt')) {
                $platformCounts['Empfehlung']++;
            } else {
                $platformCounts['Social Media']++; // Default: most non-willhaben inquiries come from campaigns
            }
        }
        // Remove zero-count platforms
        $platformCounts = array_filter($platformCounts, fn($c) => $c > 0);
        $totalPlatformCount = array_sum($platformCounts);
        $platformDistribution = [];
        foreach ($platformCounts as $name => $count) {
            $platformDistribution[] = [
                'name' => $name,
                'count' => $count,
                'percent' => $totalPlatformCount > 0 ? round(($count / $totalPlatformCount) * 100) : 0,
            ];
        }
        // Sort by count desc
        usort($platformDistribution, fn($a, $b) => $b['count'] - $a['count']);

        $generatedAt = now()->format('d.m.Y');

        // Generate PDF
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.bankbericht-pdf', [
            'property' => $property,
            'generatedAt' => $generatedAt,
            'totalUnits' => $totalUnits,
            'soldCount' => $soldCount,
            'reservedCount' => $reservedCount,
            'soldVolume' => $soldVolume,
            'totalVolume' => $totalVolume,
            'verkaufsquote' => $verkaufsquote,
            'preisMin' => $preisMin,
            'preisMax' => $preisMax,
            'avgPricePerM2' => $avgPricePerM2,
            'uniqueInteressenten' => $uniqueInteressenten,
            'besichtigungenCount' => $besichtigungenCount,
            'kaufanboteCount' => $kaufanboteCount,
            'konversionBesichtigung' => $konversionBesichtigung,
            'konversionKaufanbot' => $konversionKaufanbot,
            'weeklyInquiries' => $weeklyInquiries,
            'platformDistribution' => $platformDistribution,
            'avgInquiriesPerWeek' => $avgInquiriesPerWeek,
            'broker' => $broker,
            'logoBase64' => $logoBase64,
        ]);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        $refId = $property->ref_id ?? 'Projekt';
        $filename = 'SR-Homes_Vertriebsbericht_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $refId) . '_' . date('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

}
