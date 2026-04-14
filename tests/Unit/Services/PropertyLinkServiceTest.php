<?php

namespace Tests\Unit\Services;

use App\Models\PropertyLink;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_unique_token_of_correct_length(): void
    {
        $svc = new PropertyLinkService();
        $token = $svc->generateUniqueToken();

        $this->assertSame(43, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);
    }

    public function test_generates_token_that_does_not_collide_with_existing(): void
    {
        PropertyLink::factory()->create(['token' => 'fixed-token-value-for-testing-collisions-XX']);

        $svc = new PropertyLinkService();
        $token = $svc->generateUniqueToken();

        $this->assertNotSame('fixed-token-value-for-testing-collisions-XX', $token);
    }
}
