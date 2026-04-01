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
        $user = \Auth::user();
        $scope = $request->query('scope', '');
        $brokerFilter = $request->query('broker_filter', '');

        $conditions = [];
        $params = [];
        if (!$showDone) $conditions[] = 't.is_done = 0';

        $isAssistenz = $user && in_array($user->user_type, ['assistenz', 'backoffice']);

        if ($isAssistenz) {
            // Assistenz sees ALL tasks (own + all others)
            if ($brokerFilter && is_numeric($brokerFilter)) {
                $conditions[] = "(t.created_by = ? OR t.assigned_by = ? OR t.assigned_to = ? OR p.broker_id = ?)";
                $params[] = intval($brokerFilter);
                $params[] = intval($brokerFilter);
                $params[] = intval($brokerFilter);
                $params[] = intval($brokerFilter);
            }
        } else if ($brokerId) {
            // Makler/Admin: see tasks assigned to self OR created by self
            $conditions[] = "(t.assigned_to = ? OR t.created_by = ? OR t.assigned_by = ?)";
            $params[] = $brokerId;
            $params[] = $brokerId;
            $params[] = $brokerId;
        }
        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $rows = DB::select("
            SELECT t.*, p.ref_id, p.address, p.broker_id,
                   u_by.name as assigned_by_name, u_to.name as assigned_to_name,
                   u_cb.name as completed_by_name, u_cr.name as created_by_name
            FROM tasks t
            LEFT JOIN properties p ON t.property_id = p.id
            LEFT JOIN users u_by ON t.assigned_by = u_by.id
            LEFT JOIN users u_to ON t.assigned_to = u_to.id
            LEFT JOIN users u_cb ON t.completed_by = u_cb.id
            LEFT JOIN users u_cr ON t.created_by = u_cr.id
            {$where}
            ORDER BY t.is_done ASC, FIELD(t.priority, 'critical', 'high', 'medium', 'low'), t.created_at DESC
        ", $params);

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
        $dueDate     = !empty($data['due_date']) ? $data['due_date'] : null;
        $assignedTo  = !empty($data['assigned_to']) ? intval($data['assigned_to']) : null;

        // Default: assign to first assistenz user if not specified
        if (!$assignedTo) {
            $assistenz = DB::selectOne("SELECT id FROM users WHERE user_type IN ('assistenz','backoffice') LIMIT 1");
            $assignedTo = $assistenz ? $assistenz->id : \Auth::id();
        }

        $id = DB::table('tasks')->insertGetId([
            'property_id' => $propertyId,
            'title'       => $title,
            'stakeholder' => $stakeholder ?: null,
            'priority'    => $priority,
            'due_date'    => $dueDate,
            'assigned_to' => $assignedTo,
            'assigned_by' => \Auth::id(),
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

    public function delegate(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $taskId = intval($request->json('task_id', 0));
        if (!$taskId) {
            return response()->json(['error' => 'task_id required'], 400);
        }

        // Find an assistenz user to delegate to
        $assistenz = DB::selectOne("SELECT id FROM users WHERE user_type IN ('assistenz','backoffice') LIMIT 1");
        if (!$assistenz) {
            return response()->json(['error' => 'Kein Assistenz-Account gefunden'], 404);
        }

        DB::update('UPDATE tasks SET assigned_to = ?, assigned_by = ?, updated_at = NOW() WHERE id = ?',
            [$assistenz->id, \Auth::id(), $taskId]);

        return response()->json(['success' => true, 'message' => 'Aufgabe an Assistenz delegiert']);
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

        DB::update('UPDATE tasks SET is_done = 1, completed_by = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?', [\Auth::id(), $taskId]);

        $activityCreated = false;
        if ($task->property_id) {
            DB::insert("INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category) VALUES (?, CURDATE(), 'SR-Homes', ?, '', 'update')",
                [$task->property_id, 'Todo erledigt: ' . $task->title]);
            $activityCreated = true;
        }

        return response()->json(['success' => true, 'activity_created' => $activityCreated]);
    }

    /**
     * AI-generated todos from incoming emails.
     */
    public function generate(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required'], 405);
        }

        $user = \Auth::user();
        $brokerId = \Auth::id();
        $isAssistenz = $user && in_array($user->user_type, ['assistenz', 'backoffice']);

        // Delete old AI todos for this user
        DB::delete("DELETE FROM tasks WHERE source = 'ai' AND is_done = 0 AND created_by = ?", [$brokerId]);

        // Read recent incoming emails (last 14 days)
        if ($isAssistenz) {
            // Assistenz: ALL broker emails
            $emails = DB::select("
                SELECT pe.from_name, pe.from_email, pe.subject,
                       COALESCE(pe.ai_summary, LEFT(pe.body_text, 300)) as summary,
                       pe.category, pe.property_id, p.ref_id, p.address, p.broker_id,
                       u.name as broker_name,
                       DATE_FORMAT(pe.email_date, '%d.%m.%Y') as datum
                FROM portal_emails pe
                LEFT JOIN properties p ON pe.property_id = p.id
                LEFT JOIN users u ON p.broker_id = u.id
                WHERE pe.direction = 'inbound'
                  AND pe.category NOT IN ('spam')
                  AND pe.email_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                ORDER BY pe.email_date DESC
                LIMIT 40
            ");
        } else {
            $emails = DB::select("
                SELECT pe.from_name, pe.from_email, pe.subject,
                       COALESCE(pe.ai_summary, LEFT(pe.body_text, 300)) as summary,
                       pe.category, pe.property_id, p.ref_id, p.address,
                       DATE_FORMAT(pe.email_date, '%d.%m.%Y') as datum
                FROM portal_emails pe
                LEFT JOIN properties p ON pe.property_id = p.id
                WHERE pe.direction = 'inbound'
                  AND pe.category NOT IN ('spam')
                  AND pe.email_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                  AND (p.broker_id = ? OR pe.property_id IS NULL)
                ORDER BY pe.email_date DESC
                LIMIT 25
            ", [$brokerId]);
        }

        // Active properties for context
        if ($isAssistenz) {
            $properties = DB::select("
                SELECT p.id, p.ref_id, p.address, p.city, p.status, u.name as broker_name, p.broker_id
                FROM properties p LEFT JOIN users u ON p.broker_id = u.id
                WHERE p.status NOT IN ('verkauft')
                ORDER BY p.ref_id
            ");
        } else {
            $properties = DB::select("
                SELECT id, ref_id, address, city, status
                FROM properties WHERE status NOT IN ('verkauft') AND broker_id = ?
                ORDER BY ref_id
            ", [$brokerId]);
        }

        // Build context
        $ctx = "EINGEHENDE MAILS DER LETZTEN 14 TAGE:\n\n";
        foreach ($emails as $e) {
            $e = (array) $e;
            $body = strip_tags($e['summary'] ?? '');
            $prop = $e['ref_id'] ? "{$e['ref_id']} ({$e['address']})" : 'kein Objekt';
            $broker = $e['broker_name'] ?? '';
            $ctx .= "---\nVon: {$e['from_name']} am {$e['datum']}\n";
            $ctx .= "Betreff: {$e['subject']}\nObjekt: {$prop}" . ($e['property_id'] ? ", pid={$e['property_id']}" : "") . ($broker ? "\nMakler: {$broker}" : "") . "\n";
            $ctx .= "Inhalt: {$body}\n\n";
        }

        $ctx .= "\nAKTIVE OBJEKTE:\n";
        foreach ($properties as $p) {
            $p = (array) $p;
            $broker = $p['broker_name'] ?? '';
            $ctx .= "- {$p['ref_id']}: {$p['address']}, {$p['city']}, Status: {$p['status']}, property_id={$p['id']}" . ($broker ? ", Makler: {$broker}" : "") . "\n";
        }

        // AI call
        try {
            $anthropic = app(\App\Services\AnthropicService::class);
            if ($isAssistenz) {
                $system = "Du bist der Aufgaben-Manager fuer die Assistenz/Backoffice von SR-Homes Immobilien. Leite aus eingehenden Mails ASSISTENZ-AUFGABEN ab.

GUTE Assistenz-Aufgaben: Besichtigungstermine koordinieren, Rueckrufe organisieren, Unterlagen vom Eigentuemer anfordern, Expose zusammenstellen/versenden, Portalzugaenge einrichten, Kaufvertragsentwurf weiterleiten, Grundbuchauszug besorgen, Fotos/Grundrisse organisieren, Preisvergleiche erstellen, Kundenunterlagen vervollstaendigen.

VERBOTEN: Routine-Mails beantworten (macht der Makler), Beratungsgespraeche fuehren, Preise verhandeln. Generiere 3-8 Aufgaben bei klarem Anlass.";
            } else {
                $system = "Immobilien-Vertriebsassistent SR-Homes. Erkenne aus eingehenden Mails INTERNE Aufgaben — Dinge die der Makler vorbereiten/organisieren muss.

GUTE Aufgaben: Expose aktualisieren, Grundriss anfordern, Fotos machen lassen, Unterlagen besorgen (Energieausweis etc.), Preisanpassung pruefen, Kaufvertragsentwurf weiterleiten, intern Infos klaeren.

VERBOTEN: Expose/Unterlagen SENDEN (passiert automatisch), E-Mails beantworten, Nachfassen, Besichtigungstermin koordinieren, Routineaufgaben ohne konkreten Mail-Anlass.

Expose-Anfrage ist KEINE Aufgabe wenn Expose existiert. Generiere 2-6 Aufgaben nur bei klarem Anlass.";
            }

            $tasks = $anthropic->chatJson($system, $ctx . "\n\nGeneriere Aufgaben als JSON-Array:\n[{\"title\": \"...\", \"priority\": \"low|medium|high\", \"stakeholder\": \"Name des Beteiligten\", \"property_id\": 123}]\n\nWenn keine sinnvollen Aufgaben erkennbar sind, gib ein leeres Array zurueck: []", 1500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'KI-Antwort fehlgeschlagen', 'message' => $e->getMessage()], 500);
        }

        if (!is_array($tasks) || empty($tasks)) {
            return response()->json(['generated' => 0, 'replaced' => 0, 'tasks' => []]);
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
                'assigned_to' => $brokerId,
                'created_by'  => $brokerId,
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
            'tasks'     => $newTasks,
        ]);
    }
}
