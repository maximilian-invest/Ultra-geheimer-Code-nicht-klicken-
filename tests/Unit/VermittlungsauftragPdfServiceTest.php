<?php

namespace Tests\Unit;

use App\Services\VermittlungsauftragPdfService;
use Tests\TestCase;

class VermittlungsauftragPdfServiceTest extends TestCase
{
    public function test_render_returns_pdf_with_owner_and_property_data(): void
    {
        $service = app(VermittlungsauftragPdfService::class);
        $data = [
            'property' => ['ref_id' => 'VTA-01', 'address' => 'Musterstraße', 'house_number' => '1', 'zip' => '5020', 'city' => 'Salzburg'],
            'owner' => ['name' => 'Hans Test', 'email' => 'hans@test.at', 'address' => 'Musterweg 5', 'zip' => '5020', 'city' => 'Salzburg'],
            'broker' => ['name' => 'Susanne Renzl', 'company' => 'SR-Homes Immobilien GmbH'],
            'commission_percent' => 3.0,
        ];

        $pdf = $service->render($data);

        $this->assertStringStartsWith('%PDF-', $pdf);

        $html = view('pdf.vermittlungsauftrag', $data)->render();
        $this->assertStringContainsString('Hans Test', $html);
        $this->assertStringContainsString('VTA-01', $html);
        $this->assertStringContainsString('3', $html);
    }
}
