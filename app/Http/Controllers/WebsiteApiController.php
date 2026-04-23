<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class WebsiteApiController extends Controller
{
    /**
     * Compute and attach range fields from property_units (Neubau only).
     * Sets: area_range, rooms_range, balcony_terrace_range, garden_range.
     * Also updates area_living / rooms to the MIN value (fuer Fallback-Displays).
     */
    private static function attachNeubauRanges($p, $freeUnits)
    {
        $fmt = fn($min, $max, $unit) => $min == $max
            ? number_format((float) $min, $min == (int) $min ? 0 : 1, ',', '.') . ' ' . $unit
            : number_format((float) $min, $min == (int) $min ? 0 : 1, ',', '.') . ' – ' . number_format((float) $max, $max == (int) $max ? 0 : 1, ',', '.') . ' ' . $unit;

        $areas = $freeUnits->pluck('area_m2')->filter()->map(fn($v) => (float)$v)->values();
        $rooms = $freeUnits->pluck('rooms')->filter()->map(fn($v) => (float)$v)->filter()->values();
        $balconies = $freeUnits->pluck('balcony_terrace_m2')->filter()->map(fn($v) => (float)$v)->values();
        $gardens = $freeUnits->pluck('garden_m2')->filter()->map(fn($v) => (float)$v)->values();

        // WICHTIG: area_living + rooms NICHT ueberschreiben — die Property-Felder
        // koennen manuell gesetzt sein (User-Override). Wir setzen NUR die -Range
        // Felder, das Frontend entscheidet welche Anzeige (manuell vs. Range).
        if ($areas->count() > 0) {
            $p->area_range = trim($fmt($areas->min(), $areas->max(), 'm²'));
        }
        if ($rooms->count() > 0) {
            $p->rooms_range = $rooms->min() == $rooms->max()
                ? (string) ($rooms->min() == (int) $rooms->min() ? (int) $rooms->min() : $rooms->min())
                : $rooms->min() . ' – ' . $rooms->max();
        }
        if ($balconies->count() > 0) {
            $p->balcony_terrace_range = trim($fmt($balconies->min(), $balconies->max(), 'm²'));
        }
        if ($gardens->count() > 0) {
            $p->garden_range = trim($fmt($gardens->min(), $gardens->max(), 'm²'));
        }
        return $p;
    }

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
                    // Active website listings: only explicitly released objects.
                    $q->where(function($sub) {
                        $sub->where('show_on_website', 1)
                            ->whereNotIn('realty_status', ['verkauft', 'inaktiv']);
                    })
                    // Sold objects are still exposed for the Referenzen page.
                    ->orWhere('realty_status', 'verkauft');
                })
                ->select([
                    'id', 'ref_id', 'title', 'project_name', 'address', 'city', 'zip',
                    'latitude', 'longitude',
                    'object_type as type', 'property_category', 'realty_status', 'marketing_type', 'purchase_price as price',
                    'living_area as area_living', 'free_area', 'total_area', 'rooms_amount as rooms', 'bathrooms',
                    'area_balcony', 'area_terrace', 'area_garden', 'area_loggia', 'area_basement',
                    'construction_year as year_built', 'year_renovated', 'realty_description as description', 'highlights',
                    'main_image_id', 'website_gallery_ids',
                    'total_units', 'energy_certificate', 'heating_demand_value',
                    'energy_efficiency_value', 'heating_demand_class', 'energy_valid_until',
                    'garage_spaces', 'parking_spaces', 'parking_assignment', 'has_basement',
                    'has_garden', 'has_elevator', 'has_balcony', 'has_terrace',
                    'has_loggia', 'has_fitted_kitchen', 'has_air_conditioning',
                    'has_pool', 'has_sauna', 'has_fireplace', 'has_barrier_free',
                    'has_guest_wc', 'has_storage_room', 'has_alarm',
                    'condition_note', 'realty_condition', 'construction_type',
                    'ownership_type', 'furnishing', 'quality', 'flooring',
                    'available_from', 'construction_end',
                    'common_areas', 'has_photovoltaik', 'has_charging_station', 'charging_station_status',
                    'sold_at', 'broker_id', 'broker_name_override',
                    'purchase_price', 'rental_price', 'heating',
                    'building_details', 'energy_primary_source',
                    // Monatliche Betriebskosten-Aufschluesselung (Sidebar "zzgl. Betriebskosten" + Aufschluesselungs-Tabelle)
                    'operating_costs', 'heating_costs', 'warm_water_costs', 'cooling_costs',
                    'maintenance_reserves', 'admin_costs', 'elevator_costs',
                    'parking_costs_monthly', 'other_costs', 'monthly_costs',
                    'floor_count', 'floor_number',
                    // Nebenkosten-Daten fuer die Website-Darstellung rechts unter dem Kaufpreis
                    'buyer_commission_percent', 'commission_makler', 'buyer_commission_text', 'buyer_commission_free',
                    'land_transfer_tax_pct', 'land_register_fee_pct', 'mortgage_register_fee_pct', 'contract_fee_pct',
                    'nebenkosten_note', 'show_nebenkosten_on_website',
                    // Marketing / Hervorhebung
                    'is_featured', 'featured_order', 'badge',
                    'external_image_url'
                ])
                ->orderByRaw('is_featured DESC, featured_order IS NULL, featured_order ASC, id DESC')
                ->get();

            // Aufnahmeprotokoll: interne Felder duerfen NIE auf die Website
            // durchschlagen. Sind zwar nicht im Select oben — aber
            // defense-in-depth: nach jeder Select-Aenderung hier explizit
            // rausschmeissen, falls sie ueber JOIN/Schema-Aenderung reinkommen.
            $internalFields = ['encumbrances', 'approvals_status', 'approvals_notes', 'documents_available', 'internal_notes'];

            foreach ($properties as &$p) {
                // Defense-in-depth: niemals interne Aufnahmeprotokoll-Felder
                // exponieren (sind nicht im Select, aber Paranoia schadet nicht).
                foreach ($internalFields as $f) {
                    if (is_array($p)) unset($p[$f]);
                    elseif (is_object($p)) unset($p->$f);
                }

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
                        // Ueber /api/website/img/{id} mit Resize -> Listings brauchen nur ~800px Breite
                        $p->main_image_url = url("/api/website/img/{$firstImg->id}?w=800");
                    } else if (!empty($p->external_image_url)) {
                        // Fallback: external image URL (e.g. from immoji)
                        $p->main_image_url = $p->external_image_url;
                    } else {
                        $p->main_image_url = null;
                    }
                }

                // Broker info: override takes priority (for brokers not yet in users table)
                if (!empty($p->broker_name_override)) {
                    $p->broker_name = $p->broker_name_override;
                    $p->broker_title = 'Immobilienmakler/in';
                    $p->broker_image = null;
                } elseif ($p->broker_id) {
                    $broker = DB::table('users')->where('id', $p->broker_id)->first(['name', 'profile_image', 'signature_title']);
                    if ($broker) {
                        $p->broker_name = $broker->name;
                        $p->broker_title = $broker->signature_title;
                        $p->broker_image = $broker->profile_image ? url('/storage/' . $broker->profile_image) : null;
                    }
                }

                // Gallery images — combine all sources
                $galleryUrls = [];
                $galleryIds = json_decode($p->website_gallery_ids ?? '[]', true);
                if (!empty($galleryIds)) {
                    $galleryUrls = array_map(fn($id) => url("/api/website/image/{$id}"), $galleryIds);
                }
                // Add property_images (PropertyEditor uploads) — via Resize-Endpoint
                // w=800 reicht fuer Listings-Cards; Detail-Seite holt Grosse separat.
                $piImages = DB::table('property_images')
                    ->where('property_id', $p->id)
                    ->where('is_public', 1)
                    ->orderByDesc('is_title_image')
                    ->orderBy('sort_order')
                    ->select('id')
                    ->pluck('id');
                foreach ($piImages as $iid) {
                    $url = url("/api/website/img/{$iid}?w=800");
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

                // Units nur bei echten Neubauprojekten (property_category='newbuild'
                // ODER object_type enthaelt "Neubauprojekt"). total_units allein
                // ist kein verlaessliches Kriterium — kann durch KI-Import
                // faelschlich gesetzt worden sein bei einer Einzelwohnung.
                $isNewbuild = $p->property_category === 'newbuild'
                    || stripos((string) $p->type, 'Neubauprojekt') !== false;
                if ($isNewbuild) {
                    $units = DB::table('property_units')
                        ->where('property_id', $p->id)
                        ->where('is_parking', 0)
                        ->get();

                    $p->units_total = $units->count();
                    $p->units_free = $units->where('status', 'frei')->count();

                    // Compute ranges from unit data
                    $freeUnits = $units->whereIn('status', ['frei', '']);
                    $p = self::attachNeubauRanges($p, $freeUnits);
                }

                // Features array from boolean fields
                $features = [];
                if ($p->has_garden) $features[] = 'Garten';
                if ($p->has_balcony) $features[] = 'Balkon';
                if ($p->has_terrace) $features[] = 'Terrasse';
                if ($p->has_loggia) $features[] = 'Loggia';
                if ($p->has_elevator) $features[] = 'Lift';
                if ($p->has_basement) $features[] = 'Keller';
                if (!empty($p->has_fitted_kitchen)) $features[] = 'Einbauküche';
                if (!empty($p->has_air_conditioning)) $features[] = 'Klimaanlage';
                if (!empty($p->has_pool)) $features[] = 'Pool';
                if (!empty($p->has_sauna)) $features[] = 'Sauna';
                if (!empty($p->has_fireplace)) $features[] = 'Kamin';
                if (!empty($p->has_barrier_free)) $features[] = 'Barrierefrei';
                if (!empty($p->has_guest_wc)) $features[] = 'Gäste-WC';
                if (!empty($p->has_storage_room)) $features[] = 'Abstellraum';
                if (!empty($p->has_alarm)) $features[] = 'Alarmanlage';
                if (!empty($p->has_photovoltaik)) $features[] = 'Photovoltaik';
                // E-Ladestation: drei Zustaende — installed / prepared / none.
                $chargingStatus = $p->charging_station_status
                    ?: (!empty($p->has_charging_station) ? 'installed' : 'none');
                if ($chargingStatus === 'installed') $features[] = 'E-Ladestation';
                elseif ($chargingStatus === 'prepared') $features[] = 'Vorkehrung für E-Ladestation';
                if ($p->garage_spaces > 0) $features[] = 'Garage';
                if ($p->parking_spaces > 0) $features[] = 'Stellplatz';
                $p->features = $features;

                // building_details JSON auslesen: Heizungsart, Befeuerung, Warmwasser
                $bd = is_string($p->building_details ?? null)
                    ? (json_decode($p->building_details, true) ?: [])
                    : (is_array($p->building_details ?? null) ? $p->building_details : []);
                $heatingBlock = is_array($bd['heating'] ?? null) ? $bd['heating'] : [];
                $p->heating_types = is_array($heatingBlock['types'] ?? null) ? array_values(array_filter($heatingBlock['types'])) : [];
                $p->heating_fuel = $heatingBlock['fuel'] ?? null;
                $p->heating_hot_water = $heatingBlock['hot_water'] ?? null;

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

            // Add sold property_units as individual entries (e.g. Neubauprojekt units)
            $soldUnits = DB::table('property_units')
                ->join('properties', 'properties.id', '=', 'property_units.property_id')
                ->where('property_units.status', 'verkauft')
                ->where(function($q) {
                    $q->where('property_units.is_parking', false)
                       ->orWhereNull('property_units.is_parking');
                })
                ->whereNotIn('property_units.unit_type', ['Stellplatz', 'Tiefgarage', 'Carportplatz'])
                ->select([
                    'property_units.id as unit_id',
                    'property_units.unit_number',
                    'property_units.unit_type',
                    'property_units.area_m2',
                    'property_units.rooms',
                    'property_units.price',
                    'property_units.images as unit_images',
                    'property_units.updated_at as unit_updated_at',
                    'properties.id as parent_property_id',
                    'properties.title as parent_title',
                    'properties.project_name',
                    'properties.address',
                    'properties.city',
                    'properties.zip',
                    'properties.broker_id',
                    'properties.broker_name_override',
                    'properties.main_image_id',
                    'properties.external_image_url',
                ])
                ->get();

            foreach ($soldUnits as $u) {
                $unitTitle = $u->project_name ?: $u->parent_title;
                $unitTitle .= ' | ' . $u->unit_number;

                // Resolve broker
                $brokerName = null;
                $brokerTitle = null;
                $brokerImage = null;
                if (!empty($u->broker_name_override)) {
                    $brokerName = $u->broker_name_override;
                    $brokerTitle = 'Immobilienmakler/in';
                } elseif ($u->broker_id) {
                    $broker = DB::table('users')->where('id', $u->broker_id)->first(['name', 'profile_image', 'signature_title']);
                    if ($broker) {
                        $brokerName = $broker->name;
                        $brokerTitle = $broker->signature_title;
                        $brokerImage = $broker->profile_image ? url('/storage/' . $broker->profile_image) : null;
                    }
                }

                // Resolve image - try unit images first, then parent
                $mainImageUrl = null;
                if (!empty($u->unit_images)) {
                    $imgs = json_decode($u->unit_images, true);
                    if (!empty($imgs) && isset($imgs[0])) {
                        $imgVal = is_string($imgs[0]) ? $imgs[0] : ($imgs[0]['url'] ?? null);
                        if ($imgVal && !str_starts_with($imgVal, 'http')) {
                            $mainImageUrl = url('/storage/' . $imgVal);
                        } else {
                            $mainImageUrl = $imgVal;
                        }
                    }
                }
                if (!$mainImageUrl && $u->main_image_id) {
                    $mainImageUrl = url("/api/website/image/{$u->main_image_id}");
                }
                if (!$mainImageUrl && !empty($u->external_image_url)) {
                    $mainImageUrl = $u->external_image_url;
                }
                // Fallback: title image from parent property (via Resize-Endpoint)
                if (!$mainImageUrl) {
                    $titleImg = DB::table('property_images')
                        ->where('property_id', $u->parent_property_id)
                        ->where('is_public', 1)
                        ->orderByDesc('is_title_image')
                        ->orderBy('sort_order')
                        ->first();
                    if ($titleImg) {
                        $mainImageUrl = url("/api/website/img/{$titleImg->id}?w=800");
                    }
                }

                $properties->push((object) [
                    'id' => 'unit_' . $u->unit_id,
                    'ref_id' => null,
                    'title' => $unitTitle,
                    'project_name' => $u->project_name,
                    'address' => $u->address,
                    'city' => $u->city,
                    'zip' => $u->zip,
                    'type' => $u->unit_type,
                    'property_category' => 'newbuild',
                    'realty_status' => 'verkauft',
                    'price' => $u->price,
                    'area_living' => $u->area_m2,
                    'free_area' => null,
                    'total_area' => null,
                    'rooms' => $u->rooms,
                    'bathrooms' => null,
                    'year_built' => null,
                    'year_renovated' => null,
                    'description' => null,
                    'highlights' => null,
                    'main_image_url' => $mainImageUrl,
                    'gallery_urls' => [],
                    'total_units' => null,
                    'purchase_price' => $u->price,
                    'rental_price' => null,
                    'sold_at' => $u->unit_updated_at,
                    'broker_name' => $brokerName,
                    'broker_title' => $brokerTitle,
                    'broker_image' => $brokerImage,
                    'features' => (object) [],
                ]);
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
                $q->where(function($sub) {
                    $sub->where('show_on_website', 1)
                        ->whereNotIn('realty_status', ['verkauft', 'inaktiv']);
                })
                ->orWhere('realty_status', 'verkauft');
            })
            ->first();

        if (!$p) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Aufnahmeprotokoll: interne Felder duerfen NIE auf die Website
        // durchschlagen. `first()` ohne Select holt alle Spalten inkl. der
        // Aufnahmeprotokoll-Felder — daher hier explizit entfernen.
        $internalFields = ['encumbrances', 'approvals_status', 'approvals_notes', 'documents_available', 'internal_notes'];
        foreach ($internalFields as $f) {
            if (is_array($p)) unset($p[$f]);
            elseif (is_object($p)) unset($p->$f);
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

        // Broker-Card-Daten fuer die Objektseite auf dem Website-Frontend.
        // Prioritaet: broker_name_override (manuelle Bezeichnung) > User-Eintrag.
        $p->broker_name = null;
        $p->broker_title = null;
        $p->broker_email = null;
        $p->broker_phone = null;
        $p->broker_image = null;
        if (!empty($p->broker_name_override)) {
            $p->broker_name = $p->broker_name_override;
            $p->broker_title = 'Immobilienmakler/in';
        } elseif (!empty($p->broker_id)) {
            $broker = DB::table('users')->where('id', $p->broker_id)
                ->first(['name', 'email', 'signature_email', 'phone', 'signature_title', 'signature_phone', 'profile_image']);
            if ($broker) {
                $p->broker_name = $broker->name;
                $p->broker_title = $broker->signature_title ?: 'Immobilienmakler/in';
                // Oeffentliche E-Mail: Priorisierung in dieser Reihenfolge:
                //   1) Portal-E-Mail aus email_accounts (das ist die im Admin hinterlegte Arbeits-E-Mail)
                //   2) signature_email (expliziter Override im Settings-Dialog)
                //   3) Login-E-Mail (users.email) als letzter Fallback
                $portalEmail = DB::table('email_accounts')
                    ->where('user_id', $p->broker_id)
                    ->where('is_active', 1)
                    ->orderBy('id', 'asc')
                    ->value('email_address');
                $p->broker_email = $portalEmail
                    ?: ($broker->signature_email ?: $broker->email);
                $p->broker_phone = $broker->signature_phone ?: $broker->phone;
                $p->broker_image = $broker->profile_image ? url('/storage/' . $broker->profile_image) : null;
            }
        }

        // Features array from boolean fields
        $features = [];
        if (!empty($p->has_garden)) $features[] = 'Garten';
        if (!empty($p->has_balcony)) $features[] = 'Balkon';
        if (!empty($p->has_terrace)) $features[] = 'Terrasse';
        if (!empty($p->has_loggia)) $features[] = 'Loggia';
        if (!empty($p->has_elevator)) $features[] = 'Lift';
        if (!empty($p->has_basement)) $features[] = 'Keller';
        if (!empty($p->has_fitted_kitchen)) $features[] = 'Einbauküche';
        if (!empty($p->has_air_conditioning)) $features[] = 'Klimaanlage';
        if (!empty($p->has_pool)) $features[] = 'Pool';
        if (!empty($p->has_sauna)) $features[] = 'Sauna';
        if (!empty($p->has_fireplace)) $features[] = 'Kamin';
        if (!empty($p->has_barrier_free)) $features[] = 'Barrierefrei';
        if (!empty($p->has_guest_wc)) $features[] = 'Gäste-WC';
        if (!empty($p->has_storage_room)) $features[] = 'Abstellraum';
        if (!empty($p->has_alarm)) $features[] = 'Alarmanlage';
        if (!empty($p->garage_spaces) && $p->garage_spaces > 0) $features[] = 'Garage';
        if (!empty($p->parking_spaces) && $p->parking_spaces > 0) $features[] = 'Stellplatz';
        if (!empty($p->has_photovoltaik)) $features[] = 'Photovoltaik';
        // E-Ladestation: drei Zustaende — installed / prepared / none.
        $chargingStatus = $p->charging_station_status
            ?: (!empty($p->has_charging_station) ? 'installed' : 'none');
        if ($chargingStatus === 'installed') $features[] = 'E-Ladestation';
        elseif ($chargingStatus === 'prepared') $features[] = 'Vorkehrung für E-Ladestation';
        $p->features = $features;

        // Heizung/Warmwasser/Befeuerung aus building_details JSON
        $bd = is_string($p->building_details ?? null)
            ? (json_decode($p->building_details, true) ?: [])
            : (is_array($p->building_details ?? null) ? $p->building_details : []);
        $heatingBlock = is_array($bd['heating'] ?? null) ? $bd['heating'] : [];
        $p->heating_types = is_array($heatingBlock['types'] ?? null) ? array_values(array_filter($heatingBlock['types'])) : [];
        $p->heating_fuel = $heatingBlock['fuel'] ?? null;
        $p->heating_hot_water = $heatingBlock['hot_water'] ?? null;

        // All images — prefer property_images (PropertyEditor), fallback to property_files
        // Auf der Detail-Seite groessere Breite (1400px) — reicht fuer 2x-Retina-Darstellung.
        $piImages = DB::table('property_images')
            ->where('property_id', $id)
            ->where('is_public', 1)
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'label' => $f->category ?? $f->title ?? '',
                'url' => url("/api/website/img/{$f->id}?w=1400"),
                'is_title' => (bool) $f->is_title_image,
            ]);

        // Set main image URL for detail page too
        if (!isset($p->main_image_url) || !$p->main_image_url) {
            $titleImg = DB::table('property_images')
                ->where('property_id', $id)->where('is_public', 1)
                ->orderByDesc('is_title_image')->orderBy('sort_order')->first();
            $p->main_image_url = $titleImg ? url("/api/website/img/{$titleImg->id}?w=1400") : null;
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
        // Units NUR bei echten Neubauprojekten an die Website liefern. Eine
        // Einzel-Eigentumswohnung soll keine "TOP 1"-Unit auf der Detailseite
        // zeigen (passiert wenn KI-Import faelschlich Units aus dem Expose
        // extrahiert hat).
        $isNewbuildDetail = ($p->property_category ?? null) === 'newbuild'
            || stripos((string) ($p->type ?? $p->object_type ?? ''), 'Neubauprojekt') !== false;
        if ($isNewbuildDetail) {
            $units = DB::table("property_units")
                ->where("property_id", $id)
                ->where("is_parking", 0)
                ->select("id", "unit_number", "unit_type", "status", "price", "rooms", "area_m2",
                         "balcony_terrace_m2", "garden_m2")
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
        } else {
            $p->units = [];
            $p->parking = [];
        }

        // Compute ranges from units for Neubauprojekte (area/rooms/balcony/garden)
        if ($units->count() > 0) {
            $freeUnits = $units->whereIn('status', ['frei', '']);
            $p = self::attachNeubauRanges($p, $freeUnits);
            // Price-Range nur hier (list zeigt Einzelpreis)
            $prices = $freeUnits->pluck('price')->filter()->map(fn($v) => (float)$v)->filter()->values();
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
    /**
     * GET /api/website/img/{id}?w=1200&q=82
     * Serviert ein property_images-Eintrag mit on-the-fly Resize + Cache.
     * Resized Versions werden in storage/app/public/cache/img/ abgelegt.
     */
    public function resizedImage($id, Request $request)
    {
        $img = DB::table('property_images')->find($id);
        if (!$img || !$img->path) abort(404);

        $w = max(100, min(2400, (int) $request->query('w', 1200)));
        $q = max(50, min(95, (int) $request->query('q', 82)));

        $sourcePath = storage_path('app/public/' . $img->path);
        if (!is_file($sourcePath)) abort(404);

        // Cache-Pfad: Hash aus Pfad+Breite+Qualitaet.
        $cacheKey = md5($img->path . '|' . $w . '|' . $q) . '.jpg';
        $cacheDir = storage_path('app/public/cache/img');
        $cachePath = $cacheDir . '/' . $cacheKey;

        // Cache-Miss oder Source neuer als Cache -> neu generieren.
        $needsGen = !is_file($cachePath) || filemtime($cachePath) < filemtime($sourcePath);
        if ($needsGen) {
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            try {
                $data = file_get_contents($sourcePath);
                $src = @imagecreatefromstring($data);
                if (!$src) {
                    // Fallback: Original ausliefern (z.B. SVG)
                    return response()->file($sourcePath, [
                        'Content-Type' => $img->mime_type ?: 'image/jpeg',
                        'Cache-Control' => 'public, max-age=31536000, immutable',
                    ]);
                }
                $srcW = imagesx($src);
                $srcH = imagesy($src);
                if ($srcW > $w) {
                    $newH = (int) round($srcH * $w / $srcW);
                    $dst = imagescale($src, $w, $newH);
                } else {
                    $dst = $src;
                }
                // EXIF-Orientation respektieren (fuer Handy-Fotos im Portrait)
                imagejpeg($dst, $cachePath, $q);
                if ($dst !== $src) imagedestroy($dst);
                imagedestroy($src);
            } catch (\Throwable $e) {
                \Log::warning('resizedImage failed', ['id' => $id, 'error' => $e->getMessage()]);
                return response()->file($sourcePath, [
                    'Content-Type' => $img->mime_type ?: 'image/jpeg',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }
        }

        return response()->file($cachePath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

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
        $data = Cache::remember('website_content', 60, function () {
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

    /**
     * POST /api/website/inquiry
     * Nimmt eine Anfrage von der oeffentlichen Website entgegen und schickt sie
     * per E-Mail an den zustaendigen Makler (oder office@sr-homes.at als Fallback).
     * Das Subject enthaelt die Ref-ID — dadurch wird die eingehende Antwort vom
     * IMAP-Fetcher automatisch der richtigen Immobilie zugeordnet.
     */
    public function inquiry(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'email'       => 'required|email|max:200',
            'phone'       => 'nullable|string|max:60',
            'message'     => 'required|string|max:5000',
            'property_id' => 'nullable|integer',
            'honeypot'    => 'nullable|string|max:0', // Spam-Schutz: muss leer sein
        ]);

        // Honeypot (bot protection)
        if (!empty($data['honeypot'] ?? null)) {
            return response()->json(['success' => true]);
        }

        $property = null;
        $recipientEmail = 'office@sr-homes.at';
        $recipientName = 'SR-Homes';
        $subject = 'Anfrage über sr-homes.at';

        if (!empty($data['property_id'])) {
            $property = DB::table('properties')
                ->where('id', $data['property_id'])
                ->first(['id', 'ref_id', 'title', 'project_name', 'address', 'city', 'zip', 'broker_id']);
            if ($property) {
                $refId = $property->ref_id ?: ('ID-' . $property->id);
                $label = $property->title ?: ($property->project_name ?: trim(($property->address ?? '') . ', ' . ($property->city ?? '')));
                $subject = "Anfrage {$refId} — {$label}";

                // Broker-Zuordnung: Portal-Mail aus email_accounts > signature_email > users.email
                if ($property->broker_id) {
                    $portalEmail = DB::table('email_accounts')
                        ->where('user_id', $property->broker_id)
                        ->where('is_active', 1)
                        ->orderBy('id', 'asc')
                        ->value('email_address');
                    $broker = DB::table('users')
                        ->where('id', $property->broker_id)
                        ->first(['name', 'email', 'signature_email']);
                    if ($broker) {
                        $recipientEmail = $portalEmail
                            ?: ($broker->signature_email ?: $broker->email);
                        $recipientName = $broker->name ?: $recipientName;
                    }
                }
            }
        }

        // Body zusammenbauen (HTML + Text).
        $bodyText  = "Neue Anfrage über die Website sr-homes.at:\n\n";
        $bodyText .= "Name:    {$data['name']}\n";
        $bodyText .= "E-Mail:  {$data['email']}\n";
        if (!empty($data['phone'])) $bodyText .= "Telefon: {$data['phone']}\n";
        if ($property) {
            $bodyText .= "\nZum Objekt:\n";
            $bodyText .= "  Ref-ID:  {$property->ref_id}\n";
            $bodyText .= "  Titel:   " . ($property->title ?: $property->project_name) . "\n";
            $bodyText .= "  Adresse: " . trim(($property->address ?? '') . ', ' . ($property->zip ?? '') . ' ' . ($property->city ?? '')) . "\n";
            $bodyText .= "  Link:    " . url('/objekt.html?id=' . $property->id) . "\n";
        }
        $bodyText .= "\n--- Nachricht ---\n" . $data['message'] . "\n";

        // Direkt in portal_emails schreiben als inbound Mail — die Inbox-UI
        // zeigt die Anfrage dann wie eine echte E-Mail und die Ref-ID macht
        // Property-Assignment moeglich.
        $bodyHtml = '<p>' . nl2br(e($bodyText)) . '</p>';

        try {
            $emailId = DB::table('portal_emails')->insertGetId([
                'message_id'      => 'website-inquiry-' . bin2hex(random_bytes(8)) . '@sr-homes.at',
                'direction'       => 'inbound',
                'from_email'      => $data['email'],
                'from_name'       => $data['name'],
                'to_email'        => $recipientEmail,
                'subject'         => $subject,
                'body_text'       => $bodyText,
                'body_html'       => $bodyHtml,
                'has_attachment'  => 0,
                'email_date'      => now(),
                'property_id'     => $property?->id,
                'matched_ref_id'  => $property?->ref_id,
                'stakeholder'     => 'Interessent',
                'category'        => 'Anfrage Website',
                'is_processed'    => 0,
                'is_read'         => 0,
                'has_reply'       => 0,
                'imap_folder'     => 'website-inquiry',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Activity-Eintrag fuer die Timeline der Immobilie.
            if ($property?->id) {
                DB::table('activities')->insert([
                    'property_id'     => $property->id,
                    'activity_date'   => now(),
                    'stakeholder'     => $data['name'] . ' <' . $data['email'] . '>',
                    'activity'        => 'Anfrage über Website: ' . mb_strimwidth($data['message'], 0, 200, '…'),
                    'category'        => 'Anfrage',
                    'source_email_id' => $emailId,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Website inquiry persist failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Nachricht konnte nicht gespeichert werden. Bitte versuchen Sie es später erneut.'], 500);
        }

        // Best-effort: Mail-Benachrichtigung zusätzlich probieren (schlägt ggf. fehl,
        // stört aber die Haupt-Funktion nicht, weil die Anfrage schon gespeichert ist).
        try {
            \Illuminate\Support\Facades\Mail::raw($bodyText, function ($m) use ($recipientEmail, $recipientName, $subject, $data) {
                $m->to($recipientEmail, $recipientName)
                  ->replyTo($data['email'], $data['name'])
                  ->subject($subject);
            });
        } catch (\Throwable $e) {
            \Log::info('Website inquiry mail notification skipped', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }
}
