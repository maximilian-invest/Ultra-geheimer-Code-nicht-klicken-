<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $showDone = intval($request->query('done', 0));
        $brokerId = \Auth::id();
        $conditions = [];
        if (!$showDone) $conditions[] = 't.is_done = 0';
        // Multi-User: only show tasks for own properties or created by self
        if ($brokerId) {
            $conditions[] = "(p.broker_id = {$brokerId} OR (t.property_id IS NULL AND t.created_by = {$brokerId}))";
        }
        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $rows = DB::select("
            SELECT t.*, p.ref_id, p.address
            FROM tasks t
            LEFT JOIN properties p ON t.property_id = p.id
            {$where}
            ORDER BY t.is_done ASC, FIELD(t.priority, 'critical', 'high', 'medium', 'low'), t.created_at DESC
        ");

        return response()->json(['tasks' => $rows, 'count' => count($rows)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $data = $request->json()->all();
        $title = trim($data['text'] ?? $data['title'] ?? '');
        if (!$title) {
            return response()->json(['error' => 'text required'], 400);
        }

        $propertyId  = !empty($data['property_id']) ? intval($data['property_id']) : null;
        $stakeholder = trim($data['stakeholder'] ?? '');
        $priority    = in_array($data['priority'] ?? '', ['high','medium','low','critical']) ? $data['priority'] : 'medium';

        $id = DB::table('tasks')->insertGetId([
            'property_id' => $propertyId,
            'title'       => $title,
            'stakeholder' => $stakeholder ?: null,
            'priority'    => $priority,
            'created_by'  => \Auth::id(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $task = DB::selectOne("
            SELECT t.*, p.ref_id, p.address
            FROM tasks t
            LEFT JOIN properties p ON t.property_id = p.id
            WHERE t.id = ?
        ", [$id]);

        return response()->json(['success' => true, 'task' => $task]);
    }

    public function done(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $taskId = intval($request->json('task_id', 0));
        if (!$taskId) {
            return response()->json(['error' => 'task_id required'], 400);
        }

        $task = DB::selectOne('SELECT * FROM tasks WHERE id = ?', [$taskId]);
        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        DB::update('UPDATE tasks SET is_done = 1, updated_at = NOW() WHERE id = ?', [$taskId]);

        $activityCreated = false;
        if ($task->property_id) {
            DB::insert("INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category) VALUES (?, CURDATE(), 'SR-Homes', ?, '', 'update')",
                [$task->property_id, 'Todo erledigt: ' . $task->title]);
            $activityCreated = true;
        }

        return response()->json(['success' => true, 'activity_created' => $activityCreated]);
    }

    /**
     * AI-generated todos from incoming emails — only non-email tasks.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        // Delete old AI todos
        $deleted = DB::delete("DELETE FROM tasks WHERE source = 'ai' AND is_done = 0");

        // Read recent incoming emails (last 14 days) with their content
        $brokerId = \Auth::id();
        $brokerFilter = $brokerId ? "AND (p.broker_id = {$brokerId} OR pe.property_id IS NULL)" : "";
        $emails = DB::select("
            SELECT pe.from_name, pe.from_email, pe.subject, pe.body_text, pe.ai_summary,
                   pe.category, pe.property_id, p.ref_id, p.address,
                   DATE_FORMAT(pe.email_date, '%d.%m.%Y') as datum
            FROM portal_emails pe
            LEFT JOIN properties p ON pe.property_id = p.id
            WHERE pe.direction = 'inbound'
              AND pe.category NOT IN ('spam')
              AND pe.email_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              {$brokerFilter}
            ORDER BY pe.email_date DESC
            LIMIT 40
        ");

        // Active properties for context
        $brokerPropFilter = $brokerId ? "AND broker_id = {$brokerId}" : "";
        $properties = DB::select("
            SELECT id, ref_id, address, city, status
            FROM properties WHERE status NOT IN ('verkauft') {$brokerPropFilter}
            ORDER BY ref_id
        ");

        // Build context
        $ctx = "EINGEHENDE MAILS DER LETZTEN 14 TAGE:\n\n";

        foreach ($emails as $e) {
            $e = (array) $e;
            $body = $e['ai_summary'] ?: mb_substr(strip_tags($e['body_text'] ?? ''), 0, 300);
            $prop = $e['ref_id'] ? "{$e['ref_id']} ({$e['address']})" : 'kein Objekt';
            $ctx .= "---\nVon: {$e['from_name']} <{$e['from_email']}> am {$e['datum']}\n";
            $ctx .= "Betreff: {$e['subject']}\nObjekt: {$prop}" . ($e['property_id'] ? ", property_id={$e['property_id']}" : "") . "\n";
            $ctx .= "Inhalt: {$body}\n\n";
        }

        $ctx .= "\nAKTIVE OBJEKTE:\n";
        foreach ($properties as $p) {
            $p = (array) $p;
            $ctx .= "- {$p['ref_id']}: {$p['address']}, {$p['city']}, Status: {$p['status']}, property_id={$p['id']}\n";
        }

        // AI call
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            $system = "Du bist ein Immobilien-Vertriebsassistent fuer SR-Homes. Lies die eingehenden Mails und erkenne daraus INTERNE Aufgaben — also Dinge die der Makler vorbereiten, aendern, oder organisieren muss BEVOR er antworten kann.

Beispiele fuer GUTE Aufgaben:
- Expose fuer Objekt XY aktualisieren/neu erstellen (Layout, Fotos, Texte aendern)
- Grundriss von Wohnung Top 3 beim Bautraeger anfordern
- Neue Fotos vom Objekt XY machen lassen
- Nebenkostenuebersicht aktualisieren (neue Zahlen einarbeiten)
- Kaufvertragsentwurf an Notar weiterleiten
- Preisanpassung fuer Objekt XY pruefen (Marktanalyse)
- Fehlende Unterlagen besorgen (Energieausweis, Flaechen, etc.)
- Baufortschritt dokumentieren / aktuelles Baumaterial-Update einholen
- Information intern klaeren die ein Interessent gefragt hat und die nicht in der Wissensdatenbank steht

VERBOTEN — diese Aufgaben NIEMALS erstellen:
- Expose/Infos/Unterlagen an Interessenten SENDEN/VERSENDEN (das passiert automatisch im Antwort-System)
- E-Mails beantworten oder Antwort-Entwuerfe erstellen
- Nachfassen bei Interessenten
- Verfuegbarkeitsstatus mitteilen
- Besichtigungstermin vereinbaren/koordinieren (wird im Kalender-System gemacht)
- Allgemeine Routineaufgaben ohne konkreten Anlass aus einer Mail

WICHTIG: Wenn ein Interessent nach einem Expose fragt und das Expose bereits existiert, ist das KEINE Aufgabe — das Senden wird automatisch erledigt. Nur wenn das Expose FEHLT oder AKTUALISIERT werden muss, ist es eine Aufgabe.

Generiere 2-6 Aufgaben. Nur Aufgaben erstellen wenn es einen klaren Anlass in den Mails gibt.";

            $tasks = $anthropic->chatJson($system, $ctx . "\n\nGeneriere Aufgaben als JSON-Array:\n[{\"title\": \"...\", \"priority\": \"low|medium|high\", \"stakeholder\": \"Name des Beteiligten\", \"property_id\": 123}]\n\nWenn keine sinnvollen Aufgaben erkennbar sind, gib ein leeres Array zurueck: []", 1500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'KI-Antwort fehlgeschlagen', 'message' => $e->getMessage()], 500);
        }

        if (!is_array($tasks) || empty($tasks)) {
            return response()->json(['generated' => 0, 'replaced' => (int)$deleted, 'tasks' => []]);
        }

        $generated = 0;
        $newTasks = [];

        foreach ($tasks as $t) {
            $title = trim($t['title'] ?? $t['text'] ?? '');
            if (!$title || mb_strlen($title) > 500) continue;

            $propId      = !empty($t['property_id']) ? intval($t['property_id']) : null;
            $stakeholder = trim($t['stakeholder'] ?? '');
            $prio        = in_array($t['priority'] ?? '', ['high','medium','low','critical']) ? $t['priority'] : 'medium';

            $id = DB::table('tasks')->insertGetId([
                'title'       => $title,
                'property_id' => $propId,
                'stakeholder' => $stakeholder ?: null,
                'priority'    => $prio,
                'source'      => 'ai',
                'created_by'  => \Auth::id(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $newTasks[] = DB::selectOne("
                SELECT t.*, p.ref_id, p.address
                FROM tasks t LEFT JOIN properties p ON t.property_id = p.id
                WHERE t.id = ?
            ", [$id]);
            $generated++;
        }

        return response()->json([
            'generated' => $generated,
            'replaced'  => (int)$deleted,
            'tasks'     => $newTasks,
        ]);
    }
}
