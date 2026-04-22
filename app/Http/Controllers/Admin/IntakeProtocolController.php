<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeProtocolDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeProtocolController extends Controller
{
    public function draftSave(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $draftKey = trim((string) ($data['draft_key'] ?? ''));
        $formData = $data['form_data'] ?? [];
        $currentStep = (int) ($data['current_step'] ?? 1);

        if ($draftKey === '') {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::updateOrCreate(
            ['broker_id' => $userId, 'draft_key' => $draftKey],
            [
                'form_data' => is_array($formData) ? json_encode($formData) : (string) $formData,
                'current_step' => $currentStep,
                'last_saved_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }

    public function draftLoad(Request $request): JsonResponse
    {
        $draftKey = $request->query('draft_key');
        if (!$draftKey) {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::where('broker_id', $userId)
            ->where('draft_key', $draftKey)
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'error' => 'not found'], 404);
        }

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'form_data' => $draft->form_data_array,
            'current_step' => $draft->current_step,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }
}
