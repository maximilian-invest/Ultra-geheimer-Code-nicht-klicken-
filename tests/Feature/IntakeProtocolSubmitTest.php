<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyApiKey;
use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use App\Models\IntakeProtocol;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeProtocolSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyApiKey::class);
    }

    public function test_submit_creates_property_customer_protocol_activity_and_sends_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['user_type' => 'makler', 'email' => 'makler@test.at', 'name' => 'Makler']);
        $this->actingAs($user);

        $payload = [
            'form_data' => [
                'object_type' => 'Wohnung',
                'marketing_type' => 'kauf',
                'address' => 'Musterstraße', 'house_number' => '1',
                'zip' => '5020', 'city' => 'Salzburg',
                'living_area' => 80, 'rooms_amount' => 3,
                'construction_year' => 2010,
                'realty_condition' => 'gebraucht',
                'owner' => [
                    'name' => 'Hans Test',
                    'email' => 'hans@test.at',
                    'phone' => '+43 664 000',
                ],
                'portal_access_granted' => false,
                'documents_available' => ['grundbuchauszug' => 'available', 'energieausweis' => 'missing'],
                'approvals_status' => 'complete',
                'broker_notes' => 'Test-Notiz',
                'open_fields' => [],
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'Hans Test',
            'disclaimer_text' => 'Die im Aufnahmeprotokoll angegebenen Informationen stammen vom Eigentümer.',
        ];

        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_submit', $payload);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $property = Property::where('address', 'Musterstraße')->first();
        $this->assertNotNull($property);
        $this->assertEquals(80, $property->living_area);
        $this->assertEquals('kauf', $property->marketing_type);

        $this->assertDatabaseHas('customers', ['email' => 'hans@test.at']);

        $protocol = IntakeProtocol::where('property_id', $property->id)->first();
        $this->assertNotNull($protocol);
        $this->assertEquals('Hans Test', $protocol->signed_by_name);
        $this->assertTrue(str_starts_with($protocol->signature_png_path, 'intake-protocols/'));

        $this->assertDatabaseHas('activities', [
            'property_id' => $property->id,
            'category' => 'Aufnahmeprotokoll',
        ]);

        // Neue UX: Protokoll-Mail wird NICHT sofort versendet — der Makler triggert
        // sie spaeter ueber den MailComposer auf der Property-Detail-Seite.
        Mail::assertNotSent(IntakeProtocolMail::class);
        Mail::assertNotSent(PortalAccessMail::class);
        $this->assertNull(\App\Models\IntakeProtocol::latest()->first()->owner_email_sent_at);
    }

    public function test_submit_with_portal_access_grants_user_and_sends_portal_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $this->postJson('/api/admin_api.php?action=intake_protocol_submit', [
            'form_data' => [
                'object_type' => 'Haus', 'marketing_type' => 'kauf',
                'address' => 'Portalstr', 'house_number' => '2',
                'zip' => '5020', 'city' => 'Salzburg',
                'living_area' => 150, 'rooms_amount' => 5, 'construction_year' => 2000,
                'realty_condition' => 'gebraucht',
                'owner' => ['name' => 'P1', 'email' => 'portal@test.at'],
                'portal_access_granted' => true,
                'documents_available' => [],
                'approvals_status' => 'complete',
                'broker_notes' => '',
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'P1',
            'disclaimer_text' => 'D',
        ])->assertStatus(200);

        // user_type muss 'eigentuemer' sein — sonst findet checkPortalAccess
        // den User nicht und der Portal-Status fehlt auf der Property-Detailseite.
        $this->assertDatabaseHas('users', ['email' => 'portal@test.at', 'user_type' => 'eigentuemer']);
        // Property muss denormalisierte owner_* Felder fuer die OverviewTab-Anzeige erhalten.
        $this->assertDatabaseHas('properties', [
            'owner_name'  => 'P1',
            'owner_email' => 'portal@test.at',
        ]);
        Mail::assertSent(PortalAccessMail::class, fn($m) => $m->hasTo('portal@test.at'));
    }

    public function test_photos_in_submit_are_stored_as_property_files(): void
    {
        Storage::fake('public');
        Mail::fake();

        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_submit', [
            'form_data' => [
                'object_type' => 'Wohnung',
                'marketing_type' => 'kauf',
                'address' => 'Photostr', 'house_number' => '1',
                'zip' => '5020', 'city' => 'Salzburg',
                'owner' => ['name' => 'F', 'email' => 'f@test.at'],
                'portal_access_granted' => false,
                'documents_available' => [],
                'approvals_status' => 'complete',
                'broker_notes' => '',
                'photos' => [
                    ['dataUrl' => "data:image/png;base64,$pixel", 'filename' => 'exterior.png', 'category' => 'exterior'],
                    ['dataUrl' => "data:image/png;base64,$pixel", 'filename' => 'interior.png', 'category' => 'interior'],
                ],
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'F',
            'disclaimer_text' => 'D',
        ]);

        $response->assertStatus(200);
        $property = Property::latest()->first();
        $this->assertNotNull($property);

        $files = \DB::table('property_files')->where('property_id', $property->id)->get();
        $this->assertCount(2, $files);
        $this->assertTrue($files->contains('label', 'Außenansicht'));
        $this->assertTrue($files->contains('label', 'Innenraum'));
        $this->assertTrue($files->contains('filename', 'exterior.png'));
        $this->assertTrue($files->contains('filename', 'interior.png'));

        // Binary was written to the public disk
        foreach ($files as $file) {
            $this->assertTrue(Storage::disk('public')->exists($file->path));
        }
    }

    public function test_preview_mail_returns_default_content(): void
    {
        $user = \App\Models\User::factory()->create(['user_type' => 'makler', 'name' => 'TestMakler', 'email' => 'm@test.at']);
        $this->actingAs($user);

        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_preview_mail', [
            'form_data' => [
                'ref_id' => 'X-01',
                'address' => 'Teststr', 'house_number' => '1', 'zip' => '5020', 'city' => 'Salzburg',
                'owner' => ['name' => 'Hans', 'email' => 'hans@test.at'],
                'documents_available' => ['grundbuchauszug' => 'missing', 'energieausweis' => 'available'],
            ],
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('owner_email', 'hans@test.at');
        $this->assertStringContainsString('X-01', $response->json('subject'));
        $this->assertStringContainsString('Hans', $response->json('body'));
        $this->assertStringContainsString('Grundbuchauszug', $response->json('body'));
    }

    public function test_resend_email_with_custom_content_updates_owner_email_sent_at(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        // Zuerst: Submit eines Protokolls (schickt keine Mail mehr)
        $this->postJson('/api/admin_api.php?action=intake_protocol_submit', [
            'form_data' => [
                'object_type' => 'Wohnung', 'marketing_type' => 'kauf',
                'address' => 'Mailstr', 'house_number' => '1', 'zip' => '5020', 'city' => 'Salzburg',
                'owner' => ['name' => 'Eve Owner', 'email' => 'eve@test.at'],
                'portal_access_granted' => false,
                'documents_available' => [],
                'approvals_status' => 'complete',
                'broker_notes' => '',
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'Eve',
            'disclaimer_text' => 'D',
        ])->assertStatus(200);

        $protocol = IntakeProtocol::latest()->first();
        $this->assertNull($protocol->owner_email_sent_at);

        // Jetzt: Mail mit Custom-Content via MailComposer senden
        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_resend_email', [
            'protocol_id' => $protocol->id,
            'type' => 'protocol',
            'subject' => 'Mein custom Betreff',
            'body' => "Lieber Eve,\n\ndies ist ein editierter Text.\n\nLG",
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        Mail::assertSent(IntakeProtocolMail::class, function ($m) {
            return $m->hasTo('eve@test.at')
                && $m->customSubject === 'Mein custom Betreff'
                && str_contains($m->customBody, 'editierter Text');
        });

        $protocol->refresh();
        $this->assertNotNull($protocol->owner_email_sent_at);
    }

    public function test_preview_mail_accepts_protocol_id(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = User::factory()->create(['user_type' => 'makler', 'email' => 'm@test.at']);
        $this->actingAs($user);

        $this->postJson('/api/admin_api.php?action=intake_protocol_submit', [
            'form_data' => [
                'ref_id' => 'PID-42',
                'object_type' => 'Haus', 'marketing_type' => 'kauf',
                'address' => 'Zweitstr', 'house_number' => '7', 'zip' => '5020', 'city' => 'Salzburg',
                'owner' => ['name' => 'Bob Eigentuemer', 'email' => 'bob@test.at'],
                'documents_available' => ['energieausweis' => 'missing'],
                'approvals_status' => 'complete',
                'broker_notes' => '',
            ],
            'signature_data_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'signed_by_name' => 'Bob',
            'disclaimer_text' => 'D',
        ])->assertStatus(200);

        $protocolId = IntakeProtocol::latest()->first()->id;

        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_preview_mail', [
            'protocol_id' => $protocolId,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'owner_email' => 'bob@test.at']);
        $this->assertStringContainsString('PID-42', $response->json('subject'));
        $this->assertStringContainsString('Bob', $response->json('body'));
        $this->assertStringContainsString('Energieausweis', $response->json('body'));
        $this->assertNull($response->json('already_sent_at'));
    }
}
