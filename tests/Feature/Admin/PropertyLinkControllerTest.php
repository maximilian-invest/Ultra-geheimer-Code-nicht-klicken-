<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    public function test_index_lists_property_links_for_admin(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        PropertyLink::factory()->count(3)->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/admin/properties/{$property->id}/links");

        $response->assertOk()
            ->assertJsonCount(3, 'links');
    }

    public function test_index_rejects_non_admin(): void
    {
        $user = User::factory()->create(['user_type' => 'eigentuemer', 'email_verified_at' => now()]);
        $property = Property::factory()->create();

        $response = $this->actingAs($user)
            ->getJson("/admin/properties/{$property->id}/links");

        $response->assertForbidden();
    }
}
