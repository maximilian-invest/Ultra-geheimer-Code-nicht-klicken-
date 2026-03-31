<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\PortalDocument;
use App\Models\PortalEmail;
use App\Models\PortalMessage;
use App\Models\Property;
use App\Models\PropertyKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PortalApiController extends Controller
{
    /**
     * Main dispatch handler — reads ?action= and routes to the appropriate method.
     * Maintains 100% backward compatibility with legacy portal_api.php.
     */
    public function handle(Request $request): JsonResponse
    {
        $action = $request->query('action', '');

        try {
            return match ($action) {
                'ping'                 => $this->ping(),
                'list_properties'      => $this->listProperties(),
                'get_property'         => $this->getProperty($request),
                'list_activities'      => $this->listActivities($request),
                'add_activity'         => $this->addActivity($request),
                'delete_activity'      => $this->deleteActivity($request),
                'bulk_add_activities'  => $this->bulkAddActivities($request),
                'list_prospects'       => $this->listProspects($request),
                'dashboard_stats'      => $this->dashboardStats($request),
                'save_phone'           => $this->savePhone($request),
                'list_messages'        => $this->listMessages($request),
                'add_message'          => $this->addMessage($request),
                'delete_message'       => $this->deleteMessage($request),
                'list_documents'       => $this->listDocuments($request),
                'add_document'         => $this->addDocument($request),
                'delete_document'      => $this->deleteDocument($request),
                'get_email'            => $this->getEmail($request),
                'list_knowledge'       => $this->listKnowledge($request),
                'add_knowledge'        => $this->addKnowledge($request),
                'update_knowledge'     => $this->updateKnowledge($request),
                'delete_knowledge'     => $this->deleteKnowledge($request),
                'knowledge_summary'    => $this->knowledgeSummary($request),
                default                => $this->unknownAction(),
            };
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error'   => 'Database error',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ------------------------------------------------------------------
    // Ping
    // ------------------------------------------------------------------

    private function ping(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    private function listProperties(): JsonResponse
    {
        $properties = Property::orderBy('id')->get();

        return response()->json([
            'properties' => $properties,
            'count'      => $properties->count(),
        ]);
    }

    private function getProperty(Request $request): JsonResponse
    {
        $id = intval($request->query('property_id', 0));

        if (! $id) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $property = Property::find($id);

        if (! $property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        return response()->json(['property' => $property]);
    }

    // ------------------------------------------------------------------
    // Activities
    // ------------------------------------------------------------------

    private function listActivities(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $page    = max(1, intval($request->query('page', 1)));
        $perPage = min(100, max(1, intval($request->query('per_page', 50))));

        $query = Activity::where('property_id', $propertyId);

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($stakeholder = $request->query('stakeholder')) {
            $query->where('stakeholder', 'LIKE', '%' . $stakeholder . '%');
        }

        $total = $query->count();

        $activities = (clone $query)
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'activities'  => $activities,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    private function addActivity(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data = $request->json()->all();

        if (empty($data)) {
            return response()->json(['error' => 'Invalid JSON body'], 400);
        }

        $required = ['property_id', 'activity_date', 'stakeholder', 'activity', 'category'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return response()->json(['error' => "Field '$field' is required"], 400);
            }
        }

        $validCategories = ['email-in', 'email-out', 'expose', 'besichtigung', 'kaufanbot', 'update', 'absage', 'sonstiges'];
        if (! in_array($data['category'], $validCategories)) {
            return response()->json(['error' => 'Invalid category', 'valid_categories' => $validCategories], 400);
        }

        $activity = Activity::create([
            'property_id'   => intval($data['property_id']),
            'activity_date' => $data['activity_date'],
            'stakeholder'   => $data['stakeholder'],
            'activity'      => $data['activity'],
            'result'        => $data['result'] ?? '',
            'duration'      => $data['duration'] ?? '',
            'category'      => $data['category'],
        ]);

        return response()->json(['success' => true, 'id' => $activity->id]);
    }

    private function deleteActivity(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $id = intval($request->query('activity_id', 0));
        if (! $id) {
            $data = $request->json()->all();
            $id   = intval($data['activity_id'] ?? 0);
        }

        if (! $id) {
            return response()->json(['error' => 'activity_id required'], 400);
        }

        $deleted = Activity::where('id', $id)->delete();

        return response()->json([
            'success'       => true,
            'deleted_id'    => $id,
            'rows_affected' => $deleted,
        ]);
    }

    private function bulkAddActivities(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data = $request->json()->all();

        if (! $data || ! isset($data['activities']) || ! is_array($data['activities'])) {
            return response()->json(['error' => 'JSON body must contain "activities" array'], 400);
        }

        $validCategories = ['email-in', 'email-out', 'expose', 'besichtigung', 'kaufanbot', 'update', 'absage', 'sonstiges'];
        $added  = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($data['activities'] as $i => $item) {
                if (empty($item['property_id']) || empty($item['activity_date']) || empty($item['category'])) {
                    $errors[] = "Row $i: missing required fields";
                    continue;
                }
                if (! in_array($item['category'], $validCategories)) {
                    $errors[] = "Row $i: invalid category '{$item['category']}'";
                    continue;
                }

                Activity::create([
                    'property_id'   => intval($item['property_id']),
                    'activity_date' => $item['activity_date'],
                    'stakeholder'   => $item['stakeholder'] ?? '',
                    'activity'      => $item['activity'] ?? '',
                    'result'        => $item['result'] ?? '',
                    'duration'      => $item['duration'] ?? '',
                    'category'      => $item['category'],
                ]);
                $added++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Bulk insert failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success'         => true,
            'added'           => $added,
            'errors'          => $errors,
            'total_submitted' => count($data['activities']),
        ]);
    }

    // ------------------------------------------------------------------
    // Prospects (derived from activities — no dedicated table)
    // ------------------------------------------------------------------

    private function listProspects(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        $query = Activity::select(
            'stakeholder',
            'property_id',
            DB::raw('COUNT(*) as activity_count'),
            DB::raw('MAX(activity_date) as last_activity'),
            DB::raw("GROUP_CONCAT(DISTINCT category SEPARATOR ',') as categories")
        )->groupBy('stakeholder', 'property_id')
         ->orderByDesc(DB::raw('MAX(activity_date)'));

        if ($propertyId) {
            $query->where('property_id', $propertyId);
        }

        $prospects = $query->get();

        return response()->json([
            'prospects' => $prospects,
            'count'     => $prospects->count(),
        ]);
    }

    // ------------------------------------------------------------------
    // Save Phone
    // ------------------------------------------------------------------

    private function savePhone(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data        = $request->json()->all();
        $propertyId  = intval($data['property_id'] ?? 0);
        $stakeholder = trim($data['stakeholder'] ?? '');
        $phone       = trim($data['phone'] ?? '');

        if (! $propertyId || ! $stakeholder) {
            return response()->json(['error' => 'property_id and stakeholder required'], 400);
        }

        // Clean parenthetical suffixes from name
        $cleanName = trim(preg_replace('/\s*\(.*?\)\s*/', '', $stakeholder));

        // Check if prospects table exists, then upsert
        if (Schema::hasTable('prospects')) {
            $existing = DB::table('prospects')
                ->where('property_id', $propertyId)
                ->where('name', $cleanName)
                ->first();

            if ($existing) {
                DB::table('prospects')->where('id', $existing->id)->update(['phone' => $phone]);
            } else {
                $exact = DB::table('prospects')
                    ->where('property_id', $propertyId)
                    ->where('name', $stakeholder)
                    ->first();

                if ($exact) {
                    DB::table('prospects')->where('id', $exact->id)->update(['phone' => $phone]);
                } else {
                    DB::table('prospects')->insert([
                        'property_id'   => $propertyId,
                        'name'          => $cleanName,
                        'phone'         => $phone,
                        'source'        => '',
                        'status'        => 'neu',
                        'first_contact' => now()->toDateString(),
                    ]);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Messages (Pinnwand)
    // ------------------------------------------------------------------

    private function listMessages(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $messages = PortalMessage::where('property_id', $propertyId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'messages' => $messages,
            'count'    => $messages->count(),
        ]);
    }

    private function addMessage(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data       = $request->json()->all();
        $propertyId = intval($data['property_id'] ?? 0);
        $message    = trim($data['message'] ?? '');
        $isPinned   = intval($data['is_pinned'] ?? 0);

        if (! $propertyId || ! $message) {
            return response()->json(['error' => 'property_id and message required'], 400);
        }

        $msg = PortalMessage::create([
            'property_id' => $propertyId,
            'message'     => $message,
            'is_pinned'   => $isPinned,
        ]);

        return response()->json(['success' => true, 'id' => $msg->id]);
    }

    private function deleteMessage(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data = $request->json()->all();
        $id   = intval($data['message_id'] ?? $request->query('message_id', 0));

        if (! $id) {
            return response()->json(['error' => 'message_id required'], 400);
        }

        PortalMessage::where('id', $id)->delete();

        return response()->json(['success' => true, 'deleted_id' => $id]);
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    private function listDocuments(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $documents = PortalDocument::where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'documents' => $documents,
            'count'     => $documents->count(),
        ]);
    }

    private function addDocument(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $propertyId = intval($request->input('property_id', 0));
        $title      = trim($request->input('title', ''));

        if (! $propertyId || ! $title) {
            return response()->json(['error' => 'property_id and title required'], 400);
        }

        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return response()->json(['error' => 'File upload required'], 400);
        }

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
        if (! in_array($ext, $allowed)) {
            return response()->json(['error' => 'File type not allowed', 'allowed' => $allowed], 400);
        }

        $originalName = $file->getClientOriginalName();
        $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $dir          = storage_path('app/public/documents/' . $propertyId);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $safeName);

        $doc = PortalDocument::create([
            'property_id'   => $propertyId,
            'filename'      => $safeName,
            'original_name' => $originalName,
            'file_size'     => filesize($dir . '/' . $safeName),
            'mime_type'     => (new \finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $safeName),
            'description'   => $title,
        ]);

        return response()->json([
            'success'  => true,
            'id'       => $doc->id,
            'filename' => $safeName,
        ]);
    }

    private function deleteDocument(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data = $request->json()->all();
        $id   = intval($data['document_id'] ?? $request->query('document_id', 0));

        if (! $id) {
            return response()->json(['error' => 'document_id required'], 400);
        }

        $doc = PortalDocument::find($id);
        if ($doc) {
            $path = storage_path('app/public/documents/' . $doc->property_id . '/' . $doc->filename);
            if (file_exists($path)) {
                @unlink($path);
            }
            $doc->delete();
        }

        return response()->json(['success' => true, 'deleted_id' => $id]);
    }

    // ------------------------------------------------------------------
    // Dashboard Stats
    // ------------------------------------------------------------------

    private function dashboardStats(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $totalActivities = Activity::where('property_id', $propertyId)->count();

        $byCategory = Activity::where('property_id', $propertyId)
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        $uniqueStakeholders = Activity::where('property_id', $propertyId)
            ->distinct('stakeholder')
            ->count('stakeholder');

        $recent = Activity::where('property_id', $propertyId)
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $monthlyTrend = Activity::where('property_id', $propertyId)
            ->where('activity_date', '>=', now()->subMonths(3))
            ->select(
                DB::raw("DATE_FORMAT(activity_date, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'property_id'         => $propertyId,
            'total_activities'    => $totalActivities,
            'unique_stakeholders' => $uniqueStakeholders,
            'by_category'         => $byCategory,
            'monthly_trend'       => $monthlyTrend,
            'recent_activities'   => $recent,
        ]);
    }

    // ------------------------------------------------------------------
    // Email Detail
    // ------------------------------------------------------------------

    private function getEmail(Request $request): JsonResponse
    {
        $emailId = intval($request->query('id', 0));

        if (! $emailId) {
            return response()->json(['error' => 'id required'], 400);
        }

        $email = PortalEmail::select(
            'portal_emails.id',
            'portal_emails.direction',
            'portal_emails.from_email',
            'portal_emails.from_name',
            'portal_emails.to_email',
            'portal_emails.subject',
            'portal_emails.ai_summary',
            'portal_emails.email_date',
            'portal_emails.category',
            'portal_emails.stakeholder',
            'portal_emails.matched_ref_id',
            'properties.address',
            'properties.city'
        )
            ->leftJoin('properties', 'portal_emails.property_id', '=', 'properties.id')
            ->where('portal_emails.id', $emailId)
            ->first();

        if (! $email) {
            return response()->json(['error' => 'Email nicht gefunden'], 404);
        }

        $result            = $email->toArray();
        $result['summary'] = $result['ai_summary'] ?? 'Keine Zusammenfassung verfügbar.';
        unset($result['ai_summary']);

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Property Knowledge Base
    // ------------------------------------------------------------------

    private const VALID_KNOWLEDGE_CATEGORIES = [
        'objektbeschreibung', 'ausstattung', 'lage_umgebung',
        'preis_markt', 'rechtliches', 'energetik',
        'feedback_positiv', 'feedback_negativ', 'feedback_besichtigung',
        'verhandlung', 'eigentuemer_info', 'vermarktung',
        'dokument_extrakt', 'sonstiges',
    ];

    private const VALID_SOURCE_TYPES = [
        'email_ingest', 'email_out', 'document', 'manual', 'ai_extract', 'expose',
    ];

    private const CATEGORY_LABELS = [
        'objektbeschreibung'    => 'Objektbeschreibung',
        'ausstattung'           => 'Ausstattung',
        'lage_umgebung'         => 'Lage & Umgebung',
        'preis_markt'           => 'Preis & Markt',
        'rechtliches'           => 'Rechtliches',
        'energetik'             => 'Energetik',
        'feedback_positiv'      => 'Feedback positiv',
        'feedback_negativ'      => 'Feedback negativ',
        'feedback_besichtigung' => 'Feedback Besichtigung',
        'verhandlung'           => 'Verhandlung',
        'eigentuemer_info'      => 'Eigentümer-Info',
        'vermarktung'           => 'Vermarktung',
        'dokument_extrakt'      => 'Aus Dokumenten',
        'sonstiges'             => 'Sonstiges',
    ];

    private function listKnowledge(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $activeOnly = intval($request->query('active_only', 1));
        $category   = $request->query('category', '');

        $query = PropertyKnowledge::where('property_id', $propertyId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }
        if ($category) {
            $query->where('category', $category);
        }

        $items = $query->orderBy('category')
            ->orderByDesc('confidence')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'knowledge' => $items,
            'count'     => $items->count(),
        ]);
    }

    private function addKnowledge(Request $request): JsonResponse
    {
        $input = $request->json()->all();

        if (! $input) {
            return response()->json(['error' => 'JSON body required'], 400);
        }

        // Support bulk insert (array of items) or single item
        $items    = isset($input[0]) ? $input : [$input];
        $inserted = [];

        foreach ($items as $item) {
            $propertyId = intval($item['property_id'] ?? 0);
            $category   = $item['category'] ?? '';
            $title      = trim($item['title'] ?? '');
            $content    = trim($item['content'] ?? '');
            $sourceType = $item['source_type'] ?? 'manual';

            if (! $propertyId || ! $title || ! $content) {
                continue;
            }
            if (! in_array($category, self::VALID_KNOWLEDGE_CATEGORIES)) {
                continue;
            }
            if (! in_array($sourceType, self::VALID_SOURCE_TYPES)) {
                $sourceType = 'manual';
            }

            $knowledge = PropertyKnowledge::create([
                'property_id'        => $propertyId,
                'category'           => $category,
                'title'              => $title,
                'content'            => $content,
                'source_type'        => $sourceType,
                'source_id'          => intval($item['source_id'] ?? 0) ?: null,
                'source_description' => $item['source_description'] ?? null,
                'confidence'         => in_array($item['confidence'] ?? '', ['high', 'medium', 'low'])
                                            ? $item['confidence'] : 'medium',
                'is_verified'        => intval($item['is_verified'] ?? 0),
                'created_by'         => $item['created_by'] ?? 'system',
            ]);

            $inserted[] = $knowledge->id;
        }

        return response()->json([
            'success'      => true,
            'inserted_ids' => $inserted,
            'count'        => count($inserted),
        ]);
    }

    private function updateKnowledge(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);

        if (! $id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $allowedFields = [
            'category', 'title', 'content', 'source_type', 'source_id',
            'source_description', 'confidence', 'is_verified', 'is_active', 'expires_at',
        ];

        $updates = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }

        if (empty($updates)) {
            return response()->json(['error' => 'No fields to update'], 400);
        }

        $affected = PropertyKnowledge::where('id', $id)->update($updates);

        return response()->json(['success' => true, 'updated' => $affected]);
    }

    private function deleteKnowledge(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);

        if (! $id) {
            return response()->json(['error' => 'id required'], 400);
        }

        // Soft-delete (deactivate)
        $affected = PropertyKnowledge::where('id', $id)->update(['is_active' => false]);

        return response()->json(['success' => true, 'deactivated' => $affected]);
    }

    private function knowledgeSummary(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));

        if (! $propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $items = PropertyKnowledge::where('property_id', $propertyId)
            ->where('is_active', true)
            ->select('category', 'title', 'content', 'confidence', 'is_verified', 'source_description')
            ->orderByDesc('confidence')
            ->orderByDesc('is_verified')
            ->orderByDesc('created_at')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['summary' => '', 'count' => 0]);
        }

        // Group by category
        $grouped    = $items->groupBy('category');
        $summary    = '';
        $totalChars = 0;
        $maxChars   = 3000;

        foreach ($grouped as $cat => $catItems) {
            $label   = self::CATEGORY_LABELS[$cat] ?? ucfirst($cat);
            $section = "\n=== {$label} ===\n";

            foreach ($catItems->take(5) as $item) {
                $verified = ($item->is_verified || $item->confidence === 'high') ? ' ✓' : '';
                $line     = "- {$item->title}: {$item->content}{$verified}\n";

                if ($totalChars + strlen($section) + strlen($line) > $maxChars) {
                    break 2;
                }
                $section .= $line;
            }

            $totalChars += strlen($section);
            $summary    .= $section;
        }

        return response()->json([
            'summary'    => trim($summary),
            'count'      => $items->count(),
            'categories' => $grouped->keys()->toArray(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Unknown action fallback
    // ------------------------------------------------------------------

    private function unknownAction(): JsonResponse
    {
        return response()->json([
            'error'             => 'Unknown action',
            'available_actions' => [
                'list_properties', 'get_property',
                'list_activities', 'add_activity', 'delete_activity', 'bulk_add_activities',
                'list_prospects', 'dashboard_stats',
                'list_messages', 'add_message', 'delete_message',
                'list_documents', 'add_document', 'delete_document',
                'save_phone', 'ping', 'get_email',
                'list_knowledge', 'add_knowledge', 'update_knowledge',
                'delete_knowledge', 'knowledge_summary',
            ],
        ], 400);
    }
}
