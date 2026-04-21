<?php

namespace App\Services;

use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyManagerContactService
{
    public function __construct(
        private AnthropicService $anthropic,
    ) {}

    /**
     * Baut den HV-Kontakt-Entwurf fuer ein Template.
     * Returns: ['subject', 'body', 'attachments', 'ava_missing']
     */
    public function buildDraft(Property $property, PropertyManager $manager, string $templateKind, ?PortalEmail $sourceEmail, ?User $maklerUser): array
    {
        return match ($templateKind) {
            'unterlagen' => $this->buildUnterlagenDraft($property, $manager, $maklerUser),
            'mieter_meldung' => $this->buildMieterMeldungDraft($property, $manager, $sourceEmail, $maklerUser),
            'freitext' => $this->buildFreitextDraft($property, $manager, $maklerUser),
            default => throw new \InvalidArgumentException("Unknown template_kind: {$templateKind}"),
        };
    }

    private function buildUnterlagenDraft(Property $property, PropertyManager $manager, ?User $maklerUser): array
    {
        $address = trim(($property->address ?? '') . ' ' . ($property->zip ?? '') . ' ' . ($property->city ?? ''));
        $refId = $property->ref_id ?? '';
        $maklerName = $maklerUser?->name ?? 'Ihr SR-Homes Team';

        $body = "Sehr geehrte Damen und Herren,\n\n"
            . "ich bin mit dem Verkauf des Objekts" . ($address ? " {$address}" : '') . " beauftragt und darf\n"
            . "Sie in diesem Zusammenhang höflich um Zusendung folgender Unterlagen bitten:\n\n"
            . "- Aktuelle Betriebskostenabrechnung\n"
            . "- Nutzwertgutachten\n"
            . "- Pläne des Objekts\n"
            . "- Energieausweis\n"
            . "- Rücklagenstand\n"
            . "- Hausordnung\n"
            . "- Wohnungseigentumsvertrag\n"
            . "- Protokolle der letzten Eigentümerversammlungen\n\n"
            . "Im Anhang finden Sie den Alleinvermittlungsauftrag als Nachweis meiner\n"
            . "Beauftragung.\n\n"
            . "Vielen Dank im Voraus und mit freundlichen Grüßen\n"
            . $maklerName;

        $subject = trim("Verkauf " . ($refId ? $refId . ' ' : '') . ($address ?: '(Objekt)') . " – Bitte um Unterlagen");

        $ava = null;
        try {
            $ava = DB::table('property_files')
                ->where('property_id', $property->id)
                ->where('is_ava', 1)
                ->first();
        } catch (\Throwable $e) {
            // property_files.is_ava evtl. nicht in Test-DB vorhanden — dann als fehlend behandeln
            Log::debug('AVA lookup skipped: ' . $e->getMessage());
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'attachments' => $ava ? [(int) $ava->id] : [],
            'ava_missing' => !$ava,
        ];
    }

    private function buildMieterMeldungDraft(Property $property, PropertyManager $manager, ?PortalEmail $sourceEmail, ?User $maklerUser): array
    {
        if (!$sourceEmail) {
            throw new \InvalidArgumentException("mieter_meldung template requires source_email_id");
        }

        $address = trim(($property->address ?? '') . ' ' . ($property->zip ?? '') . ' ' . ($property->city ?? ''));
        $maklerName = $maklerUser?->name ?? 'Ihr SR-Homes Team';
        $origBody = trim(strip_tags((string) ($sourceEmail->body_text ?? '')));
        $origSubject = trim((string) ($sourceEmail->subject ?? ''));

        $summary = $this->summarizeIssueViaAi($origSubject, $origBody);

        $schlagwort = $summary['schlagwort'] ?? 'Mieter-Meldung';
        $issueText = $summary['issue'] ?? $origSubject;

        $body = "Sehr geehrte Damen und Herren,\n\n"
            . "wir haben heute von den Mietern" . ($address ? " der Wohnung {$address}" : '') . " folgende Meldung erhalten:\n\n"
            . $issueText . "\n\n"
            . "Wir bitten Sie, zeitnah mit den Mietern Kontakt aufzunehmen und sich der\n"
            . "Angelegenheit anzunehmen.\n\n"
            . "Mit freundlichen Grüßen\n"
            . $maklerName;

        $subject = trim($schlagwort . ' – Wohnung ' . ($address ?: ($property->ref_id ?? '')));

        return [
            'subject' => $subject,
            'body' => $body,
            'attachments' => [],
            'ava_missing' => false,
        ];
    }

    private function buildFreitextDraft(Property $property, PropertyManager $manager, ?User $maklerUser): array
    {
        return [
            'subject' => '',
            'body' => '',
            'attachments' => [],
            'ava_missing' => false,
        ];
    }

    private function summarizeIssueViaAi(string $subject, string $body): array
    {
        $systemPrompt = 'Du bekommst eine Mieter-Mail. Erkenne das konkrete Problem und fasse es in 1-2 sachlichen deutschen Saetzen zusammen. '
            . 'Erfinde keine Details die nicht drinstehen. '
            . 'Extrahiere zusaetzlich ein 1-2-Wort-Schlagwort fuer den Betreff einer Weiterleitung an die Hausverwaltung '
            . '(z.B. "Heizungsstörung", "Wasserschaden", "Lärmbelästigung"). '
            . 'Antworte NUR als JSON: {"issue":"...","schlagwort":"..."}';

        $userMessage = "Betreff: {$subject}\n\nText:\n" . mb_substr($body, 0, 2500);

        try {
            $result = $this->anthropic->chatJson($systemPrompt, $userMessage, 400);
            if (is_array($result) && isset($result['issue'], $result['schlagwort'])) {
                return [
                    'issue' => mb_substr((string) $result['issue'], 0, 400),
                    'schlagwort' => mb_substr((string) $result['schlagwort'], 0, 40),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('PropertyManagerContactService::summarizeIssueViaAi failed', ['error' => $e->getMessage()]);
        }

        return [
            'issue' => mb_substr($body, 0, 200) . (mb_strlen($body) > 200 ? '…' : ''),
            'schlagwort' => $subject ?: 'Mieter-Meldung',
        ];
    }
}
