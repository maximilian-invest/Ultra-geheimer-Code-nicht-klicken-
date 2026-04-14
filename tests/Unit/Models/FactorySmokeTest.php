<?php

namespace Tests\Unit\Models;

use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_link_factory_creates_valid_row(): void
    {
        $link = PropertyLink::factory()->create();
        $this->assertNotNull($link->id);
        $this->assertNotNull($link->property_id);
        $this->assertNotNull($link->created_by);
        $this->assertSame(43, strlen($link->token));
    }

    public function test_property_link_session_factory_creates_valid_row(): void
    {
        $session = PropertyLinkSession::factory()->create();
        $this->assertNotNull($session->id);
        $this->assertNotNull($session->property_link_id);
    }

    public function test_revoked_state_sets_revoked_at(): void
    {
        $link = PropertyLink::factory()->revoked()->create();
        $this->assertNotNull($link->revoked_at);
        $this->assertNotNull($link->revoked_by);
    }

    public function test_expired_state_sets_past_expires_at(): void
    {
        $link = PropertyLink::factory()->expired()->create();
        $this->assertTrue($link->expires_at->isPast());
    }
}
