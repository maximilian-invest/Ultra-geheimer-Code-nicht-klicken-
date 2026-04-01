<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    /**
     * List calendar events (user-scoped).
     * Assistenz sees all events.
     */
    public function listEvents(Request $request): JsonResponse
    {
        $start = $request->query('start', date('Y-m-d'));
        $end = $request->query('end', date('Y-m-d', strtotime('+30 days')));
        $userId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';

        $query = DB::table('calendar_events')
            ->whereBetween('start_time', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('start_time');

        // Assistenz sees all, others see only own
        if ($userType !== 'assistenz') {
            $query->where('user_id', $userId);
        }

        // Optional broker filter for assistenz
        $filterUserId = intval($request->query('filter_user_id', 0));
        if ($filterUserId && $userType === 'assistenz') {
            $query->where('user_id', $filterUserId);
        }

        $events = $query->get();

        return response()->json([
            'events' => $events->map(fn($e) => [
                'id' => $e->id,
                'summary' => $e->summary,
                'description' => $e->description,
                'location' => $e->location,
                'start' => $e->start_time,
                'end' => $e->end_time,
                'all_day' => (bool)$e->all_day,
                'color' => $e->color,
                'is_besichtigung' => (bool)$e->is_besichtigung,
                'property_id' => $e->property_id,
                'stakeholder' => $e->stakeholder,
                'user_id' => $e->user_id,
            ]),
            'source' => 'local',
        ]);
    }

    /**
     * Create a new calendar event.
     */
    public function createEvent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $summary = trim($input['summary'] ?? '');
        if (!$summary) {
            return response()->json(['error' => 'Titel ist erforderlich'], 400);
        }

        $startDate = $input['start_date'] ?? date('Y-m-d');
        $startTime = $input['start_time'] ?? '09:00';
        $endDate = $input['end_date'] ?? $startDate;
        $endTime = $input['end_time'] ?? '10:00';
        $allDay = !empty($input['all_day']);

        $startDt = $allDay ? $startDate . ' 00:00:00' : $startDate . ' ' . $startTime . ':00';
        $endDt = $allDay ? $endDate . ' 23:59:59' : $endDate . ' ' . $endTime . ':00';

        // Assistenz can create events for other users
        $eventUserId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if ($userType === 'assistenz' && !empty($input['for_user_id'])) {
            $eventUserId = intval($input['for_user_id']);
        }

        $id = DB::table('calendar_events')->insertGetId([
            'user_id' => $eventUserId,
            'summary' => $summary,
            'description' => $input['description'] ?? null,
            'location' => $input['location'] ?? null,
            'start_time' => $startDt,
            'end_time' => $endDt,
            'all_day' => $allDay,
            'color' => $input['color'] ?? null,
            'property_id' => $input['property_id'] ?? null,
            'is_besichtigung' => !empty($input['is_besichtigung']),
            'stakeholder' => $input['stakeholder'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // If besichtigung, also create viewing entry
        if (!empty($input['is_besichtigung']) && !empty($input['property_id'])) {
            DB::table('viewings')->insert([
                'property_id' => $input['property_id'],
                'viewing_date' => $startDate,
                'viewing_time' => $startTime . ':00',
                'person_name' => $input['stakeholder'] ?? $summary,
                'status' => 'geplant',
                'calendar_event_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'id' => $id]);
    }

    /**
     * Update an existing calendar event.
     */
    public function updateEvent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $eventId = intval($input['id'] ?? 0);
        if (!$eventId) {
            return response()->json(['error' => 'Event-ID erforderlich'], 400);
        }

        $event = DB::table('calendar_events')->where('id', $eventId)->first();
        if (!$event) {
            return response()->json(['error' => 'Event nicht gefunden'], 404);
        }

        // Permission: own event or assistenz
        $userId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if ($event->user_id != $userId && $userType !== 'assistenz') {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $update = ['updated_at' => now()];
        if (isset($input['summary'])) $update['summary'] = trim($input['summary']);
        if (isset($input['description'])) $update['description'] = $input['description'];
        if (isset($input['location'])) $update['location'] = $input['location'];
        if (isset($input['color'])) $update['color'] = $input['color'];
        if (isset($input['property_id'])) $update['property_id'] = $input['property_id'] ?: null;
        if (isset($input['stakeholder'])) $update['stakeholder'] = $input['stakeholder'];
        if (isset($input['is_besichtigung'])) $update['is_besichtigung'] = !empty($input['is_besichtigung']);

        if (isset($input['start_date'])) {
            $allDay = !empty($input['all_day']);
            $update['all_day'] = $allDay;
            $update['start_time'] = $allDay
                ? $input['start_date'] . ' 00:00:00'
                : $input['start_date'] . ' ' . ($input['start_time'] ?? '09:00') . ':00';
            $update['end_time'] = $allDay
                ? ($input['end_date'] ?? $input['start_date']) . ' 23:59:59'
                : ($input['end_date'] ?? $input['start_date']) . ' ' . ($input['end_time'] ?? '10:00') . ':00';
        }

        DB::table('calendar_events')->where('id', $eventId)->update($update);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a calendar event.
     */
    public function deleteEvent(Request $request): JsonResponse
    {
        $eventId = intval($request->input('id', $request->query('id', 0)));
        if (!$eventId) {
            return response()->json(['error' => 'Event-ID erforderlich'], 400);
        }

        $event = DB::table('calendar_events')->where('id', $eventId)->first();
        if (!$event) {
            return response()->json(['error' => 'Event nicht gefunden'], 404);
        }

        $userId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        if ($event->user_id != $userId && $userType !== 'assistenz') {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        // Also delete linked viewing
        DB::table('viewings')->where('calendar_event_id', $eventId)->delete();
        DB::table('calendar_events')->where('id', $eventId)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Upcoming events for dashboard widget.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $userId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        $limit = intval($request->query('limit', 10));

        $query = DB::table('calendar_events')
            ->where('start_time', '>=', now()->startOfDay())
            ->where('start_time', '<=', now()->addDays(7)->endOfDay())
            ->orderBy('start_time')
            ->limit($limit);

        if ($userType !== 'assistenz') {
            $query->where('user_id', $userId);
        }

        $events = $query->get();

        return response()->json([
            'events' => $events->map(fn($e) => [
                'id' => $e->id,
                'summary' => $e->summary,
                'start' => $e->start_time,
                'end' => $e->end_time,
                'all_day' => (bool)$e->all_day,
                'color' => $e->color,
                'is_besichtigung' => (bool)$e->is_besichtigung,
                'property_id' => $e->property_id,
                'stakeholder' => $e->stakeholder,
            ]),
        ]);
    }

    /**
     * Property viewings from viewings table.
     */
    public function propertyViewings(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) {
            return response()->json(['error' => 'property_id required'], 400);
        }

        $viewings = DB::table('viewings')
            ->where('property_id', $propertyId)
            ->orderByDesc('viewing_date')
            ->get();

        return response()->json(['viewings' => $viewings]);
    }


    /**
     * Sync stub – calendar is now local-only.
     * Returns current event counts so legacy callers stay happy.
     */
    public function syncCalendar(Request $request): JsonResponse
    {
 $total = DB::table('calendar_events')->count();
 $besichtigungen = DB::table('calendar_events')->where('is_besichtigung', 1)->count();
        return response()->json([
            'synced'        => $total,
            'new_events'    => 0,
            'besichtigungen'=> $besichtigungen,
            'source'        => 'local',
        ]);
    }

    /**
     * Calendar status - always connected (local DB).
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json(['connected' => true, 'source' => 'local']);
    }
}
