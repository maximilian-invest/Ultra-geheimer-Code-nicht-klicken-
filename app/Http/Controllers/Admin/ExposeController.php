<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Services\Expose\ExposeConfigBuilder;
use App\Services\Expose\ExposePaginationService;
use App\Services\Expose\ExposeRenderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExposeController extends Controller
{
    public function __construct(
        protected ExposeConfigBuilder $builder,
        protected ExposePaginationService $pagination,
    ) {}

    /** Generiert + speichert ein Default-Exposé für die Property. */
    public function store(Request $request, Property $property): JsonResponse
    {
        $config = $this->builder->build($property);

        // Bisherige aktive Version deaktivieren.
        PropertyExposeVersion::where('property_id', $property->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'created_by'  => $request->user()?->id,
            'name'        => 'Exposé ' . now()->format('d.m.Y H:i'),
            'config_json' => $config,
            'is_active'   => true,
        ]);

        return response()->json([
            'success'    => true,
            'version_id' => $version->id,
            'page_count' => count($config['pages']),
        ]);
    }

    /**
     * Updatet die Makler-kuratierten Expose-Textfelder auf der Property:
     * - expose_claim: Kurz-Zitat für Cover/Editorial-Spreads
     * - expose_captions_pool: Multi-Line-Pool für Editorial-Impressionen
     */
    public function updateCaptions(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'expose_claim'          => ['nullable', 'string', 'max:200'],
            'expose_captions_pool'  => ['nullable', 'string', 'max:4000'],
        ]);

        $property->forceFill([
            'expose_claim'         => $data['expose_claim'] ?? null,
            'expose_captions_pool' => $data['expose_captions_pool'] ?? null,
        ])->save();

        return response()->json(['success' => true]);
    }

    /** HTML-Preview der aktiven Version (oder einer bestimmten). */
    public function preview(Request $request, Property $property): Response
    {
        $versionId = $request->query('version_id');
        $version = $versionId
            ? PropertyExposeVersion::where('property_id', $property->id)->find($versionId)
            : PropertyExposeVersion::where('property_id', $property->id)->where('is_active', true)->first();

        if (!$version) {
            $version = new PropertyExposeVersion([
                'property_id' => $property->id,
                'config_json' => $this->builder->build($property),
            ]);
            $version->setRelation('property', $property);
        }

        $ctx = ExposeRenderContext::build($version, $this->pagination);
        return response()->view('expose.layout', ['ctx' => $ctx]);
    }
}
