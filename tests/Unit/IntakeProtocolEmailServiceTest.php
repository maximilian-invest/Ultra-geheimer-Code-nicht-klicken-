<?php

namespace Tests\Unit;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use App\Services\IntakeProtocolEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class IntakeProtocolEmailServiceTest extends TestCase
{
    public function test_sends_protocol_mail_without_missing_docs(): void
    {
        Mail::fake();
        $service = app(IntakeProtocolEmailService::class);

        $tempPdf = storage_path('app/test-protocol.pdf');
        file_put_contents($tempPdf, '%PDF-1.4 fake');

        $service->sendProtocol(
            owner: ['name' => 'X', 'email' => 'x@test.at'],
            property: ['ref_id' => 'T1', 'address' => 'Teststr'],
            broker: ['name' => 'M', 'email' => 'm@test.at'],
            missingDocs: [],
            protocolPdfPath: $tempPdf,
        );

        Mail::assertSent(IntakeProtocolMail::class, fn($m) => $m->hasTo('x@test.at'));
        @unlink($tempPdf);
    }

    public function test_sends_portal_access_mail(): void
    {
        Mail::fake();
        $service = app(IntakeProtocolEmailService::class);

        $service->sendPortalAccess(
            owner: ['name' => 'Y', 'email' => 'y@test.at'],
            loginEmail: 'y@test.at',
            initialPassword: 'Xy9!abcde',
            broker: ['name' => 'M', 'email' => 'm@test.at'],
        );

        Mail::assertSent(PortalAccessMail::class, fn($m) => $m->hasTo('y@test.at'));
    }
}
