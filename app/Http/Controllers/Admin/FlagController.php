<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConversationFlag;
use App\Models\UserFlagLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FlagController extends Controller
{
    /**
     * flag_labels (GET) — return current user's label map for the 6 colors.
     */
    public function labels(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        if (!$userId) return response()->json(['error' => 'auth required'], 401);

        return response()->json([
            'colors' => UserFlagLabel::COLORS,
            'labels' => UserFlagLabel::labelsForUser($userId),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * flag_label_save (POST) — rename one color's label for the current user.
     * Body: {color, label}
     */
    public function saveLabel(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        if (!$userId) return response()->json(['error' => 'auth required'], 401);

        $input = $request->json()->all();
        $color = (string) ($input['color'] ?? '');
        $label = trim((string) ($input['label'] ?? ''));

        if (!UserFlagLabel::isValidColor($color)) {
            return response()->json(['error' => 'invalid color'], 400);
        }
        if ($label === '') {
            $label = UserFlagLabel::DEFAULT_LABELS[$color];
        }
        if (mb_strlen($label) > 60) {
            $label = mb_substr($label, 0, 60);
        }

        UserFlagLabel::updateOrCreate(
            ['user_id' => $userId, 'color' => $color],
            ['label' => $label]
        );

        return response()->json([
            'success' => true,
            'labels'  => UserFlagLabel::labelsForUser($userId),
        ]);
    }

    /**
     * conv_flag (POST) — set or clear a flag on a conversation for the current user.
     * Body: {id: <conversation_id>, color: <color>|null}
     *
     * If the request comes from a posteingang/gesendet view where the row id
     * is a portal_emails.id, we resolve back to the matching conversation.
     */
    public function setConversationFlag(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        if (!$userId) return response()->json(['error' => 'auth required'], 401);

        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        $color = $input['color'] ?? null;
        if ($color !== null) $color = (string) $color;

        if (!$id) return response()->json(['error' => 'id required'], 400);
        if ($color !== null && $color !== '' && !UserFlagLabel::isValidColor($color)) {
            return response()->json(['error' => 'invalid color'], 400);
        }

        $conversationId = $this->resolveConversationId($id);
        if (!$conversationId) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        if ($color === null || $color === '') {
            ConversationFlag::where('user_id', $userId)
                ->where('conversation_id', $conversationId)
                ->delete();
            return response()->json([
                'success' => true,
                'conversation_id' => $conversationId,
                'flag_color' => null,
            ]);
        }

        ConversationFlag::updateOrCreate(
            ['user_id' => $userId, 'conversation_id' => $conversationId],
            ['color' => $color]
        );

        return response()->json([
            'success' => true,
            'conversation_id' => $conversationId,
            'flag_color' => $color,
        ]);
    }

    /**
     * Try the id as a conversation_id first; fall back to portal_emails.id by
     * mapping (stakeholder, property_id) → conversation. Mirrors the same idea
     * as ConversationController::resolveConversation but without all its other
     * concerns.
     */
    private function resolveConversationId(int $id): ?int
    {
        $conv = DB::table('conversations')->where('id', $id)->value('id');
        if ($conv) return (int) $conv;

        $email = DB::table('portal_emails')
            ->where('id', $id)
            ->select('stakeholder', 'from_email', 'to_email', 'property_id', 'direction')
            ->first();
        if (!$email) return null;

        $candidate = DB::table('conversations')
            ->whereRaw('COALESCE(property_id,0) = ?', [(int) ($email->property_id ?? 0)])
            ->where(function ($q) use ($email) {
                $candidates = array_filter([
                    $email->stakeholder ?? null,
                    $email->from_email ?? null,
                    $email->to_email ?? null,
                ]);
                $candidates = array_unique(array_map(fn($v) => mb_strtolower(trim((string) $v)), $candidates));
                $candidates = array_values(array_filter($candidates, fn($v) => $v !== ''));
                if (empty($candidates)) {
                    $q->whereRaw('1=0');
                    return;
                }
                $q->whereRaw('LOWER(TRIM(COALESCE(contact_email,""))) IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')', $candidates)
                  ->orWhereRaw('LOWER(TRIM(COALESCE(stakeholder,""))) IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')', $candidates);
            })
            ->orderByDesc('id')
            ->value('id');

        return $candidate ? (int) $candidate : null;
    }
}
