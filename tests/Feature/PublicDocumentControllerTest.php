<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function makeLink(array $overrides = []): PropertyLink
    {
        return PropertyLink::factory()->create($overrides);
    }

    public function test_show_renders_email_gate_for_valid_token_without_cookie(): void
    {
        $link = $this->makeLink();

        $response = $this->get("/docs/{$link->token}");

        $response->assertOk()
            ->assertSee('Unterlagen ansehen')
            ->assertSee('Ich stimme zu', false);
    }

    public function test_show_returns_410_for_expired_token(): void
    {
        $link = $this->makeLink(['expires_at' => now()->subDay()]);

        $response = $this->get("/docs/{$link->token}");

        $response->assertStatus(410);
    }

    public function test_show_returns_410_for_revoked_token(): void
    {
        $link = $this->makeLink(['revoked_at' => now()]);

        $response = $this->get("/docs/{$link->token}");

        $response->assertStatus(410);
    }

    public function test_show_returns_404_for_unknown_token(): void
    {
        $response = $this->get("/docs/unknown-token-does-not-exist-in-db-for-sure-42");

        $response->assertStatus(404);
    }

    public function test_unlock_creates_session_and_sets_cookie_with_dsgvo(): void
    {
        $link = $this->makeLink();

        $response = $this->post("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
            'dsgvo' => '1',
        ]);

        $response->assertRedirect("/docs/{$link->token}");
        $this->assertDatabaseCount('property_link_sessions', 1);

        $session = \App\Models\PropertyLinkSession::first();
        $this->assertSame('lisa@example.com', $session->email);
        $this->assertNotNull($session->dsgvo_accepted_at);
        $this->assertSame(64, strlen($session->ip_hash));

        // Cookie set
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $response->assertCookie($cookieName);

        // Activity written
        $this->assertDatabaseCount('activities', 1);
        $this->assertDatabaseHas('activities', [
            'stakeholder' => 'lisa@example.com',
            'category' => 'link_opened',
            'link_session_id' => $session->id,
        ]);
    }

    public function test_unlock_rejects_missing_dsgvo(): void
    {
        $link = $this->makeLink();

        $response = $this->postJson("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('property_link_sessions', 0);
    }

    public function test_unlock_rate_limits_after_10_attempts(): void
    {
        $link = $this->makeLink();

        for ($i = 0; $i < 10; $i++) {
            $this->post("/docs/{$link->token}/unlock", [
                'email' => "spam{$i}@example.com",
                'dsgvo' => '1',
            ]);
        }

        $response = $this->post("/docs/{$link->token}/unlock", [
            'email' => 'lisa@example.com',
            'dsgvo' => '1',
        ]);

        $response->assertStatus(429);
    }

    public function test_unlock_reuses_session_for_same_email_within_24h(): void
    {
        $link = $this->makeLink();

        $this->post("/docs/{$link->token}/unlock", ['email' => 'a@a.com', 'dsgvo' => '1']);
        $this->post("/docs/{$link->token}/unlock", ['email' => 'a@a.com', 'dsgvo' => '1']);

        $this->assertDatabaseCount('property_link_sessions', 1);
    }

    public function test_file_requires_valid_session_cookie(): void
    {
        $link = $this->makeLink();
        $fileId = \DB::table('property_files')->insertGetId([
            'property_id' => $link->property_id, 'label' => 'Expose', 'filename' => 'expose.pdf',
            'path' => 'test/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100,
            'sort_order' => 1, 'is_website_download' => 0, 'created_at' => now(),
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id' => $link->id, 'property_file_id' => $fileId, 'sort_order' => 0, 'created_at' => now(),
        ]);

        // No cookie → rejected
        $response = $this->get("/docs/{$link->token}/file/{$fileId}/view");
        $response->assertStatus(403);
    }

    public function test_file_downloads_pdf_when_session_valid(): void
    {
        $link = $this->makeLink();
        \Storage::fake('local');
        \Storage::put('test/expose.pdf', 'fake pdf content');

        $fileId = \DB::table('property_files')->insertGetId([
            'property_id' => $link->property_id, 'label' => 'Expose', 'filename' => 'expose.pdf',
            'path' => 'test/expose.pdf', 'mime_type' => 'application/pdf', 'file_size' => 16,
            'sort_order' => 1, 'is_website_download' => 0, 'created_at' => now(),
        ]);
        \DB::table('property_link_documents')->insert([
            'property_link_id' => $link->id, 'property_file_id' => $fileId, 'sort_order' => 0, 'created_at' => now(),
        ]);

        $session = \App\Models\PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'lisa@example.com',
        ]);
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $session->id . '.' . hash_hmac('sha256', (string) $session->id, config('app.key'));

        $response = $this->withCookie($cookieName, $cookieValue)
            ->get("/docs/{$link->token}/file/{$fileId}/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition');
    }
}
