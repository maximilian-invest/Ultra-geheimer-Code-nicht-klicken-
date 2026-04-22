<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class VermittlungsauftragPdfService
{
    public function render(array $data): string
    {
        $pdf = Pdf::loadView('pdf.vermittlungsauftrag', $data);
        $pdf->setPaper('A4');
        return $pdf->output();
    }
}
