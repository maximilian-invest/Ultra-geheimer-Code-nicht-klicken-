<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DocumentParserService
{
    private AnthropicService $anthropic;

    public function __construct(AnthropicService $anthropic)
    {
        $this->anthropic = $anthropic;
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
