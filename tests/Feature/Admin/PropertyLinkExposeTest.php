<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PropertyLinkExposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_expose_version_id_and_persists_to_pivot(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [['type' => 'cover']]],
            'is_active' => true,
        ]);
        // Need at least one file_id to pass existing "min:1" validation on file_ids.
        $fileId = DB::table('property_files')->insertGetId([
            'property_id' => $property->id,
            'filename' => 'x.pdf', 'label' => 'X', 'mime_type' => 'application/pdf',
            'file_size' => 1, 'sort_order' => 0,
            'path' => 'p/x.pdf', 'is_website_download' => 0,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson(
            "/admin/properties/{$property->id}/links",
            [
                'name' => 'Mein Link',
                'file_ids' => [$fileId],
                'expose_version_id' => $version->id,
            ]
        );

        $response->assertStatus(200);

        $pivots = DB::table('property_link_documents')->get();
        $this->assertEquals(2, $pivots->count(), 'expect 1 file row + 1 expose row');
        $this->assertTrue($pivots->contains(fn($r) => $r->expose_version_id === $version->id));
    }

    public function test_store_rejects_expose_version_id_from_other_property(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $propertyA = Property::factory()->create();
        $propertyB = Property::factory()->create();

        // Version gehoert zu A
        $versionA = PropertyExposeVersion::create([
            'property_id' => $propertyA->id,
            'config_json' => ['pages' => []],
            'is_active'   => true,
        ]);

        $fileIdB = DB::table('property_files')->insertGetId([
            'property_id' => $propertyB->id,
            'filename' => 'x.pdf', 'label' => 'X', 'mime_type' => 'application/pdf',
            'file_size' => 1, 'sort_order' => 0,
            'path' => 'p/x.pdf', 'is_website_download' => 0,
            'created_at' => now(),
        ]);

        // Versuch, die Version von A an Link fuer B anzuhaengen → muss fehlschlagen
        $response = $this->actingAs($user)->postJson(
            "/admin/properties/{$propertyB->id}/links",
            [
                'name' => 'Wrong',
                'file_ids' => [$fileIdB],
                'expose_version_id' => $versionA->id,
            ]
        );

        $response->assertStatus(422);
    }

    public function test_show_response_includes_active_expose(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [['type' => 'cover'], ['type' => 'details']]],
            'is_active' => true,
            'name' => 'TestExpose',
        ]);
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'token' => 'tok' . bin2hex(random_bytes(4)),
        ]);

        $response = $this->actingAs($user)->getJson(
            "/admin/properties/{$property->id}/links/{$link->id}"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('activeExpose.version_id', $version->id);
        $response->assertJsonPath('activeExpose.page_count', 2);
    }
}
