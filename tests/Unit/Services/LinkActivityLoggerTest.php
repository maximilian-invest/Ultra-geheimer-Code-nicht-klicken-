<?php

namespace Tests\Unit\Services;

use App\Models\Activity;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use App\Services\LinkActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_link_opened_creates_activity_and_upserts_on_second_call(): void
    {
        $link = PropertyLink::factory()->create(['name' => 'Erstanfrage']);
        $session = PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'email' => 'lisa@example.com',
        ]);

        $logger = new LinkActivityLogger();
        $logger->recordLinkOpened($session);

        $this->assertDatabaseCount('activities', 1);
        $activity = Activity::first();
        $this->assertSame($link->property_id, $activity->property_id);
        $this->assertSame('lisa@example.com', $activity->stakeholder);
        $this->assertSame('link_opened', $activity->category);
        $this->assertStringContainsString('Erstanfrage', $activity->activity);
        $this->assertSame($session->id, $activity->link_session_id);

        // Second call with same session → still exactly 1 activity (upserted)
        $logger->recordLinkOpened($session);
        $this->assertDatabaseCount('activities', 1);
    }
}
