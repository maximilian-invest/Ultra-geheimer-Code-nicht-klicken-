<?php

namespace Tests\Unit\Services;

use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\User;
use App\Services\AnthropicService;
use App\Services\PropertyManagerContactService;
use Tests\TestCase;

class PropertyManagerContactServiceTest extends TestCase
{
    public function test_freitext_returns_empty(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $prop->id = 1;
        $prop->ref_id = 'REF-1';
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $out = $svc->buildDraft($prop, $mgr, 'freitext', null, $user);

        $this->assertSame('', $out['subject']);
        $this->assertSame('', $out['body']);
        $this->assertEmpty($out['attachments']);
        $this->assertFalse($out['ava_missing']);
    }

    public function test_unterlagen_body_contains_required_items(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['address' => 'Teststraße 1', 'city' => 'Salzburg', 'zip' => '5020']);
        $prop->id = 1;
        $prop->ref_id = 'REF-1';
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $out = $svc->buildDraft($prop, $mgr, 'unterlagen', null, $user);

        $this->assertStringContainsString('Betriebskostenabrechnung', $out['body']);
        $this->assertStringContainsString('Nutzwertgutachten', $out['body']);
        $this->assertStringContainsString('Energieausweis', $out['body']);
        $this->assertStringContainsString('Rücklagenstand', $out['body']);
        $this->assertStringContainsString('Alleinvermittlungsauftrag', $out['body']);
        $this->assertStringContainsString('REF-1', $out['subject']);
    }

    public function test_mieter_meldung_requires_source_email(): void
    {
        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $prop->id = 1;
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);

        $this->expectException(\InvalidArgumentException::class);
        $svc->buildDraft($prop, $mgr, 'mieter_meldung', null, $user);
    }

    public function test_mieter_meldung_uses_ai_summary_with_fallback(): void
    {
        $mock = $this->createMock(AnthropicService::class);
        $mock->method('chatJson')->willReturn(null);
        $this->app->instance(AnthropicService::class, $mock);

        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $prop->id = 1;
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);
        $email = new PortalEmail(['subject' => 'Heizung kaputt', 'body_text' => 'Hallo, unsere Heizung heizt nicht mehr']);

        $out = $svc->buildDraft($prop, $mgr, 'mieter_meldung', $email, $user);

        $this->assertNotEmpty($out['body']);
        $this->assertStringContainsString('Mieter', $out['body']);
        $this->assertStringContainsString('Heizung kaputt', $out['subject']);
    }

    public function test_mieter_meldung_uses_ai_when_ai_returns_json(): void
    {
        $mock = $this->createMock(AnthropicService::class);
        $mock->method('chatJson')->willReturn([
            'issue' => 'Die Heizung im Wohnzimmer ist seit gestern ausgefallen.',
            'schlagwort' => 'Heizungsstörung',
        ]);
        $this->app->instance(AnthropicService::class, $mock);

        $svc = app(PropertyManagerContactService::class);

        $prop = new Property(['address' => 'Teststraße 1', 'city' => 'Salzburg']);
        $prop->id = 1;
        $mgr = new PropertyManager(['company_name' => 'HV', 'email' => 'hv@x.at']);
        $user = new User(['name' => 'Max']);
        $email = new PortalEmail(['subject' => 'Heizung', 'body_text' => 'Bla']);

        $out = $svc->buildDraft($prop, $mgr, 'mieter_meldung', $email, $user);

        $this->assertStringContainsString('Die Heizung im Wohnzimmer ist seit gestern ausgefallen', $out['body']);
        $this->assertStringContainsString('Heizungsstörung', $out['subject']);
    }
}
