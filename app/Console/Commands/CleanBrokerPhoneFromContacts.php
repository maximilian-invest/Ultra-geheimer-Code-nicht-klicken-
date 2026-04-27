<?php

namespace App\Console\Commands;

use App\Services\PhoneExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bereinigt Kontakte, bei denen faelschlich die SR-Homes-Maklernummer als
 * Telefonnummer gespeichert ist (passiert frueher beim Parsen von Mail-Bodies,
 * wenn die Signatur des Maklers extrahiert wurde statt der Interessenten-Nummer).
 *
 * Vorgehen pro betroffenem Kontakt:
 *   1. Earliest inbound mail aus portal_emails finden (matched ueber email-Adresse).
 *   2. Phone via PhoneExtractor neu extrahieren — der filtert die SR-Nummer raus.
 *   3. Wenn echte Nummer gefunden: setzen. Sonst: NULL.
 *
 * Mit --dry-run: nur anzeigen, nichts schreiben.
 */
class CleanBrokerPhoneFromContacts extends Command
{
    protected $signature = 'contacts:clean-broker-phone {--dry-run : Nur Aenderungen anzeigen, nichts in die DB schreiben}';
    protected $description = 'Entfernt die SR-Homes-Maklernummer aus contacts.phone und versucht die echte Nummer aus der Erstanfrage zu rekonstruieren.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN: keine DB-Aenderungen werden geschrieben.');
        }

        // Alle Kontakte finden, deren phone die Maklernummer enthaelt — in
        // beliebiger Formatierung. Wir holen alle Kontakte mit phone IS NOT NULL
        // und filtern in PHP via PhoneExtractor::isOwnNumber, damit wirklich alle
        // verstuemmelten Varianten erwischt werden.
        $candidates = DB::table('contacts')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('id', 'full_name', 'email', 'phone')
            ->get();

        $affected = $candidates->filter(fn($c) => PhoneExtractor::isOwnNumber($c->phone))->values();

        $this->info(sprintf('Gefunden: %d Kontakte mit SR-Homes-Nummer als phone.', $affected->count()));

        if ($affected->isEmpty()) {
            return self::SUCCESS;
        }

        $cleared      = 0;  // phone -> NULL (keine echte Nummer rekonstruierbar)
        $reconstructed = 0; // phone -> echte Nummer aus Erstanfrage
        $skipped      = 0;

        foreach ($affected as $c) {
            $newPhone = $this->reconstructPhone($c);

            $this->line(sprintf(
                '  [%d] %s <%s>  alt: "%s"  neu: %s',
                $c->id,
                str_pad(mb_substr((string)$c->full_name, 0, 30), 30),
                str_pad(mb_substr((string)$c->email, 0, 35), 35),
                $c->phone,
                $newPhone === null ? '<NULL>' : '"' . $newPhone . '"'
            ));

            if (!$dryRun) {
                DB::table('contacts')->where('id', $c->id)->update([
                    'phone'      => $newPhone,
                    'updated_at' => now(),
                ]);
            }

            if ($newPhone === null) {
                $cleared++;
            } else {
                $reconstructed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d rekonstruiert, %d auf NULL gesetzt, %d uebersprungen.',
            $dryRun ? 'DRY-RUN Ergebnis' : 'Cleanup abgeschlossen',
            $reconstructed,
            $cleared,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * Versucht aus den portal_emails der Person die echte Telefonnummer zu
     * rekonstruieren. Geht in Reihenfolge:
     *   1. Erstanfrage nach E-Mail-Adresse (direction=inbound, sortiert nach Datum)
     *   2. Alle inbound Mails von der Person (falls Erstanfrage keinen phone-text hat)
     *   3. NULL — wenn nichts Plausibles gefunden
     */
    private function reconstructPhone(object $contact): ?string
    {
        $email = trim((string) ($contact->email ?? ''));

        $bodies = collect();

        // (1) Inbound-Mails ueber die Email-Adresse holen, aelteste zuerst.
        if ($email !== '') {
            $bodies = DB::table('portal_emails')
                ->where('direction', 'inbound')
                ->whereRaw('LOWER(from_email) = ?', [strtolower($email)])
                ->whereNotNull('body_text')
                ->where('body_text', '!=', '')
                ->orderBy('email_date', 'asc')
                ->limit(10)
                ->pluck('body_text');
        }

        // (2) Fallback ueber stakeholder-Namen, falls keine E-Mail-Treffer.
        if ($bodies->isEmpty() && trim((string) ($contact->full_name ?? '')) !== '') {
            $needle = '%' . mb_substr(trim((string) $contact->full_name), 0, 25) . '%';
            $bodies = DB::table('portal_emails')
                ->where('direction', 'inbound')
                ->where('stakeholder', 'like', $needle)
                ->whereNotNull('body_text')
                ->where('body_text', '!=', '')
                ->orderBy('email_date', 'asc')
                ->limit(10)
                ->pluck('body_text');
        }

        foreach ($bodies as $body) {
            $extracted = PhoneExtractor::extractFromText((string) $body);
            if ($extracted !== null) {
                return $extracted;
            }
        }

        return null;
    }
}
