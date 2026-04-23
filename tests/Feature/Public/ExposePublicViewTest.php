<?php

namespace Tests\Feature\Public;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposePublicViewTest extends TestCase
{
    use RefreshDatabase;

    private function mkSessionCookie(PropertyLink $link, PropertyLinkSession $session): array
    {
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $hmac = hash_hmac('sha256', (string) $session->id, config('app.key'));
        return [$cookieName => $session->id . '.' . $hmac];
    }

    public function test_unlocked_link_with_attached_expose_returns_html(): void
    {
        $property = Property::factory()->create(['realty_description' => 'Haus-Beschreibung.']);
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [
                ['type' => 'cover'],
                ['type' => 'details'],
                ['type' => 'kontakt'],
            ]],
            'is_active'   => true,
        ]);
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'token'       => 'testtoken1234',
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id'   => $link->id,
            'property_file_id'   => null,
            'expose_version_id'  => $version->id,
            'sort_order'         => 0,
            'created_at'         => now(),
        ]);
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
        ]);

        $response = $this->withCookies($this->mkSessionCookie($link, $session))
                         ->get('/docs/testtoken1234/expose');

        $response->assertStatus(200);
        $response->assertSee('class="page cover-page"', false);
    }

    public function test_expose_blocked_on_locked_link(): void
    {
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'token'       => 'lockedtoken12',
        ]);

        // No session cookie → session resolution returns null → 403
        $this->get('/docs/lockedtoken12/expose')->assertStatus(403);
    }

    public function test_expose_404_if_not_attached_to_link(): void
    {
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'token'       => 'emptytoken12',
        ]);
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
        ]);

        $response = $this->withCookies($this->mkSessionCookie($link, $session))
                         ->get('/docs/emptytoken12/expose');

        $response->assertStatus(404);
    }
}
