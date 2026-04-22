<?php

namespace Tests\Feature\Api;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteApiInternalFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite kennt kein REGEXP_REPLACE — der WebsiteApiController nutzt
        // das in den property_units-Queries fuer Neubauprojekt-Sortierung.
        // Fuer den Filter-Test reicht eine No-Op-Implementierung.
        $pdo = DB::connection()->getPdo();
        if ($pdo && method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('REGEXP_REPLACE', function ($subject, $pattern, $replacement) {
                if ($subject === null) return null;
                return preg_replace('/' . str_replace('/', '\/', (string) $pattern) . '/', (string) $replacement, (string) $subject);
            }, 3);
        }
    }

    public function test_website_api_property_endpoint_does_not_expose_internal_fields(): void
    {
        // broker_id bewusst weggelassen — der Broker-Card-Block im Controller
        // nutzt sonst signature_title/phone, die im SQLite-Testschema fehlen.
        // Fuer den Filter-Test reicht eine Property ohne Broker.
        $property = Property::factory()->create([
            'realty_status' => 'aktiv',
            'show_on_website' => 1,
            'address' => 'Musterstraße',
            'house_number' => '1',
            'zip' => '5020',
            'city' => 'Salzburg',
            'object_type' => 'Wohnung',
            'encumbrances' => 'Pfandrecht 180k',
            'approvals_status' => 'partial',
            'approvals_notes' => 'Terrasse nicht bewilligt',
            'internal_notes' => 'Eigentümer will Vollmacht',
            'documents_available' => ['grundbuch' => 'available'],
            'parking_assignment' => 'assigned',
        ]);

        $response = $this->getJson('/api/website/property/' . $property->id);

        $response->assertOk();
        $response->assertJsonMissing(['encumbrances' => 'Pfandrecht 180k']);
        $response->assertJsonMissing(['approvals_status' => 'partial']);
        $response->assertJsonMissing(['approvals_notes' => 'Terrasse nicht bewilligt']);
        $response->assertJsonMissing(['internal_notes' => 'Eigentümer will Vollmacht']);

        // Zusaetzlich Body-String-Check: die internen Werte duerfen nirgendwo
        // im Response-Body vorkommen (z.B. auch nicht in geschachtelten Arrays).
        $body = $response->getContent();
        $this->assertStringNotContainsString('Pfandrecht 180k', $body);
        $this->assertStringNotContainsString('Terrasse nicht bewilligt', $body);
        $this->assertStringNotContainsString('Eigentümer will Vollmacht', $body);
    }

    public function test_website_api_properties_list_does_not_expose_internal_fields(): void
    {
        Property::factory()->create([
            'realty_status' => 'aktiv',
            'show_on_website' => 1,
            'address' => 'Teststr',
            'zip' => '5020',
            'city' => 'Salzburg',
            'object_type' => 'Wohnung',
            'encumbrances' => 'INTERNAL_DATA_MARKER',
            'approvals_notes' => 'INTERNAL_DATA_MARKER',
            'internal_notes' => 'INTERNAL_DATA_MARKER',
        ]);

        $response = $this->getJson('/api/website/properties');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('INTERNAL_DATA_MARKER', $body);
    }
}
