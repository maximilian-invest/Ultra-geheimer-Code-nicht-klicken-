<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\KaufanbotHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatController
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('portal.anthropic_api_key', env('ANTHROPIC_API_KEY'));
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Main chat endpoint — agentic loop with tool calling
     */
    public function chat(Request $request): array
    {
        $userMessage = trim($request->input('message', ''));
        $history     = $request->input('history', []);  // previous messages [{role, content}]

        if (!$userMessage) {
            return ['error' => 'Keine Nachricht angegeben.'];
        }

        // Build system prompt with live context
        $systemPrompt = $this->buildSystemPrompt();

        // Build messages array
        $messages = [];
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = [
                    'role'    => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Define tools
        $tools = $this->getToolDefinitions();

        // Agentic loop (max 8 iterations to prevent runaway)
        $maxIterations = 8;
        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $this->callClaude($systemPrompt, $messages, $tools);

            if (isset($response['error'])) {
                return ['reply' => 'Entschuldigung, es gab einen Fehler: ' . $response['error']];
            }

            $content  = $response['content'] ?? [];
            $stopReason = $response['stop_reason'] ?? 'end_turn';

            // Check if there are tool uses
            $toolUses = array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use');

            if (empty($toolUses) || $stopReason !== 'tool_use') {
                // No more tool calls — extract text response
                $textBlocks = array_filter($content, fn($b) => ($b['type'] ?? '') === 'text');
                $reply = implode("\n", array_map(fn($b) => $b['text'] ?? '', $textBlocks));
                return ['reply' => $reply ?: 'Ich konnte keine Antwort generieren.'];
            }

            // Add assistant message with tool_use blocks
            $messages[] = ['role' => 'assistant', 'content' => $content];

            // Execute each tool and build tool_result blocks
            $toolResults = [];
            foreach ($toolUses as $toolUse) {
                $toolName  = $toolUse['id'];
                $toolId    = $toolUse['id'];
                $toolFn    = $toolUse['name'] ?? '';
                $toolInput = $toolUse['input'] ?? [];

                $result = $this->executeTool($toolFn, $toolInput);

                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolId,
                    'content'     => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return ['reply' => 'Die Anfrage war zu komplex. Bitte versuche es mit einer einfacheren Frage.'];
    }

    /**
     * Build system prompt with live DB context
     */
    private function buildSystemPrompt(): string
    {
        $today = now()->format('d.m.Y');
        
        // Quick stats only - details via tools
        $propCount = DB::table('properties')->count();
        $brokerId = \Auth::id();
        $propQuery = DB::table('properties')->select('id', 'ref_id', 'address')->orderBy('id');
        if ($brokerId) $propQuery->where('broker_id', $brokerId);
        $propNames = $propQuery->get()
            ->map(fn($p) => "ID {$p->id}: {$p->ref_id} ({$p->address})")->implode(', ');

        // Dynamic user info
        $user = \Auth::user();
        $userName = $user ? $user->name : 'Maximilian Hölzl';
        $userEmail = $user ? $user->email : 'hoelzl@sr-homes.at';
        $settings = $user ? DB::table('admin_settings')->where('user_id', $user->id)->first() : null;
        $userCompany = $settings->signature_company ?? 'SR-Homes Immobilien GmbH';

        return <<<PROMPT
Du bist Sherlock, der KI-Assistent von {$userCompany}.
Makler: {$userName}. Heute: {$today}.
Du kannst ALLES: Daten abrufen, E-Mails senden, Aktivitäten/Kontakte/Termine/Immobilien verwalten, Portalzugänge anlegen.
Nutze IMMER die Tools statt zu sagen du könntest etwas nicht.

IMMOBILIEN ({$propCount}): {$propNames}

REGELN:
- Antworte immer auf Deutsch (österreichisches Deutsch)
- Sei professionell aber freundlich
- Bei Immobilienbezeichnungen: Nutze die Ref-IDs oder Adressen
- Geldbeträge immer mit € und Tausenderpunkten formatieren
- Nutze die verfügbaren Tools um Daten abzurufen bevor du antwortest
- Halte Antworten kurz und prägnant

WICHTIG - SCHREIBAKTIONEN (Aktivitäten anlegen, Kontakte erstellen):
- BEVOR du eine Aktivität anlegst, frage IMMER nach fehlenden Pflichtinfos:
  * Datum (Wann war/ist die Aktivität?) - NIEMALS einfach das heutige Datum annehmen
  * Uhrzeit (optional aber nachfragen)
  * Bei Besichtigungen: Dauer und Ergebnis nachfragen
- Bestätige VOR dem Anlegen kurz was du eintragen wirst (Immobilie, Stakeholder, Datum, Kategorie)
- Erst nach Bestätigung durch den User das Tool aufrufen
- Bei Kontakten: Frage nach Email/Telefon wenn nicht angegeben

WICHTIG - E-MAIL VERSAND:
- Du KANNST echte E-Mails senden (send_email Tool) - sage NIEMALS du könntest es nicht!
- BEVOR du eine E-Mail sendest: Zeige dem User Empfänger, Betreff und Inhalt
- Erst nach Bestätigung durch den User die E-Mail tatsächlich senden
- Formatiere den E-Mail-Body als HTML mit professioneller Formatierung
- Unterschrift immer: Mit freundlichen Grüßen, {$userName}, {$userCompany}
- Absender ist immer {$userEmail}

WICHTIG - DATENABFRAGEN:
- Du HAST Zugriff auf ALLE Immobiliendaten inkl. Provision, Eigentümer, Verkaufsvolumen
- Nutze get_property_details um Provisionsinfos, Eigentümer etc. abzurufen
- Nutze get_unit_details für Einheiten, Preise, Verfügbarkeit
- Sage NIEMALS du hättest keinen Zugriff auf Daten - prüfe es mit den Tools!
- Nutze search_emails um E-Mail-Verläufe zu finden
PROMPT;
    }

    /**
     * Tool definitions for Claude API
     */
    private function getToolDefinitions(): array
    {
        return [
            // ─── EXISTING TOOLS (14) ─────────────────────────────────────
            [
                'name' => 'search_properties',
                'description' => 'Suche nach Immobilien anhand von Adresse, Ref-ID, Stadt, Typ oder Status. Gibt eine Liste passender Immobilien zurück.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Suchbegriff (Adresse, Ref-ID, Stadt, Typ)',
                        ],
                        'status' => [
                            'type' => 'string',
                            'description' => 'Optional: Status-Filter (auftrag, inserat, anfragen, besichtigungen, angebote, verhandlung, verkauft)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_property_details',
                'description' => 'Hole detaillierte Informationen zu einer Immobilie inklusive Einheiten, Stellplätze und Statistiken.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Die Property-ID',
                        ],
                    ],
                    'required' => ['property_id'],
                ],
            ],
            [
                'name' => 'search_activities',
                'description' => 'Suche nach Aktivitäten. Kann nach Immobilie, Stakeholder, Kategorie und Zeitraum filtern.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Filter nach Immobilie',
                        ],
                        'stakeholder' => [
                            'type' => 'string',
                            'description' => 'Optional: Filter nach Stakeholder-Name',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Optional: Kategorie (email-in, email-out, anfrage, besichtigung, kaufanbot, absage, etc.)',
                        ],
                        'days' => [
                            'type' => 'integer',
                            'description' => 'Optional: Nur letzte X Tage (Standard: 30)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Optional: Max Ergebnisse (Standard: 20)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'search_contacts',
                'description' => 'Suche nach Kontakten anhand von Name, Email oder Telefonnummer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Suchbegriff (Name, Email oder Telefon)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'add_activity',
                'description' => 'Lege eine neue Aktivität für eine Immobilie an. WICHTIG: Datum muss vom User bestätigt sein, nie selbst annehmen!',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Die Property-ID',
                        ],
                        'stakeholder' => [
                            'type' => 'string',
                            'description' => 'Name des Stakeholders',
                        ],
                        'activity' => [
                            'type' => 'string',
                            'description' => 'Beschreibung der Aktivität',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Kategorie: besichtigung, eigentuemer, anfrage, kaufanbot, expose, nachfassen, absage, email-in, email-out. Verwende eigentuemer fuer allgemeine Aktivitaeten mit dem Eigentuemer. NICHT sonstiges/update/intern verwenden - diese sind im Kundendashboard unsichtbar!',
                        ],
                        'activity_date' => [
                            'type' => 'string',
                            'description' => 'Datum der Aktivität im Format YYYY-MM-DD. MUSS vom User angegeben werden!',
                        ],
                        'activity_time' => [
                            'type' => 'string',
                            'description' => 'Optional: Uhrzeit im Format HH:MM',
                        ],
                        'duration' => [
                            'type' => 'string',
                            'description' => 'Optional: Dauer der Aktivität (z.B. "1 Stunde", "30 Minuten")',
                        ],
                        'result' => [
                            'type' => 'string',
                            'description' => 'Optional: Ergebnis der Aktivität',
                        ],
                    ],
                    'required' => ['property_id', 'stakeholder', 'activity', 'category', 'activity_date'],
                ],
            ],
            [
                'name' => 'create_contact',
                'description' => 'Erstelle einen neuen Kontakt.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'full_name' => [
                            'type' => 'string',
                            'description' => 'Vollständiger Name',
                        ],
                        'email' => [
                            'type' => 'string',
                            'description' => 'Optional: E-Mail-Adresse',
                        ],
                        'phone' => [
                            'type' => 'string',
                            'description' => 'Optional: Telefonnummer',
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Optional: Notizen',
                        ],
                    ],
                    'required' => ['full_name'],
                ],
            ],
            [
                'name' => 'draft_email',
                'description' => 'Erstelle einen E-Mail-Entwurf. Gibt den Entwurfstext zurück (wird NICHT automatisch gesendet).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'to_name' => [
                            'type' => 'string',
                            'description' => 'Name des Empfängers',
                        ],
                        'to_email' => [
                            'type' => 'string',
                            'description' => 'E-Mail-Adresse des Empfängers',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'Betreff der E-Mail',
                        ],
                        'context' => [
                            'type' => 'string',
                            'description' => 'Kontext/Anweisungen für den E-Mail-Inhalt',
                        ],
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Zugehörige Immobilie',
                        ],
                    ],
                    'required' => ['to_name', 'subject', 'context'],
                ],
            ],
            [
                'name' => 'get_unit_details',
                'description' => 'Hole Details zu allen Einheiten einer Immobilie (Wohnungen, Stellplätze, Status, Käufer).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Die Property-ID',
                        ],
                    ],
                    'required' => ['property_id'],
                ],
            ],
            [
                'name' => 'send_email',
                'description' => 'Sende eine echte E-Mail über das SR-Homes Mailsystem (hoelzl@sr-homes.at). Die Mail wird TATSÄCHLICH versendet!',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'to_email' => [
                            'type' => 'string',
                            'description' => 'E-Mail-Adresse des Empfängers',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'Betreff',
                        ],
                        'body_html' => [
                            'type' => 'string',
                            'description' => 'E-Mail-Text als HTML',
                        ],
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Zugehörige Immobilie',
                        ],
                    ],
                    'required' => ['to_email', 'subject', 'body_html'],
                ],
            ],
            [
                'name' => 'search_emails',
                'description' => 'Suche in allen E-Mails (Posteingang und Ausgang) nach Stichworten, Absender oder Empfänger.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Suchbegriff (sucht in Betreff, Body, Absender, Empfänger)',
                        ],
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Filter nach Immobilie',
                        ],
                        'direction' => [
                            'type' => 'string',
                            'description' => 'Optional: inbound oder outbound',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Optional: Max Ergebnisse (Standard: 10)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'update_property',
                'description' => 'Aktualisiere Felder einer Immobilie (Status, Preis, Provision, etc.).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Die Property-ID',
                        ],
                        'fields' => [
                            'type' => 'object',
                            'description' => 'Zu aktualisierende Felder als Key-Value-Paare. Erlaubte Felder: status, price, commission_total, commission_makler, commission_note, owner_name, owner_email, owner_phone, description, highlights',
                        ],
                    ],
                    'required' => ['property_id', 'fields'],
                ],
            ],
            [
                'name' => 'update_unit',
                'description' => 'Aktualisiere eine Einheit (Status, Preis, Käufer, etc.).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id' => [
                            'type' => 'integer',
                            'description' => 'Die Unit-ID',
                        ],
                        'fields' => [
                            'type' => 'object',
                            'description' => 'Zu aktualisierende Felder. Erlaubt: status (verfügbar/reserviert/verkauft), price, buyer_name, buyer_email, notes',
                        ],
                    ],
                    'required' => ['unit_id', 'fields'],
                ],
            ],
            [
                'name' => 'get_tasks',
                'description' => 'Hole offene Aufgaben/Todos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'description' => 'Optional: Filter (open/done, Standard: open)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'add_task',
                'description' => 'Erstelle eine neue Aufgabe/Todo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Aufgabenbeschreibung',
                        ],
                        'property_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Zugehörige Immobilie',
                        ],
                        'due_date' => [
                            'type' => 'string',
                            'description' => 'Optional: Fälligkeitsdatum (YYYY-MM-DD)',
                        ],
                    ],
                    'required' => ['title'],
                ],
            ],

            // ─── NEW TOOLS (16) ──────────────────────────────────────────
            [
                'name' => 'get_unanswered',
                'description' => 'Zeige unbeantwortete Nachrichten (Emails auf die noch nicht geantwortet wurde) oder Leads die nachgefasst werden müssen.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'description' => 'unanswered = unbeantwortete Nachrichten, followup = Leads zum Nachfassen. Standard: unanswered',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_briefing',
                'description' => 'Hole das aktuelle Tagesbriefing mit Zusammenfassung aller wichtigen Aktivitäten.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'calendar_list',
                'description' => 'Zeige Kalendereinträge/Termine. Kann nach Zeitraum gefiltert werden.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => [
                            'type' => 'string',
                            'description' => 'Optional: Startdatum (YYYY-MM-DD). Standard: heute',
                        ],
                        'end_date' => [
                            'type' => 'string',
                            'description' => 'Optional: Enddatum (YYYY-MM-DD). Standard: +7 Tage',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'calendar_create',
                'description' => 'Erstelle einen neuen Kalendereintrag/Termin.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Titel des Termins'],
                        'start' => ['type' => 'string', 'description' => 'Startzeit (YYYY-MM-DD HH:MM oder YYYY-MM-DD)'],
                        'end' => ['type' => 'string', 'description' => 'Optional: Endzeit'],
                        'description' => ['type' => 'string', 'description' => 'Optional: Beschreibung'],
                        'location' => ['type' => 'string', 'description' => 'Optional: Ort'],
                        'property_id' => ['type' => 'integer', 'description' => 'Optional: Zugehörige Immobilie'],
                    ],
                    'required' => ['title', 'start'],
                ],
            ],
            [
                'name' => 'check_portal_access',
                'description' => 'Prüfe ob ein Kunde/Eigentümer bereits einen Zugang zum Kundenportal hat.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'E-Mail-Adresse des Kunden'],
                        'name' => ['type' => 'string', 'description' => 'Optional: Name des Kunden (Suche nach Name)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create_portal_access',
                'description' => 'Erstelle einen neuen Portalzugang für einen Kunden/Eigentümer. Generiert ein temporäres Passwort.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Vollständiger Name'],
                        'email' => ['type' => 'string', 'description' => 'E-Mail-Adresse (wird als Login verwendet)'],
                        'phone' => ['type' => 'string', 'description' => 'Optional: Telefonnummer'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Optional: Bestehende Customer-ID zuordnen'],
                        'user_type' => ['type' => 'string', 'description' => 'Optional: admin oder customer (Standard: customer)'],
                    ],
                    'required' => ['name', 'email'],
                ],
            ],
            [
                'name' => 'list_viewings',
                'description' => 'Zeige geplante Besichtigungen. Kann nach Immobilie und Status gefiltert werden.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => ['type' => 'integer', 'description' => 'Optional: Filter nach Immobilie'],
                        'status' => ['type' => 'string', 'description' => 'Optional: geplant, bestaetigt, abgesagt, durchgefuehrt'],
                        'upcoming_only' => ['type' => 'boolean', 'description' => 'Optional: Nur zukünftige Besichtigungen (Standard: true)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create_viewing',
                'description' => 'Erstelle eine neue Besichtigung für eine Immobilie.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => ['type' => 'integer', 'description' => 'Die Immobilien-ID'],
                        'viewing_date' => ['type' => 'string', 'description' => 'Datum (YYYY-MM-DD)'],
                        'viewing_time' => ['type' => 'string', 'description' => 'Uhrzeit (HH:MM)'],
                        'person_name' => ['type' => 'string', 'description' => 'Name der Person'],
                        'person_email' => ['type' => 'string', 'description' => 'Optional: E-Mail'],
                        'person_phone' => ['type' => 'string', 'description' => 'Optional: Telefon'],
                        'notes' => ['type' => 'string', 'description' => 'Optional: Notizen'],
                    ],
                    'required' => ['property_id', 'viewing_date', 'viewing_time', 'person_name'],
                ],
            ],
            [
                'name' => 'contact_timeline',
                'description' => 'Zeige die komplette Kontakthistorie einer Person (alle Aktivitäten, Emails, Kaufanbote).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'stakeholder' => ['type' => 'string', 'description' => 'Name des Kontakts/Stakeholders'],
                    ],
                    'required' => ['stakeholder'],
                ],
            ],
            [
                'name' => 'update_contact',
                'description' => 'Aktualisiere einen bestehenden Kontakt (Name, Email, Telefon, Notizen).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Die Kontakt-ID'],
                        'fields' => ['type' => 'object', 'description' => 'Zu aktualisierende Felder: full_name, email, phone, notes'],
                    ],
                    'required' => ['contact_id', 'fields'],
                ],
            ],
            [
                'name' => 'get_email_thread',
                'description' => 'Hole den vollständigen E-Mail-Verlauf zu einer bestimmten E-Mail oder einem Stakeholder bei einer Immobilie.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email_id' => ['type' => 'integer', 'description' => 'Optional: Portal-Email-ID für Thread-Kontext'],
                        'stakeholder' => ['type' => 'string', 'description' => 'Optional: Stakeholder-Name'],
                        'property_id' => ['type' => 'integer', 'description' => 'Optional: Immobilien-ID'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'mark_email_handled',
                'description' => 'Markiere eine E-Mail als erledigt/bearbeitet.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email_id' => ['type' => 'integer', 'description' => 'Die Portal-Email-ID'],
                    ],
                    'required' => ['email_id'],
                ],
            ],
            [
                'name' => 'get_analytics',
                'description' => 'Hole Performance-Analyse und Statistiken. Kann allgemein oder pro Immobilie sein.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => ['type' => 'integer', 'description' => 'Optional: Spezifische Immobilie. Ohne = Gesamtübersicht'],
                        'type' => ['type' => 'string', 'description' => 'Optional: performance, health, feedback, sales_volume, commission. Standard: performance'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'list_kaufanbote',
                'description' => 'Zeige alle Kaufanbote (hochgeladene PDFs) mit Status, Käufer und Preis.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'property_id' => ['type' => 'integer', 'description' => 'Optional: Filter nach Immobilie'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'update_activity',
                'description' => 'Aktualisiere oder lösche eine bestehende Aktivität.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'activity_id' => ['type' => 'integer', 'description' => 'Die Aktivitäts-ID'],
                        'action' => ['type' => 'string', 'description' => 'update oder delete'],
                        'fields' => ['type' => 'object', 'description' => 'Bei update: Felder zum Aktualisieren (activity, stakeholder, category, activity_date, result, duration)'],
                    ],
                    'required' => ['activity_id', 'action'],
                ],
            ],
            [
                'name' => 'create_property',
                'description' => 'Erstelle eine neue Immobilie im System.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ref_id' => ['type' => 'string', 'description' => 'Referenz-ID (z.B. Kau-Hau-Sal-01)'],
                        'address' => ['type' => 'string', 'description' => 'Straße und Hausnummer'],
                        'city' => ['type' => 'string', 'description' => 'Stadt/Ort'],
                        'zip' => ['type' => 'string', 'description' => 'PLZ'],
                        'type' => ['type' => 'string', 'description' => 'Typ (Haus, Wohnung, Grundstück, Neubauprojekt, etc.)'],
                        'purchase_price' => ['type' => 'number', 'description' => 'Optional: Preis in Euro'],
                        'owner_name' => ['type' => 'string', 'description' => 'Optional: Name des Eigentümers'],
                        'owner_email' => ['type' => 'string', 'description' => 'Optional: E-Mail des Eigentümers'],
                        'owner_phone' => ['type' => 'string', 'description' => 'Optional: Telefon des Eigentümers'],
                        'realty_status' => ['type' => 'string', 'description' => 'Optional: Status (aktiv, inaktiv, verkauft). Standard: aktiv'],
                    ],
                    'required' => ['ref_id', 'address', 'city', 'type'],
                ],
            ],
        ];
    }

    /**
     * API endpoint for Realtime voice mode tool execution.
     */
    public function executeToolApi(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $toolName = $request->json('tool_name', '');
        $arguments = $request->json('arguments', '{}');
        
        if (is_string($arguments)) {
            $input = json_decode($arguments, true) ?: [];
        } else {
            $input = $arguments;
        }

        $result = $this->executeTool($toolName, $input);
        
        return response()->json([
            'tool_name' => $toolName,
            'result' => $result,
        ]);
    }

    /**
     * Execute a tool and return result
     */
    public function executeTool(string $toolName, array $input): mixed
    {
        try {
            return match ($toolName) {
                // Existing tools
                'search_properties'    => $this->toolSearchProperties($input),
                'get_property_details' => $this->toolGetPropertyDetails($input),
                'search_activities'    => $this->toolSearchActivities($input),
                'search_contacts'      => $this->toolSearchContacts($input),
                'add_activity'         => $this->toolAddActivity($input),
                'create_contact'       => $this->toolCreateContact($input),
                'draft_email'          => $this->toolDraftEmail($input),
                'get_unit_details'     => $this->toolGetUnitDetails($input),
                'send_email'           => $this->toolSendEmail($input),
                'search_emails'        => $this->toolSearchEmails($input),
                'update_property'      => $this->toolUpdateProperty($input),
                'update_unit'          => $this->toolUpdateUnit($input),
                'get_tasks'            => $this->toolGetTasks($input),
                'add_task'             => $this->toolAddTask($input),
                // New tools
                'get_unanswered'       => $this->toolGetUnanswered($input),
                'get_briefing'         => $this->toolGetBriefing($input),
                'calendar_list'        => $this->toolCalendarList($input),
                'calendar_create'      => $this->toolCalendarCreate($input),
                'check_portal_access'  => $this->toolCheckPortalAccess($input),
                'create_portal_access' => $this->toolCreatePortalAccess($input),
                'list_viewings'        => $this->toolListViewings($input),
                'create_viewing'       => $this->toolCreateViewing($input),
                'contact_timeline'     => $this->toolContactTimeline($input),
                'update_contact'       => $this->toolUpdateContact($input),
                'get_email_thread'     => $this->toolGetEmailThread($input),
                'mark_email_handled'   => $this->toolMarkEmailHandled($input),
                'get_analytics'        => $this->toolGetAnalytics($input),
                'list_kaufanbote'      => $this->toolListKaufanbote($input),
                'update_activity'      => $this->toolUpdateActivity($input),
                'create_property'      => $this->toolCreateProperty($input),
                default                => ['error' => "Unbekanntes Tool: {$toolName}"],
            };
        } catch (\Throwable $e) {
            Log::error("AiChat tool error [{$toolName}]: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // ─── EXISTING Tool Implementations ──────────────────────────────

    private function toolSearchProperties(array $input): array
    {
        $query = $input['query'] ?? '';
        $status = $input['status'] ?? null;

        $q = DB::table('properties')
            ->select('id', 'ref_id', 'address', 'city', 'zip', 'object_type', 'purchase_price', 'realty_status', 'rooms_amount', 'total_area');

        if ($query) {
            $q->where(function($w) use ($query) {
                $w->where('address', 'LIKE', "%{$query}%")
                  ->orWhere('ref_id', 'LIKE', "%{$query}%")
                  ->orWhere('city', 'LIKE', "%{$query}%")
                  ->orWhere('object_type', 'LIKE', "%{$query}%");
            });
        }
        if ($status) {
            $q->where('realty_status', $status);
        }

        $results = $q->limit(20)->get();

        // Enrich with unit counts
        return $results->map(function($p) {
            $units = DB::table('property_units')->where('property_id', $p->id);
            $p->einheiten_gesamt = (clone $units)->count();
            $p->einheiten_verkauft = (clone $units)->where('realty_status', 'verkauft')->count();
            $p->preis_formatiert = '€ ' . number_format($p->purchase_price ?? 0, 0, ',', '.');
            return $p;
        })->toArray();
    }

    private function toolGetPropertyDetails(array $input): array
    {
        $id = $input['property_id'] ?? 0;
        $prop = DB::table('properties')->where('id', $id)->first();
        if (!$prop) return ['error' => 'Immobilie nicht gefunden.'];

        $units = DB::table('property_units')->where('property_id', $id)->get();
        $activities = DB::table('activities')
            ->where('property_id', $id)
            ->orderByDesc('activity_date')
            ->limit(10)
            ->get();

        $stats = [
            'anfragen'       => DB::table('activities')->where('property_id', $id)->where('category', 'anfrage')->count(),
            'besichtigungen' => DB::table('activities')->where('property_id', $id)->where('category', 'besichtigung')->count(),
            'kaufanbote'     => KaufanbotHelper::count($id),
            'absagen'        => DB::table('activities')->where('property_id', $id)->where('category', 'absage')->count(),
        ];

        // Commission info
        $provision = [
            'gesamt_prozent'  => $prop->commission_total,
            'makler_prozent'  => $prop->commission_makler,
            'notiz'           => $prop->commission_note ?? null,
        ];

        // Owner info
        $eigentuemer = [
            'name'  => $prop->owner_name ?? null,
            'email' => $prop->owner_email ?? null,
            'phone' => $prop->owner_phone ?? null,
        ];

        // Sold units with revenue
        $soldUnits = $units->where('status', 'verkauft');
        $totalRevenue = $soldUnits->sum('price');
        $maklerProvision = $prop->commission_makler
            ? round($totalRevenue * ($prop->commission_makler / 100), 2)
            : null;

        return [
            'immobilie'           => $prop,
            'eigentuemer'         => $eigentuemer,
            'provision'           => $provision,
            'verkaufsvolumen'     => number_format($totalRevenue, 0, ',', '.') . ' EUR',
            'makler_provision_eur'=> $maklerProvision ? number_format($maklerProvision, 0, ',', '.') . ' EUR' : 'nicht hinterlegt',
            'einheiten_gesamt'    => $units->count(),
            'einheiten_verkauft'  => $soldUnits->count(),
            'einheiten_verfuegbar'=> $units->where('status', '!=', 'verkauft')->count(),
            'statistik'           => $stats,
            'letzte_aktivitaeten' => $activities->toArray(),
        ];
    }

    private function toolSearchActivities(array $input): array
    {
        $q = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->select('a.id', 'a.activity_date', 'a.stakeholder', 'a.activity', 'a.result', 'a.category', 'p.ref_id', 'p.address');

        if (!empty($input['property_id'])) {
            $q->where('a.property_id', $input['property_id']);
        }
        if (!empty($input['stakeholder'])) {
            $q->where('a.stakeholder', 'LIKE', '%' . $input['stakeholder'] . '%');
        }
        if (!empty($input['category'])) {
            $q->where('a.category', $input['category']);
        }

        $days = $input['days'] ?? 30;
        $q->where('a.activity_date', '>=', now()->subDays($days));

        $limit = min($input['limit'] ?? 20, 50);
        return $q->orderByDesc('a.activity_date')->limit($limit)->get()->toArray();
    }

    private function toolSearchContacts(array $input): array
    {
        $query = $input['query'] ?? '';
        if (!$query) return [];

        return DB::table('contacts')
            ->where(function($q) use ($query) {
                $q->where('full_name', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function toolAddActivity(array $input): array
    {
        // Build activity description with time and duration if provided
        $activityText = $input['activity'];
        if (!empty($input['activity_time'])) {
            $activityText .= ' (Uhrzeit: ' . $input['activity_time'] . ')';
        }
        if (!empty($input['duration'])) {
            $activityText .= ' [Dauer: ' . $input['duration'] . ']';
        }

        $id = DB::table('activities')->insertGetId([
            'property_id'   => $input['property_id'],
            'stakeholder'   => $input['stakeholder'],
            'activity'      => $activityText,
            'category'      => $input['category'] ?? 'eigentuemer',
            'result'        => $input['result'] ?? null,
            'duration'      => $input['duration'] ?? null,
            'activity_date' => $input['activity_date'],
            'created_at'    => now(),
        ]);

        $prop = DB::table('properties')->where('id', $input['property_id'])->first();
        $propName = $prop ? ($prop->ref_id . ' - ' . $prop->address) : 'ID ' . $input['property_id'];

        return [
            'success' => true,
            'message' => "Aktivität #{$id} wurde angelegt für {$propName}.",
            'id' => $id,
            'details' => [
                'immobilie' => $propName,
                'stakeholder' => $input['stakeholder'],
                'datum' => $input['activity_date'],
                'uhrzeit' => $input['activity_time'] ?? null,
                'kategorie' => $input['category'],
            ],
        ];
    }

    private function toolCreateContact(array $input): array
    {
        $id = DB::table('contacts')->insertGetId([
            'full_name'  => $input['full_name'],
            'email'      => $input['email'] ?? null,
            'phone'      => $input['phone'] ?? null,
            'notes'      => $input['notes'] ?? null,
            'source'     => 'ai-assistant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['success' => true, 'message' => "Kontakt '{$input['full_name']}' wurde angelegt (#{$id}).", 'id' => $id];
    }

    private function toolDraftEmail(array $input): array
    {
        $toName   = $input['to_name'] ?? 'Interessent';
        $subject  = $input['subject'] ?? '';
        $context  = $input['context'] ?? '';
        $propId   = $input['property_id'] ?? null;

        $propInfo = '';
        if ($propId) {
            $prop = DB::table('properties')->where('id', $propId)->first();
            if ($prop) {
                $propInfo = "Immobilie: {$prop->ref_id} - {$prop->address}, {$prop->city}";
            }
        }

        // Use AnthropicService for the draft
        $anthropic = app(\App\Services\AnthropicService::class);
        $draft = $anthropic->chat(
            "Du bist Maximilian Hölzl von SR-Homes Immobilien GmbH, Salzburg. Erstelle einen professionellen E-Mail-Entwurf. Verwende die Sie-Form. Unterschrift: Mit freundlichen Grüßen, Maximilian Hölzl, SR-Homes Immobilien GmbH",
            "Erstelle eine E-Mail an {$toName}.\nBetreff: {$subject}\nKontext: {$context}\n{$propInfo}",
            1024
        );

        return [
            'subject' => $subject,
            'to_name' => $toName,
            'to_email' => $input['to_email'] ?? '',
            'draft'   => $draft,
            'hinweis' => 'Dies ist nur ein Entwurf. Die E-Mail wurde NICHT gesendet.',
        ];
    }

    private function toolGetUnitDetails(array $input): array
    {
        $propertyId = $input['property_id'] ?? 0;
        $prop = DB::table('properties')->where('id', $propertyId)->first();
        if (!$prop) return ['error' => 'Immobilie nicht gefunden.'];

        $units = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->orderBy('floor')
            ->orderBy('unit_number')
            ->get()
            ->map(function($u) {
                return [
                    'id'         => $u->id,
                    'nummer'     => $u->unit_number,
                    'stockwerk'  => $u->floor,
                    'typ'        => $u->is_parking ? 'Stellplatz' : 'Wohnung',
                    'groesse_m2' => $u->area_m2,
                    'preis'      => $u->price ? '€ ' . number_format($u->price, 0, ',', '.') : null,
                    'status'     => $u->status ?? 'verfügbar',
                    'kaeufer'    => $u->buyer_name,
                    'kaufanbot'  => $u->kaufanbot_pdf ? true : false,
                ];
            });

        return [
            'immobilie' => "{$prop->ref_id} - {$prop->address}",
            'einheiten' => $units->toArray(),
        ];
    }

    private function toolSendEmail(array $input): array
    {
        $toEmail   = $input['to_email'] ?? '';
        $subject   = $input['subject'] ?? '';
        $bodyHtml  = $input['body_html'] ?? '';
        $propId    = $input['property_id'] ?? null;

        if (!$toEmail || !$subject || !$bodyHtml) {
            return ['error' => 'to_email, subject und body_html sind Pflichtfelder.'];
        }

        // Use EmailService directly
        $emailService = app(\App\Services\EmailService::class);
        $result = $emailService->send(
            1, // account_id (hoelzl@sr-homes.at)
            $toEmail,
            $subject,
            $bodyHtml,
            $propId,
            null, // stakeholder
            null, // cc
            null, // bcc
            [],   // attachments
            null, // inReplyTo
            null, // references
            'email-out'
        );

        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'message' => "E-Mail an {$toEmail} wurde erfolgreich gesendet. Betreff: {$subject}"];
        }
        return ['error' => $result['error'] ?? 'E-Mail konnte nicht gesendet werden.'];
    }

    private function toolSearchEmails(array $input): array
    {
        $query = $input['query'] ?? '';
        $propId = $input['property_id'] ?? null;
        $direction = $input['direction'] ?? null;
        $limit = min($input['limit'] ?? 10, 30);

        $q = DB::table('portal_emails')
            ->select('id', 'direction', 'from_email', 'from_name', 'to_email', 'subject', 'ai_summary', 'email_date', 'category', 'property_id', 'stakeholder');

        if ($query) {
            $q->where(function($w) use ($query) {
                $w->where('subject', 'LIKE', "%{$query}%")
                  ->orWhere('from_email', 'LIKE', "%{$query}%")
                  ->orWhere('from_name', 'LIKE', "%{$query}%")
                  ->orWhere('to_email', 'LIKE', "%{$query}%")
                  ->orWhere('ai_summary', 'LIKE', "%{$query}%")
                  ->orWhere('body_text', 'LIKE', "%{$query}%");
            });
        }
        if ($propId) $q->where('property_id', $propId);
        if ($direction) $q->where('direction', $direction);

        return $q->orderByDesc('email_date')->limit($limit)->get()->toArray();
    }

    private function toolUpdateProperty(array $input): array
    {
        $id = $input['property_id'] ?? 0;
        $fields = $input['fields'] ?? [];

        $allowed = ['realty_status', 'purchase_price', 'commission_total', 'commission_makler', 'commission_note',
                     'owner_name', 'owner_email', 'owner_phone', 'description', 'highlights'];

        $update = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed)) {
                $update[$key] = $value;
            }
        }

        if (empty($update)) return ['error' => 'Keine gültigen Felder angegeben.'];

        $prop = DB::table('properties')->where('id', $id)->first();
        if (!$prop) return ['error' => 'Immobilie nicht gefunden.'];

        $update['updated_at'] = now();
        DB::table('properties')->where('id', $id)->update($update);

        $changed = implode(', ', array_keys($update));
        return ['success' => true, 'message' => "Immobilie {$prop->ref_id} aktualisiert: {$changed}"];
    }

    private function toolUpdateUnit(array $input): array
    {
        $id = $input['unit_id'] ?? 0;
        $fields = $input['fields'] ?? [];

        $allowed = ['status', 'price', 'buyer_name', 'buyer_email', 'notes'];

        $update = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed)) {
                $update[$key] = $value;
            }
        }

        if (empty($update)) return ['error' => 'Keine gültigen Felder angegeben.'];

        $unit = DB::table('property_units')->where('id', $id)->first();
        if (!$unit) return ['error' => 'Einheit nicht gefunden.'];

        $update['updated_at'] = now();
        DB::table('property_units')->where('id', $id)->update($update);

        return ['success' => true, 'message' => "Einheit {$unit->unit_number} aktualisiert: " . implode(', ', array_keys($update))];
    }

    private function toolGetTasks(array $input): array
    {
        $status = $input['status'] ?? 'open';

        $q = DB::table('tasks')
            ->select('id', 'title', 'property_id', 'due_date', 'is_done', 'created_at');

        if ($status === 'open') {
            $q->where('is_done', 0);
        } elseif ($status === 'done') {
            $q->where('is_done', 1);
        }

        $tasks = $q->orderByDesc('created_at')->limit(30)->get();

        // Enrich with property info
        return $tasks->map(function($t) {
            if ($t->property_id) {
                $prop = DB::table('properties')->where('id', $t->property_id)->first();
                $t->immobilie = $prop ? $prop->ref_id . ' - ' . $prop->address : null;
            }
            return $t;
        })->toArray();
    }

    private function toolAddTask(array $input): array
    {
        $title = $input['title'] ?? '';
        if (!$title) return ['error' => 'Aufgabenbeschreibung fehlt.'];

        $id = DB::table('tasks')->insertGetId([
            'title'       => $title,
            'property_id' => $input['property_id'] ?? null,
            'due_date'    => $input['due_date'] ?? null,
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return ['success' => true, 'message' => "Aufgabe erstellt: '{$title}' (#{$id})", 'id' => $id];
    }

    // ─── NEW Tool Implementations ───────────────────────────────────

    private function toolGetUnanswered(array $input): array
    {
        $mode = $input['mode'] ?? 'unanswered';
        $request = new \Illuminate\Http\Request();
        $request->merge(['mode' => $mode, 'limit' => 20]);
        $controller = app(\App\Http\Controllers\Admin\FollowupController::class);
        $response = $controller->index($request);
        $data = json_decode($response->getContent(), true);
        return $data;
    }

    private function toolGetBriefing(array $input): array
    {
        // Build a briefing from recent data
        $today = now()->toDateString();
        $week = now()->subDays(7)->toDateString();

        $newEmails = DB::table('portal_emails')
            ->where('direction', 'inbound')
            ->where('email_date', '>=', $today)
            ->count();

        $newActivities = DB::table('activities')
            ->where('activity_date', '>=', $today)
            ->count();

        $unanswered = DB::table('activities as a')
            ->select('a.stakeholder', 'a.activity_date', 'a.category', 'p.ref_id', 'p.address')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->whereIn('a.category', ['anfrage', 'email-in', 'besichtigung', 'kaufanbot'])
            ->whereNotExists(function($q) {
                $q->select(DB::raw(1))
                  ->from('activities as a2')
                  ->whereColumn('a2.property_id', 'a.property_id')
                  ->whereColumn('a2.stakeholder', 'a.stakeholder')
                  ->whereIn('a2.category', ['email-out', 'nachfassen'])
                  ->whereColumn('a2.activity_date', '>', 'a.activity_date');
            })
            ->where('a.activity_date', '>=', $week)
            ->orderByDesc('a.activity_date')
            ->limit(10)
            ->get();

        $upcomingViewings = DB::table('viewings as v')
            ->leftJoin('properties as p', 'v.property_id', '=', 'p.id')
            ->select('v.*', 'p.ref_id', 'p.address')
            ->where('v.viewing_date', '>=', $today)
            ->whereIn('v.status', ['geplant', 'bestaetigt'])
            ->orderBy('v.viewing_date')
            ->orderBy('v.viewing_time')
            ->limit(10)
            ->get();

        $recentKaufanbote = DB::table('property_units as u')
            ->leftJoin('properties as p', 'u.property_id', '=', 'p.id')
            ->whereNotNull('u.kaufanbot_pdf')
            ->where('u.kaufanbot_pdf', '!=', '')
            ->select('u.unit_number', 'u.buyer_name', 'u.price', 'p.ref_id', 'p.address')
            ->orderByDesc('u.updated_at')
            ->limit(5)
            ->get();

        return [
            'datum' => now()->format('d.m.Y'),
            'neue_emails_heute' => $newEmails,
            'aktivitaeten_heute' => $newActivities,
            'unbeantwortete_leads' => $unanswered->toArray(),
            'kommende_besichtigungen' => $upcomingViewings->toArray(),
            'aktuelle_kaufanbote' => $recentKaufanbote->toArray(),
        ];
    }

    private function toolCalendarList(array $input): array
    {
        $start = $input['start_date'] ?? now()->toDateString();
        $end = $input['end_date'] ?? now()->addDays(7)->toDateString();

        $request = new \Illuminate\Http\Request();
        $request->merge(['start' => $start, 'end' => $end]);

        try {
            $controller = app(\App\Http\Controllers\Admin\CalendarController::class);
            $response = $controller->listEvents($request);
            return json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            // Fallback: check viewings table
            $viewings = DB::table('viewings as v')
                ->leftJoin('properties as p', 'v.property_id', '=', 'p.id')
                ->select('v.*', 'p.ref_id', 'p.address')
                ->whereBetween('v.viewing_date', [$start, $end])
                ->orderBy('v.viewing_date')
                ->get();
            return ['events' => $viewings->toArray(), 'source' => 'viewings_table'];
        }
    }

    private function toolCalendarCreate(array $input): array
    {
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'title' => $input['title'],
            'start' => $input['start'],
            'end' => $input['end'] ?? null,
            'description' => $input['description'] ?? null,
            'location' => $input['location'] ?? null,
            'property_id' => $input['property_id'] ?? null,
        ]);

        try {
            $controller = app(\App\Http\Controllers\Admin\CalendarController::class);
            $response = $controller->createEvent($request);
            return json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            return ['error' => 'Kalendereintrag konnte nicht erstellt werden: ' . $e->getMessage()];
        }
    }

    private function toolCheckPortalAccess(array $input): array
    {
        $email = $input['email'] ?? null;
        $name = $input['name'] ?? null;

        $q = DB::table('users');
        if ($email) $q->where('email', $email);
        elseif ($name) $q->where('name', 'LIKE', "%{$name}%");
        else return ['error' => 'Email oder Name angeben.'];

        $users = $q->select('id', 'name', 'email', 'user_type', 'customer_id', 'created_at')->get();

        if ($users->isEmpty()) {
            return ['has_access' => false, 'message' => 'Kein Portalzugang gefunden.'];
        }

        return [
            'has_access' => true,
            'users' => $users->toArray(),
        ];
    }

    private function toolCreatePortalAccess(array $input): array
    {
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';

        if (!$name || !$email) return ['error' => 'Name und Email sind Pflichtfelder.'];

        // Check if already exists
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            return ['error' => "Ein Benutzer mit der Email {$email} existiert bereits (ID: {$existing->id}, Name: {$existing->name})."];
        }

        // Generate temp password
        $tempPassword = 'SR' . strtoupper(substr(md5(time()), 0, 6)) . '!';

        // Find or create customer
        $customerId = $input['customer_id'] ?? null;
        if (!$customerId) {
            $existingCustomer = DB::table('customers')->where('email', $email)->first();
            if ($existingCustomer) {
                $customerId = $existingCustomer->id;
            } else {
                $customerId = DB::table('customers')->insertGetId([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $input['phone'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'phone' => $input['phone'] ?? null,
            'password' => bcrypt($tempPassword),
            'user_type' => $input['user_type'] ?? 'customer',
            'customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => "Portalzugang für '{$name}' erstellt.",
            'user_id' => $userId,
            'email' => $email,
            'temp_password' => $tempPassword,
            'customer_id' => $customerId,
            'hinweis' => 'Das temporäre Passwort muss dem Kunden mitgeteilt werden. Bei erster Anmeldung sollte es geändert werden.',
        ];
    }

    private function toolListViewings(array $input): array
    {
        $q = DB::table('viewings as v')
            ->leftJoin('properties as p', 'v.property_id', '=', 'p.id')
            ->select('v.*', 'p.ref_id', 'p.address', 'p.city');

        if (!empty($input['property_id'])) $q->where('v.property_id', $input['property_id']);
        if (!empty($input['status'])) $q->where('v.status', $input['status']);

        $upcomingOnly = $input['upcoming_only'] ?? true;
        if ($upcomingOnly) $q->where('v.viewing_date', '>=', now()->toDateString());

        return $q->orderBy('v.viewing_date')->orderBy('v.viewing_time')->limit(30)->get()->toArray();
    }

    private function toolCreateViewing(array $input): array
    {
        $id = DB::table('viewings')->insertGetId([
            'property_id' => $input['property_id'],
            'viewing_date' => $input['viewing_date'],
            'viewing_time' => $input['viewing_time'],
            'person_name' => $input['person_name'],
            'person_email' => $input['person_email'] ?? null,
            'person_phone' => $input['person_phone'] ?? null,
            'status' => 'geplant',
            'notes' => $input['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prop = DB::table('properties')->where('id', $input['property_id'])->first();
        return [
            'success' => true,
            'message' => "Besichtigung #{$id} angelegt: {$input['person_name']} am {$input['viewing_date']} um {$input['viewing_time']} für " . ($prop ? $prop->ref_id : 'ID '.$input['property_id']),
            'id' => $id,
        ];
    }

    private function toolContactTimeline(array $input): array
    {
        $stakeholder = $input['stakeholder'] ?? '';
        if (!$stakeholder) return ['error' => 'Stakeholder-Name fehlt.'];

        $activities = DB::table('activities as a')
            ->leftJoin('properties as p', 'a.property_id', '=', 'p.id')
            ->where('a.stakeholder', 'LIKE', "%{$stakeholder}%")
            ->select('a.id', 'a.activity_date', 'a.activity', 'a.category', 'a.result', 'p.ref_id', 'p.address')
            ->orderByDesc('a.activity_date')
            ->limit(50)
            ->get();

        $emails = DB::table('portal_emails')
            ->where(function($q) use ($stakeholder) {
                $q->where('from_name', 'LIKE', "%{$stakeholder}%")
                  ->orWhere('stakeholder', 'LIKE', "%{$stakeholder}%");
            })
            ->select('id', 'direction', 'subject', 'email_date', 'from_email', 'to_email', 'ai_summary')
            ->orderByDesc('email_date')
            ->limit(20)
            ->get();

        $contact = DB::table('contacts')
            ->where('full_name', 'LIKE', "%{$stakeholder}%")
            ->first();

        return [
            'kontakt' => $contact,
            'aktivitaeten' => $activities->toArray(),
            'emails' => $emails->toArray(),
        ];
    }

    private function toolUpdateContact(array $input): array
    {
        $id = $input['contact_id'] ?? 0;
        $fields = $input['fields'] ?? [];

        $allowed = ['full_name', 'email', 'phone', 'notes'];
        $update = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed)) $update[$key] = $value;
        }

        if (empty($update)) return ['error' => 'Keine gültigen Felder.'];

        $contact = DB::table('contacts')->where('id', $id)->first();
        if (!$contact) return ['error' => 'Kontakt nicht gefunden.'];

        $update['updated_at'] = now();
        DB::table('contacts')->where('id', $id)->update($update);

        return ['success' => true, 'message' => "Kontakt '{$contact->full_name}' aktualisiert: " . implode(', ', array_keys($update))];
    }

    private function toolGetEmailThread(array $input): array
    {
        $emailId = $input['email_id'] ?? null;
        $stakeholder = $input['stakeholder'] ?? null;
        $propertyId = $input['property_id'] ?? null;

        $q = DB::table('portal_emails')
            ->select('id', 'direction', 'from_email', 'from_name', 'to_email', 'subject', 'body_text', 'ai_summary', 'email_date', 'category');

        if ($emailId) {
            // Get the email and related thread
            $email = DB::table('portal_emails')->where('id', $emailId)->first();
            if (!$email) return ['error' => 'Email nicht gefunden.'];

            $q->where(function($w) use ($email) {
                $w->where('stakeholder', $email->stakeholder)
                  ->where('property_id', $email->property_id);
            });
        } elseif ($stakeholder && $propertyId) {
            $q->where('stakeholder', 'LIKE', "%{$stakeholder}%")
              ->where('property_id', $propertyId);
        } elseif ($stakeholder) {
            $q->where(function($w) use ($stakeholder) {
                $w->where('stakeholder', 'LIKE', "%{$stakeholder}%")
                  ->orWhere('from_name', 'LIKE', "%{$stakeholder}%");
            });
        } else {
            return ['error' => 'email_id, stakeholder oder property_id angeben.'];
        }

        return $q->orderBy('email_date')->limit(30)->get()->toArray();
    }

    private function toolMarkEmailHandled(array $input): array
    {
        $id = $input['email_id'] ?? 0;
        $email = DB::table('portal_emails')->where('id', $id)->first();
        if (!$email) return ['error' => 'Email nicht gefunden.'];

        // Create an 'update' activity to mark as handled
        DB::table('activities')->insert([
            'property_id' => $email->property_id,
            'stakeholder' => $email->stakeholder ?? $email->from_name,
            'activity' => 'Als erledigt markiert',
            'category' => 'update',
            'activity_date' => now()->toDateString(),
            'source_email_id' => $id,
            'created_at' => now(),
        ]);

        return ['success' => true, 'message' => "Email #{$id} als erledigt markiert."];
    }

    private function toolGetAnalytics(array $input): array
    {
        $propertyId = $input['property_id'] ?? null;
        $type = $input['type'] ?? 'performance';

        if ($type === 'sales_volume' || $type === 'commission') {
            $request = new \Illuminate\Http\Request();
            $request->merge(['period' => 'all']);
            try {
                $controller = app(\App\Http\Controllers\Admin\PropertySettingsController::class);
                if ($type === 'sales_volume') {
                    $response = $controller->getSalesVolume($request);
                } else {
                    $response = $controller->getCommissionSummary($request);
                }
                return json_decode($response->getContent(), true);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        if ($propertyId) {
            $prop = DB::table('properties')->where('id', $propertyId)->first();
            if (!$prop) return ['error' => 'Immobilie nicht gefunden.'];

            $days30 = now()->subDays(30)->toDateString();
            $days7 = now()->subDays(7)->toDateString();

            return [
                'immobilie' => $prop->ref_id . ' - ' . $prop->address,
                'status' => $prop->realty_status,
                'anfragen_gesamt' => DB::table('activities')->where('property_id', $propertyId)->where('category', 'anfrage')->count(),
                'anfragen_30_tage' => DB::table('activities')->where('property_id', $propertyId)->where('category', 'anfrage')->where('activity_date', '>=', $days30)->count(),
                'besichtigungen' => DB::table('activities')->where('property_id', $propertyId)->where('category', 'besichtigung')->count(),
                'kaufanbote' => KaufanbotHelper::count($propertyId),
                'absagen' => DB::table('activities')->where('property_id', $propertyId)->where('category', 'absage')->count(),
                'emails_letzte_7_tage' => DB::table('portal_emails')->where('property_id', $propertyId)->where('email_date', '>=', $days7)->count(),
                'einheiten_gesamt' => DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->count(),
                'einheiten_verkauft' => DB::table('property_units')->where('property_id', $propertyId)->where('is_parking', 0)->where('realty_status', 'verkauft')->count(),
            ];
        }

        // Overall performance
        $days30 = now()->subDays(30)->toDateString();
        return [
            'zeitraum' => 'Letzte 30 Tage',
            'anfragen' => DB::table('activities')->where('category', 'anfrage')->where('activity_date', '>=', $days30)->count(),
            'besichtigungen' => DB::table('activities')->where('category', 'besichtigung')->where('activity_date', '>=', $days30)->count(),
            'kaufanbote_gesamt' => KaufanbotHelper::countAll(),
            'emails_eingehend' => DB::table('portal_emails')->where('direction', 'inbound')->where('email_date', '>=', $days30)->count(),
            'emails_ausgehend' => DB::table('portal_emails')->where('direction', 'outbound')->where('email_date', '>=', $days30)->count(),
            'neue_kontakte' => DB::table('contacts')->where('created_at', '>=', $days30)->count(),
            'immobilien_aktiv' => DB::table('properties')->whereNotIn('realty_status', ['verkauft'])->count(),
            'immobilien_verkauft' => DB::table('properties')->where('realty_status', 'verkauft')->count(),
        ];
    }

    private function toolListKaufanbote(array $input): array
    {
        $q = DB::table('property_units as u')
            ->leftJoin('properties as p', 'u.property_id', '=', 'p.id')
            ->whereNotNull('u.kaufanbot_pdf')
            ->where('u.kaufanbot_pdf', '!=', '')
            ->select('u.id', 'u.unit_number', 'u.buyer_name', 'u.buyer_email', 'u.price', 'u.status', 'u.kaufanbot_pdf', 'p.ref_id', 'p.address', 'p.city');

        if (!empty($input['property_id'])) $q->where('u.property_id', $input['property_id']);

        return $q->orderByDesc('u.updated_at')->get()->map(function($k) {
            $k->preis_formatiert = $k->price ? number_format($k->price, 0, ',', '.') . ' EUR' : null;
            return $k;
        })->toArray();
    }

    private function toolUpdateActivity(array $input): array
    {
        $id = $input['activity_id'] ?? 0;
        $action = $input['action'] ?? 'update';

        $activity = DB::table('activities')->where('id', $id)->first();
        if (!$activity) return ['error' => "Aktivität #{$id} nicht gefunden."];

        if ($action === 'delete') {
            DB::table('activities')->where('id', $id)->delete();
            return ['success' => true, 'message' => "Aktivität #{$id} gelöscht."];
        }

        $fields = $input['fields'] ?? [];
        $allowed = ['activity', 'stakeholder', 'category', 'activity_date', 'result', 'duration'];
        $update = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed)) $update[$key] = $value;
        }

        if (empty($update)) return ['error' => 'Keine gültigen Felder.'];

        DB::table('activities')->where('id', $id)->update($update);
        return ['success' => true, 'message' => "Aktivität #{$id} aktualisiert: " . implode(', ', array_keys($update))];
    }

    private function toolCreateProperty(array $input): array
    {
        $data = [
            'ref_id' => $input['ref_id'],
            'address' => $input['address'],
            'city' => $input['city'],
            'zip' => $input['zip'] ?? null,
            'type' => $input['type'],
            'purchase_price' => $input['purchase_price'] ?? null,
            'owner_name' => $input['owner_name'] ?? null,
            'owner_email' => $input['owner_email'] ?? null,
            'owner_phone' => $input['owner_phone'] ?? null,
            'status' => $input['status'] ?? 'auftrag',
            'broker_id' => 1, // Maximilian
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Create customer if owner info provided
        $customerId = null;
        if (!empty($input['owner_name'])) {
            $existingCustomer = null;
            if (!empty($input['owner_email'])) {
                $existingCustomer = DB::table('customers')->where('email', $input['owner_email'])->first();
            }
            if (!$existingCustomer) {
                $customerId = DB::table('customers')->insertGetId([
                    'name' => $input['owner_name'],
                    'email' => $input['owner_email'] ?? null,
                    'phone' => $input['owner_phone'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $customerId = $existingCustomer->id;
            }
            $data['customer_id'] = $customerId;
        }

        $id = DB::table('properties')->insertGetId($data);

        return [
            'success' => true,
            'message' => "Immobilie '{$input['ref_id']}' erstellt: {$input['address']}, {$input['city']} (#{$id})",
            'property_id' => $id,
        ];
    }

    // ─── Claude API Call ─────────────────────────────────────────────

    private function callClaude(string $system, array $messages, array $tools): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'system'     => $system,
                'messages'   => $messages,
                'tools'      => $tools,
            ]);

            if ($response->failed()) {
                Log::error('AiChat Claude API error: ' . $response->body());
                return ['error' => 'Claude API Fehler: ' . $response->status()];
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('AiChat Claude exception: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Text-to-Speech via OpenAI API
     */
    public function tts(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $text = trim($request->input('text', ''));
        if (!$text) {
            return response()->json(['error' => 'No text provided'], 400);
        }

        // Clean text for speech
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);
        $text = preg_replace('/\*(.*?)\*/', '$1', $text);
        $text = preg_replace('/`(.*?)`/', '$1', $text);
        $text = preg_replace('/#{1,3}\s/', '', $text);
        $text = str_replace(['€ ', '€'], ['Euro ', 'Euro '], $text);
        $text = str_replace('m²', 'Quadratmeter', $text);
        
        // Limit text length (OpenAI TTS max is 4096 chars)
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 4000) . '...';
        }

        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'OpenAI API key not configured'], 500);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/audio/speech', [
                'model' => 'tts-1',
                'input' => $text,
                'voice' => 'onyx',
                'response_format' => 'mp3',
                'speed' => 1.25,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI TTS error: ' . $response->body());
                return response()->json(['error' => 'TTS failed: ' . $response->status()], 500);
            }

            return response($response->body(), 200, [
                'Content-Type' => 'audio/mpeg',
                'Cache-Control' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenAI TTS exception: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
