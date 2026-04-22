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

    public function test_render_includes_sanierungen_and_documents_and_approvals(): void
    {
        $service = app(IntakeProtocolPdfService::class);

        $data = [
            'property' => [
                'ref_id' => 'TEST-02',
                'address' => 'Teststraße', 'house_number' => '5',
                'zip' => '5020', 'city' => 'Salzburg',
                'object_type' => 'Wohnung',
            ],
            'owner' => ['name' => 'Test', 'email' => 't@test.at'],
            'broker' => ['name' => 'Makler'],
            'disclaimer_text' => 'Disclaimer',
            'signed_at' => now(),
            'signed_by_name' => 'Test',
            'sanierungen' => [
                ['category' => 'windows', 'label' => 'Fenster', 'year' => 2018, 'description' => '3-fach verglast'],
                ['category' => 'heating', 'label' => 'Heizung', 'year' => 2022, 'description' => 'Wärmepumpe'],
            ],
            'documents_available' => [
                'grundbuchauszug' => 'available',
                'energieausweis' => 'missing',
                'mietvertrag' => 'na',
            ],
            'approvals_status' => 'partial',
            'approvals_notes' => 'Terrasse nicht bewilligt',
            'open_fields' => ['construction_year', 'bathrooms'],
        ];

        $html = view('pdf.intake-protocol', $data)->render();

        $this->assertStringContainsString('Sanierungen', $html);
        $this->assertStringContainsString('Fenster', $html);
        $this->assertStringContainsString('2018', $html);
        $this->assertStringContainsString('Wärmepumpe', $html);

        $this->assertStringContainsString('Dokumenten-Checkliste', $html);
        $this->assertStringContainsString('Grundbuchauszug', $html);

        $this->assertStringContainsString('Bewilligungen', $html);
        $this->assertStringContainsString('Terrasse nicht bewilligt', $html);

        $this->assertStringContainsString('Offene Felder', $html);
        $this->assertStringContainsString('construction_year', $html);
    }
}
