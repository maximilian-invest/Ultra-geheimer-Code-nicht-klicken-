<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\Activity;
use App\Models\Contact;
use App\Services\PhoneExtractor;
use Illuminate\Support\Facades\Log;

class ImapService
{
    private AnthropicService $ai;

    public function __construct(AnthropicService $ai)
    {
        $this->ai = $ai;
    }

    public function fetchNewEmails(EmailAccount $account): int
    {
        $baseStr = '{' . $account->imap_host . ':' . $account->imap_port . '/imap/' . $account->imap_encryption . '}';
        
        // Fetch from both INBOX and Sent folders
        $folders = [
            ['path' => 'INBOX', 'last_uid_field' => 'last_uid', 'direction_hint' => 'inbound'],
        ];
        
        // Discover the Sent folder name (varies by provider)
        $testBox = @imap_open($baseStr . 'INBOX', $account->imap_username, $account->imap_password);
        if (!$testBox) {
            Log::error("IMAP open failed for {$account->email_address}: " . imap_last_error());
            return 0;
        }
        
        $allFolders = imap_list($testBox, $baseStr, '*');
        imap_close($testBox);
        
        $sentFolderNames = ['Sent', 'Sent Items', 'Sent Messages', 'Gesendete Elemente', 'Gesendete Objekte', 'Gesendet', 'INBOX.Sent', 'INBOX.Sent Items'];
        if ($allFolders) {
            foreach ($allFolders as $folder) {
                $folderName = str_replace($baseStr, '', imap_utf7_decode($folder));
                foreach ($sentFolderNames as $sentName) {
                    if (strcasecmp($folderName, $sentName) === 0 || strcasecmp($folderName, 'INBOX.' . $sentName) === 0) {
                        $folders[] = ['path' => $folderName, 'last_uid_field' => 'last_uid_sent', 'direction_hint' => 'outbound'];
                        Log::info("IMAP: Found sent folder: {$folderName} for {$account->email_address}");
                        break 2;
                    }
                }
            }
        }
        
        $totalCount = 0;
        // Only load properties belonging to this account's broker (strict separation)
        $accountBrokerId = $account->user_id;
        $this->currentBrokerId = $accountBrokerId;
        $properties = $accountBrokerId
            ? Property::select('id', 'ref_id', 'address')->where('broker_id', $accountBrokerId)->get()->toArray()
            : Property::select('id', 'ref_id', 'address')->get()->toArray();
        $since = $account->last_fetch_at
            ? $account->last_fetch_at->format('d-M-Y')
            : now()->subDays(7)->format('d-M-Y');
        
        foreach ($folders as $folderInfo) {
            $folderPath = $folderInfo['path'];
            $lastUidField = $folderInfo['last_uid_field'];
            $directionHint = $folderInfo['direction_hint'];
            $lastUid = intval($account->$lastUidField ?? 0);
            
            $mailbox = @imap_open($baseStr . $folderPath, $account->imap_username, $account->imap_password);
            if (!$mailbox) {
                Log::warning("IMAP: Could not open folder {$folderPath} for {$account->email_address}");
                continue;
            }
            
            $emails = imap_search($mailbox, "SINCE \"{$since}\"", SE_UID);
            if (!$emails) {
                imap_close($mailbox);
                continue;
            }
            
            $maxUid = $lastUid;
            
            foreach ($emails as $uid) {
                if ($uid <= $lastUid) continue;
                if ($uid > $maxUid) $maxUid = $uid;

                $header = imap_fetchheader($mailbox, $uid, FT_UID);
                $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
                $overview = imap_fetch_overview($mailbox, $uid, FT_UID);

                if (empty($overview)) continue;
                $ov = $overview[0];

                $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '';
                // Fallback: imap_utf8 fails on some Base64/Q-encoded subjects
                if ($subject && preg_match('/=\?[A-Za-z0-9\-]+\?[BQ]\?/i', $subject)) {
                    $subject = mb_decode_mimeheader($subject);
                }
                $from = isset($ov->from) ? imap_utf8($ov->from) : '';
                if ($from && preg_match('/=\?[A-Za-z0-9\-]+\?[BQ]\?/i', $from)) {
                    $from = mb_decode_mimeheader($from);
                }
                $to = isset($ov->to) ? imap_utf8($ov->to) : '';
                if ($to && preg_match('/=\?[A-Za-z0-9\-]+\?[BQ]\?/i', $to)) {
                    $to = mb_decode_mimeheader($to);
                }
                $date = isset($ov->date) ? date('Y-m-d H:i:s', strtotime($ov->date)) : now();
                $messageId = isset($ov->message_id) ? trim($ov->message_id, '<>') : null;

                // Skip if already processed
                if ($messageId && PortalEmail::where('message_id', $messageId)->exists()) {
                    continue;
                }

                // Get body
                $body = $this->getBody($mailbox, $uid);

                // Parse from
                $fromParsed = mailparse_rfc822_parse_addresses($from);
                $fromEmail = $fromParsed[0]['address'] ?? $from;
                $fromName = $fromParsed[0]['display'] ?? '';

                // Determine direction: use hint from folder, but verify from address
                // Multi-User: pruefen ob fromEmail zu einem unserer Accounts gehoert
                $allOwnEmails = \App\Models\EmailAccount::where('is_active', true)->pluck('email_address')->map(fn($e) => strtolower($e))->toArray();
                $isOwnEmail = (stripos($fromEmail, 'sr-homes') !== false || in_array(strtolower($fromEmail), $allOwnEmails));
                $direction = $isOwnEmail ? 'outbound' : 'inbound';

                // Detect internal emails (both from AND to are sr-homes/hoelzl)
                $toAddresses = mailparse_rfc822_parse_addresses($to);
                $toEmailAddr = $toAddresses[0]['address'] ?? $to;
                $isInternalEmail = $isOwnEmail && (stripos($toEmailAddr, 'sr-homes') !== false || in_array(strtolower($toEmailAddr), $allOwnEmails));

                // Cross-account protection: skip emails addressed to a DIFFERENT sr-homes account
                // Fixes World4You catch-all where renzl@ emails appear in hoelzl@ IMAP
                if ($direction === 'inbound') {
                    foreach ($allOwnEmails as $ownEmail) {
                        if ($ownEmail !== strtolower($account->email_address) && strtolower($toEmailAddr) === $ownEmail) {
                            \Log::info("[IMAP] Skip: email to {$toEmailAddr} belongs to other account, not {$account->email_address}");
                            continue 2;
                        }
                    }
                }
                if ($direction === 'outbound' && in_array(strtolower($fromEmail), $allOwnEmails) && strtolower($fromEmail) !== strtolower($account->email_address)) {
                    \Log::info("[IMAP] Skip: outbound from {$fromEmail} belongs to other account, not {$account->email_address}");
                    continue;
                }

                // ── Bounce-Erkennung: unzustellbare E-Mails ─────────────────────────────
                // Wenn eine Zustellung fehlschlägt, kommt eine Bounce-Mail vom Mailserver.
                // → Bounce-Email speichern → Activity mit source_email_id verknüpfen
                // → Dadurch erscheint der Kunde wieder in "Unbeantwortet" (last_has_email=1)
                $isBounce = false;
                if ($direction === 'inbound' && (
                    stripos($fromEmail, 'mailer-daemon') !== false ||
                    stripos($fromEmail, 'postmaster') !== false ||
                    preg_match(
                        '/Mail delivery failed|Undelivered Mail|Delivery Status Notification.*Failure|Undeliverable|' .
                        'Unzustellbar|Zustellung.*fehlgeschlagen|konnte nicht zugestellt|nicht zustellbar|' .
                        'Delivery Failure|bounced|returned mail/i',
                        $subject
                    )
                )) {
                    $isBounce = true;
                    $lastOutbound = null;

                    // ── Empfänger-Email aus Bounce-Body extrahieren ───────────────────────
                    $bounceRecipient = null;
                    // RFC 3464 DSN Format (Final-Recipient: rfc822; email@example.com)
                    if (preg_match('/Final-Recipient\s*:\s*[^;]+;\s*([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $bm)) {
                        $bounceRecipient = strtolower($bm[1]);
                    }
                    // Original-Recipient header
                    if (!$bounceRecipient && preg_match('/Original-Recipient\s*:\s*[^;]+;\s*([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $bm)) {
                        $bounceRecipient = strtolower($bm[1]);
                    }
                    // X-Failed-Recipients header
                    if (!$bounceRecipient && preg_match('/X-Failed-Recipients\s*:\s*([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $bm)) {
                        $bounceRecipient = strtolower($bm[1]);
                    }
                    // Deutsche Bounce-Muster (Outlook, Exchange)
                    if (!$bounceRecipient && preg_match(
                        '/(?:could not be delivered to|was not delivered to|Recipient address rejected|' .
                        'konnte nicht zugestellt werden an|nicht zustellbar an|Empfänger|Fehlgeschlagen für)[:\s]+([\'"]?)([\w.+\-]+@[\w.\-]+\.[a-z]{2,})\1/i',
                        $body, $bm
                    )) {
                        $bounceRecipient = strtolower($bm[2]);
                    }
                    // Generischer Fallback: erste fremde E-Mail-Adresse im Body (nicht sr-homes/hoelzl)
                    if (!$bounceRecipient && preg_match_all('/([\w.+\-]+@[\w.\-]+\.[a-z]{2,})/i', $body, $bm)) {
                        foreach ($bm[1] as $candidate) {
                            $c = strtolower($candidate);
                            if (stripos($c, 'sr-homes') === false && stripos($c, 'hoelzl') === false &&
                                stripos($c, 'mailer-daemon') === false && stripos($c, 'postmaster') === false) {
                                $bounceRecipient = $c;
                                break;
                            }
                        }
                    }

                    // ── Letzte ausgehende Mail an diesen Empfänger finden ─────────────────
                    if ($bounceRecipient) {
                        // to_email kann "Name <email>" Format haben → LIKE-Suche
                        $lastOutbound = \DB::selectOne(
                            "SELECT pe.id, pe.stakeholder, pe.property_id, pe.subject, pe.to_email
                             FROM portal_emails pe
                             WHERE LOWER(pe.to_email) LIKE ?
                               AND pe.direction = 'outbound'
                               AND pe.property_id IS NOT NULL
                             ORDER BY pe.email_date DESC LIMIT 1",
                            ['%' . $bounceRecipient . '%']
                        );
                    }

                    // ── Bounce-Email zuerst speichern (source_email_id für Activity benötigt) ──
                    $bounceEmail = PortalEmail::create([
                        'message_id'      => $messageId,
                        'direction'       => 'inbound',
                        'from_email'      => $fromEmail,
                        'from_name'       => $fromName ?: 'MAILER-DAEMON',
                        'to_email'        => $to,
                        'subject'         => $subject,
                        'body_text'       => mb_substr($body, 0, 5000),
                        'email_date'      => $date,
                        'property_id'     => $lastOutbound->property_id ?? null,
                        'stakeholder'     => $lastOutbound->stakeholder ?? 'System',
                        'category'        => 'bounce',
                        'ai_summary'      => 'Bounce: E-Mail an ' . ($bounceRecipient ?? 'unbekannt') . ' konnte nicht zugestellt werden.',
                        'has_attachment'  => false,
                        'is_processed'    => true,
                        'imap_uid'        => $uid,
                        'imap_folder'     => $folderPath,
                        'account_id'      => $account->id,
                    ]);

                    // ── Bounce-Activity mit source_email_id verknüpfen ────────────────────
                    // WICHTIG: source_email_id gesetzt → last_has_email=1 → erscheint in "Unbeantwortet"!
                    if ($lastOutbound && $lastOutbound->property_id) {
                        Activity::create([
                            'property_id'    => $lastOutbound->property_id,
                            'activity_date'  => date('Y-m-d', strtotime($date)),
                            'stakeholder'    => $lastOutbound->stakeholder,
                            'activity'       => '⚠️ E-Mail unzustellbar: ' . ($bounceRecipient ?? 'unbekannt'),
                            'result'         => 'Zustellung fehlgeschlagen für ' . ($bounceRecipient ?? 'unbekannt') . '. Ursprünglicher Betreff: ' . ($lastOutbound->subject ?? 'unbekannt') . '. Bitte E-Mail-Adresse prüfen oder auf anderem Weg kontaktieren.',
                            'category'       => 'bounce',
                            'source_email_id'=> $bounceEmail->id,  // ← verknüpft! last_has_email = 1
                        ]);
                        \Log::info("[IMAP] Bounce: {$bounceRecipient} → Stakeholder '{$lastOutbound->stakeholder}' auf Property {$lastOutbound->property_id} zurück in Unbeantwortet");
                    } else {
                        \Log::warning("[IMAP] Bounce detected for " . ($bounceRecipient ?? 'unknown') . " but no outbound email found to link");
                    }

                    $totalCount++;
                    continue; // Skip normal processing
                }

                // Check attachments
                $hasAttachment = $this->hasAttachments($structure);
                $attachmentNames = $hasAttachment ? $this->getAttachmentNames($structure) : null;

                // Match property
                $propertyId = $this->matchProperty($subject, $body, $properties);

                // Multi-User: Fallback-Queries auf Properties des Account-Besitzers beschraenken
                $accountUserId = $account->user_id;
                $brokerScope = $accountUserId
                    ? "AND property_id IN (SELECT id FROM properties WHERE broker_id = {$accountUserId})"
                    : "";

                // For outbound emails: if no property match, try recipient-based lookup
                if (!$propertyId && $direction === 'outbound' && !$isInternalEmail) {
                    $toParsedForMatch = mailparse_rfc822_parse_addresses($to);
                    $recipientEmail = strtolower($toParsedForMatch[0]['address'] ?? '');
                    if ($recipientEmail) {
                        // 1. Check contacts table
                        $contactMatch = \DB::selectOne(
                            "SELECT property_ids FROM contacts WHERE email = ? LIMIT 1",
                            [$recipientEmail]
                        );
                        if ($contactMatch && $contactMatch->property_ids) {
                            $pids = json_decode($contactMatch->property_ids, true);
                            // Multi-User: nur Properties des eigenen Brokers
                            if ($accountUserId && !empty($pids)) {
                                $ownPids = \DB::select("SELECT id FROM properties WHERE id IN (" . implode(',', array_map('intval', $pids)) . ") AND broker_id = ?", [$accountUserId]);
                                $pids = array_map(fn($r) => $r->id, $ownPids);
                            }
                            if (!empty($pids)) {
                                $propertyId = (int) end($pids); // most recent property
                            }
                        }
                        
                        // 2. Check existing activities by stakeholder email
                        if (!$propertyId) {
                            $actMatch = \DB::selectOne(
                                "SELECT property_id FROM activities WHERE LOWER(stakeholder) = ? AND property_id IS NOT NULL {$brokerScope} ORDER BY id DESC LIMIT 1",
                                [$recipientEmail]
                            );
                            if ($actMatch) {
                                $propertyId = (int) $actMatch->property_id;
                            }
                        }
                        
                        // 3. Check previous portal_emails (inbound from this person)
                        if (!$propertyId) {
                            $emailMatch = \DB::selectOne(
                                "SELECT property_id FROM portal_emails WHERE LOWER(from_email) = ? AND property_id IS NOT NULL AND direction = 'inbound' {$brokerScope} ORDER BY id DESC LIMIT 1",
                                [$recipientEmail]
                            );
                            if ($emailMatch) {
                                $propertyId = (int) $emailMatch->property_id;
                            }
                        }

                        // 4. Check previous outbound emails to same recipient
                        if (!$propertyId) {
                            $prevOutMatch = \DB::selectOne(
                                "SELECT property_id FROM portal_emails WHERE LOWER(to_email) LIKE ? AND property_id IS NOT NULL AND direction = 'outbound' {$brokerScope} ORDER BY id DESC LIMIT 1",
                                ['%' . $recipientEmail . '%']
                            );
                            if ($prevOutMatch) {
                                $propertyId = (int) $prevOutMatch->property_id;
                            }
                        }

                        // 5. Cross-match: extract name from email, find matching stakeholder in activities
                        if (!$propertyId && preg_match('/^([a-zA-Z\x{00C0}-\x{024F}]+)[._-]([a-zA-Z\x{00C0}-\x{024F}]+)\d*@/iu', $recipientEmail, $em)) {
                            $eFn = mb_strtolower($em[1]);
                            $eLn = mb_strtolower($em[2]);
                            $namePattern = $eFn . '%' . $eLn . '%';
                            $actNameMatch = \DB::selectOne(
                                "SELECT property_id FROM activities WHERE LOWER(stakeholder) LIKE ? AND property_id IS NOT NULL {$brokerScope} ORDER BY id DESC LIMIT 1",
                                [$namePattern]
                            );
                            if ($actNameMatch) {
                                $propertyId = (int) $actNameMatch->property_id;
                            }
                        }

                        if ($propertyId) {
                            Log::info("[IMAP] Outbound recipient match: {$recipientEmail} -> property {$propertyId}");
                        }
                    }
                }

                // Check if sender is a property owner
                $isOwnerEmail = false;
                $ownerPropertyId = null;
                if ($direction === 'inbound') {
                    $ownerCheck = \DB::selectOne(
                        "SELECT p.id as property_id, c.name as owner_name FROM customers c JOIN properties p ON p.customer_id = c.id WHERE c.email = ? LIMIT 1",
                        [strtolower($fromEmail)]
                    );
                    if ($ownerCheck) {
                        $isOwnerEmail = true;
                        $ownerPropertyId = $ownerCheck->property_id;
                        if (!$propertyId) $propertyId = $ownerPropertyId;
                    }
                }

                // Inbound fallback: if still no property, look up prior emails from this sender
                // Handles replies where body/subject no longer contain ref_id
                if (!$propertyId && $direction === 'inbound' && $fromEmail && !$isInternalEmail) {
                    $senderEmail = strtolower($fromEmail);

                    // 1. Previous inbound email from same address with a known property
                    $prevIn = \DB::selectOne(
                        "SELECT property_id FROM portal_emails WHERE LOWER(from_email) = ? AND property_id IS NOT NULL {$brokerScope} ORDER BY id DESC LIMIT 1",
                        [$senderEmail]
                    );
                    if ($prevIn) {
                        $propertyId = (int) $prevIn->property_id;
                        Log::info("[IMAP] Inbound fallback (prev inbound): {$senderEmail} -> property {$propertyId}");
                    }

                    // 2. Previous outbound email sent TO this address
                    if (!$propertyId) {
                        $prevOut = \DB::selectOne(
                            "SELECT property_id FROM portal_emails WHERE LOWER(to_email) LIKE ? AND property_id IS NOT NULL AND direction = 'outbound' {$brokerScope} ORDER BY id DESC LIMIT 1",
                            ['%' . $senderEmail . '%']
                        );
                        if ($prevOut) {
                            $propertyId = (int) $prevOut->property_id;
                            Log::info("[IMAP] Inbound fallback (prev outbound to sender): {$senderEmail} -> property {$propertyId}");
                        }
                    }

                    // 3. Activities matching sender's email address as stakeholder
                    if (!$propertyId) {
                        $actMatch = \DB::selectOne(
                            "SELECT property_id FROM activities WHERE LOWER(stakeholder) = ? AND property_id IS NOT NULL {$brokerScope} ORDER BY id DESC LIMIT 1",
                            [$senderEmail]
                        );
                        if ($actMatch) {
                            $propertyId = (int) $actMatch->property_id;
                            Log::info("[IMAP] Inbound fallback (activity by email): {$senderEmail} -> property {$propertyId}");
                        }
                    }
                }

                // For outbound, parse recipient name
                $toName = '';
                if ($direction === 'outbound') {
                    $toParsed = mailparse_rfc822_parse_addresses($to);
                    $toName = $toParsed[0]['display'] ?? '';
                    $toEmail = $toParsed[0]['address'] ?? $to;
                    // If no display name, extract from email address
                    if (!$toName && $toEmail) {
                        $toName = ucfirst(explode('@', $toEmail)[0]);
                    }
                }

                // AI analysis — for outbound, tell AI about recipient
                $aiContactName = $direction === 'outbound' ? $toName : $fromName;
                // Build context for AI analysis
                $aiContext = [];

                // Thread context: last 5 messages for THIS SENDER + property
                // Filter by sender email so a busy property (many contacts) doesn't pollute context
                if ($propertyId && $fromEmail) {
                    $threadMsgs = \DB::select(
                        "SELECT pe.direction, pe.from_name, pe.subject, LEFT(pe.body_text, 500) as body_excerpt, pe.email_date
                         FROM portal_emails pe
                         WHERE pe.property_id = ?
                           AND (LOWER(pe.from_email) = LOWER(?) OR LOWER(pe.to_email) = LOWER(?))
                         ORDER BY pe.email_date DESC LIMIT 5",
                        [$propertyId, $fromEmail, $fromEmail]
                    );
                    if (!empty($threadMsgs)) {
                        $threadStr = "";
                        foreach (array_reverse($threadMsgs) as $tm) {
                            $dir = $tm->direction === "inbound" ? "EINGANG" : "AUSGANG";
                            $threadStr .= "[{$tm->email_date}] {$dir} von {$tm->from_name}: {$tm->subject}\n";
                            if ($tm->body_excerpt) $threadStr .= mb_substr(strip_tags($tm->body_excerpt), 0, 300) . "\n---\n";
                        }
                        $aiContext["thread"] = $threadStr;
                    }
                }

                // Owner info
                if ($isOwnerEmail) {
                    $aiContext["owner_info"] = "Der Absender ({$fromEmail}) ist der EIGENTÜMER des zugeordneten Objekts.";
                }

                // Enhance properties with owner data for AI
                $enrichedProperties = [];
                foreach ($properties as $p) {
                    $ep = $p;
                    $owner = \DB::selectOne("SELECT c.name, c.email FROM customers c JOIN properties p ON p.customer_id = c.id WHERE p.id = ?", [$p["id"]]);
                    if ($owner) {
                        $ep["owner_name"] = $owner->name;
                        $ep["owner_email"] = $owner->email;
                    }
                    $enrichedProperties[] = $ep;
                }

                $analysis = $this->ai->analyzeEmail($subject, $body, $aiContactName, $enrichedProperties, $aiContext);

                // Stakeholder: for outbound = recipient, for inbound = sender
                $stakeholder = $direction === 'outbound'
                    ? ($toName ?: ($analysis['stakeholder'] ?? $to))
                    : ($analysis['stakeholder'] ?? $fromName);
                
                // For notification/system emails: extract real person from subject if AI missed it
                $systemFromPatterns = ['notification', 'noreply', 'no-reply', 'typeform', 'followups.typeform', 'calendly', 'mailer-daemon'];
                $isSystemSender = false;
                foreach ($systemFromPatterns as $sp) {
                    if (stripos($fromEmail, $sp) !== false) { $isSystemSender = true; break; }
                }
                if ($isSystemSender && ($stakeholder === $fromName || stripos($stakeholder, 'notification') !== false || stripos($stakeholder, 'typeform') !== false || stripos($stakeholder, 'noreply') !== false)) {
                    // Try extract name from subject pattern "... : PersonName"
                    if (preg_match('/:\s*([A-ZÄÖÜ\x{00C0}-\x{024F}][a-zäöüß\x{00E0}-\x{024F}]+(?:[\s-][A-ZÄÖÜ\x{00C0}-\x{024F}]?[a-zäöüß\x{00E0}-\x{024F}]+)*)\s*$/u', $subject, $nameMatch)) {
                        $stakeholder = trim($nameMatch[1]);
                    }
                }
                

                // For willhaben emails: extract real name, email and phone from body
                if (stripos($fromEmail, 'willhaben') !== false && $direction === 'inbound') {
                    // willhaben masks names (e.g. "E. & G. K.") but body has real name in "Anfragetext" and contact details
                    // Extract real name from Anfragetext signature or contact details
                    $realName = null;
                    // Pattern 1: "Mit freundlichen Grüßen\nVorname Nachname" in Anfragetext
                    if (preg_match('/(?:Mit freundlichen Gr(?:ü|ue)(?:ß|ss)en|MfG|Liebe Gr(?:ü|ue)(?:ß|ss)e|Beste Gr(?:ü|ue)(?:ß|ss)e|LG)[\s\n\r]+([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+)+)/iu', $body, $nm)) {
                        $candidate = trim($nm[1]);
                        // Exclude system/platform signatures like "Dein willhaben-Team", "Ihr immowelt Team"
                        if (!preg_match('/(willhaben|immowelt|typeform|team|redaktion|kundenservice|support)/i', $candidate)) {
                            $realName = $candidate;
                        }
                    }
                    // Pattern 2: Willhaben contact details "Vorname: X\nNachname: Y"
                    if (!$realName && preg_match('/Vorname[:\s]+([a-zA-ZäöüÄÖÜß\-]+)/i', $body, $vnm) && preg_match('/Nachname[:\s]+([a-zA-ZäöüÄÖÜß.\- ]+)/i', $body, $nnm)) {
                        $vn = trim($vnm[1]);
                        $nn = trim($nnm[1]);
                        // Only use if not abbreviated (willhaben sometimes abbreviates: "E. & G." / "K.")
                        if (strlen($vn) > 2 && substr($vn, -1) !== '.') {
                            $realName = mb_convert_case($vn, MB_CASE_TITLE, 'UTF-8') . ' ' . mb_convert_case($nn, MB_CASE_TITLE, 'UTF-8');
                        }
                    }
                    // Pattern 3: stakeholder is still system name - force extraction
                    if (!$realName && preg_match('/(?:Dein willhaben|Kontaktdaten|willhaben-User)/i', $stakeholder)) {
                        // Last resort: use from_name if it looks like a person name (not a system name)
                        $fn = $fromName ?? '';
                        if ($fn && !preg_match('/(willhaben|notification|noreply|system|kontakt)/i', $fn) && mb_strlen($fn) > 3) {
                            $realName = mb_convert_case($fn, MB_CASE_TITLE, 'UTF-8');
                        }
                    }
                    if ($realName && $realName !== $stakeholder) {
                        Log::info("[IMAP] Willhaben real name extracted: '{$realName}' (was '{$stakeholder}')");
                        $stakeholder = $realName;
                    }
                    // Also extract real email from body
                    if (preg_match('/E-Mail[:\s]+(\S+@\S+\.\S+)/i', $body, $em)) {
                        $willhabenRealEmail = strtolower(trim($em[1]));
                        // Store for later contact creation
                        Log::info("[IMAP] Willhaben real email: {$willhabenRealEmail}");
                    }
                }

                // Detect forwarded prospect inquiries from business partners
                $isForwardedInquiry = false;
                $partnerDomains = ['projekt-hoch3.at', 'projekt-hoch-3.at'];
                $isPartnerEmail = false;
                foreach ($partnerDomains as $pd) {
                    if (stripos($fromEmail, $pd) !== false) { $isPartnerEmail = true; break; }
                }
                if ($isPartnerEmail && $direction === 'inbound') {
                    // Check for forwarding indicators
                    $forwardPatterns = [
                        '/^(WG|Fwd|FW):/i',  // Subject starts with WG: / Fwd: / FW:
                    ];
                    $bodyForwardPatterns = [
                        '/(?:leite|sende)\s+(?:ich\s+)?(?:Dir|Ihnen)\s+(?:eine?\s+)?(?:Anfrage|Interessenten)/i',
                        '/Interessenten-Anfrage/i',
                        '/Anfrage\s+(?:für|f(?:ü|ue)r|weiter)/i',
                    ];
                    
                    $subjectIsForward = false;
                    foreach ($forwardPatterns as $fp) {
                        if (preg_match($fp, $subject)) { $subjectIsForward = true; break; }
                    }
                    $bodyIsForward = false;
                    $bodySnippet = mb_substr($body, 0, 1000);
                    foreach ($bodyForwardPatterns as $bp) {
                        if (preg_match($bp, $bodySnippet)) { $bodyIsForward = true; break; }
                    }
                    
                    if ($subjectIsForward && $bodyIsForward) {
                        $isForwardedInquiry = true;
                        // Override category to 'anfrage' (AI often returns 'sonstiges' for these)
                        $analysis['category'] = 'anfrage';
                        // Ensure stakeholder is the prospect, not the partner employee
                        // AI usually extracts the correct prospect name, but verify it's not the sender
                        if ($stakeholder === $fromName || stripos($stakeholder, 'projekt') !== false || stripos($stakeholder, 'theresa') !== false || stripos($stakeholder, 'reichl') !== false) {
                            // AI failed to extract prospect - try from body
                            // Pattern: "Anfrage von NAME" or name after forwarded content
                            if (preg_match('/(?:Anfrage\s+von|Name|Vorname)[:\s]+([A-ZÄÖÜ\x{00C0}-\x{024F}][a-zäöüß\x{00E0}-\x{024F}]+(?:\s+[A-ZÄÖÜ\x{00C0}-\x{024F}][a-zäöüß\x{00E0}-\x{024F}]+)+)/u', $body, $prospectMatch)) {
                                $stakeholder = trim($prospectMatch[1]);
                            }
                        }
                        Log::info("[IMAP] Forwarded inquiry detected from partner {$fromEmail}: stakeholder={$stakeholder}, subject={$subject}");
                    }

                    // Extract prospect's personal email from the forwarded body
                    $prospectEmail = null;
                    $bodyFlat = preg_replace('/\s+/', ' ', $body);
                    $excludePatterns = ['projekt-hoch3', 'projekt-hoch-3', 'sr-homes', 'hoelzl', 'jimdo', 'noreply', 'no-reply'];
                    // Try E-Mail: pattern first
                    if (preg_match_all('/(?:e-?mail|E-?Mail)[=:\s]*(\S+@\S+\.\S{2,})/i', $bodyFlat, $emailMatches)) {
                        foreach ($emailMatches[1] as $em) {
                            $emLower = strtolower($em);
                            $isExcluded = false;
                            foreach ($excludePatterns as $ep) {
                                if (stripos($emLower, $ep) !== false) { $isExcluded = true; break; }
                            }
                            if (!$isExcluded) { $prospectEmail = $emLower; break; }
                        }
                    }
                    // Fallback: any non-excluded email in body
                    if (!$prospectEmail && preg_match_all('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $body, $allEmails)) {
                        foreach ($allEmails[0] as $em) {
                            $emLower = strtolower($em);
                            $isExcluded = false;
                            foreach ($excludePatterns as $ep) {
                                if (stripos($emLower, $ep) !== false) { $isExcluded = true; break; }
                            }
                            if (!$isExcluded) { $prospectEmail = $emLower; break; }
                        }
                    }
                    if ($prospectEmail) {
                        Log::info("[IMAP] Forwarded inquiry prospect email: {$prospectEmail} for {$stakeholder}");
                    }
                }

                // For internal emails: prefer AI-extracted stakeholder (the real client)
                if ($isInternalEmail && !empty($analysis['stakeholder'])) {
                    $candidate = $analysis['stakeholder'];
                    // Only use if it's not an sr-homes person
                    if (stripos($candidate, 'sr-homes') === false && stripos($candidate, 'hoelzl') === false && stripos($candidate, 'renzl') === false) {
                        $stakeholder = $candidate;
                    } else {
                        $stakeholder = $fromName ?: $fromEmail;
                    }
                }

                // Never use own name as stakeholder (for non-internal)
                if (!($isInternalEmail) && (stripos($stakeholder, 'hoelzl') !== false || stripos($stakeholder, 'sr-homes') !== false || stripos($stakeholder, 'Maximilian') !== false)) {
                    $stakeholder = $direction === 'outbound' ? ($toName ?: $to) : ($fromName ?: $from);
                }

                // Save email (catch duplicate message_id from multi-account setups)
                try {
                $email = PortalEmail::create([
                    'message_id' => $messageId,
                    'direction' => $direction,
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'to_email' => $to,
                    'subject' => $subject,
                    'body_text' => $body,
                    'email_date' => $date,
                    'property_id' => $propertyId ?? ($analysis['suggested_property_ref_id']
                        ? Property::where('ref_id', $analysis['suggested_property_ref_id'])->value('id')
                        : null),
                    'stakeholder' => $stakeholder,
                    'category' => $isOwnerEmail ? ($analysis['category'] ?? 'eigentuemer') : ($isInternalEmail ? 'intern' : ($direction === 'outbound' ? 'email-out' : ($analysis['category'] ?? 'sonstiges'))),
                    'ai_summary' => $analysis['summary'] ?? null,
                    'sentiment' => $analysis['sentiment'] ?? null,
                    'key_facts' => !empty($analysis['key_facts']) ? json_encode($analysis['key_facts'], JSON_UNESCAPED_UNICODE) : null,
                    'action_required' => $analysis['action_required'] ?? null,
                    'has_attachment' => $hasAttachment,
                    'attachment_names' => $attachmentNames,
                    'is_processed' => true,
                    'imap_uid' => $uid,
                    'imap_folder' => $folderPath,
                    'account_id' => $account->id,
                ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'Duplicate entry')) {
                        Log::info("[IMAP] Skipping duplicate message_id: {$messageId} on account {$account->email_address}");
                        continue;
                    }
                    throw $e;
                }

                // Create activity if property matched
                // Internal emails (sr-homes <-> hoelzl): save portal_email with category='intern', but NO activity
                if ($email->property_id && !$isInternalEmail) {
                    if ($direction === 'outbound') {
                        $activityText = "E-Mail gesendet: {$subject}";
                    } elseif ($isOwnerEmail) {
                        $activityText = "Eigentümer-Nachricht: {$subject}";
                    } else {
                        $activityText = match($email->category) {
                            'kaufanbot'    => "Kaufanbot eingegangen: {$subject}",
                            'besichtigung' => "Besichtigungsanfrage: {$subject}",
                            'absage'       => "Absage erhalten: {$subject}",
                            default        => "Anfrage erhalten: {$subject}",
                        };
                    }
                    
                    // Detect category: use AI category for specific types, else check first contact
                    $aiCategory = $email->category;
                    // 'anfrage' hier inkludiert: wenn KI es als Erstanfrage erkennt (z.B. Typeform),
                    // wird dieser Wert direkt übernommen ohne Nachnamen-Heuristik
                    $specificCategories = ['kaufanbot', 'besichtigung', 'absage', 'anfrage'];
                    if ($isOwnerEmail) {
                        // Owner emails get special category unless AI detected something specific
                        $inboundCategory = in_array($aiCategory, $specificCategories) ? $aiCategory : 'eigentuemer';
                    } elseif ($isInternalEmail) {
                        $inboundCategory = in_array($aiCategory, $specificCategories) ? $aiCategory : 'sonstiges';
                    } else {
                        $inboundCategory = in_array($aiCategory, $specificCategories) ? $aiCategory : 'email-in';
                    }
                    if ($direction === 'inbound' && !$isOwnerEmail) {
                        if ($isPartnerEmail && !$isForwardedInquiry) {
                            // Partner-Mail: Check if it's a Kaufanbot forwarded by the partner (e.g. Bauträger)
                            $isPartnerKaufanbot = false;
                            if ($aiCategory === 'kaufanbot' || preg_match('/kaufanbot|kaufangebot|kaufofferte|gegengezeichnet/i', $subject . ' ' . mb_substr($body, 0, 500))) {
                                // This is a Kaufanbot forwarded/countersigned by the partner
                                // Try to extract the real buyer name from the email
                                $buyerName = null;
                                $bodySnip = mb_substr($body, 0, 2000);
                                // Pattern: "Kaufanbot Top X" → look for buyer name in subject or body
                                // AI analysis should have the right stakeholder if it found the buyer
                                if (!empty($analysis['stakeholder']) && stripos($analysis['stakeholder'], 'gigl') === false && stripos($analysis['stakeholder'], 'projekt') === false) {
                                    $buyerName = $analysis['stakeholder'];
                                }
                                // Try regex patterns for buyer names in kaufanbot docs
                                if (!$buyerName && preg_match('/(?:K(?:ä|ae)ufer|Anbotleger|Bieter)[:\s]+([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+)+)/u', $bodySnip, $bm)) {
                                    $buyerName = trim($bm[1]);
                                }
                                
                                if ($buyerName) {
                                    // Check if buyer already has a kaufanbot activity for this property
                                    $normSH = \App\Helpers\StakeholderHelper::normSH('stakeholder');
                                    $normBuyer = \App\Helpers\StakeholderHelper::normSH("'" . addslashes($buyerName) . "'");
                                    $existingKaufanbot = \DB::selectOne(
                                        "SELECT id FROM activities WHERE property_id = ? AND {$normSH} = {$normBuyer} AND category = 'kaufanbot' LIMIT 1",
                                        [$email->property_id]
                                    );
                                    if ($existingKaufanbot) {
                                        // Buyer already has kaufanbot → mark as partner update
                                        $inboundCategory = 'partner';
                                        $stakeholder = $buyerName;
                                        \Log::info("[IMAP] Partner Kaufanbot from {$fromEmail}: buyer={$buyerName} already has kaufanbot → category=partner");
                                    } else {
                                        // New kaufanbot from buyer via partner → create kaufanbot for the buyer
                                        $inboundCategory = 'kaufanbot';
                                        $stakeholder = $buyerName;
                                        \Log::info("[IMAP] Partner Kaufanbot from {$fromEmail}: NEW kaufanbot for buyer={$buyerName}");
                                    }
                                    $isPartnerKaufanbot = true;
                                } else {
                                    // Could not extract buyer → keep as partner category but mark as kaufanbot
                                    $inboundCategory = 'kaufanbot';
                                    \Log::info("[IMAP] Partner Kaufanbot from {$fromEmail}: could not extract buyer name, keeping as kaufanbot");
                                    $isPartnerKaufanbot = true;
                                }
                            }
                            if (!$isPartnerKaufanbot) {
                                // Regular partner mail (not kaufanbot)
                                $inboundCategory = 'partner';
                            }
                            \Log::info("[IMAP] Partner mail from {$fromEmail} → category={$inboundCategory}");
                        } elseif (!$isSystemSender && !empty($fromEmail)) {
                            // Stage 0: Email-Adresse als eindeutiger Identifier
                            // Email ist weltweit eindeutig — zuverlässiger als jeder Namens-Vergleich.
                            // Gilt nur für direkte Mails (nicht typeform/willhaben/noreply).
                            $priorEmailCount = \DB::selectOne(
                                "SELECT COUNT(*) as cnt FROM portal_emails
                                 WHERE property_id = ? AND direction = 'inbound' AND from_email = ? AND id != ?",
                                [$email->property_id, $fromEmail, $email->id]
                            );
                            // Stage 0b: Wir haben bereits eine ausgehende Mail an diese Adresse geschickt
                            // → kein Erstkontakt, auch wenn die Erstanfrage über Plattform (immowelt/willhaben) kam
                            $priorOutboundCount = ($priorEmailCount->cnt ?? 0) === 0
                                ? \DB::selectOne(
                                    "SELECT COUNT(*) as cnt FROM portal_emails
                                     WHERE property_id = ? AND direction = 'outbound' AND LOWER(to_email) LIKE ?",
                                    [$email->property_id, '%' . strtolower($fromEmail) . '%']
                                )
                                : null;
                            // Stage 0c: Stakeholder-Name bereits in activities → kein Erstkontakt
                            $priorActivityByName = (($priorEmailCount->cnt ?? 0) === 0 && ($priorOutboundCount->cnt ?? 0) === 0 && !empty($stakeholder))
                                ? \DB::selectOne(
                                    "SELECT COUNT(*) as cnt FROM activities
                                     WHERE property_id = ? AND LOWER(stakeholder) LIKE ?",
                                    [$email->property_id, '%' . mb_strtolower($stakeholder) . '%']
                                )
                                : null;

                            $isFirstContact = ($priorEmailCount->cnt ?? 0) === 0
                                && ($priorOutboundCount->cnt ?? 0) === 0
                                && ($priorActivityByName->cnt ?? 0) === 0;

                            if ($isFirstContact) {
                                $inboundCategory = 'anfrage';
                                \Log::info("[IMAP] Stage 0 (email): First contact for {$fromEmail} on property {$email->property_id} → anfrage");
                            } else {
                                \Log::info("[IMAP] Stage 0 (email): Prior contact confirmed for {$fromEmail} on property {$email->property_id} (in=" . ($priorEmailCount->cnt ?? 0) . " out=" . ($priorOutboundCount->cnt ?? 0) . " act=" . ($priorActivityByName->cnt ?? 0) . ") → email-in");
                            }
                        } else {
                            // Stage 1: Full name match (Fallback für System-/Platform-Mails wie Typeform, willhaben)
                            $normSH = \App\Helpers\StakeholderHelper::normSH('stakeholder');
                            $normInput = \App\Helpers\StakeholderHelper::normSH("'" . addslashes($stakeholder) . "'");
                            $priorContact = \DB::selectOne(
                                "SELECT COUNT(*) as cnt FROM activities WHERE property_id = ? AND {$normSH} = {$normInput}",
                                [$email->property_id]
                            );
                            if (($priorContact->cnt ?? 0) === 0) {
                                // Stage 2: Full-Name-Substring-Fallback (catches "Häckl" matching "Häckl-Haas")
                                $lcStakeholder = mb_strtolower($stakeholder);
                                $surnameCheck = \DB::selectOne(
                                    "SELECT COUNT(*) as cnt FROM activities
                                     WHERE property_id = ?
                                       AND stakeholder != ''
                                       AND (
                                           LOWER(?) LIKE CONCAT('%', LOWER(stakeholder), '%')
                                           OR LOWER(stakeholder) LIKE CONCAT('%', LOWER(?), '%')
                                       )",
                                    [$email->property_id, $lcStakeholder, $lcStakeholder]
                                );
                                if (($surnameCheck->cnt ?? 0) === 0) {
                                    $inboundCategory = 'anfrage';
                                } else {
                                    \Log::info("[IMAP] Stage 2 (name-substring): '{$stakeholder}' has prior contact on property {$email->property_id}");
                                }
                            }
                        }
                    }

                    $finalCategory = $isInternalEmail ? $inboundCategory : ($direction === 'inbound' ? $inboundCategory : 'email-out');

                    $newActivity = Activity::create([
                        'property_id' => $email->property_id,
                        'activity_date' => date('Y-m-d', strtotime($date)),
                        'stakeholder' => $stakeholder,
                        'activity' => $activityText,
                        'result' => $email->ai_summary,
                        'category' => $finalCategory,
                        'source_email_id' => $email->id,
                    ]);

                    // Sync portal_email.category with the final determined category
                    // so all views (Posteingang, Unbeantwortet, Aktivitätsprotokoll) show the same status
                    if ($email->category !== $finalCategory) {
                        $email->category = $finalCategory;
                        $email->save();
                    }

                    // Extract real prospect email from platform mails and save to contacts
                    if ($direction === 'inbound') {
                        $this->extractAndSaveProspectEmail($stakeholder, $fromEmail, $body, $email->property_id);
                        // Dynamically update lead profile from every inbound email
                        $this->updateLeadProfileFromEmail($stakeholder, $fromEmail, $body, $subject, $email->property_id);
                    }

                    // Auto-update Kaufanbot knowledge when a new kaufanbot comes in
                    if ($finalCategory === 'kaufanbot' && $email->property_id) {
                        try {
                            $this->updateKaufanbotKnowledge($email->property_id);
                        } catch (\Throwable $e) {
                            Log::warning("updateKaufanbotKnowledge failed: " . $e->getMessage());
                        }
                    }

                    // Auto-extract knowledge from inbound emails with substantial content
                    if ($direction === 'inbound' && strlen($body) > 100) {
                        try {
                            $this->autoExtractFromEmail($email->id, $email->property_id, $body, $subject);
                        } catch (\Throwable $e) {
                            Log::warning("autoExtractFromEmail failed for email {$email->id}: " . $e->getMessage());
                        }
                    }

                    // Auto-generate AI reply draft for inbound customer emails
                    $draftCategories = ['anfrage', 'email-in', 'follow-up', 'besichtigung', 'kaufanbot', 'rückfrage', 'eigentuemer', 'partner'];
                    if ($direction === 'inbound' && in_array($finalCategory, $draftCategories) && isset($newActivity)) {
                        try {
                            $this->autoGenerateDraft($newActivity->id, $email->property_id, $stakeholder, $email->id);
                        } catch (\Throwable $e) {
                            Log::warning("autoGenerateDraft failed for activity {$newActivity->id}: " . $e->getMessage());
                        }
                    }

                    // AUTO-REPLY: Send automatic reply to Erstanfragen with Expose + BaB
                    if ($direction === 'inbound' && $finalCategory === 'anfrage' && $email->property_id && isset($newActivity)) {
                        try {
                            $this->autoReplyToErstanfrage($email, $newActivity, $stakeholder, $account);
                        } catch (\Throwable $e) {
                            Log::warning("autoReply failed for email {$email->id}: " . $e->getMessage());
                        }
                    }
                }

                $totalCount++;
            }
            
            // Update last_uid for this folder
            if ($maxUid > $lastUid) {
                $account->update([$lastUidField => $maxUid]);
            }
            
            imap_close($mailbox);
        }
        
        $account->update(['last_fetch_at' => now()]);
        
        return $totalCount;
    }

    private function matchProperty(string $subject, string $body, array $properties): ?int
    {
        $text = $subject . ' ' . $body;
        // Normalize: strip spaces/hyphens/underscores for fuzzy ref_id matching
        $textNorm = preg_replace('/[\s\-_]+/', '', $text);
        foreach ($properties as $p) {
            if (empty($p['ref_id'])) continue;
            // Exact match (case-insensitive)
            if (stripos($text, $p['ref_id']) !== false) {
                return $p['id'];
            }
            // Normalized match: "The37" matches "THE 37", "the-37", etc.
            $refNorm = preg_replace('/[\s\-_]+/', '', $p['ref_id']);
            if (stripos($textNorm, $refNorm) !== false) {
                return $p['id'];
            }
        }
        return $this->aiMatchProperty($subject, $body, $this->currentBrokerId ?? null);
    }

    private function aiMatchProperty(string $subject, string $body, ?int $brokerId = null): ?int
    {
        $brokerWhere = $brokerId ? "WHERE p.broker_id = {$brokerId}" : "";
        $properties = \Illuminate\Support\Facades\DB::select("
            SELECT p.id, p.ref_id, p.address, p.city, p.zip, p.object_type, c.name as owner_name
            FROM properties p
            LEFT JOIN customers c ON c.id = p.customer_id
            {$brokerWhere}
        ");

        if (empty($properties)) {
            return null;
        }

        $propList = '';
        foreach ($properties as $p) {
            $propList .= "ID={$p->id} | Ref={$p->ref_id} | {$p->address}, {$p->city} | Eigentuemer: {$p->owner_name}\n";
        }

        $snippet = mb_substr(strip_tags($body), 0, 1500);

        $system = 'Du bist ein Zuordnungs-Assistent fuer SR-Homes Immobilien. Ordne eingehende Emails dem richtigen Objekt zu.

Analysiere den Email-Betreff und -Text. Suche nach Hinweisen wie:
- Eigentuemer-Nachnamen (z.B. Weidinger -> Christa Weidinger)
- Adressen, Strassennamen, Ortsteile, Stadtteile
- Ref-IDs (z.B. Kau-Hau-Ste-01)
- Objekt-Beschreibungen die auf ein bestimmtes Objekt passen
- Implizite Hinweise (z.B. die Wohnung in Leopoldskron -> Liechtensteinstrasse, Salzburg)

Antworte NUR mit einem JSON-Objekt: {"property_id": <number oder null>, "confidence": "high|medium|low", "reason": "kurze Begruendung"}
Wenn kein Objekt passt, setze property_id auf null.';

        $user = "Betreff: {$subject}\n\nEmail-Text:\n{$snippet}\n\nVerfuegbare Objekte:\n{$propList}";

        try {
            $result = $this->ai->chatJson($system, $user, 300);
            if ($result && !empty($result['property_id']) && $result['confidence'] !== 'low') {
                $validIds = array_column(array_map(fn($p) => (array) $p, $properties), 'id');
                if (in_array((int) $result['property_id'], $validIds)) {
                    Log::info("AI property match: email '{$subject}' -> property {$result['property_id']} ({$result['confidence']}): {$result['reason']}");
                    return (int) $result['property_id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("AI property match failed: " . $e->getMessage());
        }

        return null;
    }

    private function getBody($mailbox, int $uid): string
    {
        $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
        $body = $this->extractTextFromStructure($mailbox, $uid, $structure);
        // Protect email addresses in angle brackets before strip_tags
        // <email@domain.com> looks like an HTML tag to strip_tags() and gets removed
        $body = preg_replace('/<([\w.+-]+@[\w.-]+\.[a-z]{2,})>/i', ' $1 ', $body);
        $body = strip_tags($body);
        return mb_substr(trim($body), 0, 50000);
    }

    /**
     * Recursively find and decode the text/plain part from MIME structure.
     */
    private function extractTextFromStructure($mailbox, int $uid, $structure, string $partNumber = ''): string
    {
        // Simple message (not multipart)
        if (empty($structure->parts)) {
            $body = $partNumber
                ? imap_fetchbody($mailbox, $uid, $partNumber, FT_UID)
                : imap_body($mailbox, $uid, FT_UID);
            return $this->decodeBody($body, $structure->encoding ?? 0);
        }

        // Multipart: find text/plain first, then text/html as fallback
        $textPart = null;
        $htmlPart = null;

        foreach ($structure->parts as $index => $part) {
            $pNum = $partNumber ? ($partNumber . '.' . ($index + 1)) : (string)($index + 1);
            $subtype = strtolower($part->subtype ?? '');

            if (isset($part->parts)) {
                // Nested multipart - recurse
                $nested = $this->extractTextFromStructure($mailbox, $uid, $part, $pNum);
                if (trim($nested)) return $nested;
            } elseif ($part->type === 0) { // type 0 = text
                if ($subtype === 'plain' && !$textPart) {
                    $textPart = ['num' => $pNum, 'encoding' => $part->encoding ?? 0];
                } elseif ($subtype === 'html' && !$htmlPart) {
                    $htmlPart = ['num' => $pNum, 'encoding' => $part->encoding ?? 0];
                }
            }
        }

        if ($textPart) {
            $body = imap_fetchbody($mailbox, $uid, $textPart['num'], FT_UID);
            return $this->decodeBody($body, $textPart['encoding']);
        }
        if ($htmlPart) {
            $body = imap_fetchbody($mailbox, $uid, $htmlPart['num'], FT_UID);
            $decoded = $this->decodeBody($body, $htmlPart['encoding']);
            // Protect email addresses in angle brackets before stripping
            $decoded = preg_replace('/<([\w.+-]+@[\w.-]+\.[a-z]{2,})>/i', ' $1 ', $decoded);
            return strip_tags($decoded);
        }

        // Last resort: fetch part 1 raw
        $body = imap_fetchbody($mailbox, $uid, '1', FT_UID);
        return quoted_printable_decode($body);
    }

    /**
     * Decode email body based on encoding type.
     */
    private function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            0 => $body,                         // 7BIT
            1 => $body,                         // 8BIT
            2 => $body,                         // BINARY
            3 => base64_decode($body),          // BASE64
            4 => quoted_printable_decode($body), // QUOTED-PRINTABLE
            default => $body,
        };
    }

    private function hasAttachments($structure): bool
    {
        if (!isset($structure->parts)) return false;
        foreach ($structure->parts as $part) {
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                return true;
            }
        }
        return false;
    }

    private function getAttachmentNames($structure): ?string
    {
        $names = [];
        if (!isset($structure->parts)) return null;
        foreach ($structure->parts as $part) {
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                $name = '';
                if (isset($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $name = imap_utf8($param->value);
                        }
                    }
                }
                if ($name) $names[] = $name;
            }
        }
        return $names ? implode(', ', $names) : null;
    }

    /**
     * Auto-extract factual knowledge from an inbound email and store in property_knowledge.
     * Lightweight call with max 500 tokens.
     */

    /**
     * Auto-update Kaufanbot knowledge base entry when a new Kaufanbot is recorded.
     * Uses AI to extract unit numbers from all kaufanbot activities, then builds a
     * consolidated status overview per property.
     */
    private function updateKaufanbotKnowledge(int $propertyId): void
    {
        // Get all kaufanbot activities for this property
        $kaufanbote = \Illuminate\Support\Facades\DB::select(
            "SELECT stakeholder, activity_date, activity, result FROM activities WHERE property_id = ? AND category = 'kaufanbot' ORDER BY activity_date ASC",
            [$propertyId]
        );

        if (empty($kaufanbote)) return;

        // Get property info
        $prop = \Illuminate\Support\Facades\DB::selectOne("SELECT ref_id, address FROM properties WHERE id = ?", [$propertyId]);
        if (!$prop) return;

        // Get price list from KB if available
        $prices = \Illuminate\Support\Facades\DB::select(
            "SELECT content FROM property_knowledge WHERE property_id = ? AND is_active = 1 AND title LIKE '%Verkaufspreise%'",
            [$propertyId]
        );
        $priceContext = implode("\n", array_map(fn($p) => $p->content, $prices));

        // Build activity text for AI
        $actText = '';
        foreach ($kaufanbote as $ka) {
            $ka = (array) $ka;
            $actText .= "{$ka['activity_date']} | {$ka['stakeholder']} | {$ka['activity']}";
            if ($ka['result']) $actText .= " → {$ka['result']}";
            $actText .= "\n";
        }

        // Ask AI to extract unit-level status
$system = 'Du analysierst Kaufanbot-Aktivitäten einer Immobilie und erstellst eine Statusübersicht. Antworte NUR als JSON mit diesem Format: {"sold_units": [{"unit": "Top X", "buyer": "Name", "date": "YYYY-MM-DD", "status": "unterschrieben|gegengezeichnet|in Bearbeitung", "details": "kurze Info"}], "summary": "Zusammenfassung in 1-2 Saetzen"}';

        $user = "Objekt: {$prop->ref_id} ({$prop->address})\n\nKAUFANBOT-AKTIVITÄTEN:\n{$actText}\n\nPREISLISTE:\n{$priceContext}\n\nExtrahiere alle Einheiten (Top X) die verkauft sind oder ein Kaufanbot haben.";

        $result = $this->ai->chatJson($system, $user, 1000);
        if (!$result || empty($result['sold_units'])) return;

        // Build KB content
        $lines = ["VERKAUFT / KAUFANBOT VORLIEGEND:"];
        foreach ($result['sold_units'] as $unit) {
            $line = "- {$unit['unit']}";
            if (!empty($unit['buyer'])) $line .= ": Käufer {$unit['buyer']}";
            if (!empty($unit['status'])) $line .= " ({$unit['status']})";
            if (!empty($unit['date'])) $line .= ", {$unit['date']}";
            if (!empty($unit['details'])) $line .= ". {$unit['details']}";
            $lines[] = $line;
        }
        if (!empty($result['summary'])) {
            $lines[] = "";
            $lines[] = $result['summary'];
        }
        $lines[] = "";
        $lines[] = "WICHTIG: Bei Anfragen auf verkaufte/reservierte Einheiten IMMER informieren dass diese vergeben ist, und eine vergleichbare Alternative im gleichen Stockwerk oder Preissegment vorschlagen.";

        $kbContent = implode("\n", $lines);
        $kbTitle = "Verkaufsstatus Einheiten {$prop->ref_id} (Stand " . date('F Y') . ")";

        // Upsert: replace existing or insert new
        $existing = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT id FROM property_knowledge WHERE property_id = ? AND is_active = 1 AND title LIKE 'Verkaufsstatus Einheiten%' LIMIT 1",
            [$propertyId]
        );

        if ($existing) {
            \Illuminate\Support\Facades\DB::table('property_knowledge')
                ->where('id', $existing->id)
                ->update([
                    'title' => $kbTitle,
                    'content' => $kbContent,
                    'confidence' => 'high',
                    'is_verified' => 1,
                    'updated_at' => now(),
                ]);
            Log::info("updateKaufanbotKnowledge: updated KB entry #{$existing->id} for property {$propertyId}");
        } else {
            \Illuminate\Support\Facades\DB::table('property_knowledge')->insert([
                'property_id' => $propertyId,
                'category' => 'verhandlung',
                'title' => $kbTitle,
                'content' => $kbContent,
                'source_type' => 'auto_kaufanbot',
                'source_description' => 'Automatisch aus Kaufanbot-Aktivitäten generiert',
                'confidence' => 'high',
                'is_verified' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info("updateKaufanbotKnowledge: created new KB entry for property {$propertyId}");
        }
    }

    /**
     * Auto-generate an AI reply draft after a new inbound activity is created.
     */
    /**
     * Auto-reply to Erstanfragen with Expose + BaB attached
     */
    private function autoReplyToErstanfrage($email, $activity, string $stakeholder, $account): void
    {
        // Check if auto-reply is enabled
        $settings = \Illuminate\Support\Facades\DB::table('admin_settings')->first();
        if (!$settings || !$settings->auto_reply_enabled) {
            Log::info("autoReply: disabled, skipping email {$email->id}");
            return;
        }

        // Check property whitelist (if set, only reply for whitelisted properties)
        $allowedIds = [];
        if (!empty($settings->auto_reply_property_ids)) {
            $allowedIds = array_map('intval', array_filter(explode(',', $settings->auto_reply_property_ids)));
        }
        if (!empty($allowedIds) && !in_array((int)$email->property_id, $allowedIds)) {
            Log::info("autoReply: property {$email->property_id} not in whitelist, skipping");
            \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                'activity_id' => $activity->id ?? null,
                'email_id' => $email->id,
                'property_id' => $email->property_id,
                'stakeholder' => $stakeholder,
                'to_email' => $email->from_email ?? '',
                'subject' => 'skipped',
                'status' => 'skipped',
                'error_message' => 'Property not in auto-reply whitelist',
            ]);
            return;
        }

        // Resolve real email address (platform emails like willhaben/immowelt use noreply sender)
        $fromEmail = strtolower($email->from_email ?? '');
        $realEmail = $fromEmail;
        $realName = $stakeholder;

        $isPlatformSender = (bool) preg_match('/(noreply|no-reply|mailer-daemon|postmaster|notification|willhaben|immowelt|typeform)/', $fromEmail);
        
        if ($isPlatformSender) {
            // Extract real email from body
            $body = $email->body_text ?? '';
            // Do NOT base64-decode blindly - it corrupts typeform tracking hashes
            $flat = preg_replace('/\r?\n/', ' ', $body);

            $extractedEmail = null;

            // Typeform format: "Email<address>" concatenated without separator, followed by tracking hash
            // Must check BEFORE generic patterns because the hash corrupts generic matching
            if (preg_match('/(?:typeform|followups\.typeform)/i', $fromEmail)) {
                // Typeform: "Emailuser@domain.tld" directly concatenated with tracking hash
                // Use explicit TLD list to avoid matching into the hash
                if (preg_match('/Email([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.(?:at|de|com|net|org|io|info|online|ch|eu|co\.at|gv\.at))/i', $flat, $m)) {
                    $extractedEmail = strtolower(trim($m[1]));
                }
                // Fallback: lookahead for digit after TLD (tracking hashes start with digits)
                if (!$extractedEmail && preg_match('/Email([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-z]{2,6}?)(?=[a-z]*[0-9]|Typeform|hat\s)/i', $flat, $m)) {
                    $extractedEmail = strtolower(trim($m[1]));
                }
                // Extract name: "First name<Name>Last name<Name>" - use camelCase boundary
                $spacedFlat = preg_replace('/([a-z])([A-Z])/', '$1 $2', $flat);
                if (preg_match('/First\s*name\s*([A-ZÄÖÜa-zäöüß][a-zäöüß\-]+)/i', $spacedFlat, $fnM)
                    && preg_match('/Last\s*name\s*([A-ZÄÖÜa-zäöüß][a-zäöüß\-]+)/i', $spacedFlat, $lnM)) {
                    $realName = mb_convert_case(trim($fnM[1]), MB_CASE_TITLE, 'UTF-8') . ' ' . mb_convert_case(trim($lnM[1]), MB_CASE_TITLE, 'UTF-8');
                    Log::info("autoReply: Typeform name extracted: {$realName}");
                }
            }

            // willhaben / immowelt / general platform patterns
            if (!$extractedEmail) {
                if (preg_match('/E-Mail:\s*([\w.+\-][\w.+\s\-]*@[\w.\-]+\.[a-z]{2,6})/i', $flat, $m)) {
                    $extractedEmail = strtolower(str_replace(' ', '', $m[1]));
                } elseif (preg_match('/(?:email|e-mail)[=:\s]+([\w.+\-][\w.+\s\-]*@[\w.\-]+\.[a-z]{2,6})/i', $flat, $m)) {
                    $extractedEmail = strtolower(str_replace(' ', '', $m[1]));
                } elseif (preg_match('/Email([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-z]{2,6})(?=[^a-z]|$)/i', $flat, $m)) {
                    $extractedEmail = strtolower(trim($m[1]));
                } else {
                    // Generic fallback: find any email that's not a platform address
                    if (preg_match_all('/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-z]{2,6})\b/', $flat, $matches)) {
                        foreach ($matches[1] as $candidate) {
                            if (!preg_match('/(noreply|no-reply|mailer|typeform|willhaben|immowelt|notification|calendly|amazonaws|bounce|sr-homes)/', strtolower($candidate))) {
                                $extractedEmail = strtolower(trim($candidate));
                                break;
                            }
                        }
                    }
                }
            }

            // Extract real name from body (willhaben format: "Vorname: X  Nachname: Y")
            if (!$realName && preg_match('/Vorname[:\s]+(.+?)\s+(Nachname|Telefon|E-Mail)/si', $flat, $nameM)) {
                $firstName = trim($nameM[1]);
                if (preg_match('/Nachname:\s*(.+?)\s+(Telefon|E-Mail|Die Anzeige)/si', $flat, $lastM)) {
                    $realName = trim($firstName . ' ' . trim($lastM[1]));
                } else {
                    $realName = $firstName;
                }
            } elseif (preg_match('/(?:Name|Kontakt):\s*([A-ZÄÖÜa-zäöüß]+\s+[A-ZÄÖÜa-zäöüß]+)/i', $flat, $nameM)) {
                $realName = trim($nameM[1]);
            }

            if ($extractedEmail && !preg_match('/(noreply|no-reply|mailer|notification)/', $extractedEmail)) {
                $realEmail = $extractedEmail;
                Log::info("autoReply: extracted real email {$realEmail} and name {$realName} from platform mail body");
            } else {
                // Platform mail but no real email found - can't reply
                Log::info("autoReply: platform mail but no real email found in body, skipping");
                \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                    'activity_id' => $activity->id ?? null,
                    'email_id' => $email->id,
                    'property_id' => $email->property_id,
                    'stakeholder' => $stakeholder,
                    'to_email' => $fromEmail,
                    'subject' => 'skipped',
                    'status' => 'skipped',
                    'error_message' => 'Platform-Mail ohne echte Email im Body',
                ]);
                return;
            }
        } else {
            // Not a platform - skip actual system addresses
            $skipPatterns = ['mailer-daemon', 'postmaster', 'bounce'];
            foreach ($skipPatterns as $pattern) {
                if (str_contains($fromEmail, $pattern)) {
                    Log::info("autoReply: skipping system address {$fromEmail}");
                    return;
                }
            }
        }

        // Update stakeholder to real name if resolved from platform
        $stakeholder = $realName;
        $fromEmail = $realEmail;

        // Don't auto-reply if we already replied to this person for this property
        $alreadyReplied = \Illuminate\Support\Facades\DB::table('auto_reply_log')
            ->where('to_email', $realEmail)
            ->where('property_id', $email->property_id)
            ->where('status', 'sent')
            ->exists();
        if ($alreadyReplied) {
            Log::info("autoReply: already sent to {$fromEmail} for property {$email->property_id}, skipping");
            \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                'activity_id' => $activity->id ?? null,
                'email_id' => $email->id,
                'property_id' => $email->property_id,
                'stakeholder' => $stakeholder,
                'to_email' => $fromEmail,
                'subject' => 'skipped',
                'status' => 'skipped',
                'error_message' => 'Already replied to this person for this property',
            ]);
            return;
        }

        // Get property info
        $property = \Illuminate\Support\Facades\DB::table('properties')
            ->where('id', $email->property_id)->first();
        if (!$property) return;

        // Find Expose and BaB files
        $attachmentPaths = [];
        $attachmentNames = [];
        $files = \Illuminate\Support\Facades\DB::table('property_files')
            ->where('property_id', $email->property_id)
            ->get();

        foreach ($files as $file) {
            $nameLower = strtolower($file->filename ?? '');
            $labelLower = strtolower($file->label ?? '');
            $isExpose = str_contains($labelLower, 'expos') || str_contains($nameLower, 'expose') || str_contains($nameLower, 'exposé');
            $isBaB = str_contains($nameLower, 'bab') || str_contains($nameLower, 'bauaus') || str_contains($labelLower, 'bau');
            
            if ($isExpose || $isBaB) {
                $fullPath = '/var/www/srhomes/storage/app/public/' . $file->path;
                if (file_exists($fullPath)) {
                    $attachmentPaths[] = $fullPath;
                    $attachmentNames[] = $file->filename;
                }
            }
        }

        // Also check expose_path on property
        if ($property->expose_path && !in_array('/var/www/srhomes/storage/app/public/' . ltrim($property->expose_path, '/'), $attachmentPaths)) {
            $exposePath = '/var/www/srhomes/storage/app/public/' . ltrim($property->expose_path, '/');
            if (file_exists($exposePath)) {
                $attachmentPaths[] = $exposePath;
                $attachmentNames[] = basename($exposePath);
            }
        }

        // Also attach portal documents (Nebenkosten, allgemeine Dokumente)
        $portalDocs = \Illuminate\Support\Facades\DB::table('portal_documents')
            ->where('property_id', $email->property_id)
            ->get();
        foreach ($portalDocs as $doc) {
            $docPath = '/var/www/srhomes/storage/app/public/documents/' . $doc->property_id . '/' . $doc->filename;
            if (file_exists($docPath)) {
                $attachmentPaths[] = $docPath;
                $attachmentNames[] = $doc->original_name;
            }
        }

        // NO EXPOSE = NO AUTO-REPLY: If no expose file was found, skip auto-reply entirely
        $hasExposeAttachment = false;
        foreach ($attachmentNames as $aName) {
            $aLower = strtolower($aName);
            if (str_contains($aLower, 'expos') || str_contains($aLower, 'bab') || str_contains($aLower, 'bauaus')) {
                $hasExposeAttachment = true;
                break;
            }
        }
        if (!$hasExposeAttachment) {
            Log::info("autoReply: no expose found for property {$email->property_id}, skipping auto-reply");
            \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                'activity_id' => $activity->id ?? null,
                'email_id' => $email->id,
                'property_id' => $email->property_id,
                'stakeholder' => $stakeholder,
                'to_email' => $fromEmail,
                'subject' => 'skipped',
                'status' => 'skipped',
                'error_message' => 'Kein Expose vorhanden - Auto-Reply deaktiviert',
            ]);
            return;
        }

        // Build reply text — use KI-generated draft (same as ai_reply action)
        $replyText = null;
        $replyHtml = null;
        
        // Custom text override if set
        $customText = $settings->auto_reply_text ?? null;
        if ($customText) {
            $firstName = explode(' ', trim($stakeholder))[0] ?? $stakeholder;
            $propName = $property->project_name ?: ($property->address . ', ' . $property->city);
            $replyText = str_replace(
                ['{name}', '{vorname}', '{immobilie}', '{adresse}'],
                [$stakeholder, $firstName, $propName, $property->address . ', ' . $property->city],
                $customText
            );
        } else {
            // Generate KI draft via ai_reply (same endpoint used in admin panel)
            try {
                $aiResponse = \Illuminate\Support\Facades\Http::timeout(60)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('http://127.0.0.1/api/admin_api.php?key=' . config('portal.api_key') . '&action=ai_reply', [
                        'email_id'     => $activity->id,
                        'tone'         => 'professional',
                        'type'         => 'activity',
                        'detail_level' => 'standard',
                    ]);
                if ($aiResponse->successful()) {
                    $aiData = $aiResponse->json();
                    $replyText = $aiData['reply_text'] ?? null;
                    Log::info("autoReply: KI draft generated for activity {$activity->id}");
                }
            } catch (\Throwable $e) {
                Log::warning("autoReply: KI draft failed for activity {$activity->id}: " . $e->getMessage());
            }
            
            // If KI fails, don't send anything
            if (empty($replyText)) {
                Log::warning("autoReply: KI draft failed, skipping auto-reply for activity {$activity->id}");
                \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                    'activity_id' => $activity->id ?? null,
                    'email_id' => $email->id,
                    'property_id' => $email->property_id,
                    'stakeholder' => $stakeholder,
                    'to_email' => strtolower($email->from_email ?? ''),
                    'subject' => 'skipped',
                    'status' => 'skipped',
                    'error_message' => 'KI-Draft konnte nicht generiert werden',
                ]);
                return;
            }
        }

        // Convert to HTML (KI draft may already contain HTML-like content)
        if (str_contains($replyText, '<')) {
            $replyHtml = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333;">' . $replyText . '</div>';
        } else {
            $replyHtml = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333;">' 
                . nl2br(htmlspecialchars($replyText)) . '</div>';
        }

        // Build subject
        $originalSubject = $email->subject ?? '';
        $replySubject = 'Re: ' . $originalSubject;

        // Get signature
        $signatureHtml = '';
        try {
            $sigCtrl = app(\App\Http\Controllers\Admin\SettingsController::class);
            $sigData = json_decode($sigCtrl->get(request())->getContent(), true);
            $baseUrl = rtrim(config('app.url'), '/');
            $signatureHtml = '<br><br><div style="border-top:1px solid #e0e0e0;padding-top:12px;margin-top:12px;font-family:Arial,sans-serif;font-size:13px;color:#666;">'
                . '<strong>' . ($sigData['signature_name'] ?? 'Maximilian Hölzl') . '</strong><br>'
                . ($sigData['signature_title'] ?? '') . '<br>'
                . ($sigData['signature_company'] ?? 'SR-Homes Immobilien GmbH') . '<br>'
                . 'Tel: ' . ($sigData['signature_phone'] ?? '+43 664 2600 930') . '<br>'
                . '<a href="https://' . ($sigData['signature_website'] ?? 'www.sr-homes.at') . '">' . ($sigData['signature_website'] ?? 'www.sr-homes.at') . '</a>';
            if (!empty($sigData['signature_photo_url'])) {
                $signatureHtml = '<br><br><div style="border-top:1px solid #e0e0e0;padding-top:12px;margin-top:12px;font-family:Arial,sans-serif;font-size:13px;color:#666;">'
                    . '<table><tr><td style="padding-right:15px;vertical-align:top"><img src="' . $sigData['signature_photo_url'] . '" width="80" style="border-radius:50%"></td>'
                    . '<td><strong>' . ($sigData['signature_name'] ?? 'Maximilian Hölzl') . '</strong><br>'
                    . ($sigData['signature_title'] ?? '') . '<br>'
                    . ($sigData['signature_company'] ?? 'SR-Homes Immobilien GmbH') . '<br>'
                    . 'Tel: ' . ($sigData['signature_phone'] ?? '+43 664 2600 930') . '<br>'
                    . '<a href="https://' . ($sigData['signature_website'] ?? 'www.sr-homes.at') . '">' . ($sigData['signature_website'] ?? 'www.sr-homes.at') . '</a>'
                    . '</td></tr></table>';
            }
            $signatureHtml .= '</div>';
        } catch (\Throwable $e) {}

        $fullHtml = $replyHtml . $signatureHtml;

        // Send email via EmailService
        try {
            $emailService = app(\App\Services\EmailService::class);
            $result = $emailService->send(
                $account->id,
                $fromEmail,
                $replySubject,
                $fullHtml,
                $email->property_id,
                $stakeholder,
                null, // cc
                null, // bcc
                $attachmentPaths, // attachments as file paths
                $email->message_id ?? null, // in-reply-to
                null, // references
                'email-out'
            );

            $status = (isset($result['success']) && $result['success']) ? 'sent' : 'failed';
            $errorMsg = $result['error'] ?? null;

            \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                'activity_id' => $activity->id ?? null,
                'email_id' => $email->id,
                'property_id' => $email->property_id,
                'stakeholder' => $stakeholder,
                'to_email' => $fromEmail,
                'subject' => $replySubject,
                'attachments' => implode(', ', $attachmentNames),
                'status' => $status,
                'error_message' => $errorMsg,
            ]);

            Log::info("autoReply: {$status} reply to {$fromEmail} for property {$property->ref_id}" . ($errorMsg ? " error: {$errorMsg}" : "") . " attachments: " . implode(', ', $attachmentNames));

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('auto_reply_log')->insert([
                'activity_id' => $activity->id ?? null,
                'email_id' => $email->id,
                'property_id' => $email->property_id,
                'stakeholder' => $stakeholder,
                'to_email' => $fromEmail,
                'subject' => $replySubject,
                'attachments' => implode(', ', $attachmentNames),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error("autoReply failed: " . $e->getMessage());
        }
    }

    private function autoGenerateDraft(int $activityId, ?int $propertyId, string $stakeholder, ?int $sourceEmailId): void
    {
        Log::info("autoGenerateDraft: generating draft for activity {$activityId}");

        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('http://127.0.0.1/api/admin_api.php?key=' . config('portal.api_key') . '&action=ai_reply', [
                'email_id'     => $activityId,
                'type'         => 'activity',
                'tone'         => 'professional',
                'detail_level' => 'standard',
            ]);

        if (!$response->successful()) {
            Log::warning("autoGenerateDraft: HTTP error " . $response->status() . " for activity {$activityId}");
            return;
        }

        $data = $response->json();
        $replyText = $data['reply_text'] ?? null;

        if (empty($replyText)) {
            Log::warning("autoGenerateDraft: empty reply_text for activity {$activityId}");
            return;
        }

        // Check if draft already exists
        if ($sourceEmailId) {
            $exists = \Illuminate\Support\Facades\DB::table('email_drafts')
                ->where('source_email_id', $sourceEmailId)
                ->exists();
            if ($exists) {
                Log::info("autoGenerateDraft: draft already exists for source_email_id {$sourceEmailId}, skipping");
                return;
            }
        }

        // Clean up old drafts (keep last 200)
        $draftCount = \Illuminate\Support\Facades\DB::table('email_drafts')->count();
        if ($draftCount > 200) {
            $cutoffId = \Illuminate\Support\Facades\DB::table('email_drafts')
                ->orderBy('id', 'desc')->skip(200)->value('id');
            if ($cutoffId) {
                \Illuminate\Support\Facades\DB::table('email_drafts')->where('id', '<', $cutoffId)->delete();
            }
        }

        \Illuminate\Support\Facades\DB::table('email_drafts')->insert([
            'to_email'        => $data['to'] ?? '',
            'subject'         => $data['subject'] ?? '',
            'body'            => $replyText,
            'property_id'     => $propertyId,
            'stakeholder'     => $stakeholder,
            'account_id'      => null,
            'tone'            => 'professional',
            'source_email_id' => $sourceEmailId,
            'imap_uid'        => null,
            'imap_folder'     => '',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        Log::info("autoGenerateDraft: draft saved for activity {$activityId} / stakeholder {$stakeholder}");
    }

    public function autoExtractFromEmail(int $emailId, int $propertyId, string $body, string $subject): void
    {
        $snippet = mb_substr(strip_tags($body), 0, 1500);

        // Load existing KB entries for this property (compact index for AI comparison)
        $existingKb = \Illuminate\Support\Facades\DB::select(
            "SELECT id, title, LEFT(content, 120) as content FROM property_knowledge WHERE property_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 150",
            [$propertyId]
        );
        $kbIndex = '';
        if (!empty($existingKb)) {
            $lines = [];
            foreach ($existingKb as $k) {
                $k = (array) $k;
                $lines[] = "#{$k['id']}: {$k['title']} — {$k['content']}";
            }
            $kbIndex = implode("\n", $lines);
        }

        $system = 'Du analysierst E-Mails zu Immobilien und extrahierst NUR neue, konkrete Fakten ueber die IMMOBILIE SELBST.

NICHT SPEICHERN (kein Objektwissen):
- Persoenliche Daten von Interessenten (Name, Email, Telefon, Adresse)
- Suchpraeferenzen von Interessenten (gewuenschte Zimmeranzahl, Groesse, Preisvorstellung)
- Finanzierungsstatus von Interessenten (Eigenkapital, Bankbestaetigung)
- Nutzungsabsicht (Eigennutzung, Kapitalanlage)
- Allgemeine Hoeflichkeitsfloskeln, Terminbestaetigungen ohne Details
- Kontaktaufnahmen ohne inhaltliche Fakten
- Information die bereits in der bestehenden Wissensdatenbank steht (siehe unten)

NUR SPEICHERN (echtes Objektwissen):
- Konkrete Fakten ueber das Objekt: Ausstattung, Zustand, Besonderheiten
- Preisaenderungen, neue Konditionen, Verhandlungsergebnisse
- Feedback von Besichtigungen (was fiel auf, was gefiel, was nicht)
- Rechtliche Infos: Widmung, Grundbuch, Nutzwertgutachten
- Eigentuemer-Entscheidungen (Preisreduktion, Umbau, etc.)
- Verfuegbarkeitsaenderungen (Einheit verkauft, neue Einheit frei)
- Baufortschritt, Termine, Planaenderungen

Antworte NUR mit einem JSON-Array: [{"title":"...", "content":"...", "category":"..."}]
Kategorien: feedback_positiv|feedback_negativ|feedback_besichtigung|preis_markt|verhandlung|rechtliches|objektbeschreibung|ausstattung|lage_umgebung|energetik|eigentuemer_info|vermarktung|sonstiges
Leeres Array [] wenn keine NEUEN Fakten enthalten sind.';

        $kbSection = $kbIndex ? "\n\nBEREITS VORHANDENES WISSEN (NICHT erneut speichern, auch nicht umformuliert!):\n{$kbIndex}" : "";
        $user = "Betreff: {$subject}\n\nText:\n{$snippet}{$kbSection}";

        $result = $this->ai->chatJson($system, $user, 500);

        if (!$result || !is_array($result) || empty($result)) {
            return;
        }

        $entries = isset($result[0]) ? $result : ($result['entries'] ?? $result['items'] ?? []);

        $validCategories = ['objektbeschreibung','ausstattung','lage_umgebung','preis_markt','rechtliches',
            'energetik','feedback_positiv','feedback_negativ','feedback_besichtigung',
            'verhandlung','eigentuemer_info','vermarktung','dokument_extrakt','sonstiges'];

        $saved = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $title   = trim($entry['title'] ?? '');
            $content = trim($entry['content'] ?? '');
            if (!$title || !$content) continue;
            if (mb_strlen($content) < 10) continue;

            $category = $entry['category'] ?? 'sonstiges';
            if (!in_array($category, $validCategories)) $category = 'sonstiges';

            // Exact content dedup
            $contentNorm = mb_strtolower(trim($content));
            $duplicate = \Illuminate\Support\Facades\DB::selectOne(
                "SELECT id FROM property_knowledge WHERE property_id = ? AND is_active = 1 AND LOWER(TRIM(content)) = ? LIMIT 1",
                [$propertyId, $contentNorm]
            );
            if ($duplicate) continue;

            // Fuzzy dedup: skip if same title already exists
            $titleNorm = mb_strtolower(trim($title));
            $fuzzyDup = \Illuminate\Support\Facades\DB::selectOne(
                "SELECT id FROM property_knowledge WHERE property_id = ? AND is_active = 1 AND LOWER(TRIM(title)) = ? LIMIT 1",
                [$propertyId, $titleNorm]
            );
            if ($fuzzyDup) continue;

            \Illuminate\Support\Facades\DB::table('property_knowledge')->insert([
                'property_id'        => $propertyId,
                'category'           => $category,
                'title'              => mb_substr($title, 0, 200),
                'content'            => mb_substr($content, 0, 2000),
                'source_type'        => 'email_ingest',
                'source_id'          => (string) $emailId,
                'source_description' => 'Auto-extrahiert aus E-Mail',
                'confidence'         => 'medium',
                'is_verified'        => 0,
                'is_active'          => 1,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $saved++;
        }

        if ($saved > 0) {
            Log::info("autoExtractFromEmail: saved {$saved} NEW knowledge entries for property {$propertyId} from email {$emailId}");
        }
    }


    /**
     * Dynamically update a contact's lead profile from every inbound email.
     * Extracts budget, rooms, size, financing, location preferences and merges into existing profile.
     */
    private function updateLeadProfileFromEmail(string $stakeholder, string $fromEmail, string $body, string $subject, ?int $propertyId): void
    {
        if (empty($stakeholder) || mb_strlen($body) < 20) return;

        // Skip system/platform senders as stakeholder
        $skipNames = ['willhaben', 'immowelt', 'typeform', 'notification', 'mailer', 'noreply', 'system'];
        foreach ($skipNames as $skip) {
            if (stripos($stakeholder, $skip) !== false) return;
        }

        try {
            // Find contact
            $contact = DB::selectOne(
                "SELECT id, lead_data, full_name FROM contacts
                 WHERE (email = ? OR full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci)
                 LIMIT 1",
                [strtolower($fromEmail), $stakeholder]
            );
            if (!$contact) return;

            $leadData = json_decode($contact->lead_data ?? 'null', true);
            if (!is_array($leadData)) $leadData = [];

            $fullText = $subject . ' ' . $body;
            $changed = false;

            // --- Extract BUDGET from email ---
            // Patterns: "Budget 300.000", "max. 250.000€", "bis 400.000", "Preisrahmen 200-300k",
            // "können uns bis 350.000 vorstellen", "Finanzierung bis 280.000 genehmigt"
            $budgetPatterns = [
                '/(?:budget|preisrahmen|preisvorstellung|finanzierung(?:szusage)?|leisten|vorstellen|max(?:imal)?|h[öo]chstens|bis)[\s:]*(?:ca\.?\s*)?(?:EUR\s*|€\s*)?(\d{2,3})[.\s]?(\d{3})(?:[.\s](\d{3}))?[\s]*(?:€|EUR|Euro)?/i',
                '/(?:EUR\s*|€\s*)(\d{2,3})[.\s]?(\d{3})(?:[.\s](\d{3}))?[\s]*(?:budget|max|bis|vorstellung|rahmen)/i',
                '/(\d{2,3})[.\s]?(\d{3})(?:[.\s](\d{3}))?[\s]*(?:€|EUR|Euro)[\s]*(?:budget|max|bis|vorstellung|rahmen|leisten|vorstellen)/i',
            ];
            foreach ($budgetPatterns as $pattern) {
                if (preg_match($pattern, $fullText, $m)) {
                    $numStr = $m[1] . $m[2] . ($m[3] ?? '');
                    $budget = (int)$numStr;
                    if ($budget >= 50000 && $budget <= 5000000) {
                        // Update budget_max if this is more specific
                        if (empty($leadData['budget_max']) || abs($leadData['budget_max'] - $budget) > 20000) {
                            $leadData['budget_max'] = $budget;
                            $leadData['budget_min'] = (int)floor($budget * 0.8 / 5000) * 5000;
                            $changed = true;
                            Log::info("[LeadProfile] Updated budget for {$contact->full_name}: max {$budget}");
                        }
                    }
                    break;
                }
            }

            // --- Extract ROOMS from email ---
            if (preg_match('/(?:suche|brauche|mindestens|wünsche|benötige|hätte gerne|interesse an|anfrage)[\s\S]{0,60}?(\d)\s*[-\s]?(?:Zimmer|Zi\.?|Raum|Räume)/i', $fullText, $m)) {
                $rooms = (int)$m[1];
                if ($rooms >= 1 && $rooms <= 8) {
                    if (empty($leadData['rooms_min']) || $leadData['rooms_min'] != $rooms) {
                        $leadData['rooms_min'] = $rooms;
                        $changed = true;
                        Log::info("[LeadProfile] Updated rooms for {$contact->full_name}: {$rooms}");
                    }
                }
            }
            // Also from subject line (e.g. "2-Zimmer Neubau-Wohnungen")
            if (empty($leadData['rooms_min']) && preg_match('/(\d)\s*[-\s]?(?:Zimmer|Zi\.?)/i', $subject, $m)) {
                $rooms = (int)$m[1];
                if ($rooms >= 1 && $rooms <= 8) {
                    $leadData['rooms_min'] = $rooms;
                    $changed = true;
                }
            }

            // --- Extract SIZE from email ---
            if (preg_match('/(?:mindestens|ab|mind\.?|wünsche|brauche|suche)[\s\S]{0,40}?(\d{2,3})\s*(?:m²|m2|qm|Quadratmeter)/i', $fullText, $m)) {
                $size = (int)$m[1];
                if ($size >= 20 && $size <= 500) {
                    if (empty($leadData['size_min_m2']) || abs($leadData['size_min_m2'] - $size) > 10) {
                        $leadData['size_min_m2'] = $size;
                        $changed = true;
                        Log::info("[LeadProfile] Updated size for {$contact->full_name}: {$size}m²");
                    }
                }
            }

            // --- Extract FINANCING from email ---
            $financingPatterns = [
                '/(?:Finanzierung(?:szusage)?|Kredit)\s*(?:ist\s*)?(?:bereits\s*)?(?:genehmigt|zugesagt|bestätigt|bewilligt|vorhanden)/i' => 'Kredit genehmigt',
                '/(?:Finanzierung|Kredit)\s*(?:ist\s*)?(?:in\s*(?:Planung|Vorbereitung)|beantragt|wird\s*beantragt|geplant)/i' => 'Kredit geplant',
                '/(?:Eigenkapital|Barmittel|Eigenmittel)\s*(?:vorhanden|verfügbar|reicht|haben)/i' => 'Eigenkapital',
                '/(?:bar\s*(?:bezahlen|zahlen)|ohne\s*(?:Finanzierung|Kredit)|aus\s*Eigenmitteln)/i' => 'Eigenkapital',
                '/(?:Finanzierung|Kredit)\s*(?:noch\s*)?(?:unklar|offen|müsste|müssen|noch\s*klären)/i' => 'Unklar',
            ];
            foreach ($financingPatterns as $pattern => $value) {
                if (preg_match($pattern, $fullText)) {
                    if (empty($leadData['financing']) || $leadData['financing'] !== $value) {
                        $leadData['financing'] = $value;
                        $changed = true;
                        Log::info("[LeadProfile] Updated financing for {$contact->full_name}: {$value}");
                    }
                    break;
                }
            }

            // --- Extract LOCATION preference from email ---
            if (preg_match('/(?:suche|bevorzuge|wünsche|Raum|Umgebung|Nähe|Region)\s*(?:in\s*|im\s*)?(?:der\s*)?(?:Nähe\s*(?:von\s*)?)?([A-ZÄÖÜ][a-zäöüß]+(?:\s*[-\/]\s*[A-ZÄÖÜ][a-zäöüß]+)?)/i', $fullText, $m)) {
                $loc = trim($m[1]);
                $skipLocs = ['Sehr', 'Liebe', 'Guten', 'Hallo', 'Danke', 'Grüße', 'Bitte', 'Herr', 'Frau', 'Mit'];
                if (!in_array($loc, $skipLocs) && mb_strlen($loc) > 2) {
                    $existing = $leadData['location_pref'] ?? '';
                    if (empty($existing) || (stripos($existing, $loc) === false && mb_strlen($existing) < 80)) {
                        $leadData['location_pref'] = $existing ? $existing . ', ' . $loc : $loc;
                        $changed = true;
                        Log::info("[LeadProfile] Updated location for {$contact->full_name}: {$loc}");
                    }
                }
            }

            // --- Extract PROPERTY TYPE preference from email ---
            $typePatterns = [
                '/(?:suche|interesse an|wünsche)[\s\S]{0,30}?(?:einer?\s*)?(?:Eigentums)?([Ww]ohnung)/i' => 'Wohnung',
                '/(?:suche|interesse an|wünsche)[\s\S]{0,30}?(?:einem?\s*)?(?:Einfamilien)?([Hh]aus)/i' => 'Haus',
                '/(?:suche|interesse an|wünsche)[\s\S]{0,30}?(?:einem?\s*)?([Gg]rundst[üu]ck)/i' => 'Grundstueck',
                '/(?:Penthouse|Dachgeschoss|Dachterrassenwohnung)/i' => 'Wohnung',
                '/(?:Reihenhaus|Doppelhaus|Doppelhaushälfte)/i' => 'Haus',
            ];
            foreach ($typePatterns as $pattern => $value) {
                if (preg_match($pattern, $fullText)) {
                    if (empty($leadData['property_type'])) {
                        $leadData['property_type'] = $value;
                        $changed = true;
                    }
                    break;
                }
            }

            // --- Extract TIMELINE from email ---
            $timelinePatterns = [
                '/(?:sofort|schnellstm[öo]glich|dringend|zeitnah|bald(?:igst)?)\s*(?:einziehen|kaufen|suchen|übersiedeln)/i' => 'sofort',
                '/(?:nächste[ns]?\s*(?:Monat|Wochen)|kurzfristig|in\s*(?:1|2|3)\s*Monaten?)/i' => '1-3 Monate',
                '/(?:dieses\s*Jahr|heuer|im\s*(?:Frühjahr|Sommer|Herbst|Winter))/i' => 'dieses Jahr',
                '/(?:nächstes\s*Jahr|keine\s*Eile|langfristig|in\s*Ruhe)/i' => 'nächstes Jahr',
            ];
            foreach ($timelinePatterns as $pattern => $value) {
                if (preg_match($pattern, $fullText)) {
                    if (empty($leadData['timeline']) || $leadData['timeline'] !== $value) {
                        $leadData['timeline'] = $value;
                        $changed = true;
                        Log::info("[LeadProfile] Updated timeline for {$contact->full_name}: {$value}");
                    }
                    break;
                }
            }

            // --- Save if changed ---
            if ($changed) {
                DB::update(
                    'UPDATE contacts SET lead_data = ?, updated_at = NOW() WHERE id = ?',
                    [json_encode($leadData, JSON_UNESCAPED_UNICODE), $contact->id]
                );
                Log::info("[LeadProfile] Auto-updated profile for {$contact->full_name} (#{$contact->id}) from email");
            }
        } catch (\Throwable $e) {
            Log::warning("[LeadProfile] Failed to update for {$stakeholder}: " . $e->getMessage());
        }
    }

        private function extractAndSaveProspectEmail(string $stakeholder, string $fromEmail, string $body, ?int $propertyId): void
    {
        if (empty($stakeholder) || empty($body)) return;

        $fromLower = strtolower($fromEmail);
        $isPlatform = preg_match('/(noreply|no-reply|mailer|notification|info@willhaben|info@immowelt|typeform|followups)/', $fromLower);

        // For platform mails, extract real email from body
        $realEmail = null;
        if ($isPlatform) {
            // Do NOT base64-decode blindly - corrupts typeform tracking hashes
            $flat = preg_replace('/\r?\n/', ' ', $body);

            // Typeform-specific: "Emailuser@domain.tld" concatenated with tracking hash
            if (preg_match('/(?:typeform|followups)/i', $fromLower)) {
                if (preg_match('/Email([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.(?:at|de|com|net|org|io|info|online|ch|eu|co\.at|gv\.at))/i', $flat, $m)) {
                    $realEmail = strtolower(trim($m[1]));
                }
            }

            // General platform patterns (willhaben, immowelt etc.)
            if (!$realEmail) {
                if (preg_match('/E-Mail:\s*([\w.+-][\w.+\s-]*@[\w.-]+\.[a-z]{2,6})/i', $flat, $m)) {
                    $realEmail = strtolower(str_replace(' ', '', $m[1]));
                } elseif (preg_match('/(?:email|e-mail)[=:\s]+([\w.+-][\w.+\s-]*@[\w.-]+\.[a-z]{2,6})/i', $flat, $m)) {
                    $realEmail = strtolower(str_replace(' ', '', $m[1]));
                } elseif (preg_match('/Email([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-z]{2,6})(?=[^a-z]|$)/i', $flat, $m)) {
                    $realEmail = strtolower(trim($m[1]));
                } else {
                    if (preg_match_all('/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-z]{2,6})\b/', $flat, $matches)) {
                        foreach ($matches[1] as $candidate) {
                            if (!preg_match('/(noreply|no-reply|mailer|typeform|willhaben|immowelt|notification|calendly|amazonaws|bounce|sr-homes)/', strtolower($candidate))) {
                                $realEmail = strtolower(trim($candidate));
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            // Non-platform: use from_email directly
            $realEmail = $fromEmail;
        }

        if (empty($realEmail)) return;
        $realEmail = strtolower(trim($realEmail));
        if (preg_match('/(noreply|no-reply|mailer|notification)/', $realEmail)) return;

        
        // Extract phone number from body — via zentralem PhoneExtractor Service
        $realPhone = PhoneExtractor::extractFromText($body);

// Upsert contact — search by EMAIL first (unique identifier), then by name
        $existing = \DB::table('contacts')
            ->whereRaw('LOWER(email) = ?', [$realEmail])
            ->first();

        // Fallback: search by name if no email match
        if (!$existing) {
            $existing = \DB::table('contacts')
                ->whereRaw('full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci', [$stakeholder])
                ->first();
        }

        if ($existing) {
            $updateData = ['updated_at' => now()];
            if (empty($existing->email)) $updateData['email'] = $realEmail;
            if (empty($existing->phone) && $realPhone) $updateData['phone'] = $realPhone;
            // Add stakeholder as alias if name differs
            if (strtolower($existing->full_name) !== strtolower($stakeholder) && !empty($stakeholder)) {
                $aliases = json_decode($existing->aliases ?? '[]', true) ?: [];
                if (!in_array($stakeholder, $aliases)) {
                    $aliases[] = $stakeholder;
                    $updateData['aliases'] = json_encode($aliases, JSON_UNESCAPED_UNICODE);
                }
            }
            // Add property_id if not already in list
            if ($propertyId) {
                $pids = json_decode($existing->property_ids ?? '[]', true) ?: [];
                if (!in_array($propertyId, $pids)) {
                    $pids[] = $propertyId;
                    $updateData['property_ids'] = json_encode($pids);
                }
            }
            if (count($updateData) > 1) {
                \DB::table('contacts')->where('id', $existing->id)->update($updateData);
            }
        } else {
            // Don't create contact if email belongs to an owner (customer)
            if ($realEmail) {
                $isOwner = \DB::table('customers')
                    ->whereRaw('LOWER(email) = LOWER(?)', [$realEmail])
                    ->where('email', 'NOT LIKE', 'placeholder%')
                    ->exists();
                if ($isOwner) {
                    Log::info("[IMAP] Skipping contact creation for owner email: {$realEmail}");
                    return;
                }
            }

            // Only create contact if we have a real email that is NOT our own
            $ownEmails = \DB::table('email_accounts')->where('is_active', 1)->pluck('email_address')->map(fn($e) => strtolower($e))->toArray();
            if ($realEmail && !in_array(strtolower($realEmail), $ownEmails)) {
                \DB::table('contacts')->insert([
                    'full_name' => $stakeholder,
                    'email' => $realEmail,
                    'phone' => $realPhone,
                    'source' => 'email_ingest',
                    'property_ids' => $propertyId ? json_encode([$propertyId]) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
