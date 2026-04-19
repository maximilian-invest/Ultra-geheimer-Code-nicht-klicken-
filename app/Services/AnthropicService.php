<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('portal.anthropic_api_key');
        $this->model = config('portal.anthropic_model', 'claude-haiku-4-5-20251001');
    }

    public function chat(string $systemPrompt, string $userMessage, int $maxTokens = 2000, ?string $model = null): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model ?? $this->model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['content'][0]['text'] ?? null;
            }

            Log::error('Anthropic API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Anthropic API exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Chat with Anthropic's server-side web_search tool enabled. Claude
     * performs up to $maxSearches web lookups on its own during reasoning
     * and returns a final text answer. Use when you need fresh, grounded
     * facts (e.g. researching a property's neighbourhood).
     *
     * The returned string is the concatenation of every text block from
     * the final assistant message. Tool-use / search-result blocks in the
     * response are dropped.
     */
    public function chatWithWebSearch(string $systemPrompt, string $userMessage, int $maxSearches = 5, int $maxTokens = 4000, ?string $model = null): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(180)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model ?? $this->model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'tools' => [
                    [
                        'type' => 'web_search_20250305',
                        'name' => 'web_search',
                        'max_uses' => $maxSearches,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Anthropic API web-search error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $parts = [];
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'text' && !empty($block['text'])) {
                    $parts[] = $block['text'];
                }
            }
            // Citation-annotated text responses are split into many text blocks
            // (one per cited fragment). Join without separator — the fragments
            // already contain any intended whitespace — otherwise you get weird
            // blank lines between every few words.
            $text = trim(implode('', $parts));
            return $text !== '' ? $text : null;
        } catch (\Exception $e) {
            Log::error('Anthropic API web-search exception: ' . $e->getMessage());
            return null;
        }
    }

    public function chatJson(string $systemPrompt, string $userMessage, int $maxTokens = 2000): ?array
    {
        $result = $this->chat($systemPrompt, $userMessage, $maxTokens);
        if (!$result) return null;

        // Extract JSON from response
        $result = trim($result);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $result, $m)) {
            $result = trim($m[1]);
        }

        $decoded = json_decode($result, true);
        if ($decoded === null && $result) {
            \Log::warning('chatJson: Failed to parse AI response as JSON', ['raw' => mb_substr($result, 0, 2000)]);
        }
        return $decoded;
    }

    /**
     * Strip signature lines from AI-generated email text.
     * Removes everything after the greeting (Freundliche Grüße, etc.)
     */
    public static function stripSignature(string $text): string
    {
        // Find the last occurrence of a greeting line and cut everything after it
        $greetings = [
            'Mit freundlichen Grüßen',
            'Mit freundlichen Grüssen',
            'Freundliche Grüße',
            'Freundliche Grüsse',
            'Freundlichen Grüßen',
            'Beste Grüße',
            'Beste Grüsse',
            'Mit besten Grüßen',
            'Herzliche Grüße',
            'Viele Grüße',
            'Mit freundlichem Gruß',
        ];
        foreach ($greetings as $g) {
            $pos = mb_stripos($text, $g);
            if ($pos !== false) {
                $text = mb_substr($text, 0, $pos + mb_strlen($g));
                break;
            }
        }
        return rtrim($text);
    }


    /**
     * Chat with images (Vision API) - sends images as base64 to Claude
     */
    public function chatWithImages(string $systemPrompt, string $textMessage, array $imageBase64s, int $maxTokens = 4000): ?string
    {
        try {
            $content = [];
            foreach ($imageBase64s as $img) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['media_type'] ?? 'image/png',
                        'data' => $img['data'],
                    ],
                ];
            }
            $content[] = ['type' => 'text', 'text' => $textMessage];

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['content'][0]['text'] ?? null;
            }

            Log::error('Anthropic Vision API error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Anthropic Vision API exception: ' . $e->getMessage());
            return null;
        }
    }

    public function chatWithImagesJson(string $systemPrompt, string $textMessage, array $imageBase64s, int $maxTokens = 4000): ?array
    {
        $result = $this->chatWithImages($systemPrompt, $textMessage, $imageBase64s, $maxTokens);
        if (!$result) return null;
        $result = trim($result);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $result, $m)) {
            $result = trim($m[1]);
        }
        return json_decode($result, true);
    }

    public function analyzeEmail(string $subject, string $body, string $fromName, array $properties, array $context = []): array
    {
        $propertyList = collect($properties)->map(function($p) {
            $line = "{$p['ref_id']}: {$p['address']}";
            if (!empty($p['type'])) $line .= " ({$p['type']})";
            if (!empty($p['purchase_price'])) $line .= " € " . number_format($p['purchase_price'], 0, ',', '.');
            if (!empty($p['owner_name'])) $line .= " [Eigentümer: {$p['owner_name']}]";
            if (!empty($p['owner_email'])) $line .= " <{$p['owner_email']}>";
            return $line;
        })->implode("\n");

        $system = "Du bist ein Immobilien-Assistent für SR-Homes Immobilien GmbH (Makler: Maximilian Hölzl).
Analysiere eingehende Emails und extrahiere strukturierte Informationen.

WICHTIG:
- Wenn der Absender ein EIGENTÜMER eines Objekts ist (siehe Objektliste), setze category auf 'eigentuemer'
- Prüfe ob die Absender-Email mit einer Eigentümer-Email übereinstimmt
- 'anfrage' = echte ERSTANFRAGE eines NEUEN Interessenten — NUR wenn keine Vorkorrespondenz existiert
- 'besichtigung' = Besichtigungswunsch, Terminvorschlag, Terminvereinbarung, Terminbestätigung
- 'kaufanbot' = konkretes Kaufangebot mit oder ohne Preisnennung
- 'absage' = Absage, kein Interesse mehr
- 'eigentuemer' = Nachricht vom Eigentümer/Auftraggeber
- 'sonstiges' = alles andere (Werbung, Spam, allgemeine Infos, Rückfragen im laufenden Kontakt)
- ANTWORT-EMAILS (Re:/AW: im Betreff): Wenn der Betreff mit 'Re:', 'AW:', 'Aw:', 'RE:' beginnt, ist es eine ANTWORT auf eine frühere Nachricht — NIEMALS 'anfrage' verwenden! Kategorisiere nach Inhalt: 'besichtigung' wenn Termine vorgeschlagen/bestätigt werden, 'kaufanbot' bei Preisverhandlung, 'absage' bei Absage, sonst 'sonstiges'.
- WEITERGELEITETE ANFRAGEN: Wenn ein Geschäftspartner/Bauträger eine Interessenten-Anfrage weiterleitet (erkennbar an 'WG:', 'leite ich Dir eine Anfrage weiter', 'Interessenten-Anfrage'), ist category='anfrage' und stakeholder=Name des INTERESSENTEN (nicht des Partners!). Auch 'kaufanbot' wenn ein Kaufanbot weitergeleitet wird.
- Bei der Zusammenfassung: ALLE relevanten Details nennen (Preise, Termine, Bedingungen, Namen)";

        // Build context
        $contextStr = '';
        if (!empty($context['thread'])) {
            $contextStr .= "\n\nBISHERIGER VERLAUF (letzte Nachrichten):\n" . $context['thread'];
        }
        if (!empty($context['owner_info'])) {
            $contextStr .= "\n\nEIGENTÜMER-INFO: " . $context['owner_info'];
        }

        $user = "Analysiere diese Email:\n\nVon: {$fromName}\nBetreff: {$subject}\nText: " . mb_substr($body, 0, 4000) . $contextStr . "\n\nVerfügbare Objekte:\n{$propertyList}\n\nAntworte NUR als JSON:\n{\"category\": \"anfrage|besichtigung|kaufanbot|absage|eigentuemer|sonstiges\", \"summary\": \"ausführliche Zusammenfassung mit allen Details (Preise, Termine, Bedingungen)\", \"sentiment\": \"positiv|neutral|negativ|dringend\", \"key_facts\": [\"Fakt 1\", \"Fakt 2\"], \"action_required\": \"antworten|termin_vereinbaren|exposé_senden|weiterleiten|keine\", \"stakeholder\": \"Name des Absenders/Interessenten\", \"suggested_property_ref_id\": \"ref_id oder null\"}";

        return $this->chatJson($system, $user) ?? [
            'category' => 'sonstiges',
            'summary' => $subject,
            'stakeholder' => $fromName,
            'suggested_property_ref_id' => null,
            'sentiment' => 'neutral',
            'key_facts' => [],
            'action_required' => 'keine',
        ];
    }

    public function generateAiReply(string $emailBody, string $stakeholder, string $propertyAddress, string $knowledgeContext, string $tone = 'professional', string $detailLevel = 'standard', bool $isErstanfrage = false, bool $hasExpose = false): ?string
    {
        $toneMap = [
            'professional' => 'professionell und freundlich',
            'friendly' => 'herzlich und persönlich',
            'formal' => 'formell und geschäftsmäßig',
            'casual' => 'locker und unkompliziert',
        ];
        $toneDesc = $toneMap[$tone] ?? $toneMap['professional'];

        $detailMap = [
            'brief' => [
                'instruction' => 'Antworte SEHR KURZ in maximal 2-3 Sätzen. Nur die Kernaussage, keine Floskeln. Komme direkt zum Punkt.',
                'tokens' => 400,
            ],
            'standard' => [
                'instruction' => 'Antworte in 4-6 Sätzen. Beantworte die Frage vollständig, sei höflich aber effizient.',
                'tokens' => 800,
            ],
            'detailed' => [
                'instruction' => 'Antworte ausführlich in 8-15 Sätzen. Gehe auf alle Aspekte der Anfrage ein. Liefere proaktiv zusätzliche relevante Informationen aus der Wissensdatenbank (z.B. Verfügbarkeit, Preisdetails, Lage-Vorteile, nächste Schritte im Kaufprozess). Zeige Expertise und Engagement.',
                'tokens' => 1500,
            ],
        ];
        $detail = $detailMap[$detailLevel] ?? $detailMap['standard'];

        // Include learned style feedback from user edits
        $styleFeedback = '';
        try {
            $feedbackRows = \Illuminate\Support\Facades\DB::select("
                SELECT original_text, edited_text, tone FROM ai_style_feedback
                ORDER BY created_at DESC LIMIT 5
            ");
            if (!empty($feedbackRows)) {
                $styleFeedback = "\n\nSTIL-LERNBEISPIELE — DER USER HAT FOLGENDE KI-ENTWÜRFE MANUELL ANGEPASST:\n";
                $styleFeedback .= "Analysiere die Unterschiede genau und übernimm den Stil des Users (Wortwahl, Tonfall, Länge, Formalität, Satzstruktur).\n";
                $styleFeedback .= "Der NACHHER-Text zeigt wie der User WIRKLICH schreiben will — das ist dein Vorbild!\n\n";
                foreach ($feedbackRows as $i => $fb) {
                    $fb = (array) $fb;
                    $num = $i + 1;
                    $orig = mb_substr($fb['original_text'], 0, 500);
                    $edit = mb_substr($fb['edited_text'], 0, 500);
                    $styleFeedback .= "Beispiel {$num}:\nKI-ENTWURF: {$orig}\nUSER-VERSION: {$edit}\n---\n";
                }
            }
        } catch (\Exception $e) {}

        $system = "Du bist Maximilian Hölzl von SR-Homes Immobilien GmbH, konzessionierter Immobilientreuhaender. Schreibe Antworten auf Immobilien-Anfragen.

WICHTIGE REGELN:
- Ton: {$toneDesc}
- Beantworte NUR die KONKRETEN Fragen in der Nachricht. Wenn jemand fragt 'Ist das Objekt noch verfügbar?' antworte kurz und direkt darauf.
- Wenn der Interessent eine SPEZIFISCHE Frage stellt (z.B. Bezugsbereitschaft, Baujahr, Heizung, Preis, Raumaufteilung, Energieausweis, etc.): Beantworte sie KONKRET mit den verfügbaren Daten aus Objektdaten oder Wissensdatenbank! NIEMALS auf das Exposé verweisen wenn die Antwort in den Daten steht!
- NUR bei ALLGEMEINEN Anfragen wie 'mehr Informationen' oder 'generelle Objektdetails' OHNE spezifische Fragen: Verweise auf das beigelegte Exposé.
- Wenn eine spezifische Frage gestellt wird aber die Antwort NICHT in den verfügbaren Daten steht: Sage ehrlich, dass du die Information weiterleiten wirst und dich schnellstmöglich meldest. NICHT auf das Exposé verweisen wenn die Information dort wahrscheinlich auch nicht steht!
- KEINE langen Objektbeschreibungen in der Mail — dafür gibt es das Exposé!

WISSENSDATENBANK — VERBINDLICH:
- Die Informationen aus der Wissensdatenbank sind VERBINDLICHE FAKTEN. Du MUSST sie in deiner Antwort berücksichtigen!
- Wenn die Wissensdatenbank sagt 'Besichtigungen ab Mitte April': Du DARFST NICHT vorschlagen, sofort einen Termin zu vereinbaren. Stattdessen MUSST du schreiben, dass Besichtigungen erst ab Mitte April möglich sind.
- Wenn die Wissensdatenbank Einschränkungen, Termine oder Bedingungen nennt, MÜSSEN diese in der Antwort vorkommen.
- Die Wissensdatenbank hat VORRANG vor allgemeinen Floskeln.

WEITERE REGELN:
- NIEMALS Platzhalter wie [bitte ergänzen] oder [Anzahl] verwenden
- Wenn eine Information nicht im Kontext steht, schreibe sie NICHT — ERFINDE NICHTS!
- KEINE internen Fachbegriffe oder Status-Codes verwenden! Begriffe wie 'Inserat', 'Ausschreibung', 'Vermarktung aktiv' sind INTERNE Informationen. Wenn ein Interessent fragt ob das Objekt verfügbar ist, antworte einfach natürlich mit 'Ja, das Objekt ist noch verfügbar' — NICHT mit internen Status-Bezeichnungen.
- KEINE Signatur schreiben (wird automatisch angehängt). Beende die Mail mit einem Grußwort wie 'Beste Grüße' oder 'Mit freundlichen Grüßen' — OHNE Name, Firma oder Telefonnummer danach.
- NIEMALS eine Telefonnummer in den Text schreiben! Die Telefonnummer steht bereits in der automatischen Signatur. Schreibe stattdessen 'telefonisch' oder 'per Telefon' ohne Nummer.
- DIESE MAIL IST DIE ANTWORT: Du antwortest dem Kunden DIREKT. Schreibe NIEMALS Sätze wie 'ich werde mich in Kürze bei Ihnen melden', 'ich setze mich mit Ihnen in Verbindung', 'ich komme auf Sie zu' oder ähnliches — diese E-Mail IST bereits die Kontaktaufnahme! Solche Floskeln sind NUR erlaubt, wenn du tatsächlich eine Information erst intern klären musst und sie nicht in der Wissensdatenbank steht.
- FINANZIERUNG: Du kannst keine konkreten Finanzierungsauskünfte geben. Wenn das Thema Finanzierung aufkommt, biete an, den Kontakt zu unserem projektübergreifenden Finanzierungspartner herzustellen. Formuliere sinngemäß: Wir arbeiten mit einem erfahrenen Finanzierungsexperten zusammen, der unverbindlich berät — gerne stelle ich den Kontakt her und wir können einen gemeinsamen Termin im Büro vereinbaren. Variiere die Formulierung.
- SPRACHQUALITÄT: Schreibe grammatikalisch einwandfreies Deutsch. NIEMALS englische Wörter oder Sätze verwenden — die gesamte Mail muss zu 100% auf Deutsch sein. Achte besonders auf korrekte Hilfsverben (ist angekommen, NICHT hat angekommen), korrekten Satzbau und natürliche Formulierungen.
- ANREDE: Verwende IMMER die Höflichkeitsform Sie. NIEMALS duzen, auch wenn der Kunde duzt. Konsequent durchziehen — kein Wechsel zwischen Du und Sie innerhalb einer Mail.
- OBJEKTSTANDORT: Verwende IMMER die exakte Objektadresse die im User-Prompt steht. NIEMALS einen anderen Ort, eine andere Stadt oder ein anderes Bundesland nennen! Kein Salzburg, kein Wien — NUR die korrekte Adresse aus dem Prompt.
- NEUBAUPROJEKTE: Bei Neubauprojekten gibt es KEINE Besichtigungen — die Wohnungen existieren noch nicht und können nicht besichtigt werden! Die Wörter Besichtigung, Besichtigungstermin, Begehung, vor Ort ansehen sind VERBOTEN bei Neubauprojekten. Stattdessen biete an: ein persönliches Beratungsgespräch im Büro, die Zusendung von Planunterlagen und Grundrissen, oder einen Termin um das Projekt gemeinsam durchzugehen. Erkenne Neubauprojekte am Objekt-Typ oder am Objektnamen (z.B. THE 37).
- LÄNGE DER ANTWORT: {$detail['instruction']}
- KEINE Markdown-Formatierung! Kein **fett**, kein *kursiv*, keine # Überschriften, keine Listen mit - oder *. Schreibe reinen Fließtext wie in einer normalen E-Mail.{$styleFeedback}";

        // Erstanfrage-Speziallogik
        $erstanfrageHint = '';
        if ($isErstanfrage) {
            if ($hasExpose) {
                $erstanfrageHint = "\n\n========================\nERSTANFRAGE MIT EXPOSÉ\n========================\nDies ist eine ERSTANFRAGE und ein Exposé ist vorhanden (wird als Anhang mitgesendet).\nDeine Antwort MUSS kurz und knapp sein (2-3 Sätze maximal):\n- Bedanke dich kurz für die Anfrage zur Immobilie (nenne Adresse/Bezeichnung).\n- Sage dass du dich freust, anbei das Exposé senden zu dürfen.\n- FALLS in der Nachricht SPEZIFISCHE FRAGEN stehen (z.B. Zimmergröße, Verfügbarkeit, Heizung etc.), beantworte diese ZUSÄTZLICH kurz und sachlich mit Fakten aus den Objektdaten/Wissensdatenbank.\n- KEINE langen Beschreibungen, KEIN Marketing-Text, NICHTS dazu dichten!\n- Halte die Antwort so kurz wie möglich.\n";
            }
            // Kein Expose = normaler KI-Entwurf ohne Sonderbehandlung
        }

        $user = "Beantworte diese Nachricht von {$stakeholder} zum Objekt {$propertyAddress}:\n\n--- NACHRICHT ---\n{$emailBody}\n--- ENDE ---\n\n{$knowledgeContext}{$erstanfrageHint}\n\nWICHTIG: Prüfe die Wissensdatenbank auf Einschränkungen (z.B. Besichtigungstermine, Verfügbarkeit) und berücksichtige diese ZWINGEND in deiner Antwort!\n\nSchreibe NUR den Email-Body (ohne Betreff, ohne An/Von). Beantworte NUR die konkreten Fragen. Keine Platzhalter! Keine langen Objektbeschreibungen! KEIN Markdown!";

        $result = $this->chat($system, $user, $detail['tokens']);
        // Strip any markdown formatting and signature that slipped through
        if ($result) {
            $result = self::stripSignature($result);
            $result = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $result);
            $result = preg_replace('/_{1,2}([^_]+)_{1,2}/', '$1', $result);
            $result = preg_replace('/^#{1,6}\s+/m', '', $result);
        }
        return $result;
    }

    public function generateFollowupRecommendation(string $stakeholder, string $propertyAddress, array $recentActivities): ?string
    {
        $activityText = collect($recentActivities)->map(fn($a) => "{$a['activity_date']}: {$a['category']} - {$a['activity']}")->implode("\n");

        $system = "Du bist ein Immobilien-Vertriebsberater. Empfehle eine Nachfass-Strategie basierend auf dem bisherigen Kontaktverlauf.";

        $user = "Stakeholder: {$stakeholder}\nObjekt: {$propertyAddress}\n\nBisherige Aktivitäten:\n{$activityText}\n\nEmpfehle:\n1. Soll nachgefasst werden? (ja/nein)\n2. Empfohlene Aktion (Anruf/Email/Abwarten)\n3. Formulierungsvorschlag (1-2 Sätze)\n\nAntworte als JSON: {\"should_follow_up\": true/false, \"action\": \"call/email/wait\", \"suggestion\": \"...\"}";

        return $this->chat($system, $user, 800);
    }

    public function generateDashboardAnalysis(array $stats, array $activities, string $propertyAddress): ?string
    {
        $system = "Du bist ein Immobilienmarkt-Analyst für SR-Homes. Erstelle eine professionelle Analyse für den Eigentümer.

KOMMUNIKATIONSREGELN:
- VERBOTEN: Mangel-Formulierungen, interne Todos, defensiver Ton, 'dringend', 'Engpass'
- Status: green = planmäßig, yellow = Optimierung möglich, orange = Aufmerksamkeit nötig
- Formuliere Marktfeedback positiv und lösungsorientiert
- Selbst-Check: Hilft es dem Eigentümer? Schwächt es SR-Homes? → Wenn B: NEU FORMULIEREN";

        $activitySummary = collect($activities)->take(20)->map(fn($a) => "{$a['activity_date']}: {$a['category']} - {$a['stakeholder']} - {$a['activity']}")->implode("\n");

        $user = "Objekt: {$propertyAddress}\n\nStatistiken:\n" . json_encode($stats, JSON_PRETTY_PRINT) . "\n\nLetzte Aktivitäten:\n{$activitySummary}\n\nErstelle eine Analyse als JSON:\n{\"status\": \"green|yellow|orange\", \"headline\": \"...\", \"summary\": \"2-3 Sätze\", \"kpis\": [{\"label\": \"...\", \"value\": \"...\", \"trend\": \"up|stable|down\"}], \"highlights\": [\"...\"], \"recommendation\": \"...\"}";

        return $this->chat($system, $user, 2000);
    }

    public function categorizeKnowledge(string $title, string $content): ?array
    {
        $categories = 'objektbeschreibung, ausstattung, lage_umgebung, preis_markt, rechtliches, energetik, verhandlung, eigentuemer_info, vermarktung, dokument_extrakt, sonstiges';

        $system = "Kategorisiere den folgenden Wissenseintrag für eine Immobilien-Knowledge-Base.";

        $user = "Titel: {$title}\nInhalt: {$content}\n\nKategorien: {$categories}\n\nAntworte als JSON: {\"category\": \"...\", \"confidence\": \"high|medium|low\"}";

        return $this->chatJson($system, $user, 200);
    }

    public function extractKnowledgeFromText(string $text, string $propertyAddress): ?array
    {
        $system = "Extrahiere strukturierte Wissensfakten aus dem folgenden Text über eine Immobilie. Jeder Fakt soll ein eigener Eintrag sein.";

        $categories = 'objektbeschreibung, ausstattung, lage_umgebung, preis_markt, rechtliches, energetik, verhandlung, eigentuemer_info, vermarktung, dokument_extrakt, sonstiges';

        $user = "Objekt: {$propertyAddress}\n\nText:\n{$text}\n\nExtrahiere Fakten als JSON-Array:\n[{\"title\": \"...\", \"content\": \"...\", \"category\": \"{$categories}\", \"confidence\": \"high|medium|low\"}]";

        return $this->chatJson($system, $user, 3000);
    }

    public function generateTodos(string $propertyAddress, array $activities, array $existingTasks): ?array
    {
        $activityText = collect($activities)->take(15)->map(fn($a) => "{$a['activity_date']}: {$a['category']} - {$a['stakeholder']} - {$a['activity']}")->implode("\n");
        $taskText = collect($existingTasks)->map(fn($t) => "- " . ($t['is_done'] ? '[x]' : '[ ]') . " {$t['title']}")->implode("\n");

        $system = "Du bist ein Immobilien-Vertriebsassistent. Generiere sinnvolle Aufgaben basierend auf den aktuellen Aktivitäten.";

        $user = "Objekt: {$propertyAddress}\n\nLetzte Aktivitäten:\n{$activityText}\n\nBestehende Aufgaben:\n{$taskText}\n\nGeneriere 2-5 neue, konkrete Aufgaben als JSON:\n[{\"title\": \"...\", \"priority\": \"low|medium|high|critical\", \"stakeholder\": \"...\"}]";

        return $this->chatJson($system, $user, 1500);
    }

    public function generateFollowupDraft(
        string $stakeholder,
        string $propertyAddress,
        string $threadContext,
        string $knowledgeContext,
        bool $hasPhone,
        string $tone = 'professional',
        int $daysSinceLastContact = 0,
        bool $hasUnansweredQuestion = false,
        string $today = '',
        bool $isSecondFollowup = false
    ): ?array {
        if (!$today) $today = date('Y-m-d');

        // Stil-Lernbeispiele aus Nutzerkorrekturen
        $styleFeedback = '';
        try {
            $feedbackRows = \Illuminate\Support\Facades\DB::select("
                SELECT original_text, edited_text FROM ai_style_feedback
                ORDER BY created_at DESC LIMIT 5
            ");
            if (!empty($feedbackRows)) {
                $styleFeedback = "\n\nSTIL-LERNBEISPIELE — Orientiere dich am Stil der USER-VERSION:\n";
                foreach ($feedbackRows as $i => $fb) {
                    $fb = (array) $fb;
                    $num = $i + 1;
                    $styleFeedback .= "Beispiel {$num}:\nKI: " . mb_substr($fb['original_text'], 0, 400) . "\nUSER: " . mb_substr($fb['edited_text'], 0, 400) . "\n---\n";
                }
            }
        } catch (\Exception $e) {}

        $phoneHint = $hasPhone
            ? "Der Lead hat eine Telefonnummer. Erstelle BEIDES: Gesprächsleitfaden (Stichpunkte, natürlich) UND E-Mail-Entwurf."
            : "Kein Telefon vorhanden. Erstelle nur einen E-Mail-Entwurf.";

        $questionHint = $hasUnansweredQuestion
            ? "WICHTIG: In der letzten Nachricht des Kunden war eine offene Frage, die noch nicht beantwortet wurde. Greife diese Frage auf."
            : "";

        $secondFollowupHint = $isSecondFollowup
            ? "WICHTIG — ZWEITES NACHFASSEN (Stufe 2):
Dies ist das ZWEITE Mal dass wir nachfassen. Der Interessent hat auf unsere erste Nachfass-Mail NICHT reagiert.
Der Ton muss jetzt DIREKTER und ABSCHLIESSENDER sein. Orientiere dich an diesem Muster:
- Bezug auf die vorherige Nachricht: 'Ich habe vor einigen Tagen bei Ihnen nachgefragt...'
- Feststellen dass keine Antwort kam: '...da ich leider noch keine Rueckmeldung erhalten habe...'
- Direkt nach einer finalen Entscheidung fragen: '...wollte ich hoeflich nachfragen, ob Sie bereits zu einer Entscheidung gekommen sind'
- Easy-Out anbieten: '...oder ob das Objekt doch nichts fuer Sie ist'
- Um Absagegrund bitten: 'In dem Fall waere es sehr freundlich, uns kurz den Grund mitzuteilen, damit wir unser Angebot verbessern koennen.'
- Maximal 4-5 Saetze. Kein Expose nochmal anbieten. Kein Objekt nochmal beschreiben. Rein die Frage nach dem Status."
            : "";

        $system = "Du bist Maximilian Hoelzl, SR-Homes Immobilien GmbH — konzessionierter Immobilientreuhaender.
Heute ist der {$today}. Du erstellst gerade eine E-Mail-Nachricht, die noch NICHT gesendet wurde.
Beziehe dich ausschliesslich auf Aktivitaeten, die im Verlauf dokumentiert sind. Erfinde keine Ereignisse.

========================
OBJEKT-ADRESSE (EXAKT VERWENDEN!)
========================
Das Objekt befindet sich an: {$propertyAddress}
Verwende IMMER diese exakte Adresse/Ortsangabe. NIEMALS den Standort aendern, ergaenzen oder einen anderen Ort nennen!
Wenn du den Standort erwaehnen willst, schreibe NUR was in der Adresse oben steht — NICHTS anderes.

WICHTIG: Analysiere den GESAMTEN bisherigen Verlauf und bestimme selbststaendig was fuer eine Art von Nachricht geschrieben werden muss:
- Wenn noch KEINE Antwort von SR-HOMES gesendet wurde → Erstantwort auf die Anfrage
- Wenn ein Expose gesendet wurde aber keine Reaktion kam → Nachfassen
- Wenn der Kunde geantwortet hat → Inhaltliche Antwort auf seine Fragen/Anliegen
- Wenn mehrfach nachgefasst wurde → Abschliessende/qualifizierende Nachfrage

{$phoneHint}
{$questionHint}
{$secondFollowupHint}

========================
IDENTITAET
========================
Du bist KEIN kreativer Werbetexter.
Du bist KEIN lockerer Sales-Writer.
Du bist KEIN Chatbot mit freiem Stil.
Du schreibst nur nuechtern, hochwertig, hoeflich, klar und maklertauglich.

========================
ANALYSE VOR DEM SCHREIBEN
========================
0. LETZTE NACHRICHT DES KUNDEN ANALYSIEREN (WICHTIGSTER SCHRITT):
   Lies die letzte Nachricht des Kunden WORT FUER WORT. Was sagt der Kunde WIRKLICH?
   - Stellt er Fragen? → Beantworte sie konkret (Typ 6)
   - Zeigt er Interesse? → Naechsten Schritt anbieten (Typ 3)
   - Sagt er ab oder kuehlt ab? (z.B. 'noch weit entfernt', 'bin noch am Sondieren', 'melde mich wenn', 'passt nicht', 'kein Interesse mehr') → NICHT verkaufen, NICHT Details nachschieben. Verstaendnis zeigen, Tuer offen lassen (Typ 7, Phase E)
   - Hat er ein konkretes Anliegen? → Darauf eingehen
   DEINE ANTWORT MUSS ZU DEM PASSEN WAS DER KUNDE GESCHRIEBEN HAT. Wenn der Kunde sagt 'ich bin noch weit entfernt', darfst du NICHT mit Preisen und Flaechen antworten.
1. LETZTE GESENDETE NACHRICHT: Was wurde wann geschickt? War es nur ein Expose oder gab es schon echten Austausch?
1b. NACHFASS-ZAEHLER: Zaehle im Verlauf wie oft SR-HOMES bereits NACHGEFASST hat. Zaehle NUR Eintraege mit Kategorie 'nachfassen' — Eintraege mit 'email-out' sind Erstantworten und zaehlen NICHT als Nachfassen. Wenn nachgefasst wurde (Zaehler > 0), MUSS diese Zahl im Text natuerlich erwaehnt werden (z.B. 'ich habe bereits zweimal nachgefragt'). Bei Erstantworten (Phase 0) oder ersten Nachrichten ist der Zaehler 0 — dann NICHT erwaehnen.
2. LEAD-STATUS einordnen: neu / mit Expose / mit echtem Interesse / nach Besichtigung / wahrscheinlich kalt
3. WAHRSCHEINLICHSTER GRUND fuer die Nichtantwort: Nachricht uebersehen / noch nicht geprueft / Unsicherheit (Preis/Lage/Finanzierung) / kein echtes Interesse mehr
4. LEAD-PHASE bestimmen:
   - Phase 0: ERSTANTWORT — Es wurde noch KEINE Antwort von SR-HOMES gesendet. Der Kunde hat gerade erst angefragt. → Freundliche Begruesssung, Dank fuer Interesse, konkret auf die Fragen/Wuensche des Kunden eingehen, Expose anbieten/ankuendigen, naechsten Schritt vorschlagen. WICHTIG: Wenn die Anfrage konkrete Fragen enthaelt (Preis, Verfuegbarkeit, Flaeche etc.), diese DIREKT beantworten soweit Objektwissen vorhanden ist.
   - Phase A: Nur Expose gesendet, noch kein echter Austausch → kurz, weich, niedrigschwellig
   - Phase B: Echtes Interesse war vorhanden, dann Funkstille → konkreten naechsten Schritt anbieten
   - Phase C: Nach Besichtigung (NUR wenn im Verlauf eine besichtigung-Aktivitaet existiert!) → persoenlich, Einwaende sichtbar machen, Entscheidungshindernis fragen
   - Phase D: Lead wahrscheinlich kalt → qualifizierend, freundlich, Easy-Out anbieten
   - Phase E: Kunde hat geantwortet und signalisiert Desinteresse/Abkuehlung/Zeitmangel → Verstaendnis zeigen, NICHT weiter verkaufen, KEINE Details/Preise/Flaechen pushen, Tuer offen lassen, maximal 3 Saetze
5. MAIL-TYP waehlen (nur einen):
   - Typ 0 Erstantwort: Noch keine Antwort gesendet → Begruessen, bedanken, Fragen beantworten, Expose ankuendigen, naechsten Schritt
   - Typ 1 Soft Reminder: nur Expose → kurze, leichte Frage ob noch Interesse
   - Typ 2 Value Add: Zusatzinfo die helfen koennte (USP, Grundriss, Rendite, Lage)
   - Typ 3 Next Step: wenn Interesse wahrscheinlich → konkrete Handlungsoption (Termin, Unterlagen, Beratungsgespraech — bei Neubauprojekten KEINE Besichtigung!)
   - Typ 4 Objection Probe: wenn Unsicherheit wahrscheinlich → elegant nach Hindernis fragen
   - Typ 5 Close the Loop: wenn kalt → freundlich qualifizieren, Easy-Out
   - Typ 6 Antwort: Der Kunde hat zuletzt geschrieben und wartet auf Antwort → inhaltlich auf seine Fragen/Anliegen eingehen, konkret und hilfreich antworten
   - Typ 7 Respektvoller Rueckzug: Der Kunde signalisiert Desinteresse, braucht Zeit, oder lehnt ab. REGELN: Verstaendnis zeigen, SEINE Entscheidung respektieren, Tuer offen lassen. NIEMALS mit Preisen/Flaechen/Details nachschieben. NIEMALS die Kontrolle uebernehmen (NICHT: 'ich kontaktiere Sie' oder 'ich merke Sie vor' — STATTDESSEN: 'melden Sie sich gerne jederzeit'). Spiegle die Absicht des Kunden: wenn er sagt er meldet sich, dann bestaetige das. Maximal 2-3 Saetze. Kurz, warm, fertig.
6. AUFHAENGER waehlen — genau einen:
   - Staerkster USP der Immobilie | Wahrscheinlichster Einwand | Offene Frage aus letzter Nachricht | Naechster logischer Schritt

========================
ABSOLUTE REGELN
========================
1. Niemals Informationen erfinden.
2. Niemals Annahmen treffen, die nicht im Datensatz stehen.
3. BESICHTIGUNGEN: Erwaehne Besichtigungen AUSSCHLIESSLICH wenn im Verlauf eine Aktivitaet mit Kategorie 'besichtigung' dokumentiert ist. Wenn keine Besichtigung stattfand, verwende NIEMALS Woerter wie Besichtigungstermin, Begehung, Besuch vor Ort, Ihr Termin, vor Ort angesehen, Eindruck vom Haus. AUCH WENN die Wissensdatenbank allgemeine Besichtigungsinfos enthaelt (z.B. 'Besichtigungen ab Mitte April') — das ist NICHT ein Beweis dass dieser Kontakt eine Besichtigung hatte. Bei Unsicherheit: NICHT erwaehnen.
4. Niemals lockere, flapsige oder seltsame Formulierungen verwenden.
5. Niemals Marketing-Blabla verwenden.
6. Niemals uebertrieben freundlich oder unterwuerfig schreiben.
7. Niemals emotional manipulativ schreiben.
8. Niemals Ausrufezeichen verwenden.
9. Niemals Smileys oder Emojis verwenden.
10. Niemals Umgangssprache verwenden.
11. Niemals mehr als 5 Saetze im Haupttext.
12. Niemals doppelte Aussagen schreiben.
13. Niemals unklare oder schwammige Aussagen schreiben.
14. Niemals den Eindruck erzeugen, dass automatisch geschrieben wurde.
15. Immer grammatikalisch korrekt und orthografisch sauber schreiben (ist angekommen, NICHT hat angekommen).
16. Immer Name, Objekt und Anlass korrekt verwenden.
17. Immer mit einer klaren, hoeflichen Handlungsaufforderung enden.
18. IMMER in sauberem, gehobenem, professionellem DEUTSCH schreiben. NIEMALS englische Woerter, Saetze oder Phrasen verwenden. Auch wenn Daten auf Englisch vorliegen, MUSS die Antwort zu 100 Prozent auf Deutsch sein.
19. Wenn Daten fehlen oder unsicher sind: lieber neutral formulieren statt raten.
20. Wenn der Fall heikel, emotional oder individuell ist: kennzeichne als MANUELLE PRUEFUNG ERFORDERLICH.
21. ANREDE: IMMER Hoeflichkeitsform Sie. NIEMALS duzen.
22. KONTEXT-TREUE: Deine Antwort muss EXAKT zum Inhalt und Ton der letzten Kundennachricht passen. Wenn der Kunde absagt oder Zeit braucht → akzeptiere es. Wenn er Fragen stellt → beantworte sie. NIEMALS gegen den Wunsch des Kunden verkaufen.
23. ABSAGE/ABKUEHLUNG RESPEKTIEREN: Wenn der Kunde signalisiert dass er nicht bereit ist, noch Zeit braucht, oder kein Interesse hat → SOFORT Typ 7 waehlen. Kein Nachschieben von Preisen, Flaechen, Features, USPs. WICHTIG: Spiegle die Worte des Kunden — wenn er sagt 'ich melde mich', antworte 'melden Sie sich gerne jederzeit' und NICHT 'wir kontaktieren Sie' oder 'ich merke Sie vor'. Der Kunde bestimmt den naechsten Schritt, NICHT du.

========================
SCHREIBSTIL
========================
Serioes, ruhig, klar, freundlich, professionell, knapp, vertrauenswuerdig.
NICHT: werblich, gekuenstelt, zu salopp, aufgesetzt, uebermotiviert, chatartig.

========================
TEXTAUFBAU
========================
1. Hoefliche, direkte Anrede
2. Kurzer Bezug auf Anfrage / Expose / Rueckmeldung (Besichtigung NUR erwaehnen wenn im Verlauf dokumentiert!)
2b. Wenn bereits nachgefasst wurde: Erwaehne natuerlich wie oft du schon geschrieben hast ('ich habe bereits einmal/zweimal nachgefragt', 'da dies meine dritte Nachricht ist'). Das zeigt dem Kunden, dass du aufmerksam bist und schafft sanften Druck.
3. Ein klarer Hauptsatz zum Zweck der Nachricht
4. Eine konkrete, einfache Handlungsaufforderung
5. Serioeser Abschluss

========================
BEVORZUGTE FORMULIERUNGEN
========================
- ich wollte kurz nachfragen
- ist die Immobilie grundsaetzlich noch interessant fuer Sie
- gerne beantworte ich offene Fragen
- gerne kann ich Ihnen einen Besichtigungstermin vorschlagen (NUR wenn noch keine Besichtigung stattfand UND es kein Neubauprojekt ist)
- falls die Immobilie nicht ganz passt, merke ich Sie gerne fuer passende Objekte vor
- ich moechte Sie nur korrekt einordnen
- ich freue mich ueber eine kurze Rueckmeldung

========================
VERBOTENE FORMULIERUNGEN
========================
- nur ganz kurz / ich hoffe, es geht Ihnen gut / ich wollte mich nochmals erkundigen
- waere es eventuell vielleicht moeglich / nur zur Sicherheit
- wir haben sehr viele Anfragen / schnell sein lohnt sich
- dieses tolle Objekt / einmalige Gelegenheit / exklusiv / sensationell
- traumhaft / perfekt fuer Sie / absolute Raritaet
- falls Sie meine Mail uebersehen haben
- ich merke Sie vor und kontaktiere Sie / ich melde mich bei Ihnen sobald (bei Typ 7: der KUNDE entscheidet wann er sich meldet, nicht wir)
- solche Missgeschicke passieren / das ist voellig verstaendlich (herablassend)
- ich freue mich dass (uebertrieben positiv bei Absagen/Abkuehlungen)

========================
SPEZIALREGELN
========================
- Je kaelter der Lead, desto kuerzer und leichter die Mail
- Je waermer der Lead, desto konkreter der naechste Schritt
- Fuer Eigennutzer: Wohnen, Lage, Alltag, Grundriss, Lebensqualitaet betonen
- Fuer Anleger: Rendite, Vermietbarkeit, Preis-Leistung betonen
- NEUBAUPROJEKTE: KEINE Besichtigungen — Wohnungen existieren noch nicht. Stattdessen: Beratungsgespraech, Planunterlagen, Grundrisse.
- FINANZIERUNG: Keine konkreten Auskuenfte. Kontakt zu Finanzierungspartner anbieten. Variiere die Formulierung.
- KEIN Markdown, keine Sternchen, kein fett/kursiv. Reiner Fliesstext.
- KEINE Platzhalter verwenden.
- KEINE Signatur anhaengen — wird automatisch beim Senden hinzugefuegt.
- E-Mail ENDET nach dem Grusswort. DANACH KEIN Name, KEINE Firma, KEINE Telefonnummer.
- NIEMALS eine Telefonnummer im Text erwaehnen.
- Jede Mail hat genau 1 Call-to-Action.
{$styleFeedback}";

        $user = "Kontakt: {$stakeholder}
Objekt: {$propertyAddress}
Tage seit letztem Kontakt: {$daysSinceLastContact}

BISHERIGER VERLAUF (chronologisch, alles vor heute):
{$threadContext}

{$knowledgeContext}

Antworte als JSON:
{
  \"lead_phase\": \"0\", \"A\", \"B\", \"C\", \"D\" oder \"E\",
  \"mail_type\": 0, 1, 2, 3, 4, 5, 6 oder 7,
  \"lead_status\": \"Ein-Satz-Einschätzung des Lead-Status\",
  \"mail_goal\": \"Ein-Satz-Ziel dieser Nachricht\",
  \"preferred_action\": \"call\" oder \"email\",
  \"call_script\": \"Gesprächsleitfaden in Stichpunkten (null wenn kein Telefon)\",
  \"email_subject\": \"Betreff\",
  \"email_body\": \"Nachricht ohne Signatur\"
}";

        $result = $this->chatJson($system, $user, 3000);
        // Strip markdown from email_body in case model ignores the instruction
        if ($result && isset($result['email_body'])) {
            $body = self::stripSignature($result['email_body']);
            $body = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $body);
            $body = preg_replace('/_{1,2}([^_]+)_{1,2}/', '$1', $body);
            $body = preg_replace('/^#{1,6}\s+/m', '', $body);
            $result['email_body'] = $body;
        }
        return $result;
    }


    /**
     * Generate a comprehensive Vermarktungsbericht (marketing report).
     * Two-layer output: owner (for customer portal) + broker (for internal use).
     */
    public function generateVermarktungsbericht(array $reportData, int $propertyId): ?array
    {
        $promptConfig = require base_path('config/prompts/vermarktungsbericht.php');
        $systemPrompt = $promptConfig['system_prompt'];

        $userMessage = $this->formatReportDataForAI($reportData, $propertyId);

        // Use Sonnet for better quality on this complex task
        $originalModel = $this->model;
        $this->model = 'claude-haiku-4-5-20251001';

        $result = $this->chatJson($systemPrompt, $userMessage, 12000);

        $this->model = $originalModel;

        return $result;
    }

    /**
     * Format aggregated report data as structured text for Claude.
     */
    private function formatReportDataForAI(array $data, int $propertyId): string
    {
        $aggregator = new \App\Services\ReportDataAggregator();
        $quality = $aggregator->assessDataQuality($data);

        $parts = [];

        // Meta
        $parts[] = "## DATENQUALITAET\n";
        $parts[] = "Qualitaet: {$quality['quality']} ({$quality['score']}/{$quality['total']})";
        if (!empty($quality['missing'])) {
            $parts[] = "Fehlende Daten: " . implode(', ', $quality['missing']);
        }
        $parts[] = "";

        // Property data
        $p = $data['property'] ?? [];
        $parts[] = "## OBJEKT-STAMMDATEN\n";
        $parts[] = "Property-ID: {$propertyId}";
        $parts[] = "Adresse: " . ($p['address'] ?? 'k.A.') . ', ' . ($p['city'] ?? '');
        $parts[] = "Typ: " . ($p['type'] ?? 'k.A.');
        $parts[] = "Status: " . ($p['status'] ?? 'k.A.');
        $parts[] = "Preis: EUR " . number_format((float)($p['purchase_price'] ?? 0), 0, ',', '.');
        $parts[] = "Wohnflaeche: " . ($p['total_area'] ?? 'k.A.') . " m2";
        $parts[] = "Preis/m2: EUR " . ($p['price_per_m2'] ?? 'k.A.');
        $parts[] = "Zimmer: " . ($p['rooms_amount'] ?? 'k.A.');
        $parts[] = "Baujahr: " . ($p['construction_year'] ?? 'k.A.');
        $parts[] = "Tage am Markt: " . ($p['days_on_market'] ?? 'k.A.');
        $parts[] = "Inserat seit: " . ($p['inserat_since'] ?? 'k.A.');
        $parts[] = "Eigentuemer: " . ($p['owner_name'] ?? 'k.A.');
        $parts[] = "";

        // Funnel
        $f = $data['funnel'] ?? [];
        $fc = $f['counts'] ?? [];
        $fr = $f['rates'] ?? [];
        $parts[] = "## KONVERSIONS-TRICHTER\n";
        $parts[] = "Anfragen: " . ($fc['anfragen'] ?? 0) . " (unique: " . ($fc['unique_anfragen'] ?? 0) . ")";
        $parts[] = "Exposes: " . ($fc['exposes'] ?? 0) . " (unique: " . ($fc['unique_exposes'] ?? 0) . ")";
        $parts[] = "Besichtigungen: " . ($fc['besichtigungen'] ?? 0) . " (unique: " . ($fc['unique_besichtigungen'] ?? 0) . ")";
        $parts[] = "Kaufanbote: " . ($fc['kaufanbote'] ?? 0) . " (unique: " . ($fc['unique_kaufanbote'] ?? 0) . ")";
        $parts[] = "Absagen: " . ($fc['absagen'] ?? 0) . " (unique: " . ($fc['unique_absagen'] ?? 0) . ")";
        $parts[] = "Nachfassen: " . ($fc['nachfassen'] ?? 0);
        $parts[] = "";
        $parts[] = "Konversionsraten:";
        $parts[] = "  Anfrage -> Expose: " . ($fr['anfrage_to_expose'] ?? 0) . "%";
        $parts[] = "  Expose -> Besichtigung: " . ($fr['expose_to_viewing'] ?? 0) . "%";
        $parts[] = "  Besichtigung -> Kaufanbot: " . ($fr['viewing_to_offer'] ?? 0) . "%";
        $parts[] = "  Anfrage -> Absage: " . ($fr['anfrage_to_absage'] ?? 0) . "%";
        $parts[] = "";

        // Temporal
        $t = $data['temporal'] ?? [];
        $parts[] = "## ZEITVERLAUF (Woechentliche Buckets)\n";
        $parts[] = "Trend: " . ($t['trend'] ?? 'k.A.');
        $parts[] = "Gesamtwochen: " . ($t['total_weeks'] ?? 0);
        if (!empty($t['weeks'])) {
            $parts[] = "\nWoche | Inbound | Outbound | Besicht. | Kaufanb. | Absagen | Unique";
            foreach (array_slice($t['weeks'], -12) as $w) {
                $parts[] = ($w['week_start'] ?? $w['week']) . " | " .
                    ($w['inbound'] ?? 0) . " | " . ($w['outbound'] ?? 0) . " | " .
                    ($w['viewings'] ?? 0) . " | " . ($w['kaufanbote'] ?? 0) . " | " .
                    ($w['absagen'] ?? 0) . " | " . ($w['unique_contacts'] ?? 0);
            }
        }
        $parts[] = "";

        // Feedback clusters
        $fb = $data['feedback'] ?? [];
        $parts[] = "## FEEDBACK-ANALYSE\n";
        $parts[] = "Total Feedback-Items: " . ($fb['total_feedback_items'] ?? 0);
        foreach ($fb['clusters'] ?? [] as $cluster) {
            $parts[] = "\n### Cluster: " . ($cluster['thema'] ?? '') . " (Gewicht: " . ($cluster['gewicht'] ?? '') . ", Anzahl: " . ($cluster['anzahl'] ?? 0) . ")";
            foreach (array_slice($cluster['items'] ?? [], 0, 5) as $item) {
                $parts[] = "  - " . ($item['stakeholder'] ?? 'Anonym') . " (" . ($item['date'] ?? '') . "): " . mb_substr($item['summary'] ?? '', 0, 200);
            }
        }
        $parts[] = "";

        // Viewings
        $v = $data['viewings'] ?? [];
        $vs = $v['stats'] ?? [];
        $parts[] = "## BESICHTIGUNGEN\n";
        $parts[] = "Gesamt: " . ($vs['total'] ?? 0) . ", Durchgefuehrt: " . ($vs['done'] ?? 0) . ", Geplant: " . ($vs['upcoming'] ?? 0) . ", Abgesagt: " . ($vs['cancelled'] ?? 0);
        foreach (array_slice($v['list'] ?? [], 0, 10) as $viewing) {
            $parts[] = "  - " . ($viewing['viewing_date'] ?? '') . " " . ($viewing['person_name'] ?? '') . " [" . ($viewing['status'] ?? '') . "] " . ($viewing['notes'] ?? '');
        }
        $parts[] = "";

        // Kaufanbote — VERBINDLICHE ZAHL aus dem zentralen System
        $ka = $data['kaufanbote'] ?? [];
        $kaufanbotCount = $ka['total_kaufanbote'] ?? 0;
        $parts[] = "## KAUFANBOTE\n";
        $parts[] = "VERBINDLICH: Es gibt genau {$kaufanbotCount} Kaufanbote. Diese Zahl ist EXAKT und darf im Bericht NICHT anders angegeben werden!";
        if (!empty($ka['units'])) {
            $parts[] = "Einheiten (Neubauprojekt): " . ($ka['total_units'] ?? 0) . " gesamt, " . ($ka['verkaufte_units'] ?? 0) . " verkauft";
        }
        $parts[] = "Kaufanbot-Verlauf (nur zur Info, NICHT zur Zaehlung verwenden):";
        foreach (array_slice($ka['kaufanbote'] ?? [], 0, 8) as $k) {
            $parts[] = "  - " . ($k['activity_date'] ?? '') . " " . ($k['stakeholder'] ?? '') . ": " . ($k['activity'] ?? '');
        }
        $parts[] = "";

        // Lead quality
        $l = $data['leads'] ?? [];
        $parts[] = "## LEAD-QUALITAET\n";
        $parts[] = "Gesamt: " . ($l['total'] ?? 0) . ", Aktiv: " . ($l['active'] ?? 0) . ", Mit Besichtigung: " . ($l['with_viewing'] ?? 0) . ", Mit Kaufanbot: " . $kaufanbotCount;
        foreach (array_slice($l['leads'] ?? [], 0, 15) as $lead) {
            $cats = $lead['categories'] ?? '';
            $parts[] = "  - " . ($lead['stakeholder'] ?? '') . " | Score: " . ($lead['progression_score'] ?? 0) . " | Status: " . ($lead['status'] ?? '') . " | Bes: " . ($lead['viewings'] ?? 0) . " | Angebote: " . ($lead['offers'] ?? 0) . " | Kategorien: {$cats}";
        }
        $parts[] = "";

        // Knowledge base
        $kb = $data['knowledge'] ?? [];
        $parts[] = "## KNOWLEDGE BASE\n";
        $parts[] = "Eintraege gesamt: " . ($kb['total'] ?? 0);
        foreach ($kb['by_category'] ?? [] as $cat => $entries) {
            $parts[] = "\n### {$cat} (" . count($entries) . " Eintraege)";
            foreach (array_slice($entries, 0, 5) as $e) {
                $parts[] = "  - " . ($e['title'] ?? '') . ": " . mb_substr($e['content'] ?? '', 0, 200);
            }
        }
        $parts[] = "";

        // Email insights
        $em = $data['emails'] ?? [];
        $es = $em['stats'] ?? [];
        $parts[] = "## EMAIL-ANALYSE\n";
        $parts[] = "Gesamt: " . ($es['total'] ?? 0) . ", Eingehend: " . ($es['inbound'] ?? 0) . ", Ausgehend: " . ($es['outbound'] ?? 0);
        $parts[] = "Erster Email: " . ($es['first_email'] ?? 'k.A.') . ", Letzter: " . ($es['last_email'] ?? 'k.A.');
        foreach (array_slice($em['summaries'] ?? [], 0, 15) as $email) {
            $dir = ($email['direction'] ?? '') === 'inbound' ? 'IN' : 'OUT';
            $parts[] = "  [{$dir}] " . ($email['email_date'] ?? '') . " " . ($email['stakeholder'] ?? $email['from_name'] ?? '') . ": " . mb_substr($email['ai_summary'] ?? '', 0, 200);
        }
        $parts[] = "";

        // Market context
        if (!empty($data['market'])) {
            $parts[] = "## MARKTKONTEXT\n";
            foreach ($data['market'] as $key => $mdata) {
                $val = is_array($mdata['value'] ?? null) ? json_encode($mdata['value'], JSON_UNESCAPED_UNICODE) : ($mdata['value'] ?? '');
                $parts[] = "  {$key}: " . mb_substr($val, 0, 300) . " (Stand: " . ($mdata['updated_at'] ?? '') . ")";
            }
        }

        // Activity timeline (last 50 for context)
        $parts[] = "\n## AKTIVITAETEN-TIMELINE (letzte 50)\n";
        foreach (array_slice(array_reverse($data['timeline'] ?? []), 0, 50) as $act) {
            $summary = $act['email_summary'] ?? '';
            $summaryText = $summary ? " | AI: " . mb_substr($summary, 0, 150) : '';
            $parts[] = ($act['activity_date'] ?? '') . " | " . ($act['category'] ?? '') . " | " . ($act['stakeholder'] ?? '') . " | " . mb_substr($act['activity'] ?? '', 0, 100) . $summaryText;
        }

        return implode("\n", $parts);
    }

}
