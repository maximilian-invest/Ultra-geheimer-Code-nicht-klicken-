<?php

namespace App\Http\Controllers\Admin;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class PropertyImageController extends Controller
{
    /**
     * List all images for a property
     */
    public function index(Request $request): JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $images = PropertyImage::where('property_id', $propertyId)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($img) {
                $img->url = $img->path
                    ? asset('storage/property-images/' . $img->path)
                    : null;
                return $img;
            });

        return response()->json(['images' => $images]);
    }

    /**
     * Upload one or more images
     */
    public function upload(Request $request): JsonResponse
    {
        $propertyId = intval($request->input('property_id', 0));
        if (!$propertyId) return response()->json(['error' => 'property_id required'], 400);

        $property = Property::find($propertyId);
        if (!$property) return response()->json(['error' => 'Property not found'], 404);

        $uploaded = [];
        $files = $request->file('images', []);
        if (!is_array($files)) $files = [$files];

        $maxSort = PropertyImage::where('property_id', $propertyId)->max('sort_order') ?? 0;

        foreach ($files as $file) {
            if (!$file || !$file->isValid()) continue;

            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $subDir = 'property-images/' . $propertyId;

            // Ensure directory exists
            Storage::disk('public')->makeDirectory($subDir);

            $path = $file->storeAs($subDir, $filename, 'public');

            // Get image dimensions
            $width = null;
            $height = null;
            if (str_starts_with($file->getMimeType(), 'image/')) {
                $imageSize = @getimagesize($file->getPathname());
                if ($imageSize) {
                    $width = $imageSize[0];
                    $height = $imageSize[1];
                }
            }

            $maxSort++;

            $category = $request->input('category', 'sonstiges');
            $isFirst = PropertyImage::where('property_id', $propertyId)->count() === 0;

            $img = PropertyImage::create([
                'property_id'    => $propertyId,
                'filename'       => $filename,
                'original_name'  => $file->getClientOriginalName(),
                'path'           => $propertyId . '/' . $filename,
                'mime_type'      => $file->getMimeType(),
                'file_size'      => $file->getSize(),
                'width'          => $width,
                'height'         => $height,
                'category'       => $category,
                'is_title_image' => $isFirst ? 1 : 0,
                'sort_order'     => $maxSort,
            ]);

            $img->url = asset('storage/property-images/' . $img->path);
            $uploaded[] = $img;
        }

        return response()->json(['images' => $uploaded, 'count' => count($uploaded)]);
    }

    /**
     * Update image metadata (category, title, is_title_image, etc.)
     */
    public function update(Request $request): JsonResponse
    {
        $id = intval($request->input('id', 0));
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $image = PropertyImage::find($id);
        if (!$image) return response()->json(['error' => 'Image not found'], 404);

        $fields = ['category', 'title', 'description', 'is_title_image', 'is_floorplan', 'is_public', 'sort_order'];
        $data = [];
        foreach ($fields as $f) {
            if ($request->has($f)) $data[$f] = $request->input($f);
        }

        // If setting as title image, unset others
        if (!empty($data['is_title_image'])) {
            PropertyImage::where('property_id', $image->property_id)
                ->where('id', '!=', $id)
                ->update(['is_title_image' => 0]);
        }

        $image->update($data);

        return response()->json(['success' => true, 'image' => $image]);
    }

    /**
     * Delete an image
     */
    public function delete(Request $request): JsonResponse
    {
        $id = intval($request->input('id', 0));
        if (!$id) return response()->json(['error' => 'id required'], 400);

        $image = PropertyImage::find($id);
        if (!$image) return response()->json(['error' => 'Image not found'], 404);

        // Delete file
        Storage::disk('public')->delete('property-images/' . $image->path);

        $propertyId = $image->property_id;
        $wasTitleImage = $image->is_title_image;
        $image->delete();

        // If it was the title image, promote the first remaining image
        if ($wasTitleImage) {
            $first = PropertyImage::where('property_id', $propertyId)->orderBy('sort_order')->first();
            if ($first) $first->update(['is_title_image' => 1]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Reorder images
     */
    public function reorder(Request $request): JsonResponse
    {
        $order = $request->input('order', []);
        if (empty($order)) return response()->json(['error' => 'order array required'], 400);

        foreach ($order as $i => $imageId) {
            PropertyImage::where('id', intval($imageId))->update(['sort_order' => $i]);
        }

        return response()->json(['success' => true]);
    }
}
