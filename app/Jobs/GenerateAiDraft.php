<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\AnthropicService;
use App\Services\ConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAiDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        protected int $conversationId
    ) {}

    public function handle(): void
    {
        $conv = Conversation::find($this->conversationId);
        if (!$conv) return;

        // Skip if draft already exists or conversation is erledigt/archiviert
        if ($conv->draft_body) return;
        if (in_array($conv->status, ['erledigt', 'archiviert'])) return;

        // Skip if no property (can't generate meaningful draft without property context)
        if (!$conv->property_id) return;

        // Skip internal emails
        if ($conv->category === 'intern') return;

        $stakeholder = $conv->stakeholder;
        $propertyId = $conv->property_id;
        $today = date('Y-m-d');

        // Build thread context from portal_emails
        $thread = DB::select("
            SELECT pe.email_date as activity_date, pe.direction, pe.category, pe.subject,
                   SUBSTRING(pe.body_text, 1, 2000) as body_snippet, pe.from_name
            FROM portal_emails pe
            WHERE pe.property_id = ?
              AND (LOWER(pe.from_email) = LOWER(?) OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%') OR pe.stakeholder = ?)
            ORDER BY pe.email_date ASC
        ", [$propertyId, $conv->contact_email, $conv->contact_email, $stakeholder]);

        if (empty($thread)) return;

        $threadContext = '';
        $lastOutboundDate = '';
        $lastInboundText = '';
        $lastInboundDate = '';
        $hasUnansweredQuestion = false;

        foreach ($thread as $msg) {
            $msg = (array) $msg;
            $isIn = strtolower($msg['direction'] ?? '') === 'inbound';
            $dir = $isIn ? 'KUNDE' : 'SR-HOMES';
            $cat = $msg['category'] ?? 'sonstiges';
            $summary = $msg['subject'] ?? '';
            if (!empty($msg['body_snippet'])) {
                $body = trim(preg_replace('/\s+/', ' ', strip_tags($msg['body_snippet'])));
                if (strlen($body) > 20) $summary .= ' — ' . mb_substr($body, 0, 200);
            }
            $threadContext .= "[{$msg['activity_date']}] {$dir} ({$cat}): {$summary}\n";
            if ($isIn) {
                $lastInboundText = $summary;
                $lastInboundDate = $msg['activity_date'];
            } else {
                $lastOutboundDate = $msg['activity_date'];
            }
        }

        if ($lastInboundDate && str_contains($lastInboundText, '?') && ($lastOutboundDate < $lastInboundDate || !$lastOutboundDate)) {
            $hasUnansweredQuestion = true;
        }

        $lastRow = (array) end($thread);
        $daysSinceLastContact = (int) floor((time() - strtotime($lastRow['activity_date'])) / 86400);

        // Last sent/received emails
        $lastOutEmail = DB::selectOne("
            SELECT body_text, subject, email_date FROM portal_emails
            WHERE property_id = ? AND direction = 'outbound'
              AND (LOWER(to_email) = LOWER(?) OR stakeholder = ?)
            ORDER BY email_date DESC LIMIT 1
        ", [$propertyId, $conv->contact_email, $stakeholder]);

        $lastInEmail = DB::selectOne("
            SELECT body_text, subject, from_name, email_date FROM portal_emails
            WHERE property_id = ? AND direction = 'inbound'
              AND (LOWER(from_email) = LOWER(?) OR stakeholder = ?)
            ORDER BY email_date DESC LIMIT 1
        ", [$propertyId, $conv->contact_email, $stakeholder]);

        if ($lastOutEmail && $lastOutEmail->body_text) {
            $outBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastOutEmail->body_text))), 0, 1500);
            $threadContext .= "\n--- LETZTE GESENDETE NACHRICHT ({$lastOutEmail->email_date}) ---\nBetreff: {$lastOutEmail->subject}\n{$outBody}\n--- ENDE ---\n";
        }
        if ($lastInEmail && $lastInEmail->body_text) {
            $inBody = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($lastInEmail->body_text))), 0, 1000);
            $threadContext .= "\n--- LETZTE NACHRICHT VOM KUNDEN ({$lastInEmail->email_date}) ---\nVon: {$lastInEmail->from_name}\nBetreff: {$lastInEmail->subject}\n{$inBody}\n--- ENDE ---\n";
        }

        // Knowledge base
        $kbContext = '';
        $kbItems = DB::select("
            SELECT category, title, content FROM property_knowledge
            WHERE property_id = ? AND is_active = 1
              AND category NOT IN ('feedback_besichtigung', 'feedback_negativ', 'feedback_positiv')
            ORDER BY CASE WHEN category IN ('verhandlung','vermarktung') THEN 0 ELSE 1 END, confidence DESC
            LIMIT 15
        ", [$propertyId]);

        if (!empty($kbItems)) {
            $kbContext = "OBJEKTWISSEN:\n";
            $chars = 0;
            foreach ($kbItems as $k) {
                $k = (array) $k;
                $line = "- {$k['title']}: {$k['content']}\n";
                if ($chars + strlen($line) > 2500) break;
                $kbContext .= $line;
                $chars += strlen($line);
            }
        }

        // Unit availability
        $freieUnits = DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->where('status', 'frei')->orderByRaw('CAST(REPLACE(unit_number, "TOP ", "") AS UNSIGNED)')->get(['unit_number','unit_type','area_m2','rooms','price']);
        $verkaufteNummern = DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->where('status', 'verkauft')->pluck('unit_number')->toArray();

        if ($freieUnits->count() + count($verkaufteNummern) > 0) {
            $kbContext .= "\n--- EINHEITEN + PREISE (LIVE-DATEN) ---\nVERFUEGBAR:\n";
            foreach ($freieUnits as $u) {
                $kbContext .= $u->unit_number . ': ' . $u->rooms . '-Zi, ' . $u->area_m2 . 'm2, ' . number_format($u->price, 0, ',', '.') . " EUR\n";
            }
            if (!empty($verkaufteNummern)) {
                $kbContext .= "VERKAUFT (NICHT anbieten!): " . implode(', ', $verkaufteNummern) . "\n";
            }
            $kbContext .= "--- ENDE ---\n";
        }

        // Viewing status
        $hasViewing = DB::selectOne("SELECT COUNT(*) as cnt FROM activities WHERE property_id = ? AND category = 'besichtigung' AND stakeholder LIKE ?", [$propertyId, '%' . mb_substr($stakeholder, 0, 20) . '%']);
        if (($hasViewing->cnt ?? 0) === 0) {
            $threadContext .= "\n--- ABSOLUTES VERBOT ---\nEs hat KEINE Besichtigung mit diesem Interessenten stattgefunden.\nDu darfst die Woerter Besichtigung, Besichtigungstermin, Begehung, vor Ort angesehen, Eindruck vom Haus NICHT verwenden.\n--- ENDE VERBOT ---\n";
        }

        // First response hint
        if (($conv->outbound_count ?? 0) === 0) {
            $threadContext .= "\n--- ERSTANTWORT ---\nDies ist eine NEUE Anfrage. Es wurde noch KEINE Antwort von SR-HOMES gesendet.\nDu schreibst die ALLERERSTE Nachricht an diesen Interessenten.\nBeziehe dich AUSSCHLIEssLICH auf die Anfrage des Kunden.\n--- ENDE ERSTANTWORT ---\n";
        }

        // Followup hints
        $followupCount = $conv->followup_count ?? 0;
        $isSecondFollowup = $followupCount >= 1 || in_array($conv->status, ['nachfassen_2', 'nachfassen_3']);
        $outboundCount = $conv->outbound_count ?? 0;
        $lastInbound = $conv->last_inbound_at ? strtotime($conv->last_inbound_at) : 0;
        $lastOutbound = $conv->last_outbound_at ? strtotime($conv->last_outbound_at) : 0;

        if ($outboundCount > 0 && $lastOutbound > $lastInbound && $followupCount === 0) {
            $threadContext .= "\n--- NACHFASSEN (STUFE 1) ---\nSR-HOMES hat bereits geantwortet. Der Kunde hat NICHT reagiert.\nSchreibe eine kurze Nachfass-Mail. Maximal 3-4 Saetze.\n--- ENDE NACHFASSEN ---\n";
        } elseif ($outboundCount > 0 && $lastOutbound > $lastInbound && $isSecondFollowup) {
            $threadContext .= "\n--- NACHFASSEN (STUFE 2+) ---\nSR-HOMES hat bereits " . ($followupCount + 1) . " Mal geschrieben. Ton muss DIREKTER sein.\n--- ENDE NACHFASSEN ---\n";
        }

        // Contact phone
        $contact = DB::selectOne("SELECT phone FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1", [$stakeholder]);
        $hasPhone = !empty($contact->phone ?? null);

        // Property info
        $prop = DB::selectOne("SELECT address, city, ref_id FROM properties WHERE id = ?", [$propertyId]);
        $propAddr = ($prop->address ?? '') . ', ' . ($prop->city ?? '');

        // Generate draft via AI
        try {
            $anthropic = app(AnthropicService::class);
            $draft = $anthropic->generateFollowupDraft(
                $stakeholder, $propAddr, $threadContext, $kbContext,
                $hasPhone, 'professional', $daysSinceLastContact,
                $hasUnansweredQuestion, $today, $isSecondFollowup
            );
        } catch (\Throwable $e) {
            Log::warning("GenerateAiDraft failed for conv {$this->conversationId}: " . $e->getMessage());
            return;
        }

        if ($draft && !empty($draft['email_body'])) {
            $subject = $draft['email_subject'] ?? 'Nachfrage: ' . $propAddr;
            // Re-fetch conversation to avoid overwriting manual changes
            $conv = Conversation::find($this->conversationId);
            if ($conv && !$conv->draft_body) {
                $conversationService = app(ConversationService::class);
                $body = $conversationService->appendDefaultLinkForErstantwort($draft['email_body'], $conv);
                $conversationService->saveDraft($conv, $body, $subject, $conv->contact_email);
                Log::info("GenerateAiDraft: saved draft for conv {$this->conversationId}");
            }
        }
    }
}
