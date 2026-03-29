<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    private function getGoogleClient(): ?\Google_Client
    {
        $settings = DB::table('admin_settings')->where('user_id', 1)->first();
        $tokenJson = $settings->google_calendar_token ?? null;

        if (!$tokenJson) return null;

        $client = new \Google_Client();
        $client->setApplicationName('SR-Homes Admin');
        $client->setScopes([\Google_Service_Calendar::CALENDAR]);

        // Load credentials from config
        $credPath = base_path('storage/app/google-credentials.json');
        if (file_exists($credPath)) {
            $client->setAuthConfig($credPath);
        }

        $token = json_decode($tokenJson, true);
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $newToken = json_encode($client->getAccessToken());
                DB::table('admin_settings')->where('user_id', 1)->update(['google_calendar_token' => $newToken]);
            } else {
                return null;
            }
        }

        return $client;
    }

    /**
     * List events from Google Calendar (or from synced DB)
     */
    public function listEvents(Request $request): JsonResponse
    {
        $start = $request->query('start', date('Y-m-d'));
        $end = $request->query('end', date('Y-m-d', strtotime('+30 days')));

        // First try Google Calendar API
        $client = $this->getGoogleClient();
        if ($client) {
            try {
                $service = new \Google_Service_Calendar($client);
                $events = $service->events->listEvents('primary', [
                    'timeMin' => $start . 'T00:00:00' . date('P'),
                    'timeMax' => $end . 'T23:59:59' . date('P'),
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 100,
                ]);

                $result = [];
                foreach ($events->getItems() as $event) {
                    $startDt = $event->getStart()->getDateTime() ?: $event->getStart()->getDate();
                    $endDt = $event->getEnd()->getDateTime() ?: $event->getEnd()->getDate();
                    $allDay = !$event->getStart()->getDateTime();

                    // Check if we have local metadata (besichtigung etc)
                    $local = DB::table('calendar_events')
                        ->where('google_event_id', $event->getId())
                        ->first();

                    $result[] = [
                        'id' => $event->getId(),
                        'summary' => $event->getSummary(),
                        'description' => $event->getDescription(),
                        'location' => $event->getLocation(),
                        'start' => $startDt,
                        'end' => $endDt,
                        'all_day' => $allDay,
                        'color' => $local->color ?? null,
                        'is_besichtigung' => (bool)($local->is_besichtigung ?? false),
                        'property_id' => $local->property_id ?? null,
                        'stakeholder' => $local->stakeholder ?? null,
                        'html_link' => $event->getHtmlLink(),
                    ];
                }

                return response()->json(['events' => $result, 'source' => 'google']);
            } catch (\Exception $e) {
                Log::warning('Google Calendar API failed: ' . $e->getMessage());
            }
        }

        // Fallback: use synced DB events
        $events = DB::table('calendar_events')
            ->whereBetween('start_time', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'events' => $events->map(fn($e) => [
                'id' => $e->google_event_id ?? 'local-' . $e->id,
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
            ]),
            'source' => 'local',
        ]);
    }

    /**
     * Create event in Google Calendar + local DB
     */
    public function createEvent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $summary = trim($input['summary'] ?? '');
        $start = $input['start'] ?? '';
        $end = $input['end'] ?? '';
        $description = trim($input['description'] ?? '');
        $location = trim($input['location'] ?? '');
        $allDay = (bool)($input['all_day'] ?? false);
        $color = $input['color'] ?? null;

        if (!$summary || !$start || !$end) {
            return response()->json(['error' => 'summary, start, end required'], 400);
        }

        $googleId = null;
        $client = $this->getGoogleClient();
        if ($client) {
            try {
                $service = new \Google_Service_Calendar($client);
                $gEvent = new \Google_Service_Calendar_Event();
                $gEvent->setSummary($summary);
                if ($description) $gEvent->setDescription($description);
                if ($location) $gEvent->setLocation($location);

                if ($allDay) {
                    $gEvent->setStart(new \Google_Service_Calendar_EventDateTime(['date' => substr($start, 0, 10)]));
                    $gEvent->setEnd(new \Google_Service_Calendar_EventDateTime(['date' => substr($end, 0, 10)]));
                } else {
                    $tz = 'Europe/Vienna';
                    $gEvent->setStart(new \Google_Service_Calendar_EventDateTime([
                        'dateTime' => (new \DateTime($start, new \DateTimeZone($tz)))->format(\DateTime::RFC3339),
                        'timeZone' => $tz,
                    ]));
                    $gEvent->setEnd(new \Google_Service_Calendar_EventDateTime([
                        'dateTime' => (new \DateTime($end, new \DateTimeZone($tz)))->format(\DateTime::RFC3339),
                        'timeZone' => $tz,
                    ]));
                }

                $created = $service->events->insert('primary', $gEvent);
                $googleId = $created->getId();
            } catch (\Exception $e) {
                Log::warning('Google Calendar create failed: ' . $e->getMessage());
            }
        }

        // Save locally
        $id = DB::table('calendar_events')->insertGetId([
            'google_event_id' => $googleId,
            'summary' => $summary,
            'description' => $description ?: null,
            'location' => $location ?: null,
            'start_time' => $allDay ? $start . ' 00:00:00' : $start,
            'end_time' => $allDay ? $end . ' 23:59:59' : $end,
            'all_day' => $allDay ? 1 : 0,
            'color' => $color,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id, 'google_id' => $googleId]);
    }

    /**
     * Update event
     */
    public function updateEvent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $eventId = $input['event_id'] ?? '';
        if (!$eventId) return response()->json(['error' => 'event_id required'], 400);

        $update = ['updated_at' => now()];
        if (isset($input['summary'])) $update['summary'] = trim($input['summary']);
        if (isset($input['start'])) $update['start_time'] = $input['start'];
        if (isset($input['end'])) $update['end_time'] = $input['end'];
        if (isset($input['description'])) $update['description'] = trim($input['description']) ?: null;
        if (isset($input['location'])) $update['location'] = trim($input['location']) ?: null;
        if (isset($input['color'])) $update['color'] = $input['color'];

        // Update Google Calendar
        $client = $this->getGoogleClient();
        if ($client) {
            try {
                $service = new \Google_Service_Calendar($client);
                $gEvent = $service->events->get('primary', $eventId);
                if (isset($input['summary'])) $gEvent->setSummary($input['summary']);
                if (isset($input['description'])) $gEvent->setDescription($input['description']);
                if (isset($input['location'])) $gEvent->setLocation($input['location']);
                if (isset($input['start']) && isset($input['end'])) {
                    $tz = 'Europe/Vienna';
                    $gEvent->setStart(new \Google_Service_Calendar_EventDateTime([
                        'dateTime' => (new \DateTime($input['start'], new \DateTimeZone($tz)))->format(\DateTime::RFC3339),
                        'timeZone' => $tz,
                    ]));
                    $gEvent->setEnd(new \Google_Service_Calendar_EventDateTime([
                        'dateTime' => (new \DateTime($input['end'], new \DateTimeZone($tz)))->format(\DateTime::RFC3339),
                        'timeZone' => $tz,
                    ]));
                }
                $service->events->update('primary', $eventId, $gEvent);
            } catch (\Exception $e) {
                Log::warning('Google Calendar update failed: ' . $e->getMessage());
            }
        }

        // Update local
        DB::table('calendar_events')->where('google_event_id', $eventId)->update($update);

        return response()->json(['success' => true]);
    }

    /**
     * Delete event
     */
    public function deleteEvent(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $eventId = $input['event_id'] ?? '';
        if (!$eventId) return response()->json(['error' => 'event_id required'], 400);

        $client = $this->getGoogleClient();
        if ($client) {
            try {
                $service = new \Google_Service_Calendar($client);
                $service->events->delete('primary', $eventId);
            } catch (\Exception $e) {
                Log::warning('Google Calendar delete failed: ' . $e->getMessage());
            }
        }

        DB::table('calendar_events')->where('google_event_id', $eventId)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Sync Google Calendar → local DB + detect Besichtigungen via Haiku
     */
    public function syncCalendar(Request $request): JsonResponse
    {
        $client = $this->getGoogleClient();
        if (!$client) {
            // Fallback: just return local events
            return response()->json(['synced' => 0, 'besichtigungen' => 0, 'error' => 'No Google Calendar connected']);
        }

        $service = new \Google_Service_Calendar($client);
        $now = new \DateTime('now', new \DateTimeZone('Europe/Vienna'));
        $monthAgo = (clone $now)->modify('-1 month');
        $monthAhead = (clone $now)->modify('+2 months');

        $events = $service->events->listEvents('primary', [
            'timeMin' => $monthAgo->format(\DateTime::RFC3339),
            'timeMax' => $monthAhead->format(\DateTime::RFC3339),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 200,
        ]);

        $synced = 0;
        $newEvents = [];

        foreach ($events->getItems() as $event) {
            $googleId = $event->getId();
            $startDt = $event->getStart()->getDateTime() ?: $event->getStart()->getDate();
            $endDt = $event->getEnd()->getDateTime() ?: $event->getEnd()->getDate();
            $allDay = !$event->getStart()->getDateTime();

            $startTime = $allDay ? substr($startDt, 0, 10) . ' 00:00:00' : (new \DateTime($startDt))->format('Y-m-d H:i:s');
            $endTime = $allDay ? substr($endDt, 0, 10) . ' 23:59:59' : (new \DateTime($endDt))->format('Y-m-d H:i:s');

            $existing = DB::table('calendar_events')->where('google_event_id', $googleId)->first();

            $data = [
                'google_event_id' => $googleId,
                'summary' => $event->getSummary(),
                'description' => $event->getDescription(),
                'location' => $event->getLocation(),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'all_day' => $allDay ? 1 : 0,
                'synced_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('calendar_events')->where('id', $existing->id)->update($data);
            } else {
                $data['created_at'] = now();
                $id = DB::table('calendar_events')->insertGetId($data);
                $newEvents[] = [
                    'id' => $id,
                    'summary' => $event->getSummary(),
                    'description' => $event->getDescription(),
                    'location' => $event->getLocation(),
                    'start_time' => $startTime,
                ];
            }
            $synced++;
        }

        // Detect Besichtigungen via Haiku for new/unanalyzed events
        $besichtigungen = $this->detectBesichtigungen($newEvents);

        return response()->json([
            'synced' => $synced,
            'new_events' => count($newEvents),
            'besichtigungen' => $besichtigungen,
        ]);
    }

    /**
     * Use Haiku to detect which calendar events are property viewings
     */
    private function detectBesichtigungen(array $events): int
    {
        if (empty($events)) return 0;

        $properties = DB::table('properties')->get(['id', 'ref_id', 'address', 'city'])->toArray();
        $propList = implode("\n", array_map(fn($p) => "ID:{$p->id} | {$p->ref_id} | {$p->address}, {$p->city}", $properties));

        $count = 0;
        foreach ($events as $evt) {
            $text = ($evt['summary'] ?? '') . ' ' . ($evt['description'] ?? '') . ' ' . ($evt['location'] ?? '');

            try {
                $response = app(\App\Services\AnthropicService::class)->chatJson(
                    "Du bist ein Immobilien-Assistent. Analysiere diesen Kalender-Termin und bestimme:
1. Ist das eine Besichtigung/Objekttermin? (ja/nein)
2. Wenn ja: Zu welchem Objekt gehört es? (property_id)
3. Wer ist der Interessent/Stakeholder? (Name)

Verfügbare Objekte:
{$propList}

Antworte als JSON: {\"is_besichtigung\": true/false, \"property_id\": null oder ID, \"stakeholder\": null oder Name}",
                    "Termin: {$evt['summary']}\nDatum: {$evt['start_time']}\nOrt: " . ($evt['location'] ?? 'nicht angegeben') . "\nBeschreibung: " . ($evt['description'] ?? 'keine')
                );

                $result = is_array($response) ? $response : json_decode($response, true);
                if ($result && !empty($result['is_besichtigung'])) {
                    $update = [
                        'is_besichtigung' => 1,
                        'property_id' => $result['property_id'] ?? null,
                        'stakeholder' => $result['stakeholder'] ?? null,
                    ];
                    DB::table('calendar_events')->where('id', $evt['id'])->update($update);

                    // Create activity if property matched and not already exists
                    if (!empty($result['property_id'])) {
                        $exists = DB::table('activities')
                            ->where('property_id', $result['property_id'])
                            ->where('category', 'besichtigung')
                            ->where('activity_date', substr($evt['start_time'], 0, 10))
                            ->where('stakeholder', $result['stakeholder'] ?? '')
                            ->exists();

                        if (!$exists) {
                            DB::table('activities')->insert([
                                'property_id' => $result['property_id'],
                                'activity_date' => substr($evt['start_time'], 0, 10),
                                'stakeholder' => $result['stakeholder'] ?? 'Kalender-Termin',
                                'activity' => 'Besichtigung: ' . $evt['summary'],
                                'category' => 'besichtigung',
                                'result' => 'Termin im Kalender bestätigt',
                                'created_at' => now(),
                            ]);
                            $count++;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Besichtigung detection failed for event: ' . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Get upcoming events for dashboard widget (next 7 days)
     */
    public function upcoming(Request $request): JsonResponse
    {
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+7 days'));

        // Try Google Calendar first
        $client = $this->getGoogleClient();
        if ($client) {
            try {
                $service = new \Google_Service_Calendar($client);
                $events = $service->events->listEvents('primary', [
                    'timeMin' => $start . 'T00:00:00' . date('P'),
                    'timeMax' => $end . 'T23:59:59' . date('P'),
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 20,
                ]);

                $result = [];
                foreach ($events->getItems() as $event) {
                    $startDt = $event->getStart()->getDateTime() ?: $event->getStart()->getDate();
                    $endDt = $event->getEnd()->getDateTime() ?: $event->getEnd()->getDate();
                    $local = DB::table('calendar_events')->where('google_event_id', $event->getId())->first();

                    $result[] = [
                        'id' => $event->getId(),
                        'summary' => $event->getSummary(),
                        'start' => $startDt,
                        'end' => $endDt,
                        'all_day' => !$event->getStart()->getDateTime(),
                        'location' => $event->getLocation(),
                        'is_besichtigung' => (bool)($local->is_besichtigung ?? false),
                        'property_id' => $local->property_id ?? null,
                        'stakeholder' => $local->stakeholder ?? null,
                    ];
                }
                return response()->json(['events' => $result, 'source' => 'google']);
            } catch (\Exception $e) {
                // fall through to local
            }
        }

        // Fallback: local DB
        $events = DB::table('calendar_events')
            ->whereBetween('start_time', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'events' => $events->map(fn($e) => [
                'id' => $e->google_event_id ?? 'local-' . $e->id,
                'summary' => $e->summary,
                'start' => $e->start_time,
                'end' => $e->end_time,
                'all_day' => (bool)$e->all_day,
                'location' => $e->location,
                'is_besichtigung' => (bool)$e->is_besichtigung,
                'property_id' => $e->property_id,
                'stakeholder' => $e->stakeholder,
            ]),
            'source' => 'local',
        ]);
    }

    /**
     * Get upcoming viewings for a specific property (for customer portal)
     */
    public function propertyViewings(Request $request): JsonResponse
    {
        $propertyId = intval($request->query('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $viewings = DB::table('calendar_events')
            ->where('property_id', $propertyId)
            ->where('is_besichtigung', 1)
            ->where('start_time', '>=', now()->format('Y-m-d H:i:s'))
            ->orderBy('start_time')
            ->get(['id', 'summary', 'start_time', 'end_time', 'stakeholder', 'location']);

        $past = DB::table('calendar_events')
            ->where('property_id', $propertyId)
            ->where('is_besichtigung', 1)
            ->where('start_time', '<', now()->format('Y-m-d H:i:s'))
            ->count();

        return response()->json([
            'upcoming' => $viewings,
            'past_count' => $past,
        ]);
    }

    /**
     * Google OAuth: initiate
     */
    public function oauthStart(Request $request): JsonResponse
    {
        $credPath = base_path('storage/app/google-credentials.json');
        if (!file_exists($credPath)) {
            return response()->json(['error' => 'Google credentials not configured. Upload google-credentials.json to storage/app/'], 400);
        }

        $client = new \Google_Client();
        $client->setAuthConfig($credPath);
        $client->setScopes([\Google_Service_Calendar::CALENDAR]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setRedirectUri(url('/api/admin_api.php') . '?action=google_oauth_callback');

        return response()->json(['auth_url' => $client->createAuthUrl()]);
    }

    /**
     * Google OAuth: callback
     */
    public function oauthCallback(Request $request): JsonResponse
    {
        $code = $request->query('code', '');
        if (!$code) return response()->json(['error' => 'No auth code'], 400);

        $credPath = base_path('storage/app/google-credentials.json');
        $client = new \Google_Client();
        $client->setAuthConfig($credPath);
        $client->setRedirectUri(url('/api/admin_api.php') . '?action=google_oauth_callback');

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            return response()->json(['error' => $token['error_description'] ?? $token['error']], 400);
        }

        DB::table('admin_settings')->where('user_id', 1)->update([
            'google_calendar_token' => json_encode($token),
        ]);

        return response()->json(['success' => true, 'message' => 'Google Calendar verbunden!']);
    }

    /**
     * Check if Google Calendar is connected
     */
    public function status(Request $request): JsonResponse
    {
        $settings = DB::table('admin_settings')->where('user_id', 1)->first();
        $connected = !empty($settings->google_calendar_token ?? null);

        return response()->json([
            'connected' => $connected,
            'calendar_id' => $connected ? 'primary' : null,
        ]);
    }
}
