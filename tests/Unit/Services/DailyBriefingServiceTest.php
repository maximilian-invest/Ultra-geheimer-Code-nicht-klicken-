<?php

namespace Tests\Unit\Services;

use App\Services\AnthropicService;
use App\Services\DailyBriefingService;
use Tests\TestCase;

/**
 * Pure-Logic Tests für DailyBriefingService.
 *
 * DB-Integration-Tests sind bewusst ausgespart: die Test-SQLite
 * hat kein broker_id auf properties (Technical Debt — nur in Prod
 * via direct ALTER hinzugefügt). Broker-Scoping wird stattdessen
 * über Code-Review + Manual-Smoke auf Production validiert.
 */
class DailyBriefingServiceTest extends TestCase
{
    private function emptyContext(): array
    {
        return [
            'date' => '2026-04-21',
            'broker_name' => 'Max',
            'activities_24h' => [],
            'active_threads' => [],
            'tasks_due' => [],
            'viewings_today' => [],
            'property_signals' => [],
            'nachfass_outcome' => ['sent' => 0, 'replied' => 0],
        ];
    }

    // ===== fallbackTemplate =====

    public function test_fallbackTemplate_produces_valid_structure(): void
    {
        $service = app(DailyBriefingService::class);

        $context = $this->emptyContext();
        $context['activities_24h'] = [
            ['activity' => 'Kaufanbot erhalten', 'category' => 'kaufanbot'],
            ['activity' => 'Anfrage', 'category' => 'anfrage'],
            ['activity' => 'Anfrage', 'category' => 'anfrage'],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertArrayHasKey('preview', $out);
        $this->assertArrayHasKey('narrative', $out);
        $this->assertArrayHasKey('anomalies', $out);
        $this->assertArrayHasKey('thread_annotations', $out);
        $this->assertIsString($out['preview']);
        $this->assertLessThanOrEqual(181, mb_strlen($out['preview']));
    }

    public function test_fallbackTemplate_quiet_day_returns_ruhiger_tag(): void
    {
        $service = app(DailyBriefingService::class);
        $out = $service->fallbackTemplate($this->emptyContext());

        $this->assertStringContainsString('Ruhiger Tag', $out['narrative']);
        $this->assertEmpty($out['anomalies']);
    }

    public function test_fallbackTemplate_builds_category_summary(): void
    {
        $service = app(DailyBriefingService::class);
        $context = $this->emptyContext();
        $context['activities_24h'] = [
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'kaufanbot'],
            ['activity' => 'X', 'category' => 'email-out'],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertStringContainsString('3 neue Anfragen', $out['narrative']);
        $this->assertStringContainsString('1 Kaufanbote', $out['narrative']);
        $this->assertStringContainsString('1 verschickte E-Mails', $out['narrative']);
    }

    public function test_fallbackTemplate_converts_property_signals_to_anomalies(): void
    {
        $service = app(DailyBriefingService::class);
        $context = $this->emptyContext();
        $context['activities_24h'] = [
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
        ];
        $context['property_signals'] = [
            ['kind' => 'hot', 'property_ref' => 'KAU-74', 'property_id' => 1, 'sessions_24h' => 23],
            ['kind' => 'cool', 'property_ref' => 'WO-1', 'property_id' => 2, 'recent_inquiries' => 2, 'previous_inquiries' => 15],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertCount(2, $out['anomalies']);
        $this->assertSame('hot', $out['anomalies'][0]['kind']);
        $this->assertStringContainsString('KAU-74', $out['anomalies'][0]['text']);
        $this->assertSame('cool', $out['anomalies'][1]['kind']);
    }

    public function test_fallbackTemplate_caps_anomalies_at_three(): void
    {
        $service = app(DailyBriefingService::class);
        $context = $this->emptyContext();
        $context['activities_24h'] = [
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
            ['activity' => 'X', 'category' => 'anfrage'],
        ];
        $context['property_signals'] = [
            ['kind' => 'hot', 'property_ref' => 'A', 'sessions_24h' => 10],
            ['kind' => 'hot', 'property_ref' => 'B', 'sessions_24h' => 10],
            ['kind' => 'hot', 'property_ref' => 'C', 'sessions_24h' => 10],
            ['kind' => 'hot', 'property_ref' => 'D', 'sessions_24h' => 10],
            ['kind' => 'hot', 'property_ref' => 'E', 'sessions_24h' => 10],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertCount(3, $out['anomalies']);
    }

    public function test_fallbackTemplate_annotates_waiting_threads(): void
    {
        $service = app(DailyBriefingService::class);
        $context = $this->emptyContext();
        $context['active_threads'] = [
            ['id' => 1, 'days_waiting' => 5, 'stakeholder' => 'Kunde1'],
            ['id' => 2, 'days_waiting' => 1, 'stakeholder' => 'Kunde2'],
            ['id' => 3, 'days_waiting' => 0, 'stakeholder' => 'Kunde3'],
        ];

        $out = $service->fallbackTemplate($context);

        $this->assertSame('red', $out['thread_annotations']['1']['priority']);
        $this->assertStringContainsString('wartet 5 Tage', $out['thread_annotations']['1']['label']);
        $this->assertSame('orange', $out['thread_annotations']['2']['priority']);
        $this->assertSame('green', $out['thread_annotations']['3']['priority']);
    }

    // ===== callAi =====

    public function test_callAi_returns_null_on_empty_context(): void
    {
        $service = app(DailyBriefingService::class);
        $this->assertNull($service->callAi([]));
    }

    public function test_callAi_validates_response_structure(): void
    {
        $anthropicMock = $this->createMock(AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn([
            'preview' => 'Test Preview',
            'narrative' => 'Test Narrative',
            'anomalies' => [],
            'thread_annotations' => [],
        ]);
        $this->app->instance(AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi($this->emptyContext());

        $this->assertSame('Test Preview', $out['preview']);
        $this->assertSame('Test Narrative', $out['narrative']);
    }

    public function test_callAi_returns_null_when_anthropic_fails(): void
    {
        $anthropicMock = $this->createMock(AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn(null);
        $this->app->instance(AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi($this->emptyContext());

        $this->assertNull($out);
    }

    public function test_callAi_returns_null_on_missing_required_fields(): void
    {
        $anthropicMock = $this->createMock(AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn(['only_preview' => 'x']);
        $this->app->instance(AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi($this->emptyContext());

        $this->assertNull($out);
    }

    public function test_callAi_truncates_too_long_preview(): void
    {
        $anthropicMock = $this->createMock(AnthropicService::class);
        $anthropicMock->method('chatJson')->willReturn([
            'preview' => str_repeat('x', 500),
            'narrative' => 'Test',
        ]);
        $this->app->instance(AnthropicService::class, $anthropicMock);

        $service = app(DailyBriefingService::class);
        $out = $service->callAi($this->emptyContext());

        $this->assertLessThanOrEqual(180, mb_strlen($out['preview']));
    }
}
