<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use App\Services\AnthropicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerAutoInsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_draft_on_erstanfrage_appends_default_link_url(): void
    {
        $admin = User::factory()->create([
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ]);

        $property = Property::factory()->create();

        $defaultLink = PropertyLink::factory()->default()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        // Bind a fake AnthropicService that returns a deterministic draft.
        $this->app->instance(AnthropicService::class, new class extends AnthropicService {
            public function __construct() {}
            public function generateFollowupDraft(...$args): array
            {
                return [
                    'email_body'    => 'Sehr geehrte Damen und Herren, vielen Dank fuer Ihre Anfrage. Mit freundlichen Gruessen, SR-Homes',
                    'email_subject' => 'Ihre Anfrage',
                    'lead_phase'    => 'erstkontakt',
                    'call_script'   => null,
                ];
            }
        });

        // Seed a conversation with outbound_count = 0 (Erstantwort) and one inbound portal_email.
        $conversationId = \DB::table('conversations')->insertGetId([
            'contact_email'    => 'lisa@example.com',
            'stakeholder'      => 'Lisa Musterfrau',
            'property_id'      => $property->id,
            'status'           => 'offen',
            'outbound_count'   => 0,
            'inbound_count'    => 1,
            'first_contact_at' => now(),
            'last_inbound_at'  => now(),
            'last_activity_at' => now(),
            'is_read'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        \DB::table('portal_emails')->insert([
            'direction'   => 'inbound',
            'from_email'  => 'lisa@example.com',
            'from_name'   => 'Lisa Musterfrau',
            'to_email'    => 'office@sr-homes.at',
            'subject'     => 'Anfrage Erstkontakt',
            'body_text'   => 'Hallo, ich interessiere mich fuer das Objekt und haette gerne mehr Infos. Ist eine Besichtigung moeglich?',
            'email_date'  => now(),
            'property_id' => $property->id,
            'stakeholder' => 'Lisa Musterfrau',
            'category'    => 'anfrage',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin_api.php?action=conv_regenerate_draft', [
                'id' => $conversationId,
            ]);

        $response->assertOk();
        $body = $response->json('draft_body');
        $this->assertNotNull($body);
        $this->assertStringContainsString("/docs/{$defaultLink->token}", $body);
        $this->assertStringContainsString('Unterlagen', $body);
    }

    public function test_ai_draft_on_followup_does_not_append_link(): void
    {
        $admin = User::factory()->create([
            'user_type' => 'admin',
            'email_verified_at' => now(),
        ]);

        $property = Property::factory()->create();

        PropertyLink::factory()->default()->create([
            'property_id' => $property->id,
            'created_by' => $admin->id,
        ]);

        $this->app->instance(AnthropicService::class, new class extends AnthropicService {
            public function __construct() {}
            public function generateFollowupDraft(...$args): array
            {
                return [
                    'email_body'    => 'Kurzer Followup-Text ohne Link.',
                    'email_subject' => 'Nachfass',
                ];
            }
        });

        // Conversation with outbound_count > 0 = Nachfass, not Erstantwort.
        $conversationId = \DB::table('conversations')->insertGetId([
            'contact_email'    => 'max@example.com',
            'stakeholder'      => 'Max Mustermann',
            'property_id'      => $property->id,
            'status'           => 'nachfassen_1',
            'outbound_count'   => 1,
            'inbound_count'    => 1,
            'first_contact_at' => now()->subDays(5),
            'last_inbound_at'  => now()->subDays(5),
            'last_outbound_at' => now()->subDays(3),
            'last_activity_at' => now()->subDays(3),
            'is_read'          => true,
            'created_at'       => now()->subDays(5),
            'updated_at'       => now()->subDays(3),
        ]);

        \DB::table('portal_emails')->insert([
            [
                'direction'   => 'inbound',
                'from_email'  => 'max@example.com',
                'from_name'   => 'Max Mustermann',
                'to_email'    => 'office@sr-homes.at',
                'subject'     => 'Anfrage',
                'body_text'   => 'Hallo, Anfrage.',
                'email_date'  => now()->subDays(5),
                'property_id' => $property->id,
                'stakeholder' => 'Max Mustermann',
                'created_at'  => now()->subDays(5),
                'updated_at'  => now()->subDays(5),
            ],
            [
                'direction'   => 'outbound',
                'from_email'  => 'office@sr-homes.at',
                'from_name'   => 'SR Homes',
                'to_email'    => 'max@example.com',
                'subject'     => 'Re: Anfrage',
                'body_text'   => 'Unsere erste Antwort.',
                'email_date'  => now()->subDays(3),
                'property_id' => $property->id,
                'stakeholder' => 'Max Mustermann',
                'created_at'  => now()->subDays(3),
                'updated_at'  => now()->subDays(3),
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin_api.php?action=conv_regenerate_draft', [
                'id' => $conversationId,
            ]);

        $response->assertOk();
        $body = $response->json('draft_body');
        $this->assertStringNotContainsString('/docs/', $body);
    }
}
