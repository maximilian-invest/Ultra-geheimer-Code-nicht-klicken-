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

                        // Format body: convert plain text newlines to HTML + append signature
            $formattedBody = $body;

            // If body doesn't contain HTML tags, convert newlines to <br>
            if (strip_tags($formattedBody) === $formattedBody || !preg_match('/<(br|p|div|table|td|tr)[\s>]/i', $formattedBody)) {
                $formattedBody = nl2br(htmlspecialchars($formattedBody, ENT_QUOTES, 'UTF-8'));
            }

            // Append HTML signature if not already present.
            // Bei Forwards/Replies platzieren wir die Signatur VOR dem Quote-
            // Block (also direkt nach der Nachricht des Maklers), nicht ans
            // Ende — sonst klebt sie unter der weitergeleiteten Original-Mail.
            if (!str_contains($formattedBody, 'SR-Homes Immobilien') && !str_contains($formattedBody, 'signature')) {
                $sig = $this->buildHtmlSignature($accountId);
                $formattedBody = $this->insertSignatureBeforeQuote($formattedBody, $sig);
            }

            $email = (new Email())
                ->from("{$account->from_name} <{$account->email_address}>")
                ->to($to)
                ->subject($subject)
                ->html($formattedBody);

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

            // Anhang-Metadaten aus den uebergebenen $attachments extrahieren,
            // damit 'Gesendet' + Conversation-Listen ein Paperclip-Icon
            // zeigen koennen. Unterstuetzt sowohl UploadedFile (Bytes aus
            // Compose-View) als auch Pfad-Strings (resolvte property_files).
            $attachmentNamesList = [];
            foreach (($attachments ?? []) as $attachment) {
                if ($attachment instanceof \Illuminate\Http\UploadedFile) {
                    $attachmentNamesList[] = $attachment->getClientOriginalName();
                } elseif (is_string($attachment) && $attachment !== '') {
                    $attachmentNamesList[] = basename($attachment);
                }
            }
            $hasAttachment = !empty($attachmentNamesList);

            // Save to portal_emails with message_id for threading
            $portalEmail = PortalEmail::create([
                'direction' => 'outbound',
                'from_email' => $account->email_address,
                'from_name' => $account->from_name,
                'to_email' => $to,
                'subject' => $subject,
                'body_html' => $formattedBody,
                'body_text' => strip_tags($body),
                'email_date' => now(),
                'property_id' => $propertyId,
                'stakeholder' => $stakeholder,
                'category' => $outCategory,
                'is_processed' => true,
                'account_id' => $accountId,
                'message_id' => $messageId,
                'has_attachment' => $hasAttachment ? 1 : 0,
                'attachment_names' => $hasAttachment ? implode(', ', $attachmentNamesList) : null,
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

            // Mark inbound emails from same stakeholder+property as replied.
            // Wer antwortet, hat auch gelesen — also is_read=1 in einem Rutsch
            // mitsetzen. Sonst kann eine Mail gleichzeitig "beantwortet" und
            // "ungelesen" sein (blauer Punkt + Bold), was inkonsistent wirkt.
            if ($stakeholder && $propertyId) {
                \DB::update("UPDATE portal_emails SET has_reply = 1, is_read = 1 WHERE direction = 'inbound' AND (has_reply = 0 OR is_read = 0) AND LOWER(TRIM(COALESCE(stakeholder, from_name, ''))) = ? AND COALESCE(property_id, 0) = ?", [
                    strtolower(trim($stakeholder)),
                    (int) $propertyId
                ]);
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

    /**
     * Plaziert die Signatur intelligent in einer Reply/Forward-Mail:
     *   - bei Weitergeleiteten Nachrichten: VOR dem "-------- Weitergeleitete
     *     Nachricht --------" Marker
     *   - bei Antworten: VOR dem Quote-Block (border-left:2px solid o.ae.) bzw.
     *     "Am ... schrieb ...:" Header
     *   - sonst: ans Ende
     * So bleibt die Signatur immer direkt unter der eigenen Nachricht und
     * nicht am Ende der zitierten Original-Mail.
     */
    private function insertSignatureBeforeQuote(string $body, string $sig): string
    {
        // Pattern in absteigender Spezifitaet — der erste Treffer gewinnt.
        // Whitespace + ggf. <br> davor wird mit-erfasst, damit die Signatur
        // sauber DAVOR landet ohne den Quote-Block zu zerreissen.
        $patterns = [
            // Forward-Marker (deutsch + englisch)
            '/(?:<br\s*\/?>\s*|\s)*-{2,}\s*Weitergeleitete Nachricht\s*-{2,}/i',
            '/(?:<br\s*\/?>\s*|\s)*-{2,}\s*Forwarded\s+message\s*-{2,}/i',
            '/(?:<br\s*\/?>\s*|\s)*-{2,}\s*Original\s+Message\s*-{2,}/i',
            '/(?:<br\s*\/?>\s*|\s)*-{2,}\s*Original-Nachricht\s*-{2,}/i',
            // Reply-Quote-Block (vom InboxTab.vue beim Reply generiert)
            '/<div\s+style="[^"]*border-left[^"]*"/i',
            '/<blockquote\b/i',
            // Reply-Attribution-Zeile als Fallback
            '/(?:<br\s*\/?>\s*|<p[^>]*>\s*)Am\s+[^<>]{1,120}\s+schrieb\s*[^<>:]{0,80}:/i',
        ];

        foreach ($patterns as $pat) {
            if (preg_match($pat, $body, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                return substr($body, 0, $offset) . $sig . substr($body, $offset);
            }
        }

        return $body . $sig;
    }

    private function buildHtmlSignature(int $accountId): string
    {
        $userId = \DB::table('email_accounts')->where('id', $accountId)->value('user_id');
        if (!$userId) return '';

        $s = \DB::table('admin_settings')->where('user_id', $userId)->first();
        if (!$s) return '<br><br><span style="color:#999">--</span><br>SR-Homes Immobilien GmbH<br>www.sr-homes.at';

        $baseUrl = rtrim(config('app.url', 'https://kundenportal.sr-homes.at'), '/');
        $logoUrl = $s->signature_logo_path ? $baseUrl . '/storage/' . $s->signature_logo_path : null;
        $bannerUrl = $s->signature_banner_path ? $baseUrl . '/storage/' . $s->signature_banner_path : null;
        $photoUrl = $s->signature_photo_path ? $baseUrl . '/storage/' . $s->signature_photo_path : null;

        $name = $s->signature_name ?? '';
        $title = $s->signature_title ?? '';
        $company = $s->signature_company ?? 'SR-Homes Immobilien GmbH';
        $phone = $s->signature_phone ?? '+43 664 2600 930';
        $website = $s->signature_website ?? 'www.sr-homes.at';

        $cs = $photoUrl ? 2 : 1;
        $html = '<br><br><table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333">';
        if ($logoUrl) {
            $html .= '<tr><td colspan="' . $cs . '" style="padding-bottom:8px"><img src="' . $logoUrl . '" alt="Logo" style="max-height:60px;max-width:200px"></td></tr>';
        }
        $html .= '<tr>';
        if ($photoUrl) {
            $html .= '<td style="border-top:2px solid #ee7606;padding-top:8px;padding-right:12px;vertical-align:top"><img src="' . $photoUrl . '" alt="" style="width:70px;height:90px;object-fit:cover;border-radius:4px"></td>';
        }
        $html .= '<td style="border-top:2px solid #ee7606;padding-top:8px">';
        $html .= '<strong style="font-size:14px;color:#222">' . htmlspecialchars($name) . '</strong>';
        if ($title) $html .= '<br><span style="color:#666">' . htmlspecialchars($title) . '</span>';
        $html .= '<br><span style="color:#666">' . htmlspecialchars($company) . '</span>';
        $html .= '<br>Tel: <a href="tel:' . preg_replace('/\s/', '', $phone) . '" style="color:#ee7606;text-decoration:none">' . htmlspecialchars($phone) . '</a>';
        $html .= '<br><a href="https://' . htmlspecialchars($website) . '" style="color:#ee7606;text-decoration:none">' . htmlspecialchars($website) . '</a>';
        $html .= '</td></tr>';
        if ($bannerUrl) {
            $html .= '<tr><td colspan="' . $cs . '" style="padding-top:8px"><img src="' . $bannerUrl . '" alt="" style="max-width:400px;width:100%;border-radius:4px"></td></tr>';
        }
        $html .= '</table>';
        return $html;
    }
}

