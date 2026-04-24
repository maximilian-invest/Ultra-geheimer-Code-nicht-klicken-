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
            'expose_cover_kicker'   => ['nullable', 'string', 'max:120'],
            'expose_cover_title'    => ['nullable', 'string', 'max:120'],
            'expose_cover_subtitle' => ['nullable', 'string', 'max:200'],
        ]);

        $property->forceFill([
            'expose_claim'          => $data['expose_claim'] ?? null,
            'expose_captions_pool'  => $data['expose_captions_pool'] ?? null,
            'expose_cover_kicker'   => $data['expose_cover_kicker'] ?? null,
            'expose_cover_title'    => $data['expose_cover_title'] ?? null,
            'expose_cover_subtitle' => $data['expose_cover_subtitle'] ?? null,
        ])->save();

        return response()->json(['success' => true]);
    }

    /**
     * Liefert die aktuelle aktive Version + den Medien-Pool als JSON für
     * den Editor-Frontend. Wenn keine Version existiert: Default-Config
     * on-the-fly gebaut (aber nicht gespeichert) damit der Editor sofort
     * was anzeigen kann.
     */
    public function config(Property $property): JsonResponse
    {
        $version = PropertyExposeVersion::where('property_id', $property->id)
            ->where('is_active', true)
            ->first();

        $config = $version?->config_json ?? $this->builder->build($property);

        // Bild-Pool für Picker-UI (nur public, keine Grundrisse).
        $images = $property->images()
            ->where('is_public', true)
            ->where('is_floorplan', false)
            ->reorder()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'path', 'category', 'is_title_image']);

        $imgList = $images->map(fn($img) => [
            'id'             => $img->id,
            'url'            => asset('storage/' . $img->path),
            'category'       => $img->category,
            'is_title_image' => (bool) $img->is_title_image,
        ])->values();

        return response()->json([
            'version_id' => $version?->id,
            'config'     => $config,
            'images'     => $imgList,
        ]);
    }

    /**
     * Speichert die vom Editor manipulierte Config als neue aktive Version.
     * Vorherige aktive Version wird deaktiviert.
     */
    public function updateConfig(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'config'                  => ['required', 'array'],
            'config.pages'            => ['required', 'array'],
            'config.pages.*.type'     => ['required', 'string', 'in:cover,details,haus,sanierungen,lage,impressionen_intro,impressionen,kontakt'],
            'config.pages.*.layout'   => ['nullable', 'string', 'in:L1,L2,L3,L4,L5,LM,M1,M3,M4'],
            'config.pages.*.image_id' => ['nullable', 'integer'],
            'config.pages.*.image_ids'=> ['nullable', 'array'],
            'config.pages.*.caption'  => ['nullable', 'string', 'max:300'],
            'config.pages.*.hidden'   => ['nullable', 'boolean'],
        ]);

        PropertyExposeVersion::where('property_id', $property->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'created_by'  => $request->user()?->id,
            'name'        => 'Exposé ' . now()->format('d.m.Y H:i'),
            'config_json' => $data['config'],
            'is_active'   => true,
        ]);

        return response()->json([
            'success'    => true,
            'version_id' => $version->id,
            'page_count' => count($data['config']['pages']),
        ]);
    }

    /**
     * HTML-Preview der aktiven Version (oder einer bestimmten).
     * Akzeptiert `pdf_bypass` mit HMAC für Puppeteer-Aufrufe ohne Session.
     */
    public function preview(Request $request, Property $property): Response
    {
        $bypass = $request->query('pdf_bypass');
        if ($bypass) {
            $expected = hash_hmac('sha256', 'expose-preview-' . $property->id, config('app.key'));
            if (!hash_equals($expected, (string) $bypass)) {
                return response('Forbidden', 403);
            }
        } elseif (!$request->user()) {
            return response('Unauthenticated', 401);
        }

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

    /**
     * PDF-Download der aktiven Version für Admin. Generiert einen HMAC-Bypass,
     * damit Puppeteer die Preview-Route ohne Session aufrufen kann.
     */
    public function previewPdf(Property $property): Response
    {
        $version = PropertyExposeVersion::where('property_id', $property->id)
            ->where('is_active', true)
            ->first();

        if (!$version) {
            // Default on-the-fly erzeugen, damit auch ohne vorheriges Speichern
            // ein PDF rauskommt.
            $tmp = new PropertyExposeVersion([
                'property_id' => $property->id,
                'config_json' => $this->builder->build($property),
            ]);
            $tmp->setRelation('property', $property);
            $version = $tmp;
        }

        $bypass = hash_hmac('sha256', 'expose-preview-' . $property->id, config('app.key'));
        $url = url("/admin/properties/{$property->id}/expose/preview") . '?pdf_bypass=' . $bypass;

        $pdfService = app(\App\Services\Expose\ExposePdfService::class);
        $binary = $pdfService->renderFromUrl($url);

        $filename = 'Expose-' . \Illuminate\Support\Str::slug($property->title ?: ($property->city ?: 'immobilie')) . '.pdf';

        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($binary),
        ]);
    }
}
