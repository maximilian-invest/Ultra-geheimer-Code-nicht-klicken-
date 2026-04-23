<?php

namespace App\Services\Expose;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExposePdfService
{
    /**
     * Rendert eine HTML-Preview-URL zu einem A4-Querformat-PDF.
     * Gibt den PDF-Binary zurück (nicht auf Disk).
     */
    public function renderFromUrl(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'expose_') . '.pdf';
        $script = base_path('resources/scripts/expose-pdf.cjs');

        $process = new Process(['node', $script, $url, $tmp]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($tmp);
            throw new ProcessFailedException($process);
        }

        if (!file_exists($tmp)) {
            throw new \RuntimeException('PDF file not created at ' . $tmp);
        }

        $binary = file_get_contents($tmp);
        @unlink($tmp);
        return $binary;
    }
}
