<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Models\PropertyLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationService
{
    /**
     * Update or create a conversation from an incoming/outgoing email.
     */
    public function updateFromEmail(PortalEmail $email, ?Activity $activity = null): ?Conversation
    {

        $contactEmail = $this->resolveContactEmail($email);

        if (!$contactEmail) {
            Log::warning('ConversationService: could not resolve contact email', [
                'email_id' => $email->id,
                'direction' => $email->direction,
            ]);
            return null;
        }

        try {
            return DB::transaction(function () use ($email, $activity, $contactEmail) {

                // Lock existing row to prevent race conditions
                $conv = Conversation::where('contact_email', $contactEmail)
                    ->where(function($pq) use ($email) { if ($email->property_id) { $pq->where('property_id', $email->property_id); } else { $pq->whereNull('property_id'); } })
                    ->lockForUpdate()
                    ->first();

                // Fallback lookup: when we can't find a conversation by
                // contact_email (the typeform body parser occasionally
                // picks a slightly different email for the same person —
                // stuebermail@gmail.com vs m.stu3ber@gmail.com), try to
                // reuse an existing conversation that already holds the
                // same stakeholder on the same property. Avoids spawning
                // duplicate conversations for recurring customers.
                if (!$conv && !empty($email->stakeholder) && !empty($email->property_id)) {
                    $candidate = Conversation::where('property_id', $email->property_id)
                        ->whereRaw('LOWER(stakeholder) = LOWER(?)', [$email->stakeholder])
                        ->lockForUpdate()
                        ->first();
                    if ($candidate) {
                        $conv = $candidate;
                        Log::info("ConversationService: reused conv {$conv->id} by stakeholder/property match for email {$email->id} (contact_email {$contactEmail} did not match the stored {$conv->contact_email})");
                    }
                }

                // Persistenz-Regel: einmal zugeordneter Kunde bleibt seinem Objekt
                // fix zugeordnet, auch wenn neue Mails keinen ref_id-Match haben.
                // Beispiel: Willhaben-Forwards ohne ref_id würden sonst einen neuen
                // "Nicht zugeordnet"-Thread erzeugen obwohl der Kunde bereits manuell
                // einem Objekt zugewiesen wurde. Nur durch manuelle Umzuordnung
                // via UI-Popup ändern.
                //
                // ABER: Wenn die neue Mail SELBST schon einen sauberen Property-
                // Match hat (z.B. weil die Ref-ID im Willhaben-Body steht), darf
                // die Persistenz-Regel den NICHT ueberschreiben. Sonst landen
                // Anfragen zu Objekt B unter Objekt A, weil der Kunde frueher mal
                // zu A geschrieben hat. In dem Fall suchen wir nur nach einer
                // existierenden Conversation auf GENAU dem Ziel-Objekt; wenn es
                // keine gibt, faellt der Code unten auf "neue Conversation
                // anlegen" zurueck — der Kunde bekommt einen separaten Thread
                // pro angefragtem Objekt.
                if (!$conv) {
                    $persistenceQuery = Conversation::where('contact_email', $contactEmail)
                        ->whereNotNull('property_id')
                        ->whereNotIn('status', ['erledigt', 'archiviert']);

                    if (!empty($email->property_id)) {
                        $persistenceQuery->where('property_id', $email->property_id);
                    }

                    // Multi-Conversation-Safety: hat die Mail KEINE eigene Property
                    // und der Kunde hat MEHRERE offene Conversations auf
                    // VERSCHIEDENEN Objekten, dann lassen wir das Auto-Routing
                    // bewusst aus. Lieber landet die Mail in "Nicht zugeordnet"
                    // und der Makler entscheidet, statt dass wir sie zur
                    // "neuesten" Conversation packen und damit den Kontext
                    // verfaelschen (typischer Wiederkehr-Kunde-Bug).
                    if (empty($email->property_id)) {
                        $distinctProps = (clone $persistenceQuery)
                            ->distinct()
                            ->pluck('property_id');
                        if ($distinctProps->count() > 1) {
                            Log::info("ConversationService: skipping auto-route for {$contactEmail} (no own ref-id, multiple open conversations on properties [" . $distinctProps->implode(',') . "]) — leaving in Nicht zugeordnet for manual triage");
                            // $conv bleibt null -> faellt unten in "neue Conversation"
                            // mit der noch null-en property_id.
                        } else {
                            $existing = $persistenceQuery
                                ->orderByDesc('last_activity_at')
                                ->lockForUpdate()
                                ->first();
                            if ($existing) {
                                $conv = $existing;
                                Log::info("ConversationService: persisted existing conv {$conv->id} for {$contactEmail} on property {$existing->property_id} (new email had no own property)");
                                if ($email->property_id != $conv->property_id) {
                                    $email->property_id = $conv->property_id;
                                    $email->save();
                                }
                            }
                        }
                    } else {
                        $existing = $persistenceQuery
                            ->orderByDesc('last_activity_at')
                            ->lockForUpdate()
                            ->first();
                        if ($existing) {
                            $conv = $existing;
                            // property_id stimmt bereits ueberein (Filter oben).
                        }
                    }
                }

                if (!$conv) {
                    $conv = Conversation::create([
                        'contact_email'    => $contactEmail,
                        'property_id'      => $email->property_id,
                        'status'           => 'offen',
                        'source_platform'  => $this->detectPlatform($email->from_email ?? ''),
                        'first_contact_at' => $email->email_date ?? now(),
                        'inbound_count'    => 0,
                        'outbound_count'   => 0,
                        'followup_count'   => 0,
                        'is_read'          => false,
                    ]);
                }

                $isInbound = strtolower($email->direction ?? '') === 'inbound';

                if ($isInbound) {
                    $conv->inbound_count = ($conv->inbound_count ?? 0) + 1;
                    $conv->last_inbound_at = $email->email_date ?? now();
                    $conv->is_read = false;

                    // Detect "zur Info / CC" mails: the To header points at someone
                    // other than one of our own registered mailboxes, which means we
                    // only received this mail because we were CC'd. These threads
                    // should NEVER auto-generate a reply draft (the right action is
                    // usually "nothing" or at most a one-line acknowledgement to the
                    // sender, not an answer to the external addressee). Mark the
                    // conversation with category='info-cc' so GenerateAiDraft and
                    // the UI can treat it appropriately. If a later mail in the
                    // same thread comes in directly addressed to us, promote the
                    // conversation back to a normal open state.
                    if ($this->isCcOnlyCopy($email)) {
                        // Sticky info-cc only when no more specific category is set
                        $sticky = ['kaufanbot', 'besichtigung', 'absage'];
                        if (!in_array(strtolower($conv->category ?? ''), $sticky, true)) {
                            $conv->category = 'info-cc';
                        }
                    } elseif (($conv->category ?? '') === 'info-cc') {
                        // Direct mail arrived on a previously info-cc thread: promote
                        $conv->category = null;
                    }

                    // Absage → auto-archive, nicht ins Nachfassen
                    if (strtolower($email->category ?? '') === 'absage') {
                        $conv->status = 'archiviert';
                    }
                    // Customer replied -> reopen if we were waiting
                    elseif (in_array($conv->status, ['beantwortet', 'nachfassen_1', 'nachfassen_2', 'nachfassen_3', 'erledigt'])) {
                        $conv->status = 'offen';
                    }
                } else {
                    // Outbound
                    $conv->outbound_count = ($conv->outbound_count ?? 0) + 1;
                    $conv->last_outbound_at = $email->email_date ?? now();

                    // We replied -> mark answered
                    if ($conv->status === 'offen') {
                        $conv->status = 'beantwortet';
                    }
                }

                $conv->last_activity_at = $email->email_date ?? now();
                $conv->last_email_id = $email->id;

                if ($activity) {
                    $conv->last_activity_id = $activity->id;
                }

                // Update stakeholder name: prefer clean, longer, real names.
                // KEIN @ in Kandidat — sonst klebt eine fallback-Email als
                // Stakeholder fest und KI-Anreden werden zu "Sehr geehrter
                // Herr nb2776@gmail.com".
                if ($email->stakeholder) {
                    $candidateName = trim(preg_replace('/\s+/', ' ', $email->stakeholder));
                    $candidateIsEmail = str_contains($candidateName, '@')
                        || filter_var($candidateName, FILTER_VALIDATE_EMAIL);

                    // Skip junk names (newlines, "Kontaktdaten", too short, OR email).
                    $isClean = !str_contains($email->stakeholder, "
")
                        && !str_contains(strtolower($candidateName), 'kontaktdaten')
                        && Str::length($candidateName) >= 3
                        && !$candidateIsEmail;

                    if ($isClean) {
                        $currentName = $conv->stakeholder ?? '';
                        $currentIsEmail = str_contains($currentName, '@')
                            || filter_var($currentName, FILTER_VALIDATE_EMAIL);
                        $currentIsJunk = str_contains($currentName, "
")
                            || str_contains(strtolower($currentName), 'kontaktdaten')
                            || Str::length(trim($currentName)) < 3
                            || $currentIsEmail; // ← Email gilt jetzt als junk

                        // Replace if current is junk OR new name is longer (more complete)
                        if ($currentIsJunk || Str::length($candidateName) > Str::length(trim($currentName))) {
                            $conv->stakeholder = $candidateName;
                        }
                    }
                }

                // Update category if more specific
                $specificCategories = ['kaufanbot', 'besichtigung', 'absage', 'intern'];
                $newCat = $email->category ? strtolower($email->category) : null;
                if ($newCat && in_array($newCat, $specificCategories)) {
                    $conv->category = $newCat;
                } elseif ($isInbound && $newCat === 'anfrage' && $conv->category === 'absage') {
                    // Re-Aktivierung: Person hat abgesagt, schreibt jetzt aber
                    // wieder eine Anfrage (z.B. zu einem anderen Objekt). Den
                    // 'absage'-Tag von der Conv loesen, damit sie nicht mehr
                    // als verlorener Lead in der UI wirkt. Ohne diesen Reset
                    // blieb der absage-Status sticky und neue Anfragen
                    // erschienen weiter als "absage geflagged".
                    $conv->category = null;
                }

                $conv->save();

                // Mismatch-Hinweis: enthaelt der Mail-Body eine Ref-ID, die zu
                // einem ANDEREN Objekt gehoert als das jetzt zugeordnete? Wenn
                // ja, an der Mail vermerken — die Inbox blendet ein gelbes
                // Banner ein mit One-Click-Verschieben. Greift z.B. wenn
                // (a) die Persistenz-Regel die Mail ans alte Objekt geklebt
                //     hat obwohl der Body eine andere Ref-ID nennt
                // (b) matchProperty den falschen Footer-Ref erwischt hat
                //     (sollte mit pickEarliestRefMatch nicht mehr passieren,
                //     aber doppelt haelt besser)
                $hint = $this->detectMismatchedRefId($email);
                if ($hint !== ($email->property_mismatch_ref_id ?? null)) {
                    $email->property_mismatch_ref_id = $hint;
                    $email->save();
                }

                return $conv;
            });
        } catch (\Throwable $e) {
            Log::error('ConversationService::updateFromEmail failed', [
                'email_id' => $email->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Advance follow-up status after sending a follow-up email.
     */
    public function advanceFollowup(Conversation $conv): void
    {
        $transitions = [
            'beantwortet'  => 'nachfassen_1',
            'nachfassen_1' => 'nachfassen_2',
            'nachfassen_2' => 'nachfassen_3',
        ];

        if (isset($transitions[$conv->status])) {
            $conv->status = $transitions[$conv->status];
        }

        $conv->followup_count = ($conv->followup_count ?? 0) + 1;
        $conv->last_outbound_at = now();

        // Clear draft fields
        $conv->draft_body = null;
        $conv->draft_subject = null;
        $conv->draft_to = null;
        $conv->draft_generated_at = null;

        $conv->save();
    }

    /**
     * Mark a conversation as done. Also trashes every portal_email that
     * belongs to this conversation AND is visible to the current user —
     * i.e. mails in the current user's own email accounts. Mails from
     * other colleagues that happen to share the same stakeholder/contact
     * are left untouched.
     *
     * This is the fix for the Baldinger/Susanne case where Max clicking
     * "Erledigt" on a forwarded thread would otherwise have wiped out
     * Susanne's entire internal Baldinger correspondence.
     */
    public function markDone(Conversation $conv): void
    {
        $conv->status = 'erledigt';
        $conv->draft_body = null;
        $conv->draft_subject = null;
        $conv->draft_to = null;
        $conv->draft_generated_at = null;
        $conv->save();

        // Scope the trash to the current user's mailboxes.
        $accountIds = $this->currentUserAccountIds();
        $mailIds = $this->mailIdsForConversation($conv, $accountIds);
        if (!empty($mailIds)) {
            $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
            DB::update("UPDATE portal_emails SET is_deleted = 1, deleted_at = NOW() WHERE id IN ({$placeholders}) AND is_deleted = 0", $mailIds);
        }

        // Recompute the conversation's global counters — the user's mails
        // are gone, but other users may still hold live mails in the same
        // thread, so the conv row reflects what's left across all accounts.
        $this->rebuildFromEmails($conv->id);
    }

    /**
     * Mark a conversation as read.
     */
    public function markRead(Conversation $conv): void
    {
        if (!$conv->is_read) {
            $conv->is_read = true;
            $conv->save();
        }
    }

    /**
     * Save a draft reply for a conversation.
     */
    public function saveDraft(Conversation $conv, string $body, ?string $subject = null, ?string $to = null): void
    {
        $conv->draft_body = $body;
        $conv->draft_subject = $subject;
        $conv->draft_to = $to;
        $conv->draft_generated_at = now();
        $conv->save();
    }

    /**
     * Append the default PropertyLink URL to an Erstantwort draft body.
     *
     * Only modifies the body when:
     *  - the conversation has not yet sent any outbound (Erstantwort case),
     *  - the conversation is linked to a property,
     *  - that property has a default PropertyLink that is neither expired nor revoked.
     *
     * Returns the body unchanged otherwise. Shared between the admin
     * ConversationController::regenerateDraft() action and the
     * GenerateAiDraft queued job so both code paths stay in sync.
     */
    public function appendDefaultLinkForErstantwort(string $draftBody, Conversation $conv): string
    {
        if (($conv->outbound_count ?? 0) > 0) {
            return $draftBody;
        }
        if (empty($conv->property_id)) {
            return $draftBody;
        }
        return $this->appendDefaultLinkByPropertyId($draftBody, (int) $conv->property_id);
    }

    /**
     * Property-ID-only Variante — fuer Code-Pfade die keine Conversation
     * zur Hand haben (z.B. EmailController::aiReply). Idempotent: wenn der
     * Body den Link bereits enthaelt, wird er nicht doppelt angefuegt.
     */
    public function appendDefaultLinkByPropertyId(string $draftBody, int $propertyId): string
    {
        if ($propertyId <= 0) return $draftBody;

        $defaultLink = PropertyLink::where('property_id', $propertyId)
            ->where('is_default', true)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$defaultLink) {
            return $draftBody;
        }

        $url = url("/docs/{$defaultLink->token}");
        // Idempotenz-Schutz: wenn der Token-Pfad schon im Body steht
        // (z.B. aus einem früheren Generate-Lauf), nicht nochmal anhaengen.
        if (str_contains($draftBody, "/docs/{$defaultLink->token}")) {
            return $draftBody;
        }
        // Bewusst nur die URL auf eigener Zeile, OHNE einleitenden Satz
        // wie "finden Sie unter folgendem Link". Der KI-Prompt verlangt
        // dass der Body selbst auf den "unten angefügten Link" verweist;
        // ein zweiter Einleitungssatz hier wäre redundant und kollidiert
        // mit der Body-Formulierung.
        return rtrim($draftBody) . "\n\n" . $url;
    }

    /**
     * Resolve the contact email address from a PortalEmail.
     * Inbound: use from_email if real, otherwise extract from body, check contacts, or placeholder.
     * Outbound: parse to_email (handle "Name <email>" format).
     */
    public function resolveContactEmail(PortalEmail $email): ?string
    {
        if ($email->direction === 'inbound') {
            $fromEmail = strtolower(trim($email->from_email ?? ''));

            // Direct email from an external party (not a platform noreply, not an
            // internal SR-Homes address). This is the happy path for customer mails.
            if ($fromEmail && !$this->isNoReplyEmail($fromEmail) && !$this->isInternalEmail($fromEmail)) {
                return $fromEmail;
            }

            // Internal sender (@sr-homes.at): use the real address directly. These
            // are colleagues we CAN reply to — previously we fell through to the
            // placeholder branch which broke "Reply" by populating drafts with a
            // bogus @placeholder.local recipient (see the Nico Berger case).
            if ($fromEmail && !$this->isNoReplyEmail($fromEmail) && $this->isInternalEmail($fromEmail)) {
                return $fromEmail;
            }

            // Extract real email from body (willhaben, immoscout, immowelt embed real email in body)
            $body = $email->body_text ?? '';
            $realEmail = $this->extractRealEmailFromBody($body, $fromEmail);
            if ($realEmail) {
                return strtolower($realEmail);
            }

            // Check contacts table
            if ($email->stakeholder && $email->property_id) {
                $contact = \DB::table('contacts')
                    ->where('full_name', 'LIKE', '%' . mb_substr($email->stakeholder, 0, 30) . '%')
                    ->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->first();
                if ($contact && !$this->isNoReplyEmail($contact->email)) {
                    return strtolower($contact->email);
                }
            }

            // Last resort: placeholder. Only for truly unreachable senders
            // (platform noreply without embedded contact, automated systems).
            if (empty($email->stakeholder)) return null;
            return 'noreply_' . \Illuminate\Support\Str::slug($email->stakeholder) . '@placeholder.local';
        }

        // Outbound: parse to_email
        $to = $email->to_email ?? '';
        if (preg_match('/<([^>]+)>/', $to, $m)) $to = $m[1];
        $to = strtolower(trim($to));
        // Internal outbound (reply to a colleague) is a valid conversation contact.
        // We used to return null here, which meant replies to an internal thread
        // never got logged under the inbound conversation — previous behavior only
        // made sense when inbound-internal was rejected; now that both sides are
        // accepted the rule needs to be symmetric.
        return $to ?: null;
    }

    /**
     * Extract a real customer email embedded in portal email body text.
     */
    private function extractRealEmailFromBody(string $body, string $senderEmail): ?string
    {
        // Pattern 1: "E-Mail: xxx@yyy.zz" (willhaben format)
        if (preg_match('/(E-Mail|Email)[:\s]+([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $m)) {
            $candidate = strtolower($m[2]);
            if (!$this->isNoReplyEmail($candidate)) return $candidate;
        }

        // Pattern 2: "mailto:xxx@yyy.zz"
        if (preg_match('/mailto:([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $m)) {
            $candidate = strtolower($m[1]);
            if (!$this->isNoReplyEmail($candidate)) return $candidate;
        }

        // Pattern 3: Find first real email in body — must start with a letter and be bounded by whitespace/punctuation
        if (preg_match_all('/(?<=\s|^|[,;:<>("\x27])([a-zA-Z][\w.+\-]*@[\w.\-]+\.[a-z]{2,6})(?=\s|$|[,;:>)"\x27\]])/m', $body, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = strtolower(trim($candidate));
                if (substr_count($candidate, '@') !== 1) continue;
                if (strlen($candidate) > 80) continue;
                if (!$this->isNoReplyEmail($candidate) &&
                    !str_contains($candidate, 'sr-homes') &&
                    !str_contains($candidate, 'hoelzl')) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Detect the source platform from the sender email.
     */
    public function detectPlatform(string $fromEmail): string
    {
        $fromEmail = strtolower($fromEmail);

        $platforms = [
            'willhaben'        => 'willhaben',
            'immoscout'        => 'immoscout',
            'immobilienscout'  => 'immoscout',
            'immowelt'         => 'immowelt',
            'typeform'         => 'typeform',
            'calendly'         => 'calendly',
        ];

        foreach ($platforms as $pattern => $platform) {
            if (Str::contains($fromEmail, $pattern)) {
                return $platform;
            }
        }

        return 'direkt';
    }

    /**
     * Return every portal_email id that belongs to a given conversation,
     * using the same matching rules as conv_detail (contact_email on either
     * side, stakeholder fallback, same property scope).
     *
     * When $accountIds is provided (typically the current user's mailboxes),
     * the result is scoped to mails in those accounts only. This is used by
     * markDone so that "Erledigt" clicks only trash the current user's view
     * of a shared conversation — a colleague's mails on the same thread
     * stay untouched.
     *
     * @param int[]|null $accountIds
     * @return int[]
     */
    public function mailIdsForConversation(Conversation $conv, ?array $accountIds = null): array
    {
        $contactEmail = strtolower((string) $conv->contact_email);
        $stakeholder = (string) ($conv->stakeholder ?? '');
        $propertyId = $conv->property_id;

        $sql = "
            SELECT pe.id
            FROM portal_emails pe
            WHERE (pe.property_id = ? OR pe.property_id IS NULL)
              AND (
                  LOWER(pe.from_email) = LOWER(?)
                  OR LOWER(pe.to_email) LIKE CONCAT('%', LOWER(?), '%')
                  OR LOWER(pe.stakeholder) = LOWER(?)
                  OR pe.id = ?
              )
        ";
        $params = [
            $propertyId,
            $contactEmail,
            $contactEmail,
            $stakeholder,
            $conv->last_email_id,
        ];

        if (is_array($accountIds) && count($accountIds) > 0) {
            $ph = implode(',', array_fill(0, count($accountIds), '?'));
            $sql .= " AND pe.account_id IN ({$ph})";
            foreach ($accountIds as $aid) $params[] = (int) $aid;
        }

        $rows = DB::select($sql, $params);
        return array_values(array_unique(array_map(fn($r) => (int) $r->id, $rows)));
    }

    /**
     * Rebuild a conversation's denormalised counters and status from the
     * current set of NON-trashed portal_emails. Called after batch trash /
     * restore actions so the Anfragen list reflects Papierkorb state.
     *
     * When all linked mails are trashed the conversation is forced into
     * 'erledigt'. When restoring revives mails, an 'erledigt' conversation
     * is flipped back to 'offen'.
     */
    public function rebuildFromEmails(int $convId): void
    {
        $conv = Conversation::find($convId);
        if (!$conv) return;

        $contactEmail = strtolower((string) $conv->contact_email);
        $stakeholder = (string) ($conv->stakeholder ?? '');

        $rows = DB::select("
            SELECT id, direction, email_date
            FROM portal_emails
            WHERE is_deleted = 0
              AND (property_id = ? OR property_id IS NULL)
              AND (
                  LOWER(from_email) = LOWER(?)
                  OR LOWER(to_email) LIKE CONCAT('%', LOWER(?), '%')
                  OR LOWER(stakeholder) = LOWER(?)
              )
            ORDER BY email_date ASC, id ASC
        ", [
            $conv->property_id,
            $contactEmail,
            $contactEmail,
            $stakeholder,
        ]);

        $inbound = 0;
        $outbound = 0;
        $lastIn = null;
        $lastOut = null;
        $lastEmailId = null;

        foreach ($rows as $r) {
            if (strtolower((string) $r->direction) === 'inbound') {
                $inbound++;
                $lastIn = $r->email_date;
            } else {
                $outbound++;
                $lastOut = $r->email_date;
            }
            $lastEmailId = (int) $r->id;
        }

        if ($inbound === 0 && $outbound === 0) {
            // Everything trashed — force erledigt and clear drafts so the
            // conversation disappears from the active listings.
            $conv->status = 'erledigt';
            $conv->inbound_count = 0;
            $conv->outbound_count = 0;
            $conv->last_inbound_at = null;
            $conv->last_outbound_at = null;
            $conv->last_email_id = null;
            $conv->draft_body = null;
            $conv->draft_subject = null;
            $conv->draft_to = null;
            $conv->save();
            return;
        }

        // Still has live mails. If a previously 'erledigt' conv now has
        // inbound mails newer than any outbound, reopen it (typical case:
        // user restored a trashed customer mail).
        $conv->inbound_count = $inbound;
        $conv->outbound_count = $outbound;
        $conv->last_inbound_at = $lastIn;
        $conv->last_outbound_at = $lastOut;
        $conv->last_email_id = $lastEmailId;
        $conv->last_activity_at = $lastOut && $lastIn
            ? max($lastOut, $lastIn)
            : ($lastOut ?: $lastIn);

        if ($conv->status === 'erledigt' && $inbound > 0) {
            if (!$lastOut || ($lastIn && $lastIn > $lastOut)) {
                $conv->status = 'offen';
            }
        }

        $conv->save();
    }

    /**
     * Return the conversation ids that reference any of the given mail ids.
     * Used by EmailController::trash / restore to know which conversations
     * need rebuildFromEmails() after a batch trash.
     *
     * @param int[] $mailIds
     * @return int[]
     */
    public function findConversationIdsForMailIds(array $mailIds): array
    {
        if (empty($mailIds)) return [];
        $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
        $mails = DB::select("SELECT id, from_email, to_email, property_id, stakeholder FROM portal_emails WHERE id IN ({$placeholders})", $mailIds);

        $convIds = [];
        foreach ($mails as $m) {
            $rows = DB::select("
                SELECT id FROM conversations
                WHERE (property_id = ? OR property_id IS NULL OR ? IS NULL)
                  AND (
                      LOWER(contact_email) = LOWER(?)
                      OR LOWER(contact_email) = LOWER(?)
                      OR LOWER(stakeholder) = ?
                  )
            ", [
                $m->property_id,
                $m->property_id,
                $m->from_email ?? '',
                $m->to_email ?? '',
                $m->stakeholder ?? '',
            ]);
            foreach ($rows as $r) $convIds[(int) $r->id] = true;
            // Also any conversation whose last_email_id was exactly this mail
            $lastHit = DB::select("SELECT id FROM conversations WHERE last_email_id = ?", [(int) $m->id]);
            foreach ($lastHit as $r) $convIds[(int) $r->id] = true;
        }
        return array_keys($convIds);
    }

    /**
     * Return the active EmailAccount ids that belong to the currently
     * authenticated user. Assistenz/backoffice/admin roles get all active
     * accounts. If no user is authenticated (e.g. a console command), the
     * method returns null so callers can skip account scoping entirely.
     *
     * @return int[]|null
     */
    public function currentUserAccountIds(): ?array
    {
        $userId = \Illuminate\Support\Facades\Auth::id();
        if (!$userId) return null;

        $userType = \Illuminate\Support\Facades\Auth::user()->user_type ?? 'makler';

        if (in_array($userType, ['assistenz', 'backoffice'], true)) {
            return DB::table('email_accounts')->where('is_active', 1)->pluck('id')->map(fn($v) => (int) $v)->all();
        }

        return DB::table('email_accounts')
            ->where('is_active', 1)
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    /**
     * Check if an email address belongs to an internal SR-Homes user
     * (and should therefore never be used as an external contact_email).
     */
    public function isInternalEmail(?string $email): bool
    {
        if (!$email) return false;
        $email = strtolower(trim($email));
        // Strip angle brackets if present (e.g. "Name <a@b>")
        if (preg_match('/<([^>]+)>/', $email, $m)) {
            $email = $m[1];
        }
        return (bool) preg_match('/@(sr-homes\.at|bstf\.at)$/i', $email);
    }

    /**
     * Check if an inbound email was only received because we were CC'd — i.e.
     * the To header points at a third party rather than one of our own
     * registered mailboxes. Such mails are "zur Info" copies and must not
     * trigger AI draft generation, because we're not the party the sender
     * was writing to.
     *
     * Returns false for outbound mails and for mails where we cannot tell
     * (missing to_email), erring toward the normal-conversation behaviour.
     */
    public function isCcOnlyCopy(PortalEmail $email): bool
    {
        if (strtolower($email->direction ?? '') !== 'inbound') return false;

        $to = strtolower(trim($email->to_email ?? ''));
        if (!$to) return false;
        // "Display Name <address@domain>" → address@domain
        if (preg_match('/<([^>]+)>/', $to, $m)) {
            $to = strtolower(trim($m[1]));
        }
        if (!$to) return false;

        // If the To is one of our own registered accounts, this mail was
        // addressed directly at us (normal conversation).
        $ownEmails = \App\Models\EmailAccount::where('is_active', true)
            ->pluck('email_address')
            ->map(fn($e) => strtolower(trim($e)))
            ->filter()
            ->values()
            ->all();

        if (in_array($to, $ownEmails, true)) return false;

        // Also treat any @sr-homes.at / @bstf.at address as "one of us" in case
        // an active account isn't registered in EmailAccount yet.
        if ($this->isInternalEmail($to) && in_array($to, $ownEmails, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an email address is a no-reply / automated address.
     */
    public function isNoReplyEmail(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        $email = strtolower($email);

        $patterns = [
            'noreply',
            'no-reply',
            'notification',
            'mailer-daemon',
            'postmaster',
            'willhaben',
            'immoscout',
            'immobilienscout',
            'immowelt',
            'typeform',
            'calendly',
            'mcgrundriss',
            'immobilienmarkt@sn.at',
        ];

        foreach ($patterns as $pattern) {
            if (Str::contains($email, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reicht eine Liste von Mail-Rows (stdClass aus Raw-SQL ODER Eloquent-Models)
     * durch detectMismatchedRefId. Mails ohne gespeicherten Hint
     * (property_mismatch_ref_id IS NULL) bekommen den Live-Wert per Side-Effect
     * in die DB geschrieben (saveQuietly, ohne Model-Events). So muss kein
     * Backfill-Cron laufen — der Hint wird beim ersten Anschauen befuellt.
     *
     * Damit das Frontend "schon getestet, kein Mismatch" von "noch nie getestet"
     * unterscheiden kann, wird im DB-NULL-Fall nach der Live-Detection
     * - bei Treffer: der Treffer geschrieben
     * - ohne Treffer: ein leerer Sentinel-String '' geschrieben (DB wird '!='
     *   zu NULL, das Banner zeigt nichts)
     * Beide Faelle reflektieren den Live-Stand in der zurueckgegebenen Liste.
     *
     * @param array $emails Array aus stdClass oder PortalEmail
     * @return array dieselbe Liste, mit aktualisiertem property_mismatch_ref_id
     */
    public function fillMissingMismatchHints(array $emails): array
    {
        if (empty($emails)) return $emails;

        foreach ($emails as $em) {
            // bei stdClass: Property direkt; bei PortalEmail: ueber Attribute.
            $current = is_object($em)
                ? ($em->property_mismatch_ref_id ?? null)
                : null;
            // Nur wenn noch NIE gecheckt wurde (DB NULL).
            if ($current !== null) continue;

            // Wir brauchen ein PortalEmail-Model fuer detectMismatchedRefId.
            // Bei stdClass aus Raw-SQL: minimaler Hydrate ohne extra DB-Hit.
            $emailObj = $em instanceof PortalEmail
                ? $em
                : (function () use ($em) {
                    $m = new PortalEmail();
                    // exists=true verhindert dass save() ein INSERT macht
                    $m->setRawAttributes((array) $em, true);
                    $m->exists = true;
                    return $m;
                })();

            $detected = $this->detectMismatchedRefId($emailObj);

            // In DB schreiben (saveQuietly umgeht updateFromEmail-Recursion).
            // Wir speichern den leeren Sentinel-String als "checked, no mismatch"
            // damit wir nicht jedes Mal neu rechnen.
            try {
                if ($emailObj->getKey()) {
                    $emailObj->property_mismatch_ref_id = $detected ?? '';
                    $emailObj->saveQuietly();
                }
            } catch (\Throwable $e) {
                // Persist-Fehler darf API-Response nicht blocken.
                Log::warning('fillMissingMismatchHints: persist failed', ['email_id' => $emailObj->id ?? null, 'err' => $e->getMessage()]);
            }

            // Auf der Response-Row spiegeln (Frontend nutzt das direkt).
            // Empty string an Frontend zurueck mappen wir auf null, damit
            // das Banner nicht versucht ein Property mit ref_id="" zu finden.
            $value = ($detected !== null && $detected !== '') ? $detected : null;
            if ($em instanceof PortalEmail) {
                $em->property_mismatch_ref_id = $value;
            } elseif (is_object($em)) {
                $em->property_mismatch_ref_id = $value;
            }
        }

        return $emails;
    }

    /**
     * Scans subject + body of a saved email for ref_ids of OTHER properties
     * (not the one currently assigned). Stores the first hit as
     * property_mismatch_ref_id. Used to surface "Diese Mail nennt Ref-ID X
     * — gehoert evtl. zu anderem Objekt"-Banner in der Inbox.
     *
     * Bewusst verlustarm: matched nur exakte Substrings (case-insensitive
     * sowie normalisiert), keine Fuzzy-Heuristik. Lieber kein Hint als
     * ein falscher Hint.
     */
    public function detectMismatchedRefId(PortalEmail $email): ?string
    {
        $currentPid = (int) ($email->property_id ?? 0);
        $haystack   = strtolower(($email->subject ?? '') . ' ' . ($email->body_text ?? ''));
        $haystackNorm = strtolower(preg_replace('/[\s\-_]+/', '', $haystack));
        if ($haystack === '' || $haystackNorm === '') return null;

        // Properties (broker-skopiert wenn account_id bekannt) holen.
        // Caching auf Request-Ebene, damit Bulk-Import nicht 1x SELECT pro Mail laeuft.
        static $cache = null;
        if ($cache === null) {
            $cache = DB::table('properties')
                ->whereNotNull('ref_id')
                ->where('ref_id', '!=', '')
                ->get(['id', 'ref_id'])
                ->all();
        }

        foreach ($cache as $p) {
            if ((int) $p->id === $currentPid) continue;
            $ref = strtolower((string) $p->ref_id);
            if (strlen($ref) < 4) continue;
            if (str_contains($haystack, $ref)) return (string) $p->ref_id;
            $refNorm = preg_replace('/[\s\-_]+/', '', $ref);
            if (strlen($refNorm) >= 4 && str_contains($haystackNorm, $refNorm)) return (string) $p->ref_id;
        }

        return null;
    }

    /**
     * Splittet eine einzelne Mail aus ihrer aktuellen Conversation heraus
     * in eine eigene Conversation auf einem anderen Objekt. Die zugehoerigen
     * Activities (matched via source_email_id) wandern mit. Counters auf
     * der QUELL- und der ZIEL-Conversation werden anschliessend neu aus
     * den Mails abgeleitet (rebuildFromEmails).
     *
     * @param int      $emailId        portal_emails.id
     * @param int|null $newPropertyId  Ziel-Property; null = "Nicht zugeordnet"
     * @return array{ok:bool, source_conversation_id:?int, target_conversation_id:?int, error?:string}
     */
    public function splitMailToNewConversation(int $emailId, ?int $newPropertyId): array
    {
        $email = PortalEmail::find($emailId);
        if (!$email) return ['ok' => false, 'error' => 'Email not found'];

        $oldPropertyId = $email->property_id;
        if ((int) $oldPropertyId === (int) $newPropertyId) {
            return ['ok' => true, 'source_conversation_id' => null, 'target_conversation_id' => null, 'unchanged' => true];
        }

        $contactEmail = $this->resolveContactEmail($email);
        if (!$contactEmail) return ['ok' => false, 'error' => 'Could not resolve contact email'];

        $result = DB::transaction(function () use ($email, $oldPropertyId, $newPropertyId, $contactEmail) {
            // 1) Quell-Conversation: ueber (contact_email + alte property_id) finden.
            $sourceConv = Conversation::where('contact_email', $contactEmail)
                ->where(function ($q) use ($oldPropertyId) {
                    if ($oldPropertyId === null) $q->whereNull('property_id');
                    else $q->where('property_id', $oldPropertyId);
                })
                ->first();

            // 2) Ziel-Conversation: existiert schon eine fuer (contact_email + neue property_id)?
            $targetConv = null;
            if ($newPropertyId !== null) {
                $targetConv = Conversation::where('contact_email', $contactEmail)
                    ->where('property_id', $newPropertyId)
                    ->lockForUpdate()
                    ->first();
            }
            if (!$targetConv) {
                $targetConv = Conversation::create([
                    'contact_email'    => $contactEmail,
                    'stakeholder'      => $email->stakeholder ?? null,
                    'property_id'      => $newPropertyId,
                    'status'           => 'offen',
                    'source_platform'  => $this->detectPlatform($email->from_email ?? ''),
                    'first_contact_at' => $email->email_date ?? now(),
                    'last_activity_at' => now(),
                    'inbound_count'    => 0,
                    'outbound_count'   => 0,
                    'followup_count'   => 0,
                    'is_read'          => false,
                ]);
            }

            // 3) Mail auf das neue Objekt umhaengen + Mismatch-Hint loeschen
            //    (das Banner wuerde sonst weiter blinken obwohl der User
            //    gerade aktiv den Split bestaetigt hat).
            $email->property_id = $newPropertyId;
            $email->property_mismatch_ref_id = null;
            $email->save();

            // 4) Activities, die DIREKT durch diese Mail entstanden sind, mit-umhaengen
            if ($newPropertyId !== null) {
                DB::table('activities')
                    ->where('source_email_id', $email->id)
                    ->update(['property_id' => $newPropertyId]);
            }

            // 5) Audit-Activity am Ziel
            if ($newPropertyId !== null) {
                $newRef = DB::table('properties')->where('id', $newPropertyId)->value('ref_id') ?: ('#' . $newPropertyId);
                $oldRef = $oldPropertyId
                    ? (DB::table('properties')->where('id', $oldPropertyId)->value('ref_id') ?: ('#' . $oldPropertyId))
                    : 'Nicht zugeordnet';
                DB::table('activities')->insert([
                    'property_id'     => $newPropertyId,
                    'stakeholder'     => $email->stakeholder ?: ($email->from_name ?: 'System'),
                    'activity_date'   => now(),
                    'category'        => 'intern',
                    'activity'        => "Mail manuell umgehaengt von {$oldRef} (Subject: \"" . mb_substr($email->subject ?? '', 0, 80) . "\")",
                    'source_email_id' => $email->id,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            // 6) Counters auf beiden Convs neu ableiten
            if ($sourceConv) {
                $this->rebuildFromEmails($sourceConv->id);
            }
            $this->rebuildFromEmails($targetConv->id);

            return [
                'ok' => true,
                'source_conversation_id' => $sourceConv?->id,
                'target_conversation_id' => $targetConv->id,
            ];
        });

        return $result;
    }
}
