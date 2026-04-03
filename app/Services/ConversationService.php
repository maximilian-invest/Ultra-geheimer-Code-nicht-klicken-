<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Conversation;
use App\Models\PortalEmail;
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
        if (!$email->property_id) {
            return null;
        }

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
                    ->where('property_id', $email->property_id)
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

                    // Customer replied -> reopen if we were waiting
                    if (in_array($conv->status, ['beantwortet', 'nachfassen_1', 'nachfassen_2', 'nachfassen_3'])) {
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

                // Update stakeholder name if new one is longer (more complete)
                if ($email->stakeholder && Str::length($email->stakeholder) > Str::length($conv->stakeholder ?? '')) {
                    $conv->stakeholder = $email->stakeholder;
                }

                // Update category if more specific
                $specificCategories = ['kaufanbot', 'besichtigung', 'absage'];
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
     * Resolve the contact email address from a PortalEmail.
     * Inbound: use from_email (skip noreply addresses).
     * Outbound: parse to_email (handle "Name <email>" format).
     */
    public function resolveContactEmail(PortalEmail $email): ?string
    {
        $isInbound = strtolower($email->direction ?? '') === 'inbound';

        if ($isInbound) {
            $candidate = $email->from_email;

            if ($candidate && $this->isNoReplyEmail($candidate)) {
                // Generate a placeholder from the stakeholder name
                $name = $email->stakeholder ?? 'unknown';
                $slug = Str::slug($name, '_');
                return "noreply_{$slug}@placeholder.local";
            }

            return $candidate ? strtolower(trim($candidate)) : null;
        }

        // Outbound: parse to_email
        $toRaw = $email->to_email;

        if (!$toRaw) {
            return null;
        }

        // Handle "Name <email>" format
        if (preg_match('/<([^>]+)>/', $toRaw, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($toRaw));
    }

    /**
     * Detect the source platform from the sender email.
     */
    public function detectPlatform(string $fromEmail): string
    {
        $fromEmail = strtolower($fromEmail);

        $platforms = [
            'willhaben'  => 'willhaben',
            'immoscout'  => 'immoscout',
            'immowelt'   => 'immowelt',
            'typeform'   => 'typeform',
            'calendly'   => 'calendly',
        ];

        foreach ($platforms as $pattern => $platform) {
            if (Str::contains($fromEmail, $pattern)) {
                return $platform;
            }
        }

        return 'direkt';
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
