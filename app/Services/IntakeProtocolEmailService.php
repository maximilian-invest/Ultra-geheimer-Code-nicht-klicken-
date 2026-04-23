<?php

namespace App\Services;

use App\Mail\IntakeProtocolMail;
use App\Mail\PortalAccessMail;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Mail\Mailable;

class IntakeProtocolEmailService
{
    /**
     * Versendet ein Mailable ueber den SMTP-Account des Brokers, falls dieser
     * einen aktiven email_accounts-Eintrag hat. Fallback: Default-Mailer aus .env.
     *
     * Der brokerId-Param wird uebergeben damit wir die richtige SMTP-Config
     * finden. Wenn der Broker keinen Account konfiguriert hat, fliegt der
     * Versand auf den Default-Mailer zurueck — der kann auch fehlschlagen, wird
     * aber vom aufrufenden Controller per try/catch abgefangen.
     */
    private function dispatchMailable(Mailable $mailable, ?int $brokerId = null): void
    {
        if ($brokerId) {
            $account = EmailAccount::where('user_id', $brokerId)
                ->where('is_active', 1)
                ->first();
            if ($account) {
                $this->sendViaAccount($account, $mailable);
                return;
            }
        }
        // Fallback: Default-Mailer (.env) — funktioniert nur wenn MAIL_USERNAME/PASSWORD gesetzt
        Mail::send($mailable);
    }

    /**
     * Sendet ein Mailable ueber einen konkreten EmailAccount via dynamischer
     * Laravel-Mailer-Config. Laravel's MailManager cached Mailer — daher
     * registrieren wir unter einem account-spezifischen Key und purgen vorher.
     */
    private function sendViaAccount(EmailAccount $account, Mailable $mailable): void
    {
        $mailerKey = 'broker_' . $account->id;
        $encryption = $account->smtp_port == 465 ? 'ssl' : ($account->smtp_encryption ?: 'tls');

        config([
            "mail.mailers.{$mailerKey}" => [
                'transport'   => 'smtp',
                'host'        => $account->smtp_host,
                'port'        => (int) $account->smtp_port,
                'encryption'  => $encryption === 'none' ? null : $encryption,
                'username'    => $account->smtp_username,
                'password'    => $account->smtp_password,
                'timeout'     => 30,
                'local_domain'=> env('MAIL_EHLO_DOMAIN'),
            ],
        ]);

        // Mailer neu initialisieren (wird sonst gecached)
        app('mail.manager')->purge($mailerKey);

        // Mailable bekommt den richtigen From-Header
        $mailable->from($account->email_address, $account->from_name ?: 'SR-Homes');

        Mail::mailer($mailerKey)->send($mailable);

        Log::info('IntakeProtocolEmailService: sent via broker account', [
            'account_id' => $account->id,
            'from'       => $account->email_address,
        ]);
    }

    public function sendProtocol(
        array $owner,
        array $property,
        array $broker,
        array $missingDocs,
        string $protocolPdfPath,
        ?string $vermittlungsauftragPdfPath = null,
        ?string $customSubject = null,
        ?string $customBody = null,
        ?int $brokerId = null,
    ): void {
        $mailable = new IntakeProtocolMail(
            property: $property,
            owner: $owner,
            broker: $broker,
            missingDocs: $missingDocs,
            protocolPdfPath: $protocolPdfPath,
            vermittlungsauftragPdfPath: $vermittlungsauftragPdfPath,
            customSubject: $customSubject,
            customBody: $customBody,
        );
        $this->dispatchMailable($mailable, $brokerId);
    }

    public function sendPortalAccess(
        array $owner,
        string $loginEmail,
        string $initialPassword,
        array $broker,
        ?string $loginUrl = null,
        ?int $brokerId = null,
    ): void {
        $loginUrl = $loginUrl ?: config('app.url') . '/login';
        $mailable = new PortalAccessMail(
            owner: $owner,
            loginEmail: $loginEmail,
            initialPassword: $initialPassword,
            loginUrl: $loginUrl,
            broker: $broker,
        );
        $this->dispatchMailable($mailable, $brokerId);
    }
}
