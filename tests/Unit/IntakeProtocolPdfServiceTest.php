<?php

namespace Tests\Unit;

use App\Services\IntakeProtocolPdfService;
use Tests\TestCase;

class IntakeProtocolPdfServiceTest extends TestCase
{
    public function test_render_returns_binary_pdf_for_minimal_data(): void
    {
        $service = app(IntakeProtocolPdfService::class);
        $data = [
            'property' => [
                'ref_id' => 'TEST-01',
                'address' => 'Teststraße',
                'house_number' => '1',
                'zip' => '5020',
                'city' => 'Salzburg',
                'object_type' => 'Wohnung',
                'living_area' => 72,
            ],
            'owner' => [
                'name' => 'Max Mustermann',
                'email' => 'max@test.at',
            ],
            'broker' => [
                'name' => 'Susanne Renzl',
            ],
            'disclaimer_text' => 'Test-Disclaimer',
            'signed_at' => now(),
            'signed_by_name' => 'Max Mustermann',
        ];

        $pdfBinary = $service->render($data);

        $this->assertStringStartsWith('%PDF-', $pdfBinary);
        $this->assertGreaterThan(1000, strlen($pdfBinary));
    }
}
