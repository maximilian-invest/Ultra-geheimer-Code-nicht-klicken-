<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class IntakeProtocolPdfService
{
    /**
     * Rendert ein Aufnahmeprotokoll-PDF als Binary-String.
     * $data: ['property' => [...], 'owner' => [...], 'broker' => [...],
     *        'disclaimer_text', 'signed_at', 'signed_by_name',
     *        'signature_png_path' (optional), 'broker_notes' (optional),
     *        'sanierungen' (array, optional), 'documents_available' (assoc, optional),
     *        'approvals_status', 'approvals_notes', 'photos' (array, optional),
     *        'open_fields' (array, optional)]
     */
    public function render(array $data): string
    {
        $pdf = Pdf::loadView('pdf.intake-protocol', $data);
        $pdf->setPaper('A4');
        return $pdf->output();
    }
}
