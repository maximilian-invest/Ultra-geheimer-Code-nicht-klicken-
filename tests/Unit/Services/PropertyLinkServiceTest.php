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

    public function test_mark_as_default_unsets_other_defaults_on_same_property(): void
    {
        $p1 = Property::factory()->create();
        $p2 = Property::factory()->create();

        $linkA = PropertyLink::factory()->create(['property_id' => $p1->id, 'is_default' => true]);
        $linkB = PropertyLink::factory()->create(['property_id' => $p1->id, 'is_default' => false]);
        $linkC = PropertyLink::factory()->create(['property_id' => $p2->id, 'is_default' => true]);

        $svc = new PropertyLinkService();
        $svc->markAsDefault($linkB);

        $this->assertFalse($linkA->fresh()->is_default, 'linkA default should be unset');
        $this->assertTrue($linkB->fresh()->is_default, 'linkB should now be default');
        $this->assertTrue($linkC->fresh()->is_default, 'linkC on a DIFFERENT property stays default');
    }
}
