<?php

namespace App\Services\Expose;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExposePdfService
{
    /**
     * Rendert eine HTML-URL zu einem A4-Querformat-PDF. Gibt den PDF-Binary
     * zurück (nicht auf Disk).
     *
     * Wenn der Server seinen eigenen FQDN nicht über DNS auflöst, wird
     * Chromium per `--host-resolver-rules` angewiesen, den Host lokal auf
     * 127.0.0.1 zu mappen — die URL (inkl. Scheme, Session-Cookie-Domain)
     * bleibt dadurch unverändert gültig.
     */
    public function renderFromUrl(string $url, bool $loopbackMap = true): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'expose_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file for PDF output');
        }
        $tmp .= '.pdf';
        $script = base_path('resources/scripts/expose-pdf.cjs');

        $mapHost = null;
        if ($loopbackMap) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                $mapHost = $host;
            }
        }

        try {
            $args = ['node', $script, $url, $tmp];
            if ($mapHost !== null) {
                $args[] = $mapHost;
            }
            $process = new Process($args);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            if (!file_exists($tmp)) {
                throw new \RuntimeException('PDF file not created at ' . $tmp);
            }

            $binary = file_get_contents($tmp);
            if ($binary === false) {
                throw new \RuntimeException('Failed to read PDF from ' . $tmp);
            }
            return $binary;
        } finally {
            @unlink($tmp);
        }
    }
}
