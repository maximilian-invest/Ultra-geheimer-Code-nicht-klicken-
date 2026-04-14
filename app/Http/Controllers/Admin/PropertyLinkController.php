<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyLink;
use App\Services\PropertyLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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

        $data['file_ids'] = array_values(array_unique($data['file_ids']));

        $validFileIds = DB::table('property_files')
            ->where('property_id', $property->id)
            ->whereIn('id', $data['file_ids'])
            ->pluck('id')
            ->all();

        if (count($validFileIds) !== count($data['file_ids'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file_ids' => ['Ein oder mehrere Dokumente gehoeren nicht zu dieser Property.'],
            ]);
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

    public function show(Request $request, Property $property, PropertyLink $link)
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->load('sessions.events');

        $sessions = $link->sessions->map(fn ($s) => [
            'id' => $s->id,
            'email' => $s->email,
            'first_seen_at' => $s->first_seen_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'dsgvo_accepted_at' => $s->dsgvo_accepted_at?->toIso8601String(),
            'events' => $s->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'property_file_id' => $e->property_file_id,
                'duration_s' => $e->duration_s,
                'created_at' => $e->created_at,
            ])->values(),
        ])->values();

        $allFiles = DB::table('property_files')
            ->where('property_id', $property->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'label', 'filename', 'mime_type', 'file_size'])
            ->map(fn ($f) => [
                'id' => (int) $f->id,
                'label' => $f->label ?: $f->filename,
                'filename' => $f->filename,
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
            ])
            ->values();

        $payload = [
            'property' => [
                'id' => $property->id,
                'address' => $property->address,
                'city' => $property->city,
            ],
            'link' => $this->serialize($link),
            'sessions' => $sessions,
            'allFiles' => $allFiles,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Admin/PropertyLinkDetail', $payload);
    }

    public function update(Request $request, Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['integer'],
        ]);

        $data['file_ids'] = array_values(array_unique($data['file_ids']));

        $validFileIds = DB::table('property_files')
            ->where('property_id', $property->id)
            ->whereIn('id', $data['file_ids'])
            ->pluck('id')
            ->all();

        if (count($validFileIds) !== count($data['file_ids'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file_ids' => ['Ein oder mehrere Dokumente gehoeren nicht zu dieser Property.'],
            ]);
        }

        DB::transaction(function () use ($link, $data, $validFileIds) {
            $link->update([
                'name' => $data['name'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            DB::table('property_link_documents')->where('property_link_id', $link->id)->delete();
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
            }
        });

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }

    public function destroy(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->delete();

        return response()->json(['ok' => true]);
    }

    public function revoke(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->update([
            'revoked_at' => now(),
            'revoked_by' => auth()->id(),
        ]);

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }

    public function reactivate(Property $property, PropertyLink $link): JsonResponse
    {
        abort_unless($link->property_id === $property->id, 404);

        $link->update([
            'revoked_at' => null,
            'revoked_by' => null,
        ]);

        return response()->json(['link' => $this->serialize($link->fresh())]);
    }

    public function activeForProperty(Property $property): JsonResponse
    {
        $links = $property->propertyLinks()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PropertyLink $link) => [
                'id' => $link->id,
                'name' => $link->name,
                'url' => url("/docs/{$link->token}"),
                'expires_at' => $link->expires_at?->toIso8601String(),
                'document_ids' => $link->documentIds()->all(),
                'is_default' => (bool) $link->is_default,
            ]);

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
