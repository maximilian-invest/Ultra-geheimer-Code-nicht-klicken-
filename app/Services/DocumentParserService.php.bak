<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DocumentParserService
{
    private AnthropicService $anthropic;

    public function __construct(AnthropicService $anthropic)
    {
        $this->anthropic = $anthropic;
    }

    /**
     * Parse property fields from uploaded files using AI.
     * Only fills empty fields — never overwrites existing values.
     *
     * @return array{success: bool, fields_filled: int, fields_skipped: int, filled_list: array, skipped_list: array, confidence: string}
     */
    public function parsePropertyFields(int $propertyId, array $fileIds = []): array
    {
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property) {
            return ['success' => false, 'error' => 'Property not found'];
        }
        $property = (array) $property;

        // Resolve file paths
        $filePaths = $this->resolveFilePaths($propertyId, $fileIds);
        if (empty($filePaths)) {
            return ['success' => false, 'error' => 'Keine Dateien gefunden'];
        }

        Log::info("parsePropertyFields: property={$propertyId}, files=" . count($filePaths));

        // Extract content from all files
        $allImages = [];
        $allText = '';
        foreach ($filePaths as $fp) {
            $content = $this->extractContent($fp);
            if (!empty($content['images'])) {
                $allImages = array_merge($allImages, $content['images']);
            }
            if (!empty($content['text'])) {
                $allText .= $content['text'] . "\n\n";
            }
        }

        // Limit to 20 images (API limit)
        $allImages = array_slice($allImages, 0, 20);

        Log::info("parsePropertyFields: images=" . count($allImages) . ", text_len=" . strlen($allText));

        if (empty($allImages) && empty(trim($allText))) {
            return ['success' => false, 'error' => 'Keine Inhalte extrahiert'];
        }

        // Build prompt with field labels
        $fieldLabels = \App\Http\Controllers\Admin\PropertySettingsController::getFieldLabels();
        $fieldTypes = \App\Http\Controllers\Admin\PropertySettingsController::getFieldTypes();
        $fieldsJson = json_encode($fieldLabels, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = "Analysiere dieses Immobilien-Dokument und extrahiere NUR Objektdaten (KEINE Einheiten/Wohnungen).\n\n";
        $prompt .= "ERLAUBTE FELD-KEYS (verwende EXAKT diese keys):\n{$fieldsJson}\n\n";
        $prompt .= "STRIKTE REGELN:\n";
        $prompt .= "- Extrahiere NUR Felder des Objekts selbst, KEINE einzelnen Wohneinheiten/Units!\n";
        $prompt .= "- BESCHREIBUNGEN (realty_description, location_description, equipment_description, other_description, highlights): Den VOLLSTÄNDIGEN Originaltext übernehmen - JEDES WORT, JEDEN ABSATZ! NIEMALS zusammenfassen oder kürzen!\n";
        $prompt .= "- Numerische Felder (m², €, Anzahl): NUR Zahlen, KEINE Einheiten/Texte (z.B. 85.5 statt '85,5 m²')\n";
        $prompt .= "- Boolean-Felder (has_*): true oder false\n";
        $prompt .= "- property_category: 'newbuild' | 'house' | 'apartment' | 'land' | etc.\n";
        $prompt .= "- Felder die nicht im Dokument vorkommen: WEGLASSEN (nicht null setzen)\n";
        $prompt .= "- ENERGIEWERTE (KRITISCH - NIEMALS leer lassen wenn im Dokument vorhanden!):\n";
        $prompt .= "  energy_certificate, heating_demand_value (HWB als Zahl), energy_type, heating_demand_class, energy_efficiency_value (fGEE als Zahl), heating\n";
        $prompt .= "- property_history: JSON-Array [{\"year\": \"1995\", \"title\": \"Dachsanierung\", \"description\": \"Details\"}]\n";
        $prompt .= "- Suche SEHR GRÜNDLICH im gesamten Dokument nach allen Werten!\n\n";
        $prompt .= "Antworte NUR mit gültigem JSON:\n";
        $prompt .= "{ \"fields\": { ... }, \"confidence\": \"high|medium|low\" }";

        // Call AI
        $result = null;
        $systemPrompt = "Du bist ein präziser Immobilien-Datenextraktions-Agent für den österreichischen Markt.";

        try {
            if (count($allImages) > 0) {
                // Has images — use vision API, optionally append text
                $textForVision = $prompt;
                if (!empty(trim($allText))) {
                    $textForVision = "ZUSÄTZLICHER TEXT AUS DATEIEN:\n" . mb_substr($allText, 0, 15000) . "\n\n" . $prompt;
                }
                $result = $this->anthropic->chatWithImagesJson(
                    $systemPrompt,
                    $textForVision,
                    $allImages,
                    16000
                );
            } else {
                // Text only (Excel/Word)
                $textPrompt = "DOKUMENTINHALT:\n" . mb_substr($allText, 0, 15000) . "\n\n" . $prompt;
                $result = $this->anthropic->chatJson(
                    $systemPrompt,
                    $textPrompt,
                    16000
                );
            }
        } catch (\Throwable $e) {
            Log::error("parsePropertyFields: AI call failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'AI-Analyse fehlgeschlagen: ' . $e->getMessage()];
        }

        if (!$result || !isset($result['fields'])) {
            Log::warning("parsePropertyFields: No fields in AI result for property {$propertyId}");
            return ['success' => false, 'error' => 'KI konnte keine Felder extrahieren'];
        }

        $extracted = $result['fields'];
        $confidence = $result['confidence'] ?? 'medium';

        Log::info("parsePropertyFields: AI extracted " . count($extracted) . " fields, confidence={$confidence}");

        // Process results — only fill empty fields
        $filledList = [];
        $skippedList = [];
        $update = ['updated_at' => now(), 'last_expose_parsed_at' => now()];
        $validKeys = array_keys($fieldLabels);

        foreach ($extracted as $key => $value) {
            // Skip keys not in our valid field list
            if (!in_array($key, $validKeys)) {
                continue;
            }

            // Check current value
            $currentValue = $property[$key] ?? null;
            $isEmpty = ($currentValue === null || $currentValue === '' || $currentValue === 0);

            // For booleans, 0 could be a valid "false" value — only consider null/empty as empty
            if (isset($fieldTypes[$key]) && $fieldTypes[$key] === 'boolean') {
                $isEmpty = ($currentValue === null || $currentValue === '');
            }

            if (!$isEmpty) {
                $skippedList[] = $key;
                continue;
            }

            // Convert value based on type
            $processedValue = $value;
            if (isset($fieldTypes[$key]) && $fieldTypes[$key] === 'boolean') {
                $processedValue = ($value === true || $value === 'true' || $value === 1 || $value === '1') ? 1 : 0;
            } elseif ($value === '' || $value === null) {
                continue; // Skip empty AI values
            } elseif (is_array($value)) {
                $processedValue = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $update[$key] = $processedValue;
            $filledList[] = $key;
        }

        // Write to DB
        if (count($filledList) > 0 || true) {
            // Always update timestamp even if no fields filled
            DB::table('properties')->where('id', $propertyId)->update($update);
        }

        Log::info("parsePropertyFields: property={$propertyId}, filled=" . count($filledList) . ", skipped=" . count($skippedList));

        return [
            'success' => true,
            'fields_filled' => count($filledList),
            'fields_skipped' => count($skippedList),
            'filled_list' => $filledList,
            'skipped_list' => $skippedList,
            'confidence' => $confidence,
        ];
    }

    /**
     * Resolve file paths for a property.
     *
     * @return array<string> Filesystem paths that exist
     */
    private function resolveFilePaths(int $propertyId, array $fileIds): array
    {
        $query = DB::table('property_files')->where('property_id', $propertyId);
        if (!empty($fileIds)) {
            $query->whereIn('id', $fileIds);
        }
        $files = $query->get();

        $paths = [];
        foreach ($files as $file) {
            $file = (array) $file;
            // Use stored path, or construct from convention
            $storagePath = $file['path'] ?? '';
            if ($storagePath) {
                $fullPath = '/var/www/srhomes/storage/app/public/' . ltrim($storagePath, '/');
            } else {
                $fullPath = '/var/www/srhomes/storage/app/public/property_files/' . $file['property_id'] . '/' . $file['filename'];
            }

            if (file_exists($fullPath)) {
                $paths[] = $fullPath;
            } else {
                Log::warning("resolveFilePaths: file not found: {$fullPath}");
            }
        }

        // Fallback: check expose_path if no files found and no specific IDs requested
        if (empty($paths) && empty($fileIds)) {
            $property = DB::table('properties')->where('id', $propertyId)->first();
            if ($property && !empty($property->expose_path)) {
                $exposePath = $property->expose_path;
                // Handle both absolute and relative paths
                if (!str_starts_with($exposePath, '/')) {
                    $exposePath = '/var/www/srhomes/storage/app/public/' . ltrim($exposePath, '/');
                }
                if (file_exists($exposePath)) {
                    $paths[] = $exposePath;
                    Log::info("resolveFilePaths: using expose_path fallback: {$exposePath}");
                }
            }
        }

        return $paths;
    }

    /**
     * Extract content from any supported file for AI input.
     *
     * @return array{images: array, text: string}
     */
    public function extractContent(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $images = [];
        $text = '';

        if ($ext === 'pdf') {
            $images = $this->buildImages($filePath);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $mimeMap = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];
            $imgData = base64_encode(file_get_contents($filePath));
            $images[] = [
                'data'       => $imgData,
                'media_type' => $mimeMap[$ext] ?? 'image/jpeg',
            ];
        } elseif (in_array($ext, ['xlsx', 'xls'])) {
            $text = $this->excelToText($filePath);
        } elseif (in_array($ext, ['doc', 'docx'])) {
            $text = $this->wordToText($filePath);
        } else {
            Log::warning("DocumentParserService: unsupported file type '{$ext}' for " . basename($filePath));
        }

        return ['images' => $images, 'text' => $text];
    }

    /**
     * Convert PDF pages to base64-encoded PNG images.
     *
     * @return array<array{data: string, media_type: string}>
     */
    public function buildImages(string $pdfPath, int $maxPages = 30): array
    {
        $tmpDir = '/tmp/doc_parse_' . md5($pdfPath) . '_' . time();
        @mkdir($tmpDir, 0755, true);

        $pageCount = intval(
            shell_exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null | grep "^Pages:" | awk \'{print $2}\'') ?: 0
        );
        if ($pageCount < 1) {
            $pageCount = $maxPages;
        }
        $renderPages = min($pageCount, $maxPages);

        exec(
            'pdftoppm -png -r 120 -l ' . $renderPages . ' '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg($tmpDir . '/page') . ' 2>/dev/null'
        );

        $pageFiles = glob("$tmpDir/page-*.png");
        sort($pageFiles);

        $images = [];
        foreach ($pageFiles as $pf) {
            $images[] = [
                'data'       => base64_encode(file_get_contents($pf)),
                'media_type' => 'image/png',
            ];
        }

        Log::info("DocumentParserService::buildImages: {$pdfPath} — {$pageCount} pages, {$renderPages} rendered, " . count($images) . " images");

        // Cleanup
        array_map('unlink', glob("$tmpDir/*"));
        @rmdir($tmpDir);

        return $images;
    }

    /**
     * Convert Excel file to tab-separated text via Python openpyxl.
     */
    private function excelToText(string $filePath): string
    {
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

        $output = shell_exec('python3 ' . escapeshellarg($pyTmp) . ' ' . escapeshellarg($filePath) . ' 2>/dev/null') ?: '';
        @unlink($pyTmp);

        Log::info("DocumentParserService::excelToText: " . basename($filePath) . " — " . strlen($output) . " chars");

        return $output;
    }

    /**
     * Convert Word document to plain text via LibreOffice headless.
     */
    private function wordToText(string $filePath): string
    {
        $tmpDir = '/tmp/doc_word_' . md5($filePath) . '_' . time();
        @mkdir($tmpDir, 0755, true);

        exec(
            'libreoffice --headless --convert-to txt:Text --outdir '
            . escapeshellarg($tmpDir) . ' '
            . escapeshellarg($filePath) . ' 2>/dev/null'
        );

        $baseName = pathinfo($filePath, PATHINFO_FILENAME) . '.txt';
        $txtPath = $tmpDir . '/' . $baseName;

        $text = '';
        if (file_exists($txtPath)) {
            $text = file_get_contents($txtPath);
        } else {
            // Fallback: check for any .txt file in the output dir
            $txtFiles = glob("$tmpDir/*.txt");
            if (!empty($txtFiles)) {
                $text = file_get_contents($txtFiles[0]);
            }
        }

        Log::info("DocumentParserService::wordToText: " . basename($filePath) . " — " . strlen($text) . " chars");

        // Cleanup
        array_map('unlink', glob("$tmpDir/*"));
        @rmdir($tmpDir);

        return $text;
    }
}
