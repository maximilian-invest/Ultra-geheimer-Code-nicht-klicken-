<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyApiKey;
use App\Models\User;
use App\Models\IntakeProtocolDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeProtocolDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyApiKey::class);
    }

    public function test_draft_save_creates_new_row_and_returns_draft_id(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        $response = $this->postJson('/api/admin_api.php?action=intake_protocol_draft_save', [
            'draft_key' => 'test-uuid-1111',
            'form_data' => ['object_type' => 'Wohnung', 'address' => 'Teststraße'],
            'current_step' => 2,
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonStructure(['draft_id', 'last_saved_at']);

        $this->assertDatabaseHas('intake_protocol_drafts', [
            'broker_id' => $user->id,
            'draft_key' => 'test-uuid-1111',
            'current_step' => 2,
        ]);
    }

    public function test_draft_save_updates_existing_row_on_same_key(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        IntakeProtocolDraft::create([
            'broker_id' => $user->id,
            'draft_key' => 'abc',
            'form_data' => json_encode(['step1' => 'initial']),
            'current_step' => 1,
        ]);

        $this->postJson('/api/admin_api.php?action=intake_protocol_draft_save', [
            'draft_key' => 'abc',
            'form_data' => ['step1' => 'updated'],
            'current_step' => 3,
        ])->assertStatus(200);

        $this->assertEquals(1, IntakeProtocolDraft::where('broker_id', $user->id)
            ->where('draft_key', 'abc')->count());

        $draft = IntakeProtocolDraft::where('draft_key', 'abc')->first();
        $this->assertEquals(3, $draft->current_step);
        $this->assertStringContainsString('updated', $draft->form_data);
    }

    public function test_draft_load_returns_most_recent_draft_for_user(): void
    {
        $user = User::factory()->create(['user_type' => 'makler']);
        $this->actingAs($user);

        IntakeProtocolDraft::create([
            'broker_id' => $user->id,
            'draft_key' => 'xyz',
            'form_data' => json_encode(['loaded' => true]),
            'current_step' => 5,
        ]);

        $response = $this->getJson('/api/admin_api.php?action=intake_protocol_draft_load&draft_key=xyz');
        $response->assertStatus(200)
                 ->assertJsonPath('form_data.loaded', true)
                 ->assertJsonPath('current_step', 5);
    }
}
