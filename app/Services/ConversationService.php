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

                // Update stakeholder name: prefer clean, longer names
                if ($email->stakeholder) {
                    $candidateName = trim(preg_replace('/\s+/', ' ', $email->stakeholder));
                    // Skip junk names (contain newlines, "Kontaktdaten", too short, or noreply-style)
                    $isClean = !str_contains($email->stakeholder, "
")
                        && !str_contains(strtolower($candidateName), 'kontaktdaten')
                        && Str::length($candidateName) >= 3;

                    if ($isClean) {
                        $currentName = $conv->stakeholder ?? '';
                        $currentIsJunk = str_contains($currentName, "
")
                            || str_contains(strtolower($currentName), 'kontaktdaten')
                            || Str::length(trim($currentName)) < 3;

                        // Replace if current is junk OR new name is longer (more complete)
                        if ($currentIsJunk || Str::length($candidateName) > Str::length(trim($currentName))) {
                            $conv->stakeholder = $candidateName;
                        }
                    }
                }

                // Update category if more specific
                $specificCategories = ['kaufanbot', 'besichtigung', 'absage', 'intern'];
                if ($email->category && in_array(strtolower($email->category), $specificCategories)) {
                    $conv->category = strtolower($email->category);
                }

                $conv->save();

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
     * Mark a conversation as done.
     */
    public function markDone(Conversation $conv): void
    {
        $conv->status = 'erledigt';
        $conv->draft_body = null;
        $conv->save();
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

        $defaultLink = PropertyLink::where('property_id', $conv->property_id)
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
        $sentence = "Die ausfuehrlichen Unterlagen zum Objekt finden Sie unter folgendem Link:\n" . $url;

        return rtrim($draftBody) . "\n\n" . $sentence;
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
        ];

        foreach ($patterns as $pattern) {
            if (Str::contains($email, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
