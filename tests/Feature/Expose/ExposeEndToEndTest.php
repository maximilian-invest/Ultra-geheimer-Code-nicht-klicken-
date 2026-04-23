<?php

namespace Tests\Feature\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyImage;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExposeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_broker_generates_attaches_customer_views(): void
    {
        // ── 1) Setup: Makler + Property mit 4 Bildern ────────────────────
        $broker = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create([
            'title'                 => 'Testhaus',
            'realty_description'    => 'Ein schönes Haus mit Charme.',
            'location_description'  => 'Ruhige Lage in Salzburg.',
            'broker_id'             => $broker->id,
        ]);
        for ($i = 0; $i < 4; $i++) {
            PropertyImage::create([
                'property_id'    => $property->id,
                'filename'       => "img{$i}.jpg",
                'path'           => "property_images/{$property->id}/img{$i}.jpg",
                'sort_order'     => $i,
                'is_title_image' => $i === 0,
                'is_floorplan'   => false,
                'is_public'      => true,
                'category'       => 'sonstiges',
            ]);
        }

        // ── 2) Makler generiert Exposé via POST ─────────────────────────
        $this->actingAs($broker)
             ->postJson("/admin/properties/{$property->id}/expose")
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        $version = PropertyExposeVersion::where('property_id', $property->id)->first();
        $this->assertNotNull($version, 'Expose version should be created');
        $this->assertTrue($version->is_active);

        // Default config should contain cover/details/haus/lage/impressionen/kontakt
        $pageTypes = array_column($version->config_json['pages'], 'type');
        $this->assertContains('cover', $pageTypes);
        $this->assertContains('details', $pageTypes);
        $this->assertContains('kontakt', $pageTypes);

        // ── 3) Freigabelink anlegen + Exposé anhängen ────────────────────
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'token'       => 'e2etest' . bin2hex(random_bytes(4)),
            'created_by'  => $broker->id,
        ]);
        DB::table('property_link_documents')->insert([
            'property_link_id'  => $link->id,
            'property_file_id'  => null,
            'expose_version_id' => $version->id,
            'sort_order'        => 0,
            'created_at'        => now(),
        ]);

        // ── 4) Kunde entsperrt Link (Session) ────────────────────────────
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
        ]);

        // ── 5) Kunde öffnet /docs/{token}/expose ─────────────────────────
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $hmac = hash_hmac('sha256', (string) $session->id, config('app.key'));
        $cookieValue = $session->id . '.' . $hmac;

        $response = $this->withCookies([$cookieName => $cookieValue])
                         ->get("/docs/{$link->token}/expose");

        $response->assertStatus(200);
        $response->assertSee('class="page cover-page"', false);
        $response->assertSee('class="page details-page"', false);
        $response->assertSee('class="page kontakt-page"', false);
    }
}
