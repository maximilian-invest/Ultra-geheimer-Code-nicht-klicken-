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
     * Wenn der Server seinen eigenen FQDN nicht auflöst (typisch auf einem
     * VPS ohne Split-Horizon-DNS), kann mit `$rewriteToLoopback=true` die
     * URL vor dem Aufruf auf 127.0.0.1 umgebogen werden; der ursprüngliche
     * Host wird dann als Host-Header mitgeschickt, damit nginx den richtigen
     * VHost matcht.
     */
    public function renderFromUrl(string $url, bool $rewriteToLoopback = true): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'expose_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file for PDF output');
        }
        $tmp .= '.pdf';
        $script = base_path('resources/scripts/expose-pdf.cjs');

        [$effectiveUrl, $hostHeader] = $rewriteToLoopback
            ? $this->rewriteToLoopback($url)
            : [$url, null];

        try {
            $args = ['node', $script, $effectiveUrl, $tmp];
            if ($hostHeader !== null) {
                $args[] = $hostHeader;
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

    /**
     * Biegt https://portal.sr-homes.at/… auf http://127.0.0.1/… um und
     * liefert den ursprünglichen Host als Header-Wert zurück.
     */
    private function rewriteToLoopback(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return [$url, null];
        }

        $host = $parts['host'];
        // Nicht umschreiben wenn bereits loopback.
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return [$url, null];
        }

        $scheme = 'http'; // loopback → unverschlüsselt
        $rewritten = $scheme . '://127.0.0.1'
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [$rewritten, $host];
    }
}
