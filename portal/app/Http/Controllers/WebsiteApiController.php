<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class WebsiteApiController extends Controller
{
    /**
     * GET /api/website/properties
     * Public: Returns all properties marked for website display
     */
    public function properties(Request $request)
    {
        // Allow cache bust via ?refresh=1
        if ($request->query('refresh')) {
            Cache::forget('website_properties');
        }
        $data = Cache::remember('website_properties', 120, function () {
            $properties = DB::table('properties')
                ->where(function($q) {
                    // Show if sr-homes portal toggle is enabled
                    $q->whereExists(function($sub) {
                        $sub->select(DB::raw(1))
                            ->from('property_portals')
                            ->whereColumn('property_portals.property_id', 'properties.id')
                            ->where('property_portals.portal_name', 'sr-homes')
                            ->where('property_portals.sync_enabled', 1);
                    });
                })
                ->select([
                    'id', 'ref_id', 'project_name', 'address', 'city', 'zip',
                    'object_type as type', 'property_category', 'realty_status', 'purchase_price as price',
                    'living_area as area_living', 'free_area', 'total_area', 'rooms_amount as rooms', 'bathrooms',
                    'construction_year as year_built', 'year_renovated', 'realty_description as description', 'highlights',
                    'main_image_id', 'website_gallery_ids',
                    'total_units', 'energy_certificate', 'heating_demand_value',
                    'garage_spaces', 'parking_spaces', 'has_basement',
                    'has_garden', 'has_elevator', 'has_balcony', 'has_terrace',
                    'has_loggia', 'condition_note'
                ])
                ->orderBy('id', 'desc')
                ->get();

            foreach ($properties as &$p) {
                // Main image
                if ($p->main_image_id) {
                    $p->main_image_url = url("/api/website/image/{$p->main_image_id}");
                } else {
                    // Fallback: first image from property_images (uploaded via PropertyEditor)
                    $firstImg = DB::table('property_images')
                        ->where('property_id', $p->id)
                        ->where('is_public', 1)
                        ->orderByDesc('is_title_image')
                        ->orderBy('sort_order')
                        ->first();
                    if ($firstImg) {
                        $p->main_image_url = url('/storage/' . $firstImg->path);
                    } else {
                        // Final fallback: property_files
                        $firstFile = DB::table('property_files')
                            ->where('property_id', $p->id)
                            ->where('mime_type', 'like', 'image/%')
                            ->orderBy('sort_order')
                            ->first();
                        $p->main_image_url = $firstFile
                            ? url("/api/website/image/{$firstFile->id}")
                            : null;
                    }
                }

                // Gallery images — combine all sources
                $galleryUrls = [];
                $galleryIds = json_decode($p->website_gallery_ids ?? '[]', true);
                if (!empty($galleryIds)) {
                    $galleryUrls = array_map(fn($id) => url("/api/website/image/{$id}"), $galleryIds);
                }
                // Add property_images (PropertyEditor uploads)
                $piImages = DB::table('property_images')
                    ->where('property_id', $p->id)
                    ->where('is_public', 1)
                    ->orderByDesc('is_title_image')
                    ->orderBy('sort_order')
                    ->pluck('path');
                foreach ($piImages as $path) {
                    $url = url('/storage/' . $path);
                    if (!in_array($url, $galleryUrls)) $galleryUrls[] = $url;
                }
                // Add property_files
                $pfImages = DB::table('property_files')
                    ->where('property_id', $p->id)
                    ->where('mime_type', 'like', 'image/%')
                    ->orderBy('sort_order')
                    ->pluck('id');
                foreach ($pfImages as $fid) {
                    $url = url("/api/website/image/{$fid}");
                    if (!in_array($url, $galleryUrls)) $galleryUrls[] = $url;
                }
                $p->gallery_urls = $galleryUrls;

                // Units for Neubauprojekt — compute ranges from units
                if (stripos($p->type, 'Neubauprojekt') !== false || $p->total_units > 0) {
                    $units = DB::table('property_units')
                        ->where('property_id', $p->id)
                        ->where('is_parking', 0)
                        ->get();

                    $p->units_total = $units->count();
                    $p->units_free = $units->where('status', 'frei')->count();

                    // Compute ranges from unit data for the stats grid
                    $freeUnits = $units->whereIn('status', ['frei', '']);
                    $areas = $freeUnits->pluck('area_m2')->filter()->map(fn($v) => (float)$v)->values();
                    $rooms = $freeUnits->pluck('rooms')->filter()->map(fn($v) => (int)$v)->filter()->values();

                    if ($areas->count() > 0) {
                        $minA = $areas->min();
                        $maxA = $areas->max();
                        $p->area_living = $minA;
                        $p->area_range = $minA == $maxA
                            ? number_format($minA, 0, ',', '.') . ' m²'
                            : number_format($minA, 0, ',', '.') . ' – ' . number_format($maxA, 0, ',', '.') . ' m²';
                    }
                    if ($rooms->count() > 0) {
                        $minR = $rooms->min();
                        $maxR = $rooms->max();
                        $p->rooms = $minR;
                        $p->rooms_range = $minR == $maxR ? (string)$minR : $minR . ' – ' . $maxR;
                    }
                }

                // Features array from boolean fields
                $features = [];
                if ($p->has_garden) $features[] = 'Garten';
                if ($p->has_balcony) $features[] = 'Balkon';
                if ($p->has_terrace) $features[] = 'Terrasse';
                if ($p->has_loggia) $features[] = 'Loggia';
                if ($p->has_elevator) $features[] = 'Lift';
                if ($p->has_basement) $features[] = 'Keller';
                if ($p->garage_spaces > 0) $features[] = 'Garage';
                if ($p->parking_spaces > 0) $features[] = 'Stellplatz';
                $p->features = $features;

                // Cast numeric values to clean integers
                if ($p->area_living) $p->area_living = (int) round((float) $p->area_living);
                if ($p->rooms) $p->rooms = (int) round((float) $p->rooms);
                if ($p->bathrooms) $p->bathrooms = (int) $p->bathrooms;
                if ($p->free_area) $p->free_area = (int) round((float) $p->free_area);
                if ($p->total_area) $p->total_area = (int) round((float) $p->total_area);

                // Clean up internal fields
                unset($p->main_image_id, $p->website_gallery_ids);
                unset($p->has_garden, $p->has_balcony, $p->has_terrace, $p->has_loggia);
                unset($p->has_elevator, $p->has_basement, $p->garage_spaces, $p->parking_spaces);
            }

            return $properties;
        });

        return response()->json([
            'success' => true,
            'properties' => $data,
            'count' => count($data)
        ])->header('Access-Control-Allow-Origin', config('app.frontend_url', '*'));
    }

    /**
     * GET /api/website/property/{id}
     * Public: Single property detail
     */
    public function property($id)
    {
        $p = DB::table('properties')
            ->where('id', $id)
            ->where(function($q) {
                $q->whereExists(function($sub) {
                    $sub->select(DB::raw(1))
                        ->from('property_portals')
                        ->whereColumn('property_portals.property_id', 'properties.id')
                        ->where('property_portals.portal_name', 'sr-homes')
                        ->where('property_portals.sync_enabled', 1);
                });
            })
            ->first();

        if (!$p) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Map DB field names to what the React frontend expects
        $p->description = $p->realty_description ?? null;
        $p->type = $p->object_type ?? null;
        $p->price = $p->purchase_price ?? null;
        $p->area_living = $p->living_area ?? null;
        $p->rooms = $p->rooms_amount ?? 0;
        $p->year_built = $p->construction_year ?? null;
        $p->location_description = $p->location_description ?? null;
        $p->equipment_description = $p->equipment_description ?? null;

        // Features array from boolean fields
        $features = [];
        if (!empty($p->has_garden)) $features[] = 'Garten';
        if (!empty($p->has_balcony)) $features[] = 'Balkon';
        if (!empty($p->has_terrace)) $features[] = 'Terrasse';
        if (!empty($p->has_loggia)) $features[] = 'Loggia';
        if (!empty($p->has_elevator)) $features[] = 'Lift';
        if (!empty($p->has_basement)) $features[] = 'Keller';
        if (!empty($p->garage_spaces) && $p->garage_spaces > 0) $features[] = 'Garage';
        if (!empty($p->parking_spaces) && $p->parking_spaces > 0) $features[] = 'Stellplatz';
        $p->features = $features;

        // All images — prefer property_images (PropertyEditor), fallback to property_files
        $piImages = DB::table('property_images')
            ->where('property_id', $id)
            ->where('is_public', 1)
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'label' => $f->category ?? $f->title ?? '',
                'url' => url('/storage/' . $f->path),
                'is_title' => (bool) $f->is_title_image,
            ]);

        // Set main image URL for detail page too
        if (!isset($p->main_image_url) || !$p->main_image_url) {
            $titleImg = DB::table('property_images')
                ->where('property_id', $id)->where('is_public', 1)
                ->orderByDesc('is_title_image')->orderBy('sort_order')->first();
            $p->main_image_url = $titleImg ? url('/storage/' . $titleImg->path) : null;
        }

        // Combine both image sources (property_images + property_files)
        $pfImages = DB::table('property_files')
            ->where('property_id', $id)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($f) => [
                'id' => 'pf_' . $f->id,
                'label' => $f->label ?? '',
                'url' => url("/api/website/image/{$f->id}"),
                'is_title' => false,
            ]);

        $allImages = $piImages->concat($pfImages);
        // Deduplicate by URL
        $seen = [];
        $p->images = $allImages->filter(function($img) use (&$seen) {
            if (in_array($img['url'], $seen)) return false;
            $seen[] = $img['url'];
            return true;
        })->values();
        // Units for development projects
        $units = DB::table("property_units")
            ->where("property_id", $id)
            ->where("is_parking", 0)
            ->select("id", "unit_number", "unit_type", "status", "price", "rooms", "area_m2")
            ->orderByRaw("CAST(REGEXP_REPLACE(unit_number, '[^0-9]', '') AS UNSIGNED)")
            ->get();
        $parking = DB::table("property_units")
            ->where("property_id", $id)
            ->where("is_parking", 1)
            ->select("id", "unit_number", "unit_type", "status", "price")
            ->orderByRaw("CAST(REGEXP_REPLACE(unit_number, '[^0-9]', '') AS UNSIGNED)")
            ->get();
        $p->units = $units;
        $p->parking = $parking;

        // Compute ranges from units for Neubauprojekte
        if ($units->count() > 0) {
            $freeUnits = $units->whereIn('status', ['frei', '']);
            $areas = $freeUnits->pluck('area_m2')->filter()->map(fn($v) => (float)$v)->values();
            $rooms = $freeUnits->pluck('rooms')->filter()->map(fn($v) => (int)$v)->filter()->values();
            $prices = $freeUnits->pluck('price')->filter()->map(fn($v) => (float)$v)->filter()->values();

            if ($areas->count() > 0) {
                $p->area_range = $areas->min() == $areas->max()
                    ? number_format($areas->min(), 0, ',', '.') . ' m²'
                    : number_format($areas->min(), 0, ',', '.') . ' – ' . number_format($areas->max(), 0, ',', '.') . ' m²';
            }
            if ($rooms->count() > 0) {
                $p->rooms_range = $rooms->min() == $rooms->max()
                    ? (string)$rooms->min()
                    : $rooms->min() . ' – ' . $rooms->max();
            }
            if ($prices->count() > 0) {
                $p->price_range = 'EUR ' . number_format($prices->min(), 0, ',', '.') . ' – ' . number_format($prices->max(), 0, ',', '.');
            }
        }

        // Downloads — files marked as website-downloadable
        $downloads = DB::table('property_files')
            ->where('property_id', $id)
            ->where('is_website_download', 1)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'label' => $f->label,
                'filename' => $f->filename,
                'url' => url('/storage/' . $f->path),
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
            ]);
        $p->downloads = $downloads;

        return response()->json([
            'success' => true,
            'property' => $p
        ])->header('Access-Control-Allow-Origin', config('app.frontend_url', '*'));
    }

    /**
     * GET /api/website/image/{id}
     * Public: Serves a property image file
     */
    public function image($id)
    {
        $file = DB::table('property_files')->find($id);

        if (!$file || !str_starts_with($file->mime_type, 'image/')) {
            abort(404);
        }

        $path = storage_path("app/public/property-files/{$file->filename}");
        if (!file_exists($path)) {
            // Try alternate path
            $path = storage_path("app/property-files/{$file->filename}");
        }
        if (!file_exists($path)) {
            abort(404);
        }
        // Prevent directory traversal
        $realPath = realpath($path);
        if (!$realPath || !str_starts_with($realPath, storage_path('app'))) {
            abort(403);
        }

        return response()->file($path, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
        ]);
    }

    /**
     * GET /api/website/content
     * Public: Returns all CMS content for the website
     */
    public function content()
    {
        $data = Cache::remember('website_content', 600, function () {
            $rows = DB::table('website_content')
                ->where('is_active', 1)
                ->orderBy('section')
                ->orderBy('sort_order')
                ->get();

            $grouped = [];
            foreach ($rows as $row) {
                $value = $row->content_value;
                if ($row->content_type === 'json') {
                    $value = json_decode($value, true);
                }
                $grouped[$row->section][$row->content_key] = $value;
            }

            return $grouped;
        });

        return response()->json([
            'success' => true,
            'content' => $data
        ])->header('Access-Control-Allow-Origin', config('app.frontend_url', '*'));
    }

    /**
     * POST /api/website/upload
     * Admin only: Upload image/video for CMS
     */
    public function upload(Request $request)
    {
        // Simple API key check
        $key = $request->input('key') ?? $request->header('X-Api-Key');
        if ($key !== config('services.admin_api_key', env('ADMIN_API_KEY'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'file' => 'required|file|max:102400|mimes:jpg,jpeg,png,webp,svg,mp4,webm,mov',
            'section' => 'required|string|max:100',
            'content_key' => 'required|string|max:100',
        ]);

        $file = $request->file('file');
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $filename = time() . '_' . $safeName;
        $path = $file->storeAs('website', $filename, 'public');

        $url = url(Storage::disk('public')->url($path));

        // Update or create the content entry
        $contentType = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image';

        DB::table('website_content')->updateOrInsert(
            ['section' => $request->section, 'content_key' => $request->content_key],
            ['content_value' => $url, 'content_type' => $contentType, 'updated_at' => now()]
        );

        // Clear cache
        Cache::forget('website_content');

        return response()->json([
            'success' => true,
            'url' => $url,
            'content_type' => $contentType
        ]);
    }
}
