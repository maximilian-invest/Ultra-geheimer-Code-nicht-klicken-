<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PortalEmail;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Viewing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // Stats for sidebar (broker-scoped)
        $brokerId = \Auth::id();
        $userType = \Auth::user()->user_type ?? 'makler';
        // Assistenz sees ALL properties, admin/makler see only their own
        $scopeAll = in_array($userType, ['assistenz']);
        $brokerPropertyIds = ($brokerId && !$scopeAll)
            ? Property::where('broker_id', $brokerId)->pluck('id')->toArray()
            : Property::pluck('id')->toArray();

        $stats = [
            'properties' => count($brokerPropertyIds),
            'emails' => PortalEmail::whereIn('property_id', $brokerPropertyIds)->count(),
            'activities' => Activity::whereIn('property_id', $brokerPropertyIds)->count(),
            'new_24h' => PortalEmail::where('direction', 'inbound')
                ->where('email_date', '>=', now()->subDay())
                ->whereNotIn('category', ['sonstiges'])
                ->whereIn('property_id', $brokerPropertyIds)
                ->count(),
            'viewings_today' => Viewing::whereDate('viewing_date', today())
                ->whereIn('status', ['geplant', 'bestaetigt'])
                ->whereIn('property_id', $brokerPropertyIds)
                ->count(),
            'customers' => Customer::count(),
        ];

        $properties = Property::select('id', 'broker_id', 'ref_id', 'project_name', 'title', 'address', 'city', 'realty_status', 'property_category', 'customer_id', 'owner_name', 'owner_email', 'owner_phone', 'purchase_price', 'total_area', 'rooms_amount', 'object_type', 'project_group_id', 'parent_id', 'openimmo_id', 'show_on_website', 'created_at',
            DB::raw('COALESCE(on_hold, 0) as on_hold'), 'on_hold_note',
            DB::raw('(SELECT COUNT(*) FROM property_files WHERE property_files.property_id = properties.id) as files_count'),
            DB::raw('(SELECT COALESCE(SUM(price), 0) FROM property_units WHERE property_units.property_id = properties.id AND property_units.is_parking = 0) as total_volume'),
            DB::raw('(SELECT name FROM users WHERE users.id = properties.broker_id LIMIT 1) as broker_name'))
            // All users see all properties; makler gets readonly flag on non-owned ones
            ->orderBy('address')
            ->get()
            ->map(function($p) use ($userType, $brokerId) {
                // Thumbnail: 1) property_images (title image), 2) property_files (image mime)
                $img = DB::table('property_images')
                    ->where('property_id', $p->id)
                    ->where('is_public', 1)
                    ->orderByDesc('is_title_image')
                    ->orderBy('sort_order')
                    ->first();
                if ($img) {
                    $p->thumbnail_url = url('/storage/' . $img->path);
                } else {
                    $fileImg = DB::table('property_files')
                        ->where('property_id', $p->id)
                        ->where('mime_type', 'LIKE', 'image/%')
                        ->orderBy('sort_order')
                        ->first();
                    $p->thumbnail_url = $fileImg ? url('/storage/' . $fileImg->path) : null;
                }
                // Mark properties not owned by current user
                $p->readonly = ($userType === 'makler' && $brokerId && $p->broker_id != $brokerId);
                $p->is_other_broker = ($brokerId && $p->broker_id != $brokerId);
                return $p;
            });

        // Knowledge counts per property
        $kbCounts = DB::table('property_knowledge')
            ->where('is_active', 1)
            ->selectRaw('property_id, COUNT(*) as cnt')
            ->groupBy('property_id')
            ->pluck('cnt', 'property_id');

        // Load portal data for all properties
        $portalData = DB::table('property_portals')
            ->whereIn('property_id', $properties->pluck('id'))
            ->get()
            ->groupBy('property_id');

        $properties->each(function($p) use ($portalData) {
            $portals = $portalData->get($p->id, collect());
            $p->portals = $portals->map(fn($row) => [
                'name' => $row->portal_name,
                'enabled' => (bool) $row->sync_enabled,
            ])->values()->toArray();
        });

        // Parent-Child hierarchy: attach children to parents, exclude children from top-level
        $propertiesById = $properties->keyBy('id');
        $childIds = [];
        $childrenMap = [];
        $properties->each(function($p) {
            $p->setAttribute('children', []);
        });
        $properties->each(function($p) use ($propertiesById, &$childIds, &$childrenMap) {
            if ($p->parent_id && $propertiesById->has($p->parent_id)) {
                $childrenMap[$p->parent_id][] = $p;
                $childIds[] = $p->id;
            }
        });
        foreach ($childrenMap as $parentId => $kids) {
            if ($propertiesById->has($parentId)) {
                $propertiesById->get($parentId)->setAttribute('children', $kids);
            }
        }
        // Remove children from top-level list (they are nested under their parent)
        $properties = $properties->filter(fn($p) => !in_array($p->id, $childIds))->values();

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'properties' => $properties,
            'kbCounts' => $kbCounts,
            'apiKey' => config('portal.api_key'),
        ]);
    }
}

