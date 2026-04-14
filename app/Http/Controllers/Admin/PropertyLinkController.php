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

    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['integer'],
        ]);

        $validFileIds = DB::table('property_files')
            ->where('property_id', $property->id)
            ->whereIn('id', $data['file_ids'])
            ->pluck('id')
            ->all();

        if (count($validFileIds) !== count($data['file_ids'])) {
            return response()->json(['error' => 'Ein oder mehrere Dokumente gehoeren nicht zu dieser Property'], 422);
        }

        $link = DB::transaction(function () use ($data, $property, $validFileIds) {
            $link = PropertyLink::create([
                'property_id' => $property->id,
                'name' => $data['name'],
                'token' => $this->service->generateUniqueToken(),
                'is_default' => false,
                'expires_at' => $data['expires_at'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($validFileIds as $sort => $fileId) {
                DB::table('property_link_documents')->insert([
                    'property_link_id' => $link->id,
                    'property_file_id' => $fileId,
                    'sort_order' => $sort,
                    'created_at' => now(),
                ]);
            }

            if (!empty($data['is_default'])) {
                $this->service->markAsDefault($link);
                $link->refresh();
            }

            return $link;
        });

        return response()->json(['link' => $this->serialize($link)]);
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
