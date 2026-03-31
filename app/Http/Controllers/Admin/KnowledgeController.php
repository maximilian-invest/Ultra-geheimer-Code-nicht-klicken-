<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PropertyKnowledge;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KnowledgeController extends Controller
{
    private const VALID_CATEGORIES = [
        'objektbeschreibung','ausstattung','lage_umgebung',
        'preis_markt','rechtliches','energetik',
        'feedback_positiv','feedback_negativ','feedback_besichtigung',
        'verhandlung','eigentuemer_info','vermarktung',
        'dokument_extrakt','sonstiges',
    ];

    private const VALID_SOURCE_TYPES = [
        'email_ingest','email_out','document','manual','ai_extract','expose',
    ];

    private const CATEGORY_LABELS = [
        'objektbeschreibung'     => 'Objektbeschreibung',
        'ausstattung'            => 'Ausstattung',
        'lage_umgebung'          => 'Lage & Umgebung',
        'preis_markt'            => 'Preis & Markt',
        'rechtliches'            => 'Rechtliches',
        'energetik'              => 'Energetik',
        'feedback_positiv'       => 'Feedback positiv',
        'feedback_negativ'       => 'Feedback negativ',
        'feedback_besichtigung'  => 'Feedback Besichtigung',
        'verhandlung'            => 'Verhandlung',
        'eigentuemer_info'       => 'Eigentümer-Info',
        'vermarktung'            => 'Vermarktung',
        'dokument_extrakt'       => 'Aus Dokumenten',
        'sonstiges'              => 'Sonstiges',
    ];

    /**
     * list_knowledge — List knowledge entries for a property.
     */
    public function index(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $category   = $request->query('category', '');
        $activeOnly = intval($request->query('active_only', 1));

        // Also load KB entries from the property's project group
        $projectGroupId = \DB::table('properties')->where('id', $propertyId)->value('project_group_id');
        if ($projectGroupId) {
            $sql = 'SELECT pk.*, IF(pk.property_id = ?, 0, 1) as is_group_entry FROM property_knowledge pk WHERE (pk.property_id = ? OR pk.project_group_id = ?) AND pk.property_id != 0';
            $params[] = $propertyId;
            $params[] = $projectGroupId;
        } else {
            $sql = 'SELECT *, 0 as is_group_entry FROM property_knowledge WHERE property_id = ?';
        }
        $params = [$propertyId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        if ($category) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }

        $sql .= ' ORDER BY category, confidence DESC, created_at DESC';

        $items = DB::select($sql, $params);

        // Category counts (active only)
        $counts = DB::select(
            $projectGroupId
                ? 'SELECT category, COUNT(*) as cnt FROM property_knowledge WHERE (property_id = ? OR project_group_id = ?) AND is_active = 1 GROUP BY category'
                : 'SELECT category, COUNT(*) as cnt FROM property_knowledge WHERE property_id = ? AND is_active = 1 GROUP BY category',
            [$propertyId]
        );

        $categoryCounts = [];
        foreach ($counts as $row) {
            $categoryCounts[$row->category] = (int) $row->cnt;
        }

        return response()->json([
            'knowledge'       => $items,
            'count'           => count($items),
            'category_counts' => $categoryCounts,
        ]);
    }

    /**
     * add_knowledge — Add one or more knowledge entries.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (empty($input)) {
            return response()->json(['error' => 'JSON body required'], 400);
        }

        $items    = isset($input[0]) ? $input : [$input];
        $inserted = [];

        foreach ($items as $item) {
            $propertyId = intval($item['property_id'] ?? 0);
            $category   = $item['category'] ?? '';
            $title      = trim($item['title'] ?? '');
            $content    = trim($item['content'] ?? '');
            $sourceType = $item['source_type'] ?? 'manual';

            if (!$propertyId || !$title || !$content) continue;
            if (!in_array($category, self::VALID_CATEGORIES, true)) continue;
            if (!in_array($sourceType, self::VALID_SOURCE_TYPES, true)) {
                $sourceType = 'manual';
            }

            $id = DB::table('property_knowledge')->insertGetId([
                'property_id'        => $propertyId,
                'project_group_id'   => $item['project_group_id'] ?? null,
                'category'           => $category,
                'title'              => $title,
                'content'            => $content,
                'source_type'        => $sourceType,
                'source_id'          => intval($item['source_id'] ?? 0) ?: null,
                'source_description' => $item['source_description'] ?? null,
                'confidence'         => in_array($item['confidence'] ?? '', ['high','medium','low']) ? $item['confidence'] : 'medium',
                'is_verified'        => intval($item['is_verified'] ?? 0),
                'created_by'         => $item['created_by'] ?? 'admin',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $inserted[] = $id;
        }

        return response()->json([
            'success'      => true,
            'inserted_ids' => $inserted,
            'count'        => count($inserted),
        ]);
    }

    /**
     * update_knowledge — Update a knowledge entry.
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $allowed = [
            'category','title','content','source_type','source_id',
            'source_description','confidence','is_verified','is_active','expires_at',
        ];

        $sets = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $sets[$field] = $input[$field];
            }
        }

        if (empty($sets)) {
            return response()->json(['error' => 'No fields to update'], 400);
        }

        $sets['updated_at'] = now();

        $affected = DB::table('property_knowledge')->where('id', $id)->update($sets);

        return response()->json(['success' => true, 'updated' => $affected]);
    }

    /**
     * delete_knowledge — Soft-delete (deactivate) a knowledge entry.
     */
    public function destroy(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $affected = DB::table('property_knowledge')
            ->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return response()->json(['success' => true, 'deactivated' => $affected]);
    }

    /**
     * delete_knowledge_permanent — Hard-delete a knowledge entry.
     */
    public function destroyPermanent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $deleted = DB::table('property_knowledge')->where('id', $id)->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * knowledge_summary — Generate a structured text summary for a property.
     */
    public function summary(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $items = DB::select(
            'SELECT category, title, content, confidence, is_verified, source_description
             FROM property_knowledge
             WHERE property_id = ? AND is_active = 1
             ORDER BY confidence DESC, is_verified DESC, created_at DESC',
            [$propertyId]
        );

        if (empty($items)) {
            return response()->json(['summary' => '', 'count' => 0]);
        }

        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->category][] = $item;
        }

        $summary    = '';
        $totalChars = 0;
        $maxChars   = 3000;

        foreach ($grouped as $cat => $catItems) {
            $label   = self::CATEGORY_LABELS[$cat] ?? ucfirst($cat);
            $section = "\n=== {$label} ===\n";

            foreach (array_slice($catItems, 0, 5) as $item) {
                $verified = ($item->is_verified || $item->confidence === 'high') ? ' ✓' : '';
                $line     = "- {$item->title}: {$item->content}{$verified}\n";
                if ($totalChars + strlen($section) + strlen($line) > $maxChars) {
                    break 2;
                }
                $section .= $line;
            }

            $totalChars += strlen($section);
            $summary .= $section;
        }

        return response()->json([
            'summary'    => trim($summary),
            'count'      => count($items),
            'categories' => array_keys($grouped),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * ai_categorize_knowledge — Categorize free text using AI.
     */
    public function aiCategorize(Request $request): JsonResponse
    {
        $input      = $request->json()->all();
        $propertyId = intval($input['property_id'] ?? 0);
        $text       = trim($input['text'] ?? '');

        if (!$propertyId || !$text) {
            return response()->json(['error' => 'property_id and text required'], 400);
        }

        $prop = DB::selectOne('SELECT ref_id, address, city FROM properties WHERE id = ?', [$propertyId]);
        if (!$prop) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $propAddress = "{$prop->address}, {$prop->city}";
        $categories  = implode(', ', self::VALID_CATEGORIES);

        $system = 'Du kategorisierst Informationen über Immobilien. Antworte NUR mit einem JSON-Objekt.';
        $user   = "IMMOBILIE: {$propAddress} (Ref: {$prop->ref_id})\n\nEINGABE: {$text}\n\n"
            . "Antworte NUR mit JSON:\n{\"category\": \"<Kategorie>\", \"title\": \"<Kurztitel, max 100 Zeichen>\", \"content\": \"<Inhalt, 1-3 Sätze>\"}\n\n"
            . "KATEGORIEN: {$categories}\n\nWähle die passendste Kategorie. Formuliere Titel und Content professionell.";

        /** @var AnthropicService $ai */
        $ai     = app(AnthropicService::class);
        $result = $ai->chatJson($system, $user, 500);

        if ($result && isset($result['category'], $result['title'], $result['content'])) {
            return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['error' => 'Could not parse AI response'], 502);
    }

    /**
     * ingest_document — Extract knowledge from an uploaded document.
     */
    public function ingestDocument(Request $request): JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return response()->json(['error' => 'File upload required'], 400);
        }

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $tmpPath      = $file->getRealPath();
        $ext          = strtolower($file->getClientOriginalExtension());

        // Extract text
        $extractedText = '';
        if (in_array($ext, ['txt', 'csv'])) {
            $extractedText = file_get_contents($tmpPath);
        } elseif ($ext === 'docx') {
            $extractedText = $this->extractTextFromDocx($tmpPath);
        } elseif ($ext === 'pdf') {
            $extractedText = $this->extractTextFromPdf($tmpPath);
        } else {
            return response()->json(['error' => 'Format nicht unterstützt. Erlaubt: PDF, DOCX, TXT, CSV'], 400);
        }

        $extractedText = trim($extractedText);
        if (strlen($extractedText) < 100) {
            return response()->json([
                'error' => 'Zu wenig Text extrahiert (' . strlen($extractedText) . ' Zeichen). Möglicherweise ist das Dokument gescannt (Bild-PDF).',
                'items' => [],
            ]);
        }

        $prop = DB::selectOne('SELECT ref_id, address, city FROM properties WHERE id = ?', [$propertyId]);
        if (!$prop) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $propAddress = "{$prop->address}, {$prop->city}";

        // Detect document type from filename
        $docType  = 'Sonstiges';
        $nameLow  = strtolower($originalName);
        if (str_contains($nameLow, 'expose') || str_contains($nameLow, 'exposé')) $docType = 'Exposé';
        elseif (str_contains($nameLow, 'energieausweis') || str_contains($nameLow, 'energie')) $docType = 'Energieausweis';
        elseif (str_contains($nameLow, 'baubeschreibung') || str_contains($nameLow, 'bau')) $docType = 'Baubeschreibung';
        elseif (str_contains($nameLow, 'grundbuch')) $docType = 'Grundbuchauszug';
        elseif (str_contains($nameLow, 'gutachten') || str_contains($nameLow, 'bewertung')) $docType = 'Gutachten/Bewertung';

        $textSnippet = mb_substr($extractedText, 0, 4000);
        $categories  = implode(', ', self::VALID_CATEGORIES);

        $system = 'Du extrahierst strukturiertes Wissen über Immobilien aus Dokumenten. Antworte NUR mit einem JSON-Array.';
        $user   = "IMMOBILIE: {$propAddress} (Ref: {$prop->ref_id})\nDOKUMENT-TYP: {$docType}\nDATEINAME: {$originalName}\n\n"
            . "DOKUMENTINHALT:\n{$textSnippet}\n\n"
            . "Extrahiere ALLE konkreten FAKTEN die für die Vermarktung relevant sind.\n\n"
            . "JSON-Array. Jedes Element: {\"category\": \"<Kategorie>\", \"title\": \"<Kurztitel>\", \"content\": \"<Inhalt, 1-3 Sätze>\", \"confidence\": \"high\"}\n\n"
            . "KATEGORIEN: {$categories}\n\n"
            . "REGELN: NUR echte Fakten, Zahlen EXAKT übernehmen, max 15 Einträge, confidence='high'.";

        /** @var AnthropicService $ai */
        $ai     = app(AnthropicService::class);
        $result = $ai->chatJson($system, $user, 4000);

        if (!is_array($result)) {
            return response()->json(['items' => [], 'message' => 'Keine Wissenseinträge extrahiert', 'raw_length' => strlen($extractedText)]);
        }

        // chatJson may return an object or array; ensure we have items
        $resultItems = isset($result[0]) ? $result : [$result];

        return response()->json([
            'success'     => true,
            'items'       => $resultItems,
            'count'       => count($resultItems),
            'document'    => $originalName,
            'text_length' => strlen($extractedText),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * bulk_extract_knowledge — Batch extract knowledge from unprocessed emails.
     */
    public function bulkExtract(Request $request): JsonResponse
    {
        $input      = $request->json()->all();
        $propertyId = intval($input['property_id'] ?? 0);
        $batchSize  = min(50, max(1, intval($input['batch_size'] ?? 30)));
        $offset     = max(0, intval($input['offset'] ?? 0));

        $where  = "pe.property_id IS NOT NULL AND pe.category NOT IN ('sonstiges') AND LENGTH(pe.body_text) >= 50";
        $params = [];
        if ($propertyId) {
            $where   .= ' AND pe.property_id = ?';
            $params[] = $propertyId;
        }

        $totalRemaining = (int) DB::selectOne(
            "SELECT COUNT(*) as cnt FROM portal_emails pe
             WHERE {$where}
             AND pe.id NOT IN (SELECT DISTINCT source_id FROM property_knowledge WHERE source_id IS NOT NULL)",
            $params
        )->cnt;

        $emails = DB::select(
            "SELECT pe.id, pe.direction, pe.subject, pe.body_text, pe.property_id,
                    pe.category, pe.stakeholder, pe.email_date
             FROM portal_emails pe
             WHERE {$where}
             AND pe.id NOT IN (SELECT DISTINCT source_id FROM property_knowledge WHERE source_id IS NOT NULL)
             ORDER BY pe.email_date ASC
             LIMIT {$batchSize} OFFSET {$offset}",
            $params
        );

        if (empty($emails)) {
            return response()->json([
                'success'           => true,
                'processed'         => 0,
                'knowledge_created' => 0,
                'remaining'         => 0,
                'done'              => true,
                'message'           => 'Keine weiteren Emails zu verarbeiten',
            ]);
        }

        /** @var AnthropicService $ai */
        $ai = app(AnthropicService::class);

        $properties = DB::select('SELECT id, ref_id, address, city, zip, platforms FROM properties');
        $propMap    = [];
        foreach ($properties as $p) {
            $propMap[$p->id] = $p;
        }

        $processed        = 0;
        $knowledgeCreated = 0;
        $errors           = 0;

        foreach ($emails as $email) {
            try {
                $prop        = $propMap[$email->property_id] ?? null;
                $propContext = $prop ? "{$prop->address}, {$prop->city} (Ref: {$prop->ref_id})" : 'Unbekannt';
                $direction   = $email->direction === 'outbound' ? 'Ausgehend' : 'Eingehend';

                $system = 'Du extrahierst konkretes Immobilien-Wissen aus Emails. Antworte NUR mit einem JSON-Array.';
                $user   = "IMMOBILIE: {$propContext}\nRICHTUNG: {$direction}\nKATEGORIE: {$email->category}\n"
                    . "VON: {$email->stakeholder}\nBETREFF: {$email->subject}\n\n"
                    . "EMAIL:\n" . mb_substr($email->body_text, 0, 2000) . "\n\n"
                    . "Extrahiere KONKRETE Fakten als JSON-Array. Jedes Element:\n"
                    . "{\"category\": \"<Kategorie>\", \"title\": \"<Kurztitel>\", \"content\": \"<Inhalt>\", \"confidence\": \"medium|high\"}\n\n"
                    . "KATEGORIEN: " . implode(', ', self::VALID_CATEGORIES) . "\n\n"
                    . "Leeres Array [] wenn nichts Relevantes.";

                $items = $ai->chatJson($system, $user, 2000) ?? [];
                if (!isset($items[0]) && !empty($items)) {
                    $items = [$items];
                }

                $sourceType = $email->direction === 'outbound' ? 'email_out' : 'email_ingest';
                $emailDate  = date('d.m.Y', strtotime($email->email_date));
                $sourceDesc = "Email von {$email->stakeholder}, {$emailDate}";

                foreach ($items as $item) {
                    if (empty($item['category']) || empty($item['title']) || empty($item['content'])) continue;
                    if (!in_array($item['category'], self::VALID_CATEGORIES, true)) continue;

                    DB::table('property_knowledge')->insert([
                        'property_id'        => $email->property_id,
                        'category'           => $item['category'],
                        'title'              => $item['title'],
                        'content'            => $item['content'],
                        'source_type'        => $sourceType,
                        'source_id'          => $email->id,
                        'source_description' => $sourceDesc,
                        'confidence'         => $item['confidence'] ?? 'medium',
                        'created_by'         => 'bulk-extract',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                    $knowledgeCreated++;
                }

                // Marker for empty extractions to avoid re-processing
                if (empty($items)) {
                    DB::table('property_knowledge')->insert([
                        'property_id'        => $email->property_id,
                        'category'           => 'sonstiges',
                        'title'              => '_no_knowledge',
                        'content'            => 'Keine extrahierbaren Fakten',
                        'source_type'        => $sourceType,
                        'source_id'          => $email->id,
                        'source_description' => $sourceDesc,
                        'confidence'         => 'low',
                        'is_active'          => 0,
                        'created_by'         => 'bulk-extract',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }

                $processed++;
                usleep(200000); // 200ms rate limit

            } catch (\Exception $e) {
                $errors++;
                Log::error("[BULK-KB] Error email #{$email->id}: " . $e->getMessage());
            }
        }

        $newRemaining = $totalRemaining - $processed;

        return response()->json([
            'success'           => true,
            'processed'         => $processed,
            'knowledge_created' => $knowledgeCreated,
            'errors'            => $errors,
            'remaining'         => max(0, $newRemaining),
            'total'             => $totalRemaining,
            'done'              => $newRemaining <= 0,
            'next_offset'       => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // Private helpers for document text extraction
    // ------------------------------------------------------------------

    private function extractTextFromDocx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return '';

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) return '';

        $xml  = str_replace(['</w:p>', '</w:br>'], ["\n", "\n"], $xml);
        $text = strip_tags($xml);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extractTextFromPdf(string $path): string
    {
        // Method 1: Use pdftotext (poppler-utils) — best quality
        $escapedPath = escapeshellarg($path);
        $output = null;
        $returnCode = null;
        exec("pdftotext -layout {$escapedPath} - 2>/dev/null", $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            $text = implode("\n", $output);
            $text = trim($text);
            if (strlen($text) > 50) {
                return $text;
            }
        }
        
        // Method 2: pdftotext without layout flag
        $output2 = null;
        exec("pdftotext {$escapedPath} - 2>/dev/null", $output2, $returnCode);
        if ($returnCode === 0 && !empty($output2)) {
            $text = implode("\n", $output2);
            $text = trim($text);
            if (strlen($text) > 50) {
                return $text;
            }
        }

        // Method 3: PHP fallback — basic stream parsing
        $content = file_get_contents($path);
        if (!$content) return '';

        $text = '';
        if (preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $streams)) {
            foreach ($streams[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) $decoded = @gzinflate($stream);
                if ($decoded !== false) {
                    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $decoded, $tj)) {
                        $text .= implode(' ', array_map([$this, 'pdfDecodeString'], $tj[1]));
                    }
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tjs)) {
                        foreach ($tjs[1] as $tjContent) {
                            if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $tjContent, $parts)) {
                                $text .= implode('', array_map([$this, 'pdfDecodeString'], $parts[1]));
                            }
                        }
                        $text .= ' ';
                    }
                }
            }
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        return trim($text);
    }

    private function pdfDecodeString(string $str): string
    {
        $str = str_replace(
            ['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'],
            ["\n", "\r", "\t", '(', ')', '\\'],
            $str
        );
        $str = preg_replace_callback('/\\\\(\d{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);
        return $str;
    }


    /**
     * AI bulk categorize - split a long text into multiple KB entries.
     */
    public function aiBulkCategorize(Request $request): JsonResponse
    {
        $input      = $request->json()->all();
        $propertyId = intval($input['property_id'] ?? 0);
        $text       = trim($input['text'] ?? '');

        if (!$propertyId || !$text) {
            return response()->json(['error' => 'property_id and text required'], 400);
        }

        $prop = DB::selectOne('SELECT ref_id, address, city FROM properties WHERE id = ?', [$propertyId]);
        if (!$prop) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $propAddress = "{$prop->address}, {$prop->city}";
        $categories  = implode(', ', self::VALID_CATEGORIES);

        // Truncate text to avoid token limits
        $text = mb_substr($text, 0, 8000);

        $system = 'Du bist ein Immobilien-Experte. Du extrahierst strukturiertes Wissen aus Dokumenten. Antworte NUR mit einem JSON-Array.';
        $user   = "IMMOBILIE: {$propAddress} (Ref: {$prop->ref_id})\n\n"
            . "DOKUMENT-TEXT:\n{$text}\n\n"
            . "AUFGABE: Extrahiere ALLE relevanten Informationen aus dem Text und teile sie in separate Wissenseintraege auf.\n"
            . "Jeder Eintrag bekommt die passendste Kategorie.\n\n"
            . "KATEGORIEN: {$categories}\n\n"
            . "Antworte NUR mit einem JSON-Array:\n"
            . "[{\"category\": \"<Kategorie>\", \"title\": \"<Kurztitel max 100 Zeichen>\", \"content\": \"<Inhalt, 1-3 Saetze, professionell formuliert>\"}]\n\n"
            . "REGELN:\n"
            . "- Erstelle so viele Eintraege wie noetig (typisch 3-15)\n"
            . "- Jede Info nur EINMAL erfassen\n"
            . "- Fasse zusammengehoerige Details in einem Eintrag zusammen\n"
            . "- Ignoriere irrelevante Textteile (Disclamer, Werbung, etc.)\n"
            . "- Verwende nur die vorgegebenen Kategorien";

        /** @var \App\Services\AnthropicService $ai */
        $ai     = app(\App\Services\AnthropicService::class);
        $result = $ai->chatJson($system, $user, 4000);

        // chatJson might return array directly or nested
        $entries = [];
        if (is_array($result)) {
            // Check if it's a list of entries or a single entry
            if (isset($result[0]) && isset($result[0]['category'])) {
                $entries = $result;
            } elseif (isset($result['entries'])) {
                $entries = $result['entries'];
            } elseif (isset($result['category'])) {
                $entries = [$result];
            }
        }

        // Validate categories
        $valid = [];
        foreach ($entries as $e) {
            if (!isset($e['category'], $e['title'], $e['content'])) continue;
            if (!in_array($e['category'], self::VALID_CATEGORIES, true)) {
                $e['category'] = 'sonstiges';
            }
            $valid[] = $e;
        }

        if (empty($valid)) {
            return response()->json(['error' => 'KI konnte keine Eintraege extrahieren'], 502);
        }

        return response()->json(['entries' => $valid], 200, [], JSON_UNESCAPED_UNICODE);
    }


    /**
     * Upload file → extract text → Haiku categorizes into KB entries. File is NOT stored.
     */
    public function aiExtractFromFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $prop = DB::selectOne('SELECT ref_id, address, city, object_type as type, realty_status as status FROM properties WHERE id = ?', [$propertyId]);
        if (!$prop) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $tempPath = $file->getRealPath();

        // Step 1: Extract raw text
        try {
            if ($ext === 'pdf') {
                $rawText = $this->extractTextFromPdf($tempPath);
            } elseif (in_array($ext, ['doc', 'docx'])) {
                $rawText = $this->extractTextFromDocx($tempPath);
            } elseif (in_array($ext, ['txt', 'csv', 'json', 'xml', 'html', 'md', 'log'])) {
                $rawText = file_get_contents($tempPath);
            } elseif (in_array($ext, ['xls', 'xlsx'])) {
                $rawText = $this->extractTextFromXlsx($tempPath);
            } else {
                return response()->json(['error' => 'Dateityp nicht unterstuetzt: .' . $ext], 400);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Fehler beim Auslesen: ' . $e->getMessage()], 500);
        }

        $rawText = trim($rawText);
        if (empty($rawText) || strlen($rawText) < 20) {
            return response()->json(['error' => 'Kein ausreichender Text in der Datei gefunden'], 400);
        }

        // Step 2: Send to Haiku for intelligent extraction & categorization
        $rawText = mb_substr($rawText, 0, 10000);
        $categories = implode(', ', self::VALID_CATEGORIES);
        $propAddress = "{$prop->address}, {$prop->city}";
        $fileName = $file->getClientOriginalName();

        $system = 'Du bist ein erfahrener Immobilien-Experte. Du analysierst Dokumente (Expose, Gutachten, Energieausweis, etc.) und extrahierst ALLE relevanten Fakten als strukturierte Wissenseintraege. Antworte NUR mit einem JSON-Array.';

        $user = "IMMOBILIE: {$propAddress} (Ref: {$prop->ref_id}, Typ: {$prop->object_type}, Status: {$prop->realty_status})
"
            . "DATEI: {$fileName}

"
            . "DOKUMENT-INHALT:
{$rawText}

"
            . "AUFGABE: Extrahiere ALLE relevanten Immobilien-Informationen. Erstelle fuer jedes Thema einen eigenen Eintrag.

"
            . "KATEGORIEN: {$categories}

"
            . "Typische Eintraege die du finden solltest (falls im Text vorhanden):
"
            . "- Wohnflaeche, Grundstuecksflaeche, Zimmeranzahl -> objektbeschreibung
"
            . "- Kueche, Bad, Boeden, Fenster, Balkon, Garten, Garage, Keller -> ausstattung
"
            . "- Heizungsart, Energiekennwert, HWB -> energetik
"
            . "- Stadtteil, Infrastruktur, Verkehrsanbindung, Schulen -> lage_umgebung
"
            . "- Kaufpreis, Betriebskosten, Provision -> preis_markt
"
            . "- Baujahr, Sanierungen, Renovierungen -> objektbeschreibung
"
            . "- Grundbuch, Widmung, Flaeche -> rechtliches

"
            . "Antworte NUR mit JSON-Array:
"
            . "[{\"category\": \"<Kategorie>\", \"title\": \"<Kurztitel max 80 Zeichen>\", \"content\": \"<Alle relevanten Details, konkrete Zahlen und Fakten, 1-4 Saetze>\"}]

"
            . "WICHTIG:
"
            . "- Erfasse KONKRETE Zahlen, Masse, Ausstattungsdetails — keine vagen Beschreibungen
"
            . "- Jede Info nur EINMAL
"
            . "- Ignoriere Marketing-Floskeln, Disclaimer, Kontaktdaten
"
            . "- Erstelle 5-20 Eintraege je nach Dokumentumfang";

        $ai = app(\App\Services\AnthropicService::class);
        $result = $ai->chatJson($system, $user, 4000);

        $entries = [];
        if (is_array($result)) {
            if (isset($result[0]) && isset($result[0]['category'])) {
                $entries = $result;
            } elseif (isset($result['entries'])) {
                $entries = $result['entries'];
            } elseif (isset($result['category'])) {
                $entries = [$result];
            }
        }

        $valid = [];
        foreach ($entries as $e) {
            if (!isset($e['category'], $e['title'], $e['content'])) continue;
            if (!in_array($e['category'], self::VALID_CATEGORIES, true)) {
                $e['category'] = 'sonstiges';
            }
            $valid[] = $e;
        }

        if (empty($valid)) {
            return response()->json(['error' => 'KI konnte keine Wissenseintraege extrahieren. Rohdaten: ' . mb_substr($rawText, 0, 200)], 502);
        }

        return response()->json([
            'entries' => $valid,
            'source_file' => $fileName,
            'raw_chars' => mb_strlen($rawText),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extract text from uploaded file without storing it.
     */
    public function extractFileText(Request $request): JsonResponse
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $tempPath = $file->getRealPath();

        try {
            $text = '';

            if ($ext === 'pdf') {
                $text = $this->extractTextFromPdf($tempPath);
            } elseif (in_array($ext, ['doc', 'docx'])) {
                $text = $this->extractTextFromDocx($tempPath);
            } elseif (in_array($ext, ['txt', 'csv', 'json', 'xml', 'html', 'md', 'log'])) {
                $text = file_get_contents($tempPath);
            } elseif (in_array($ext, ['xls', 'xlsx'])) {
                // Basic xlsx extraction via zip
                $text = $this->extractTextFromXlsx($tempPath);
            } else {
                return response()->json(['error' => 'Dateityp nicht unterstuetzt: .' . $ext], 400);
            }

            // Clean up text
            $text = trim($text);
            if (empty($text)) {
                return response()->json(['error' => 'Kein Text konnte aus der Datei extrahiert werden'], 400);
            }

            return response()->json([
                'text' => mb_substr($text, 0, 15000),
                'filename' => $file->getClientOriginalName(),
                'chars' => mb_strlen($text),
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Fehler beim Auslesen: ' . $e->getMessage()], 500);
        }
    }

    private function extractTextFromXlsx(string $path): string
    {
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $name)) {
                    $xml = $zip->getFromIndex($i);
                    // Extract cell values
                    preg_match_all('/<v>([^<]*)<\/v>/', $xml, $matches);
                    if (!empty($matches[1])) {
                        $text .= implode(' ', $matches[1]) . "\n";
                    }
                }
                // Shared strings
                if ($name === 'xl/sharedStrings.xml') {
                    $xml = $zip->getFromIndex($i);
                    preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $xml, $matches);
                    if (!empty($matches[1])) {
                        $text = implode(' ', $matches[1]) . "\n" . $text;
                    }
                }
            }
            $zip->close();
        }
        return $text;
    }

}