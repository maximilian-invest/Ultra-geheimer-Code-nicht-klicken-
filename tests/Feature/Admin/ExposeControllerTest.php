<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_version_with_default_config(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create();
        PropertyImage::create([
            'property_id'   => $property->id,
            'filename'      => 'img.jpg',
            'path'          => "property_images/{$property->id}/img.jpg",
            'is_title_image' => true,
            'is_public'     => true,
        ]);

        $this->actingAs($user)
             ->postJson("/admin/properties/{$property->id}/expose")
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        $version = PropertyExposeVersion::where('property_id', $property->id)->first();
        $this->assertNotNull($version);
        $this->assertTrue($version->is_active);
        $this->assertNotEmpty($version->config_json['pages']);
    }

    public function test_preview_renders_html_with_page_count(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create(['realty_description' => 'Kurze Beschreibung.']);
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => [
                'pages' => [
                    ['type' => 'cover'],
                    ['type' => 'details'],
                    ['type' => 'haus'],
                    ['type' => 'lage'],
                    ['type' => 'kontakt'],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
                         ->get("/admin/properties/{$property->id}/expose/preview");

        $response->assertStatus(200);
        $response->assertSee('class="page cover-page"', false);
        $response->assertSee('class="page details-page"', false);
        $response->assertSee('class="page kontakt-page"', false);
    }
}
