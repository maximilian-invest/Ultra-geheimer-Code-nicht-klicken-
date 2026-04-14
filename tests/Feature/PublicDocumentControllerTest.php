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
}
