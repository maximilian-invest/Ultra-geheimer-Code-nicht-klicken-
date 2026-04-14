<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Services\PropertyLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyLinkController extends Controller
{
    public function __construct(protected PropertyLinkService $service)
    {
    }

    public function index(Property $property): JsonResponse
    {
        $links = $property->propertyLinks()
            ->withCount(['sessions'])
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) THEN 0 ELSE 1 END', [now()])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PropertyLink $link) => $this->serialize($link));

        return response()->json(['links' => $links]);
    }

    protected function serialize(PropertyLink $link): array
    {
        $docIds = $link->documentIds();
        if (is_object($docIds) && method_exists($docIds, 'all')) {
            $docIds = $docIds->all();
        }

        return [
            'id' => $link->id,
            'name' => $link->name,
            'token' => $link->token,
            'is_default' => $link->is_default,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at->toIso8601String(),
            'sessions_count' => $link->sessions_count ?? 0,
            'document_ids' => $docIds,
            'url' => url("/docs/{$link->token}"),
            'status' => $this->statusOf($link),
        ];
    }

    protected function statusOf(PropertyLink $link): string
    {
        if ($link->revoked_at) return 'revoked';
        if ($link->expires_at && $link->expires_at->isPast()) return 'expired';
        return 'active';
    }
}
