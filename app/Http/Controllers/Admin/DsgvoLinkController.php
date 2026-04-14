<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\PropertyLinkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DsgvoLinkController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $sessions = PropertyLinkSession::with('events')
            ->where('email', strtolower(trim($data['email'])))
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'property_link_id' => $s->property_link_id,
                'email' => $s->email,
                'dsgvo_accepted_at' => $s->dsgvo_accepted_at?->toIso8601String(),
                'first_seen_at' => $s->first_seen_at?->toIso8601String(),
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'events' => $s->events,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($data['email']));

        $sessionIds = PropertyLinkSession::where('email', $email)->pluck('id');

        Activity::whereIn('link_session_id', $sessionIds)->update([
            'stakeholder' => 'geloeschter-empfaenger@deleted.local',
            'link_session_id' => null,
        ]);

        $deleted = PropertyLinkSession::whereIn('id', $sessionIds)->delete();

        return response()->json(['deleted' => $deleted]);
    }
}
