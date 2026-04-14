<?php

namespace Tests\Feature;

use App\Console\Commands\PurgeOldLinkSessions;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOldLinkSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_sessions_older_than_90_days(): void
    {
        $link = PropertyLink::factory()->create();

        // Old session — should be deleted
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'last_seen_at' => now()->subDays(91),
            'first_seen_at' => now()->subDays(91),
            'created_at' => now()->subDays(91),
        ]);

        // Fresh session — should survive
        PropertyLinkSession::factory()->create([
            'property_link_id' => $link->id,
            'last_seen_at' => now()->subDays(10),
        ]);

        $this->artisan('links:purge-old-sessions')->assertSuccessful();

        $this->assertDatabaseCount('property_link_sessions', 1);
    }
}
