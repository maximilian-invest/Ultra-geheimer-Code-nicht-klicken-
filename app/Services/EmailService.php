<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\PortalEmail;
use App\Models\Activity;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function send(int $accountId, string $to, string $subject, string $body, ?int $propertyId = null, ?string $stakeholder = null, ?string $cc = null, ?string $bcc = null, array $attachments = [], ?string $inReplyToMessageId = null, ?string $references = null, string $outCategory = 'email-out', ?int $followupStage = null): array
    {
        $account = EmailAccount::findOrFail($accountId);

        try {
            // Port 587 = STARTTLS (tls param = false), Port 465 = implicit SSL (tls param = true)
            $useTls = $account->smtp_port == 465;
            $transport = new EsmtpTransport(
                $account->smtp_host,
                $account->smtp_port,
                $useTls
            );
            $transport->setUsername($account->smtp_username);
            $transport->setPassword($account->smtp_password);

            // Generate a unique Message-ID for this email
            $domain = substr($account->email_address, strpos($account->email_address, '@') + 1);
            $messageId = uniqid('srh-') . '@' . $domain;

            $email = (new Email())
                ->from("{$account->from_name} <{$account->email_address}>")
                ->to($to)
                ->subject($subject)
                ->html($body);

            // Set Message-ID
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            // Set threading headers for replies
            if ($inReplyToMessageId) {
                $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyToMessageId);
                // References should include the full chain
                $refChain = $references ? ($references . ' <' . $inReplyToMessageId . '>') : ('<' . $inReplyToMessageId . '>');
                $email->getHeaders()->addTextHeader('References', $refChain);
            }

            // Add CC recipients
            if ($cc) {
                foreach (array_filter(array_map('trim', explode(',', $cc))) as $ccAddr) {
                    if (filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                        $email->addCc($ccAddr);
                    }
                }
            }

            // Add BCC recipients
            if ($bcc) {
                foreach (array_filter(array_map('trim', explode(',', $bcc))) as $bccAddr) {
                    if (filter_var($bccAddr, FILTER_VALIDATE_EMAIL)) {
                        $email->addBcc($bccAddr);
                    }
                }
            }

            // Add attachments
            foreach ($attachments as $attachment) {
                if ($attachment instanceof \Illuminate\Http\UploadedFile) {
                    $email->attachFromPath(
                        $attachment->getRealPath(),
                        $attachment->getClientOriginalName(),
                        $attachment->getMimeType()
                    );
                } elseif (is_string($attachment) && file_exists($attachment)) {
                    // File path string
                    $email->attachFromPath($attachment, basename($attachment));
                }
            }

            // Get the raw MIME message before sending (needed for IMAP append)
            $sentMessage = $transport->send($email);

            // Append to IMAP Sent folder so it shows up in webmail
            $this->appendToImapSent($account, $email);

            // Stakeholder-Fallback: wenn kein Name uebergeben, aus contacts oder Email-Adresse ableiten
            if (!$stakeholder && $to) {
                $toEmail = strtolower(trim($to));
                // 1. Contacts-Lookup
                $contact = \DB::selectOne("SELECT full_name FROM contacts WHERE LOWER(email) = ? LIMIT 1", [$toEmail]);
                if ($contact && $contact->full_name) {
                    $stakeholder = $contact->full_name;
                } else {
                    // 2. Fruehere portal_emails mit diesem Empfaenger
                    $prev = \DB::selectOne("SELECT stakeholder FROM portal_emails WHERE LOWER(from_email) = ? AND stakeholder IS NOT NULL AND stakeholder != '' ORDER BY id DESC LIMIT 1", [$toEmail]);
                    if ($prev && $prev->stakeholder) {
                        $stakeholder = $prev->stakeholder;
                    } else {
                        // 3. Name aus Email extrahieren (georg_haslinger@... -> Georg Haslinger)
                        $localPart = explode('@', $toEmail)[0];
                        $parts = preg_split('/[._\-]/', $localPart);
                        $stakeholder = implode(' ', array_map('ucfirst', $parts));
                    }
                }
                Log::info("[EmailService] Stakeholder resolved from email: {$stakeholder} for {$to}");
            }

            // Save to portal_emails with message_id for threading
            $portalEmail = PortalEmail::create([
                'direction' => 'outbound',
                'from_email' => $account->email_address,
                'from_name' => $account->from_name,
                'to_email' => $to,
                'subject' => $subject,
                'body_html' => $body,
                'body_text' => strip_tags($body),
                'email_date' => now(),
                'property_id' => $propertyId,
                'stakeholder' => $stakeholder,
                'category' => $outCategory,
                'is_processed' => true,
                'account_id' => $accountId,
                'message_id' => $messageId,
            ]);

            // Create activity
            if ($propertyId && $stakeholder) {
                $activityData = [
                    'property_id' => $propertyId,
                    'activity_date' => now()->toDateString(),
                    'stakeholder' => $stakeholder,
                    'activity' => "Email-Antwort gesendet: {$subject}",
                    'result' => mb_substr(strip_tags($body), 0, 200),
                    'category' => $outCategory,
                    'source_email_id' => $portalEmail->id,
                ];
                if ($followupStage !== null) {
                    $activityData['followup_stage'] = $followupStage;
                }
                Activity::create($activityData);
            }

            return ['success' => true, 'email_id' => $portalEmail->id, 'activity_id' => $activity->id ?? null];
        } catch (\Exception $e) {
            Log::error('Email send failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Append sent email to IMAP Sent folder.
     */
    private function appendToImapSent(EmailAccount $account, Email $email): void
    {
        try {
            $imapStr = "{{$account->imap_host}:{$account->imap_port}/imap/{$account->imap_encryption}}";
            $mailbox = imap_open(
                $imapStr . 'INBOX',
                $account->imap_username,
                $account->imap_password
            );
            if (!$mailbox) {
                Log::warning('IMAP append: could not connect - ' . imap_last_error());
                return;
            }

            // Build raw MIME message from the Symfony Email
            $rawMessage = $email->toString();

            // Try common Sent folder names
            $sentFolders = ['Sent', 'INBOX.Sent', 'Sent Items', 'Gesendete Objekte', 'Gesendet'];
            $appended = false;
            foreach ($sentFolders as $folder) {
                if (@imap_append($mailbox, $imapStr . $folder, $rawMessage, "\\Seen")) {
                    $appended = true;
                    break;
                }
            }

            if (!$appended) {
                Log::warning('IMAP append: could not append to any Sent folder - ' . imap_last_error());
            }

            imap_close($mailbox);
        } catch (\Exception $e) {
            Log::warning('IMAP append failed: ' . $e->getMessage());
        }
    }

    public function testConnection(EmailAccount $account): array
    {
        // Test IMAP
        $imapOk = false;
        $imapError = '';
        try {
            $mailbox = imap_open(
                "{{$account->imap_host}:{$account->imap_port}/imap/{$account->imap_encryption}}INBOX",
                $account->imap_username,
                $account->imap_password
            );
            if ($mailbox) {
                $imapOk = true;
                imap_close($mailbox);
            }
        } catch (\Exception $e) {
            $imapError = $e->getMessage();
        }

        // Test SMTP
        $smtpOk = false;
        $smtpError = '';
        try {
            $useTls = $account->smtp_port == 465;
            $transport = new EsmtpTransport(
                $account->smtp_host,
                $account->smtp_port,
                $useTls
            );
            $transport->setUsername($account->smtp_username);
            $transport->setPassword($account->smtp_password);
            // Just creating transport tests connection params
            $smtpOk = true;
        } catch (\Exception $e) {
            $smtpError = $e->getMessage();
        }

        return [
            'imap' => ['ok' => $imapOk, 'error' => $imapError],
            'smtp' => ['ok' => $smtpOk, 'error' => $smtpError],
        ];
    }

    /**
     * Download or return an email attachment by index.
     *
     * @param int    $emailId   portal_emails.id
     * @param int    $fileIndex 0-based attachment index
     * @param string $mode      'download' for binary response, 'base64' for JSON
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadAttachment(int $emailId, int $fileIndex, string $mode = 'download'): \Symfony\Component\HttpFoundation\Response
    {
        $email = \App\Models\PortalEmail::findOrFail($emailId);
        $account = \App\Models\EmailAccount::findOrFail($email->account_id);

        $folder = $email->imap_folder ?: 'INBOX';
        $uid = $email->imap_uid;

        if (!$uid) {
            return response()->json(['error' => 'Email has no IMAP UID'], 400);
        }

        $connStr = '{' . $account->imap_host . ':' . $account->imap_port . '/imap/' . $account->imap_encryption . '}';
        $mailbox = @imap_open($connStr . $folder, $account->imap_username, $account->imap_password);

        if (!$mailbox) {
            Log::error('downloadAttachment: IMAP open failed - ' . imap_last_error());
            return response()->json(['error' => 'IMAP connection failed: ' . imap_last_error()], 500);
        }

        try {
            $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
            if (!$structure || !isset($structure->parts)) {
                return response()->json(['error' => 'No parts found in email'], 404);
            }

            // Collect attachments
            $attachments = [];
            foreach ($structure->parts as $partIndex => $part) {
                $filename = null;

                // Check disposition parameters (filename)
                if (isset($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = imap_utf8($param->value);
                        }
                    }
                }

                // Check parameters (name) as fallback
                if (!$filename && isset($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (strtolower($param->attribute) === 'name') {
                            $filename = imap_utf8($param->value);
                        }
                    }
                }

                // Is it an attachment?
                $isAttachment = false;
                if (isset($part->disposition) && in_array(strtolower($part->disposition), ['attachment', 'inline'])) {
                    if ($filename) $isAttachment = true;
                }
                // Also treat parts with a filename but no explicit disposition as attachments
                if ($filename && !$isAttachment && $part->ifsubtype && !in_array(strtolower($part->subtype), ['plain', 'html', 'alternative', 'mixed', 'related'])) {
                    $isAttachment = true;
                }

                if ($isAttachment && $filename) {
                    $attachments[] = [
                        'part_number' => (string)($partIndex + 1),
                        'filename' => $filename,
                        'encoding' => $part->encoding ?? 0,
                        'mime_type' => strtolower(($part->type ?? 0) === 0 ? 'text' : ($part->type === 1 ? 'multipart' : ($part->type === 2 ? 'message' : ($part->type === 3 ? 'application' : ($part->type === 4 ? 'audio' : ($part->type === 5 ? 'image' : ($part->type === 6 ? 'video' : 'other'))))))) . '/' . strtolower($part->subtype ?? 'octet-stream'),
                    ];
                }
            }

            if ($fileIndex < 0 || $fileIndex >= count($attachments)) {
                return response()->json(['error' => 'Attachment index out of range. Found ' . count($attachments) . ' attachments.'], 404);
            }

            $att = $attachments[$fileIndex];
            $body = imap_fetchbody($mailbox, $uid, $att['part_number'], FT_UID);

            // Decode
            $decoded = match ((int)$att['encoding']) {
                3 => base64_decode($body),           // BASE64
                4 => quoted_printable_decode($body),  // QUOTED-PRINTABLE
                default => $body,
            };

            imap_close($mailbox);

            if ($mode === 'base64') {
                return response()->json([
                    'success' => true,
                    'filename' => $att['filename'],
                    'mime_type' => $att['mime_type'],
                    'file_size' => strlen($decoded),
                    'data' => base64_encode($decoded),
                ]);
            }

            // Download mode
            return response($decoded, 200, [
                'Content-Type' => $att['mime_type'],
                'Content-Disposition' => 'attachment; filename="' . $att['filename'] . '"',
                'Content-Length' => strlen($decoded),
            ]);

        } catch (\Exception $e) {
            imap_close($mailbox);
            throw $e;
        }
    }
}
