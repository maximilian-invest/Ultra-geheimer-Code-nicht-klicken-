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

    public function test_store_creates_link_with_documents_and_returns_url(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();

        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'Expose', 'filename' => 'expose.pdf', 'path' => 'p/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100000, 'sort_order' => 1, 'is_website_download' => 0],
            ['property_id' => $property->id, 'label' => 'Grundriss', 'filename' => 'gr.pdf', 'path' => 'p/gr.pdf', 'mime_type' => 'application/pdf', 'file_size' => 50000, 'sort_order' => 2, 'is_website_download' => 0],
        ]);
        $fileIds = \DB::table('property_files')->where('property_id', $property->id)->pluck('id')->all();

        $response = $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links", [
                'name' => 'Erstanfrage',
                'is_default' => true,
                'expires_at' => now()->addDays(14)->toIso8601String(),
                'file_ids' => $fileIds,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['link' => ['id', 'token', 'url', 'is_default', 'document_ids']]);

        $this->assertDatabaseCount('property_links', 1);
        $link = PropertyLink::first();
        $this->assertSame('Erstanfrage', $link->name);
        $this->assertTrue((bool) $link->is_default);
        $this->assertSame(43, strlen($link->token));

        $this->assertDatabaseCount('property_link_documents', 2);
    }

    public function test_store_enforces_single_default_per_property(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        PropertyLink::factory()->default()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);
        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'Expose', 'filename' => 'e.pdf', 'path' => 'e.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'sort_order' => 1, 'is_website_download' => 0],
        ]);
        $fileId = \DB::table('property_files')->where('property_id', $property->id)->value('id');

        $this->actingAs($admin)
            ->postJson("/admin/properties/{$property->id}/links", [
                'name' => 'Phase 2',
                'is_default' => true,
                'expires_at' => now()->addDays(7)->toIso8601String(),
                'file_ids' => [$fileId],
            ])->assertOk();

        $defaults = PropertyLink::where('property_id', $property->id)->where('is_default', true)->count();
        $this->assertSame(1, $defaults);
    }

    public function test_show_returns_link_with_sessions_and_events(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);
        $session = \App\Models\PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'bob@example.com',
        ]);
        \App\Models\PropertyLinkEvent::create([
            'session_id' => $session->id,
            'event_type' => 'link_opened',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/admin/properties/{$property->id}/links/{$link->id}");

        $response->assertOk()
            ->assertJsonPath('link.id', $link->id)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.email', 'bob@example.com')
            ->assertJsonPath('sessions.0.events.0.event_type', 'link_opened');
    }

    public function test_update_changes_name_expiry_and_documents(): void
    {
        $admin = $this->adminUser();
        $property = Property::factory()->create();
        $link = PropertyLink::factory()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
            'name' => 'Old',
        ]);
        \DB::table('property_files')->insert([
            ['property_id' => $property->id, 'label' => 'A', 'filename' => 'a.pdf', 'path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'sort_order' => 1, 'is_website_download' => 0],
            ['property_id' => $property->id, 'label' => 'B', 'filename' => 'b.pdf', 'path' => 'b.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'sort_order' => 2, 'is_website_download' => 0],
        ]);
        $fileIds = \DB::table('property_files')->where('property_id', $property->id)->pluck('id')->all();

        $response = $this->actingAs($admin)
            ->putJson("/admin/properties/{$property->id}/links/{$link->id}", [
                'name' => 'New Name',
                'expires_at' => now()->addDays(60)->toIso8601String(),
                'file_ids' => $fileIds,
                'is_default' => false,
            ]);

        $response->assertOk();
        $this->assertSame('New Name', $link->fresh()->name);
        $this->assertDatabaseCount('property_link_documents', 2);
    }
}
