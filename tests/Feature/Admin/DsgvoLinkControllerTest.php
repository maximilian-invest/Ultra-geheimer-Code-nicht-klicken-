<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DsgvoLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'email_verified_at' => now()]);
    }

    public function test_export_returns_all_sessions_for_email(): void
    {
        $admin = $this->admin();
        $link = PropertyLink::factory()->create(['created_by' => $admin->id]);

        PropertyLinkSession::factory()->count(3)->create([
            'property_link_id' => $link->id,
            'email' => 'target@example.com',
        ]);
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'other@example.com',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/dsgvo/links?email=target@example.com');

        $response->assertOk()
            ->assertJsonCount(3, 'sessions');
    }

    public function test_delete_removes_all_sessions_for_email(): void
    {
        $admin = $this->admin();
        $link = PropertyLink::factory()->create(['created_by' => $admin->id]);
        PropertyLinkSession::factory()->count(2)->create([
            'property_link_id' => $link->id,
            'email' => 'target@example.com',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/admin/dsgvo/links', ['email' => 'target@example.com'])
            ->assertOk();

        $this->assertDatabaseMissing('property_link_sessions', ['email' => 'target@example.com']);
    }
}
