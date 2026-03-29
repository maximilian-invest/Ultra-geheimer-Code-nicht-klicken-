<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ActivityController extends Controller
{
    /**
     * Quick-add activity with optional AI polish.
     * Input: property_id, activity (raw text), duration (minutes), category, stakeholder,
     *        activity_date, activity_time
     */
    public function add(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $propertyId  = intval($input['property_id'] ?? 0);
        $rawActivity = trim($input['activity'] ?? '');
        $duration    = intval($input['duration'] ?? 0) ?: null;
        $category    = $input['category'] ?? 'sonstiges';
        $stakeholder = trim($input['stakeholder'] ?? '');
        $date        = $input['activity_date'] ?? now()->toDateString();
        $time        = $input['activity_time'] ?? now()->format('H:i');

        if (!$propertyId || !$rawActivity) {
            return response()->json(['error' => 'property_id und activity sind Pflichtfelder'], 400);
        }

        // Multi-User: broker_id pruefen
        $brokerId = \Auth::id();
        if ($brokerId) {
            $owns = DB::selectOne("SELECT id FROM properties WHERE id = ? AND broker_id = ?", [$propertyId, $brokerId]);
            if (!$owns) return response()->json(['error' => 'Kein Zugriff auf dieses Objekt'], 403);
        }

        // KI-Polish: kurzen Rohtext in saubere Aktivitaets-Beschreibung umformulieren
        $polished = $rawActivity;
        try {
            $property = DB::selectOne("SELECT ref_id, address, city FROM properties WHERE id = ?", [$propertyId]);
            $propContext = $property ? "{$property->ref_id} ({$property->address}, {$property->city})" : "";

            $prompt = "Du bist ein Assistent fuer ein Immobilienbuero. Formuliere die folgende Aktivitaets-Notiz professionell und knapp um (1-2 Saetze, deutsch). "
                    . "Behalte alle Fakten bei, mach es nur sprachlich sauber. Kein Smalltalk, keine Anrede. "
                    . "Objekt: {$propContext}\n"
                    . "Kategorie: {$category}\n"
                    . "Rohtext: {$rawActivity}";

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 200,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if ($response->successful()) {
                $aiText = $response->json('content.0.text', '');
                if (strlen($aiText) > 10) {
                    $polished = trim($aiText);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Activity AI polish failed: " . $e->getMessage());
            // Fallback: use raw text
        }

        $activityId = DB::table('activities')->insertGetId([
            'property_id'   => $propertyId,
            'activity_date'  => $date,
            'stakeholder'   => $stakeholder ?: (\Auth::user()->name ?? 'Admin'),
            'activity'      => $polished,
            'result'        => $rawActivity !== $polished ? $rawActivity : null,
            'duration'      => $duration,
            'category'      => $category,
            'created_at'    => "{$date} {$time}:00",
        ]);

        Log::info("[Activity] Manual add: property={$propertyId}, category={$category}, by=" . (\Auth::user()->name ?? 'API'));

        return response()->json([
            'success' => true,
            'activity_id' => $activityId,
            'polished' => $polished,
            'raw' => $rawActivity,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
