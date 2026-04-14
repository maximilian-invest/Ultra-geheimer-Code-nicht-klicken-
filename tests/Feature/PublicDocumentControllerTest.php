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
}
