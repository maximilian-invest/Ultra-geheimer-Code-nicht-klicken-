<?php

namespace App\Services;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use Illuminate\Support\Facades\Mail;

class IntakeProtocolEmailService
{
    public function sendProtocol(
        array $owner,
        array $property,
        array $broker,
        array $missingDocs,
        string $protocolPdfPath,
        ?string $vermittlungsauftragPdfPath = null,
    ): void {
        Mail::send(new IntakeProtocolMail(
            property: $property,
            owner: $owner,
            broker: $broker,
            missingDocs: $missingDocs,
            protocolPdfPath: $protocolPdfPath,
            vermittlungsauftragPdfPath: $vermittlungsauftragPdfPath,
        ));
    }

    public function sendPortalAccess(
        array $owner,
        string $loginEmail,
        string $initialPassword,
        array $broker,
        ?string $loginUrl = null,
    ): void {
        $loginUrl = $loginUrl ?: config('app.url') . '/login';
        Mail::send(new PortalAccessMail(
            owner: $owner,
            loginEmail: $loginEmail,
            initialPassword: $initialPassword,
            loginUrl: $loginUrl,
            broker: $broker,
        ));
    }
}
