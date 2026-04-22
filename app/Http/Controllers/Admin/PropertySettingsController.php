<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PropertyActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertySettingsController extends Controller
{
    /**
     * Fields allowed per property_category.
     * 'common' fields apply to all categories.
     */
    private const FIELD_SCHEMA = [
        'common' => [
            'project_name', 'title', 'ref_id', 'address', 'city', 'zip', 'object_type', 'property_category',
            'object_subtype', 'marketing_type',
            'price', 'rental_price', 'rent_warm', 'rent_deposit', 'price_per_m2',
            'realty_description', 'location_description', 'equipment_description', 'other_description',
            'highlights', 'platforms', 'property_history',
            'owner_name', 'owner_phone', 'owner_email',
            'contact_person', 'contact_phone', 'contact_email',
            'commission_percent', 'commission_note', 'commission_total', 'commission_makler',
            'buyer_commission_percent', 'buyer_commission_text',
            'inserat_since', 'condition_note', 'realty_condition', 'quality', 'furnishing',
        ],
        'house' => [
            'living_area', 'free_area', 'realty_area',
            'area_balcony', 'area_terrace', 'area_garden', 'area_basement',
            'rooms_amount', 'bedrooms', 'bathrooms', 'toilets', 'floor_count',
            'construction_year', 'year_renovated', 'heating',
            'energy_certificate', 'heating_demand_value', 'energy_type', 'heating_demand_class', 'energy_efficiency_value',
            'garage_spaces', 'parking_spaces', 'parking_type', 'parking_price',
            'has_basement', 'has_garden', 'has_balcony', 'has_terrace',
            'has_fitted_kitchen', 'has_fireplace', 'has_pool', 'has_sauna',
            'has_alarm', 'has_barrier_free', 'has_guest_wc', 'has_storage_room',
            'has_cellar', 'has_washing_connection',
            'flooring', 'bathroom_equipment', 'kitchen_type',
            'orientation', 'noise_level', 'available_from',
        ],
        'apartment' => [
            'living_area', 'realty_area',
            'area_balcony', 'area_terrace', 'area_loggia', 'area_basement',
            'rooms_amount', 'bedrooms', 'bathrooms', 'toilets', 'floor_number', 'floor_count',
            'construction_year', 'year_renovated', 'heating',
            'energy_certificate', 'heating_demand_value', 'energy_type', 'heating_demand_class', 'energy_efficiency_value',
            'has_elevator', 'has_balcony', 'has_terrace', 'has_loggia',
            'has_basement', 'has_cellar', 'has_fitted_kitchen', 'has_barrier_free',
            'has_guest_wc', 'has_storage_room', 'has_washing_connection',
            'parking_spaces', 'garage_spaces', 'parking_type', 'parking_price',
            'operating_costs', 'maintenance_reserves',
            'flooring', 'bathroom_equipment', 'kitchen_type',
            'orientation', 'noise_level', 'available_from',
        ],
        'newbuild' => [
            'living_area', 'free_area', 'realty_area', 'rooms_amount',
            'bedrooms', 'bathrooms',
            'builder_company', 'property_manager', 'construction_start', 'construction_end',
            'move_in_date', 'available_from', 'total_units',
            'energy_certificate', 'heating_demand_value', 'energy_type', 'heating_demand_class', 'energy_efficiency_value',
            'has_elevator', 'has_basement', 'has_barrier_free',
            'garage_spaces', 'parking_spaces', 'parking_type', 'parking_price',
            'flooring', 'bathroom_equipment',
            'orientation',
        ],
        'newbuild_single' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_basement', 'has_garden', 'has_balcony', 'has_terrace',
            'builder_company', 'construction_start', 'construction_end', 'move_in_date',
            'orientation', 'noise_level',
        ],
        'bungalow' => [
            'living_area', 'free_area', 'rooms_amount',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_garden', 'has_terrace',
            'condition_note', 'furnishing', 'orientation', 'noise_level',
        ],
        'villa' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_basement', 'has_garden', 'has_balcony', 'has_terrace',
            'condition_note', 'furnishing', 'orientation', 'noise_level',
        ],
        'reihenhaus' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_basement', 'has_garden', 'has_balcony', 'has_terrace',
            'condition_note', 'orientation',
        ],
        'doppelhaus' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_basement', 'has_garden', 'has_balcony', 'has_terrace',
            'condition_note', 'orientation',
        ],
        'penthouse' => [
            'living_area', 'rooms_amount', 'floor_number', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'has_elevator', 'has_terrace', 'has_loggia', 'parking_spaces', 'garage_spaces',
            'operating_costs', 'maintenance_reserves', 'condition_note', 'furnishing', 'orientation',
        ],
        'dachgeschoss' => [
            'living_area', 'rooms_amount', 'floor_number', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'has_elevator', 'has_balcony', 'has_terrace', 'parking_spaces',
            'operating_costs', 'maintenance_reserves', 'condition_note', 'orientation',
        ],
        'garconniere' => [
            'living_area', 'rooms_amount', 'floor_number',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'has_elevator', 'has_balcony', 'parking_spaces',
            'operating_costs', 'maintenance_reserves',
        ],
        'gewerbe' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_elevator', 'has_basement',
            'operating_costs', 'condition_note', 'orientation',
        ],
        'anlage' => [
            'living_area', 'free_area', 'rooms_amount', 'floor_count',
            'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value',
            'garage_spaces', 'parking_spaces', 'has_elevator', 'has_basement',
            'operating_costs', 'maintenance_reserves', 'total_units', 'condition_note',
        ],
        'land' => [
            'free_area', 'realty_area',
            'plot_dedication', 'plot_buildable', 'plot_developed',
            'orientation', 'noise_level', 'available_from',
        ],
    ];

    /**
     * Field labels for the frontend (German).
     */
    public static function getFieldTypes(): array
    {
        return self::FIELD_TYPES;
    }

    public static function getFieldLabels(): array
    {
        return self::FIELD_LABELS;
    }

    private const FIELD_LABELS = [
        // Basis
        'project_name' => 'Projektname / Objektname',
        'title' => 'Titel',
        'ref_id' => 'Referenz-ID',
        'address' => 'Adresse',
        'city' => 'Stadt/Ort',
        'zip' => 'PLZ',
        'object_type' => 'Immobilientyp (Freitext)',
        'property_category' => 'Kategorie (newbuild|house|apartment|land)',
        'object_subtype' => 'Unterkategorie',
        'marketing_type' => 'Transaktionsart (kauf|miete)',
        'purchase_price' => 'Kaufpreis (€, nur Zahl)',
        'rental_price' => 'Kaltmiete (€/Monat)',
        'rent_warm' => 'Warmmiete (€/Monat)',
        'rent_deposit' => 'Kaution (€)',
        'price_per_m2' => 'Preis pro m² (€)',
        // Beschreibungen
        'realty_description' => 'Objektbeschreibung (Freitext)',
        'location_description' => 'Lagebeschreibung',
        'equipment_description' => 'Ausstattungsbeschreibung',
        'other_description' => 'Sonstige Beschreibung',
        'highlights' => 'Highlights',
        'property_history' => 'Objekt-Historie (JSON-Array mit year, title, description — Sanierungen, Umbauten, Errichtungsjahr etc.)',
        // Eigentümer
        'owner_name' => 'Eigentümer Name',
        'owner_phone' => 'Eigentümer Telefon',
        'owner_email' => 'Eigentümer E-Mail',
        // Kontakt / Ansprechpartner
        'contact_person' => 'Ansprechpartner Name',
        'contact_phone' => 'Ansprechpartner Telefon',
        'contact_email' => 'Ansprechpartner E-Mail',
        // Provision
        'commission_percent' => 'Provision (%)',
        'commission_note' => 'Provisions-Info',
        'buyer_commission_percent' => 'Käuferprovision (%)',
        'buyer_commission_text' => 'Käuferprovisions-Text',
        'inserat_since' => 'Inseriert seit',
        // Flächen
        'living_area' => 'Wohnfläche (m², nur Zahl)',
        'free_area' => 'Grundstücksfläche (m², nur Zahl)',
        'realty_area' => 'Nutzfläche (m²)',
        'area_balcony' => 'Balkonfläche (m²)',
        'area_terrace' => 'Terrassenfläche (m²)',
        'area_garden' => 'Gartenfläche (m²)',
        'area_basement' => 'Kellerfläche (m²)',
        'area_loggia' => 'Loggiafläche (m²)',
        'area_garage' => 'Garagenfläche (m²)',
        'office_space' => 'Bürofläche (m²)',
        // Anzahl-Felder zu den Flächen (für den Exposé-Parser / Immoji)
        'balcony_count' => 'Balkone (Anzahl)',
        'terrace_count' => 'Terrassen (Anzahl)',
        'garden_count' => 'Gartenbereiche (Anzahl)',
        'loggia_count' => 'Loggias (Anzahl)',
        'basement_count' => 'Keller (Anzahl)',
        // Zimmer
        'rooms_amount' => 'Zimmer (Anzahl)',
        'bedrooms' => 'Schlafzimmer (Anzahl)',
        'bathrooms' => 'Badezimmer (Anzahl)',
        'toilets' => 'Toiletten (Anzahl)',
        // Stockwerk
        'floor_count' => 'Stockwerke gesamt',
        'floor_number' => 'Stockwerk (Etage)',
        // Alter
        'construction_year' => 'Baujahr',
        'year_renovated' => 'Renoviert (Jahr)',
        // Energie
        'heating' => 'Heizung',
        'energy_certificate' => 'Energieausweis (Typ)',
        'heating_demand_value' => 'HWB (kWh/m²a, nur Zahl)',
        'energy_type' => 'Energieträger (z.B. Gas, Fernwärme)',
        'heating_demand_class' => 'Energieklasse (A-G)',
        'energy_efficiency_value' => 'fGEE (nur Zahl)',
        // Parken
        'garage_spaces' => 'Garagen (Anzahl)',
        'parking_spaces' => 'Stellplätze (Anzahl)',
        'parking_type' => 'Parkplatz-Typ (z.B. Tiefgarage, Carport)',
        'parking_price' => 'Parkplatz-Preis (€)',
        // Ausstattung (boolean: true/false)
        'has_basement' => 'Keller vorhanden',
        'has_garden' => 'Garten vorhanden',
        'has_elevator' => 'Aufzug vorhanden',
        'has_balcony' => 'Balkon vorhanden',
        'has_terrace' => 'Terrasse vorhanden',
        'has_loggia' => 'Loggia vorhanden',
        'has_fitted_kitchen' => 'Einbauküche vorhanden',
        'has_air_conditioning' => 'Klimaanlage vorhanden',
        'has_pool' => 'Pool vorhanden',
        'has_sauna' => 'Sauna vorhanden',
        'has_fireplace' => 'Kamin vorhanden',
        'has_alarm' => 'Alarmanlage vorhanden',
        'has_barrier_free' => 'Barrierefrei',
        'has_guest_wc' => 'Gäste-WC vorhanden',
        'has_storage_room' => 'Abstellraum vorhanden',
        'has_washing_connection' => 'Waschmaschinenanschluss',
        'has_cellar' => 'Kellerabteil vorhanden',
        // Website-only Felder (werden NICHT an Immoji gepusht)
        'common_areas' => 'Allgemeinräume (Freitext, nur Website)',
        'has_photovoltaik' => 'Photovoltaik vorhanden (nur Website)',
        'has_charging_station' => 'E-Ladestation vorhanden (nur Website)',
        // Kosten
        'operating_costs' => 'Betriebskosten (€/Monat, nur Zahl)',
        'maintenance_reserves' => 'Rücklage (€/Monat, nur Zahl)',
        // Grundstück
        'plot_dedication' => 'Widmung',
        'plot_buildable' => 'Bebaubar (true/false)',
        'plot_developed' => 'Aufgeschlossen (true/false)',
        // Neubau
        'builder_company' => 'Bauträger (Firmenname)',
        'property_manager' => 'Hausverwaltung (Firmenname)',
        'construction_start' => 'Baustart (Datum)',
        'construction_end' => 'Fertigstellung (Datum)',
        'move_in_date' => 'Einzug möglich (Datum)',
        'available_from' => 'Verfügbar ab (Datum)',
        'total_units' => 'Einheiten gesamt (Anzahl)',
        // Zustand & Qualität
        'condition_note' => 'Zustand (Freitext)',
        'realty_condition' => 'Zustand (erstbezug|neuwertig|gut|renovierungsbedürftig)',
        'quality' => 'Ausstattungsqualität (einfach|normal|gehoben|luxus)',
        'furnishing' => 'Ausstattung (Freitext)',
        'flooring' => 'Bodenbelag',
        'bathroom_equipment' => 'Badausstattung',
        'kitchen_type' => 'Küche',
        // Lage
        'orientation' => 'Ausrichtung (Nord/Süd/Ost/West)',
        'noise_level' => 'Lärmbelastung',
        // Provision erweitert
        'commission_total' => 'Provision Gesamt (EUR)',
        'commission_makler' => 'Makler-Provision (EUR)',
        'commission_incl_vat' => 'Provision inkl. MwSt.',
        // Energie erweitert
        'energy_primary_source' => 'Primaerenergietraeger',
        'energy_valid_until' => 'Energieausweis gueltig bis',
        // Verfuegbarkeit
        'available_text' => 'Verfuegbarkeit (Text)',
        'platforms' => 'Plattformen',
        // Immoji Sync - Allgemeines
        'construction_type' => 'Bauart',
        'ownership_type' => 'Eigentumsform',
        'subtitle' => 'Untertitel',
        'ad_tag' => 'Werbetag',
        'closing_date' => 'Abschlussdatum',
        'internal_rating' => 'Interne Bewertung',
        'house_number' => 'Hausnummer',
        'staircase' => 'Stiege',
        'door' => 'Tuer',
        'entrance' => 'Eingang',
        'address_floor' => 'Adress-Etage',
        'latitude' => 'Breitengrad',
        'longitude' => 'Laengengrad',
        // Immoji Sync - Kosten
        'heating_costs' => 'Heizkosten',
        'warm_water_costs' => 'Warmwasserkosten',
        'cooling_costs' => 'Kuehlungskosten',
        'admin_costs' => 'Verwaltungskosten',
        'elevator_costs' => 'Aufzugkosten',
        'parking_costs_monthly' => 'Parkplatzkosten monatlich',
        'other_costs' => 'Sonstige Kosten',
        'monthly_costs' => 'Monatliche Kosten gesamt',
        'land_register_fee_pct' => 'Grundbucheintragung %',
        'land_transfer_tax_pct' => 'Grunderwerbssteuer %',
        'contract_fee_pct' => 'Vertragserstellung %',
        'buyer_commission_free' => 'Provisionsfrei fuer Kaeufer',
        // Immoji Sync - Gebaeude
        'building_details' => 'Gebaeudedetails (JSON)',
        // Status/Verwaltung
        'realty_status' => 'Status',
        'is_published' => 'Veroeffentlicht',
        'on_hold' => 'Pausiert',
        'parent_id' => 'Uebergeordnetes Objekt',
        'total_area' => 'Gesamtflaeche',
        'broker_id' => 'Makler-ID',
    ];

    /**
     * Field types for the frontend.
     */
    private const FIELD_TYPES = [
        'purchase_price' => 'number', 'rental_price' => 'number', 'rent_warm' => 'number',
        'rent_deposit' => 'number', 'price_per_m2' => 'number',
        'living_area' => 'number', 'free_area' => 'number',
        'realty_area' => 'number', 'area_balcony' => 'number', 'area_terrace' => 'number',
        'area_garden' => 'number', 'area_basement' => 'number', 'area_loggia' => 'number',
        'area_garage' => 'number', 'office_space' => 'number',
        'rooms_amount' => 'number', 'bedrooms' => 'number', 'bathrooms' => 'number', 'toilets' => 'number',
        'floor_count' => 'number', 'floor_number' => 'number',
        'construction_year' => 'number', 'year_renovated' => 'number',
        'heating_demand_value' => 'number', 'energy_efficiency_value' => 'number',
        'garage_spaces' => 'number', 'parking_spaces' => 'number', 'parking_price' => 'number',
        'operating_costs' => 'number', 'maintenance_reserves' => 'number',
        'commission_percent' => 'number', 'buyer_commission_percent' => 'number',
        'total_units' => 'number',
        'has_basement' => 'boolean', 'has_garden' => 'boolean',
        'has_elevator' => 'boolean', 'has_balcony' => 'boolean',
        'has_terrace' => 'boolean', 'has_loggia' => 'boolean',
        'has_fitted_kitchen' => 'boolean', 'has_air_conditioning' => 'boolean',
        'has_pool' => 'boolean', 'has_sauna' => 'boolean', 'has_fireplace' => 'boolean',
        'has_alarm' => 'boolean', 'has_barrier_free' => 'boolean',
        'has_guest_wc' => 'boolean', 'has_storage_room' => 'boolean',
        'has_washing_connection' => 'boolean', 'has_cellar' => 'boolean',
        'has_photovoltaik' => 'boolean', 'has_charging_station' => 'boolean',
        'plot_buildable' => 'boolean', 'plot_developed' => 'boolean',
        'realty_description' => 'textarea', 'location_description' => 'textarea',
        'equipment_description' => 'textarea', 'other_description' => 'textarea',
        'highlights' => 'textarea', 'common_areas' => 'textarea',
        'inserat_since' => 'date', 'construction_start' => 'date',
        'construction_end' => 'date', 'move_in_date' => 'date', 'available_from' => 'date',
        // Immoji Sync
        'internal_rating' => 'number', 'closing_date' => 'date',
        'heating_costs' => 'number', 'warm_water_costs' => 'number', 'cooling_costs' => 'number',
        'admin_costs' => 'number', 'elevator_costs' => 'number', 'parking_costs_monthly' => 'number',
        'other_costs' => 'number', 'monthly_costs' => 'number',
        'land_register_fee_pct' => 'number', 'land_transfer_tax_pct' => 'number', 'contract_fee_pct' => 'number',
        'buyer_commission_free' => 'boolean', 'is_published' => 'boolean', 'on_hold' => 'boolean',
        'latitude' => 'number', 'longitude' => 'number', 'total_area' => 'number',
        'commission_total' => 'number', 'commission_makler' => 'number',
        'energy_valid_until' => 'date',
    ];

    /**
     * GET: Load property settings + field schema + units (if newbuild)
     */
    public function getSettings(Request $request): JsonResponse
    {
        $propId = intval($request->query('property_id', 0));
        if (!$propId) return response()->json(['error' => 'property_id required'], 400);

        $property = DB::table('properties')->where('id', $propId)->first();
        if (!$property) return response()->json(['error' => 'Property not found'], 404);

        $property = (array) $property;
        $category = $property['property_category'] ?? null;

        // Build field schema for this category
        $fields = self::FIELD_SCHEMA['common'];
        if ($category && isset(self::FIELD_SCHEMA[$category])) {
            $fields = array_merge($fields, self::FIELD_SCHEMA[$category]);
        } else {
            // No category set yet → show all fields
            $allSpecific = [];
            foreach (['house', 'apartment', 'newbuild', 'land'] as $cat) {
                $allSpecific = array_merge($allSpecific, self::FIELD_SCHEMA[$cat]);
            }
            $fields = array_merge($fields, array_unique($allSpecific));
        }

        $schema = [];
        foreach ($fields as $f) {
            $schema[] = [
                'key' => $f,
                'label' => self::FIELD_LABELS[$f] ?? $f,
                'type' => self::FIELD_TYPES[$f] ?? 'text',
            ];
        }

        // Load units and parking separately
        $allItems = [];
        if ($category === 'newbuild' || $property['total_units']) {
            $allItems = array_map(fn($r) => (array) $r,
                DB::select("SELECT * FROM property_units WHERE property_id = ? ORDER BY is_parking, floor, unit_number", [$propId])
            );
            // Build parking lookup
            $parkingLookup = [];
            foreach ($allItems as $item) {
                if ($item['is_parking'] ?? 0) $parkingLookup[$item['id']] = $item;
            }

            foreach ($allItems as &$item) {
                $offers = DB::selectOne("SELECT COUNT(*) as cnt FROM activities WHERE unit_id = ? AND category = 'kaufanbot'", [$item['id']]);
                $item['offer_count'] = $offers->cnt ?? 0;

                // Enrich non-parking units with their assigned parking details
                if (!($item['is_parking'] ?? 0) && !empty($item['assigned_parking'])) {
                    $parkingIds = json_decode($item['assigned_parking'], true) ?: [];
                    $item['parking_details'] = [];
                    $parkingTotal = 0;
                    foreach ($parkingIds as $pid) {
                        if (isset($parkingLookup[$pid])) {
                            $p = $parkingLookup[$pid];
                            $item['parking_details'][] = [
                                'id' => $p['id'],
                                'unit_number' => $p['unit_number'],
                                'unit_type' => $p['unit_type'],
                                'purchase_price' => $p['purchase_price'] ?? 0,
                            ];
                            $parkingTotal += floatval($p['price'] ?? 0);
                        }
                    }
                    $item['parking_total'] = $parkingTotal;
                    $item['total_price'] = floatval($item['price'] ?? 0) + $parkingTotal;
                } else {
                    $item['parking_details'] = [];
                    $item['parking_total'] = 0;
                    $item['total_price'] = floatval($item['purchase_price'] ?? 0);
                }
            }
            unset($item);
        }

        $units = array_values(array_filter($allItems, fn($u) => !($u['is_parking'] ?? 0)));
        $parking = array_values(array_filter($allItems, fn($u) => ($u['is_parking'] ?? 0)));

        // Unit summary (only actual units, not parking)
        $unitSummary = null;
        if (count($units)) {
            $unitSummary = [
                'total' => count($units),
                'frei' => count(array_filter($units, fn($u) => $u['status'] === 'frei')),
                'reserviert' => count(array_filter($units, fn($u) => $u['status'] === 'reserviert')),
                'verkauft' => count(array_filter($units, fn($u) => $u['status'] === 'verkauft')),
            ];
        }

        // Customer (owner) info
        $customer = null;
        if ($property['customer_id']) {
            $customer = DB::table('customers')->where('id', $property['customer_id'])->first();
        }

        // Portal access user info — search by customer_id OR by owner_email
        $portalUser = null;
        if ($property['customer_id']) {
            $portalUser = DB::table('users')
                ->where('customer_id', $property['customer_id'])
                ->whereIn('user_type', ['eigentuemer', ''])
                ->select('id', 'name', 'email', 'created_at')
                ->first();
        }
        if (!$portalUser && !empty($property['owner_email'])) {
            $portalUser = DB::table('users')
                ->where('email', $property['owner_email'])
                ->whereIn('user_type', ['eigentuemer', ''])
                ->select('id', 'name', 'email', 'created_at')
                ->first();
        }

        return response()->json([
            'property' => $property,
            'portal_user' => $portalUser ? (array) $portalUser : null,
            'schema'   => $schema,
            'units'    => $units,
            'parking'  => $parking,
            'unit_summary' => $unitSummary,
            'customer' => $customer ? (array) $customer : null,
            'categories' => [
                ['value' => 'house', 'label' => 'Haus'],
                ['value' => 'apartment', 'label' => 'Wohnung'],
                ['value' => 'newbuild', 'label' => 'Neubauprojekt'],
                ['value' => 'newbuild_single', 'label' => 'Neubau'],
                ['value' => 'bungalow', 'label' => 'Bungalow'],
                ['value' => 'villa', 'label' => 'Villa'],
                ['value' => 'reihenhaus', 'label' => 'Reihenhaus'],
                ['value' => 'doppelhaus', 'label' => 'Doppelhaushälfte'],
                ['value' => 'penthouse', 'label' => 'Penthouse'],
                ['value' => 'dachgeschoss', 'label' => 'Dachgeschoss'],
                ['value' => 'garconniere', 'label' => 'Garconniere'],
                ['value' => 'land', 'label' => 'Grundstück'],
                ['value' => 'gewerbe', 'label' => 'Gewerbe'],
                ['value' => 'anlage', 'label' => 'Anlageobjekt'],
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST: Save property settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $data = $request->json()->all();
        $propId = intval($data['property_id'] ?? 0);
        if (!$propId) return response()->json(['error' => 'property_id required'], 400);

        // Build update array from ALL known fields (FIELD_LABELS keys = all valid DB columns)
        $allFields = array_keys(self::FIELD_LABELS);

        Log::info("saveSettings: received " . count($data) . " fields for property " . $propId . " keys=" . implode(",", array_keys($data)));
        $update = ["updated_at" => now()];
        foreach ($allFields as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                // Handle booleans
                if (isset(self::FIELD_TYPES[$field]) && self::FIELD_TYPES[$field] === 'boolean') {
                    $val = $val ? 1 : (is_null($val) ? null : 0);
                }
                // Handle empty strings → null
                if ($val === '' || $val === 'null') $val = null;
                if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                $update[$field] = $val;
            }
        }

        // Allow ref_id changes (previously blocked)

        // Handle customer_id explicitly (not in FIELD_SCHEMA)
        if (array_key_exists('customer_id', $data)) {
            $cid = intval($data['customer_id']);
            $update['customer_id'] = $cid ?: null;
        }

        // Altzustand laden bevor wir speichern — der PropertyActivityLogger
        // vergleicht danach alt vs. neu und schreibt nur echte Diffs.
        $oldRow = (array) (DB::table('properties')->where('id', $propId)->first() ?: []);

        try {
            DB::table('properties')->where('id', $propId)->update($update);

            // Kundensichtbare Aktivitaet protokollieren ("Objektdaten aktualisiert: …").
            // Fehlschlaege hier duerfen den Save nicht abbrechen — der Logger
            // swallowed Exceptions selbst und loggt per Log::warning.
            app(PropertyActivityLogger::class)->logFieldChanges($propId, $oldRow, $update);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error("savePropertySettings: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST: Create/update property units (for newbuild projects)
     */
    public function saveUnit(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $data = $request->json()->all();
        $propId = intval($data['property_id'] ?? 0);
        $unitId = intval($data['unit_id'] ?? 0);

        if (!$propId) return response()->json(['error' => 'property_id required'], 400);

        $unitData = [
            'property_id'        => $propId,
            'unit_number'        => trim($data['unit_number'] ?? ''),
            'unit_label'         => trim($data['unit_label'] ?? '') ?: null,
            'unit_type'          => trim($data['unit_type'] ?? '') ?: null,
            'floor'              => isset($data['floor']) ? intval($data['floor']) : null,
            'area_m2'            => isset($data['area_m2']) ? floatval($data['area_m2']) : null,
            'rooms'              => isset($data['rooms_amount']) ? floatval($data['rooms_amount']) : null,
            'price'              => isset($data['purchase_price']) ? floatval($data['purchase_price']) : null,
            'status'             => in_array($data['status'] ?? '', ['frei','reserviert','verkauft']) ? $data['status'] : 'frei',
            'balcony_terrace_m2' => isset($data['balcony_terrace_m2']) ? floatval($data['balcony_terrace_m2']) : null,
            'garden_m2'          => isset($data['garden_m2']) ? floatval($data['garden_m2']) : null,
            'parking'            => trim($data['parking'] ?? '') ?: null,
            'notes'              => trim($data['notes'] ?? '') ?: null,
            'portal_exports'     => isset($data['portal_exports']) ? (is_string($data['portal_exports']) ? $data['portal_exports'] : json_encode($data['portal_exports'])) : null,
            // immoji_id is NOT set here — managed exclusively by immoji_push_single_unit endpoint
            'assigned_parking'   => isset($data['assigned_parking']) ? $data['assigned_parking'] : null,
            'parking_spaces'     => isset($data['parking_spaces'])
                ? (is_string($data['parking_spaces']) ? $data['parking_spaces'] : json_encode($data['parking_spaces'], JSON_UNESCAPED_UNICODE))
                : null,
            'images'             => isset($data['images']) ? json_encode($data['images']) : null,
            'buyer_name'         => trim($data['buyer_name'] ?? '') ?: null,
            'buyer_email'        => trim($data['buyer_email'] ?? '') ?: null,
            'commission_total'   => isset($data['commission_total']) ? floatval($data['commission_total']) : null,
            'commission_makler'  => isset($data['commission_makler']) ? floatval($data['commission_makler']) : null,
            'updated_at'         => now(),
        ];

        if (!$unitData['unit_number']) {
            return response()->json(['error' => 'unit_number required'], 400);
        }

        try {
            $isNew = !$unitId;
            if ($unitId) {
                DB::table('property_units')->where('id', $unitId)->update($unitData);
            } else {
                $unitData['created_at'] = now();
                $unitId = DB::table('property_units')->insertGetId($unitData);
            }

            // Update total_units on property
            $this->recalcUnitStats($propId);

            // Kundensichtbare Aktivitaet
            $unitLabel = $unitData['unit_number'] . (($unitData['unit_label'] ?? null) ? " · {$unitData['unit_label']}" : '');
            $text = $isNew ? "Einheit hinzugefügt: {$unitLabel}" : "Einheit aktualisiert: {$unitLabel}";
            app(PropertyActivityLogger::class)->logEvent($propId, $text);

            return response()->json(['success' => true, 'unit_id' => $unitId]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST: Delete a property unit
     */
    public function deleteUnit(Request $request): JsonResponse
    {
        $unitId = intval($request->json('unit_id', 0));
        if (!$unitId) return response()->json(['error' => 'unit_id required'], 400);

        $unit = DB::table('property_units')->where('id', $unitId)->first();
        if (!$unit) return response()->json(['error' => 'Unit not found'], 404);

        DB::table('property_units')->where('id', $unitId)->delete();
        // Clear unit_id from activities
        DB::table('activities')->where('unit_id', $unitId)->update(['unit_id' => null]);

        $this->recalcUnitStats($unit->property_id);

        $unitLabel = $unit->unit_number . ($unit->unit_label ? " · {$unit->unit_label}" : '');
        app(PropertyActivityLogger::class)->logEvent((int) $unit->property_id, "Einheit entfernt: {$unitLabel}");

        return response()->json(['success' => true]);
    }

    /**
     * POST: Bulk import units (from exposé parse or manual)
     */
    public function bulkImportUnits(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $data = $request->json()->all();
        $propId = intval($data['property_id'] ?? 0);
        $units = $data['units'] ?? [];

        if (!$propId || empty($units)) {
            return response()->json(['error' => 'property_id and units required'], 400);
        }

        $created = 0;
        $updated = 0;
        foreach ($units as $u) {
            $number = trim($u['unit_number'] ?? '');
            if (!$number) continue;

            $existing = DB::table('property_units')
                ->where('property_id', $propId)
                ->where('unit_number', $number)
                ->first();

            $unitData = [
                'property_id' => $propId,
                'unit_number' => $number,
                'unit_label'  => trim($u['unit_label'] ?? '') ?: null,
                'unit_type'   => trim($u['unit_type'] ?? '') ?: null,
                'floor'       => intval($u['floor'] ?? 0),
                'area_m2'     => floatval($u['area_m2'] ?? 0) ?: null,
                'rooms'       => floatval($u['rooms_amount'] ?? $u['rooms'] ?? 0) ?: null,
                'price'       => floatval($u['purchase_price'] ?? $u['price'] ?? 0) ?: null,
                'status'      => in_array($u['status'] ?? '', ['frei','reserviert','verkauft']) ? $u['status'] : 'frei',
                'balcony_terrace_m2' => floatval($u['balcony_terrace_m2'] ?? 0) ?: null,
                'garden_m2'   => floatval($u['garden_m2'] ?? 0) ?: null,
                'updated_at'  => now(),
            ];

            if ($existing) {
                DB::table('property_units')->where('id', $existing->id)->update($unitData);
                $updated++;
            } else {
                $unitData['created_at'] = now();
                DB::table('property_units')->insert($unitData);
                $created++;
            }
        }

        $this->recalcUnitStats($propId);

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'total'   => DB::table('property_units')->where('property_id', $propId)->count(),
        ]);
    }

    /**
     * POST: Save full property (used by PropertyEditor wizard)
     * Accepts the full property object and updates/creates it.
     */
    public function saveFullProperty(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $data = $request->json()->all();
        $propId = intval($data['id'] ?? 0);

        // Build update array from ALL known fields
        $allFields = array_keys(self::FIELD_LABELS);
        $update = ['updated_at' => now()];
        foreach ($allFields as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if (isset(self::FIELD_TYPES[$field]) && self::FIELD_TYPES[$field] === 'boolean') {
                    $val = $val ? 1 : (is_null($val) ? null : 0);
                }
                if ($val === '' || $val === 'null') $val = null;
                if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                $update[$field] = $val;
            }
        }

        // Handle customer_id, broker_id explicitly
        if (array_key_exists('customer_id', $data)) {
            $update['customer_id'] = intval($data['customer_id']) ?: null;
        }
        if (array_key_exists('broker_id', $data)) {
            $update['broker_id'] = intval($data['broker_id']) ?: null;
        }
        // Map 'status' form field to 'realty_status' DB column
        if (array_key_exists('status', $data) && !array_key_exists('realty_status', $data)) {
            $update['realty_status'] = $data['status'];
        }

        if (array_key_exists('property_history', $data)) {
            $update['property_history'] = is_array($data['property_history']) ? json_encode($data['property_history'], JSON_UNESCAPED_UNICODE) : $data['property_history'];
        }

        // Projektname wird vom Titel abgeleitet — das Feld existiert nicht mehr im UI.
        // Wenn der Titel gesetzt wird, synchronisieren wir project_name mit.
        if (array_key_exists('title', $update)) {
            $update['project_name'] = $update['title'];
        }

        // Altzustand laden bevor wir speichern, fuer den Activity-Diff.
        $oldRow = $propId ? (array) (DB::table('properties')->where('id', $propId)->first() ?: []) : [];

        try {
            if ($propId) {
                DB::table('properties')->where('id', $propId)->update($update);
            } else {
                $update['created_at'] = now();

                // Auto-generate ref_id if empty
                if (empty($update['ref_id'])) {
                    $type = $update['object_type'] ?? 'Obj';
                    $typeMap = [
                        'Eigentumswohnung' => 'Woh', 'Haus' => 'Hau', 'Einfamilienhaus' => 'EFH',
                        'Grundstueck' => 'Gst', 'Neubauprojekt' => 'Neu', 'Gartenwohnung' => 'Gar',
                        'Dachgeschosswohnung' => 'DG', 'Penthouse' => 'PH', 'Maisonette' => 'Mai',
                        'Reihenhaus' => 'RH', 'Doppelhaushaelfte' => 'DHH', 'Gewerbe' => 'Gew',
                        'Buero' => 'Bue', 'Anlage' => 'Anl', 'Sonstiges' => 'Son', 'Neubau' => 'Neu',
                    ];
                    $prefix = ($update['marketing_type'] ?? 'kauf') === 'kauf' ? 'Kau' : 'Mie';
                    $typePart = $typeMap[$type] ?? 'Obj';
                    $city = $update['city'] ?? '';
                    $cityPart = $city ? mb_substr(preg_replace('/[^A-Za-z]/', '', $city), 0, 3) : 'XX';
                    $num = DB::table('properties')->count() + 1;
                    $update['ref_id'] = $prefix . '-' . $typePart . '-' . ucfirst($cityPart) . '-' . str_pad($num, 2, '0', STR_PAD_LEFT);
                }

                // Auto-assign broker_id to current user
                if (empty($update['broker_id']) && \Auth::check()) {
                    $update['broker_id'] = \Auth::id();
                }

                $propId = DB::table('properties')->insertGetId($update);
            }

            $property = DB::table('properties')->where('id', $propId)->first();

            // Kundensichtbare Aktivitaet protokollieren ("Objektdaten
            // aktualisiert: ..."). Bei neu angelegten Objekten zeichnen wir
            // die Erst-Anlage als Event, sonst den Diff gegen den Altzustand.
            if (!empty($oldRow)) {
                app(PropertyActivityLogger::class)->logFieldChanges($propId, $oldRow, $update);
            } else {
                app(PropertyActivityLogger::class)->logEvent($propId, 'Objekt angelegt');
            }

            // Immoji auto-sync is handled by the frontend (EditTab.vue sends immoji_push after save)
            // No backend auto-sync needed — removed to prevent duplicate pushes

            return response()->json(['success' => true, 'property' => $property]);
        } catch (\Throwable $e) {
            Log::error("saveFullProperty: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

        /**
     * POST: Parse exposé PDF with AI (Vision API for image-based PDFs)
     */
    public function parseExpose(Request $request): JsonResponse
    {
        try {
        $propId = intval($request->input('property_id', $request->query('property_id', 0)));
        Log::info("parseExpose: called for property_id={$propId}");
        if (!$propId) return response()->json(['error' => 'property_id required'], 400);

        $property = DB::table('properties')->where('id', $propId)->first();
        if (!$property) return response()->json(['error' => 'Property not found'], 404);

        $ai = app(\App\Services\AnthropicService::class);
        $fieldsJson = json_encode(self::FIELD_LABELS, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Build list of PDF paths to parse
        $exposePaths = [];
        $fileIds = $request->input('file_ids', []);

        if (!empty($fileIds)) {
            // Frontend sent specific file IDs to parse
            $files = DB::table('property_files')
                ->where('property_id', $propId)
                ->whereIn('id', $fileIds)
                ->get();
            foreach ($files as $file) {
                $fp = '/var/www/srhomes/storage/app/public/' . ($file->path ?: ('property_files/' . $propId . '/' . $file->filename));
                if (file_exists($fp)) $exposePaths[] = $fp;
            }
        } else {
            // Auto-detect: check expose_path first
            if ($property->expose_path) {
                $ep = '/var/www/srhomes/storage/app/public/' . ltrim($property->expose_path, '/');
                if (file_exists($ep)) $exposePaths[] = $ep;
            }
            // Then check property_files for expose-named files
            if (empty($exposePaths)) {
                $file = DB::table('property_files')
                    ->where('property_id', $propId)
                    ->where(function($q) {
                        $q->where('filename', 'like', '%expos%')
                           ->orWhere('label', 'like', '%xpos%');
                    })
                    ->first();
                if (!$file) {
                    $file = DB::table('property_files')
                        ->where('property_id', $propId)
                        ->where('filename', 'like', '%.pdf')
                        ->first();
                }
                if ($file) {
                    $fp = '/var/www/srhomes/storage/app/public/' . ($file->path ?: ('property_files/' . $propId . '/' . $file->filename));
                    if (file_exists($fp)) $exposePaths[] = $fp;
                }
            }
        }

        Log::info("parseExpose: found " . count($exposePaths) . " PDF files to parse");
        $exposePath = $exposePaths[0] ?? null;

        // Prompt MUSS vor der Datei-Schleife definiert sein, weil der Excel-
        // Zweig (.xlsx/.xls) sofort mit $ai->chatJson arbeitet und $prompt
        // einbindet — der Fallback-Pfad setzt den Prompt sonst erst NACH der
        // Schleife und wir bekommen 'Undefined variable $prompt'.
        $prompt = "Analysiere dieses Immobilien-Exposé und extrahiere ALLE Daten.\n\n";
        $prompt .= "ERLAUBTE FELD-KEYS (verwende EXAKT diese keys, KEINE eigenen erfinden!):\n{$fieldsJson}\n\n";
        $prompt .= "STRIKTE REGELN:\n";
        $prompt .= "- Verwende AUSSCHLIEßLICH die oben gelisteten Feld-Keys. Erfinde KEINE neuen Keys!\n";
        $prompt .= "- BESCHREIBUNGEN (description, description_location, description_equipment, description_other, highlights): KRITISCH WICHTIG! Den VOLLSTÄNDIGEN Originaltext aus dem Exposé übernehmen - JEDES WORT, JEDEN ABSATZ, JEDEN SATZ! NIEMALS zusammenfassen, kürzen oder umschreiben! Die Beschreibungstexte können mehrere Absätze lang sein - kopiere ALLES davon wörtlich. Wenn der Text 500 Wörter hat, muss dein Output auch 500 Wörter haben.\n";
        $prompt .= "- Numerische Felder (m², €, Anzahl): NUR Zahlen, KEINE Einheiten/Texte (z.B. 85.5 statt '85,5 m²')\n";
        $prompt .= "- Boolean-Felder (has_*): true oder false\n";
        $prompt .= "- property_category: 'newbuild' (Neubauprojekt), 'house' (Haus), 'apartment' (Wohnung), 'land' (Grundstück)\n";
        $prompt .= "- Felder die nicht im Exposé vorkommen: WEGLASSEN (nicht null setzen)\n";
        $prompt .= "- Beschreibungs-Texte aufteilen: description (Haupttext), description_location (Lage), description_equipment (Ausstattung)\n";
        $prompt .= "- Kontakt-/Ansprechpartner-Info: contact_person, contact_phone, contact_email\n";
        $prompt .= '- property_history: JSON-Array [{"year": "1995", "title": "Dachsanierung", "description": "Details"}] — extrahiere ALLE Jahreszahlen mit Sanierungen, Umbauten, Errichtungsjahr etc. aus dem Expose' . "\n";
        $prompt .= "- Bauträger/Hausverwaltung: builder_company, property_manager (nur Firmennamen)\n";
        $prompt .= "- Einzelflächen: area_balcony, area_terrace, area_garden (nicht in description packen)\n";
        $prompt .= "- ENERGIEWERTE (KRITISCH - NIEMALS leer lassen wenn im Dokument vorhanden!):\n";
        $prompt .= "  energy_certificate: 'ja'/'nein' oder Beschreibung\n";
        $prompt .= "  energy_hwb: HWB-Wert als Zahl (z.B. 45.2 für 45,2 kWh/m²a)\n";
        $prompt .= "  energy_type: 'Endenergie'/'Primärenergie'\n";
        $prompt .= "  energy_class: Energieklasse (z.B. 'B', 'C', 'D', 'fGEE 0.81')\n";
        $prompt .= "  energy_fgee: fGEE-Wert als Zahl (z.B. 0.81)\n";
        $prompt .= "  heating: Heizungsart (z.B. 'Fußbodenheizung', 'Fernwärme', 'Gas-Zentralheizung')\n";
        $prompt .= "- WEITERE PFLICHTFELDER (wenn im Dokument vorhanden):\n";
        $prompt .= "  operating_costs: Betriebskosten als Zahl\n";
        $prompt .= "  reserve_fund: Rücklage/Reparaturrücklage als Zahl\n";
        $prompt .= "  year_built: Baujahr als Zahl (z.B. 2024)\n";
        $prompt .= "  year_renovated: Renovierungsjahr als Zahl\n";
        $prompt .= "  available_from: Verfügbar ab (z.B. 'sofort', '01.06.2026')\n";
        $prompt .= "- Suche SEHR GRÜNDLICH im gesamten Dokument nach diesen Werten. Energieausweise stehen oft auf den letzten Seiten oder in kleiner Schrift!\n\n";
        $prompt .= "Bei Neubauprojekten: Erkenne ALLE einzelnen Wohnungen/Einheiten:\n";
        $prompt .= "- Durchgestrichene Preise/Namen = VERKAUFT (status: 'verkauft')\n";
        $prompt .= "- Jede Einheit: unit_number, unit_type, floor (0=EG), area_m2, rooms, price, status, balcony_terrace_m2, garden_m2\n\n";
        $prompt .= "Antworte NUR mit gültigem JSON:\n";
        $prompt .= "{\n  \"fields\": { \"property_category\": \"newbuild\", \"project_name\": \"...\", \"address\": \"...\", \"rooms\": 3, \"size_m2\": 85.5, \"has_balcony\": true, ... },\n";
        $prompt .= "  \"units\": [ { \"unit_number\": \"TOP 1\", \"unit_type\": \"2-Zimmer\", \"floor\": 0, \"area_m2\": 54.33, \"rooms\": 2, \"price\": 291900, \"status\": \"frei\", \"balcony_terrace_m2\": 12.36, \"garden_m2\": 84.30 }, ... ],\n";
        $prompt .= "  \"confidence\": \"high|medium|low\",\n";
        $prompt .= "  \"warnings\": []\n}";

        $result = null;
        $source = 'none';

        $images = [];
        foreach ($exposePaths as $ep) {
            $ext = strtolower(pathinfo($ep, PATHINFO_EXTENSION));

            if ($ext === 'pdf') {
                // PDF: convert pages to images
                $tmpDir = '/tmp/expose_parse_' . $propId . '_' . time() . '_' . crc32($ep);
                @mkdir($tmpDir, 0755, true);
                $pageCount = intval(shell_exec('pdfinfo ' . escapeshellarg($ep) . ' 2>/dev/null | grep "^Pages:" | awk "{print \$2}"') ?: 25);
                exec('pdftoppm -png -r 120 -l ' . min($pageCount, 30) . ' ' . escapeshellarg($ep) . ' ' . $tmpDir . '/page 2>/dev/null');
                $pageFiles = glob("$tmpDir/page-*.png");
                sort($pageFiles);
                $selected = [];
                $total = count($pageFiles);
                if ($total <= 20) {
                    // Small PDFs: send ALL pages (exposés are typically 5-15 pages)
                    $selected = $pageFiles;
                } else {
                    // Large PDFs: first 3 + last 60%
                    for ($i = 0; $i < min(3, $total); $i++) $selected[] = $pageFiles[$i];
                    $startFrom = max(3, intval($total * 0.4));
                    for ($i = $startFrom; $i < $total; $i++) $selected[] = $pageFiles[$i];
                }
                foreach ($selected as $pf) {
                    $imgData = base64_encode(file_get_contents($pf));
                    $images[] = ['data' => $imgData, 'media_type' => 'image/png'];
                }
                Log::info('parseExpose: ' . basename($ep) . ': ' . $total . ' pages, ' . count($selected) . ' selected');
                array_map('unlink', glob("$tmpDir/*"));
                @rmdir($tmpDir);
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Image files: read directly as vision input
                $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                $imgData = base64_encode(file_get_contents($ep));
                $images[] = ['data' => $imgData, 'media_type' => $mimeMap[$ext] ?? 'image/jpeg'];
                Log::info('parseExpose: image file ' . basename($ep) . ' added directly');
            } elseif (in_array($ext, ['xlsx', 'xls'])) {
                $pyScript = <<<'PY'
import sys, openpyxl
wb = openpyxl.load_workbook(sys.argv[1], data_only=True)
for sheet in wb.sheetnames:
    ws = wb[sheet]
    print(f"=== {sheet} ===")
    for row in ws.iter_rows(values_only=True):
        vals = [str(v) if v is not None else "" for v in row]
        if any(vals):
            print("\t".join(vals))
PY;
                $pyTmp = tempnam('/tmp', 'xlsx_') . '.py';
                file_put_contents($pyTmp, $pyScript);
                $excelText = shell_exec('python3 ' . escapeshellarg($pyTmp) . ' ' . escapeshellarg($ep) . ' 2>/dev/null') ?: '';
                @unlink($pyTmp);
                if (strlen(trim($excelText)) > 20) {
                    $textPrompt = "TABELLENINHALT (Excel-Datei):\n" . mb_substr($excelText, 0, 15000) . "\n\n" . $prompt;
                    $result = $ai->chatJson(
                        "Du bist ein praeziser Immobilien-Datenextraktions-Agent. Analysiere diese Excel-Tabelle sehr gruendlich.",
                        $textPrompt, 16000
                    );
                    $source = 'excel_openpyxl';
                    Log::info('parseExpose: Excel ' . basename($ep) . ' parsed, text=' . strlen($excelText));
                }
            } else {
                Log::info('parseExpose: skipping unsupported file type: ' . basename($ep));
            }
        }
        $images = array_slice($images, 0, 20);
        Log::info('parseExpose: total images for Vision API: ' . count($images));

        try {
            // Try Vision API first (best for styled/image-based PDFs)
            if (count($images) > 0) {
                $result = $ai->chatWithImagesJson(
                    "Du bist ein präziser Immobilien-Datenextraktions-Agent für den österreichischen Markt. Analysiere die Exposé-Seiten als Bilder.",
                    $prompt,
                    $images,
                    16000
                );
                $source = 'vision_pdf';
                Log::info("parseExpose: Vision API used with " . count($images) . " pages");
                Log::info("parseExpose: AI result type=" . gettype($result) . " keys=" . (is_array($result) ? implode(",", array_keys($result)) : "N/A"));
                if (is_array($result) && isset($result["fields"])) { Log::info("parseExpose: fields count=" . count($result["fields"]) . " keys=" . implode(",", array_keys($result["fields"]))); } else { Log::info("parseExpose: NO fields key in result!"); }
            }

            // Fallback: Try text extraction from knowledge base
            if (!$result) {
                $kb = DB::table('property_knowledge')
                    ->where('property_id', $propId)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($kb && !empty($kb->content)) {
                    $textPrompt = "EXPOSÉ-TEXT:\n" . mb_substr($kb->content, 0, 12000) . "\n\n" . $prompt;
                    $result = $ai->chatJson(
                        "Du bist ein präziser Immobilien-Datenextraktions-Agent.",
                        $textPrompt,
                        16000
                    );
                    $source = 'knowledge_base';
                }
            }

            // Fallback: pdftotext
            if (!$result && $exposePath && file_exists($exposePath)) {
                $pdfText = shell_exec("pdftotext " . escapeshellarg($exposePath) . " - 2>/dev/null") ?: '';
                if (strlen(trim($pdfText)) > 100) {
                    $textPrompt = "EXPOSÉ-TEXT:\n" . mb_substr($pdfText, 0, 12000) . "\n\n" . $prompt;
                    $result = $ai->chatJson(
                        "Du bist ein präziser Immobilien-Datenextraktions-Agent.",
                        $textPrompt,
                        16000
                    );
                    $source = 'pdftotext';
                }
            }

            if (!$result) {
                Log::warning("parseExpose: NO result for property {$propId}. exposePath={$exposePath}, images=" . count($images));
                return response()->json([
                    'error' => 'Kein Exposé gefunden oder KI konnte nichts extrahieren. Bitte ein PDF unter Dateien hochladen.',
                ], 400);
            }

            DB::table('properties')->where('id', $propId)->update(['last_expose_parsed_at' => now()]);

            // Auto-save extracted fields directly to properties table
            $savedCount = 0;
            if (is_array($result) && !empty($result['fields'])) {
                $allValidFields = array_keys(self::FIELD_LABELS);
                $update = ['updated_at' => now()];
                foreach ($result['fields'] as $key => $val) {
                    if (!in_array($key, $allValidFields)) continue;
                    // Handle booleans
                    if (isset(self::FIELD_TYPES[$key]) && self::FIELD_TYPES[$key] === 'boolean') {
                        $val = $val ? 1 : (is_null($val) ? null : 0);
                    }
                    // Handle empty strings
                    if ($val === '' || $val === 'null') $val = null;
                    $update[$key] = $val;
                }
                $savedCount = count($update) - 1; // minus updated_at
                if ($savedCount > 0) {
                    DB::table('properties')->where('id', $propId)->update($update);
                    Log::info("parseExpose: auto-saved {$savedCount} fields to property {$propId}");
                }
            }

            // Auto-import units if present
            $unitsCreated = 0;
            $unitsUpdated = 0;
            if (is_array($result) && !empty($result['units'])) {
                foreach ($result['units'] as $unit) {
                    $unitNumber = $unit['unit_number'] ?? null;
                    if (!$unitNumber) continue;
                    $unitData = [
                        'property_id' => $propId,
                        'unit_number' => $unitNumber,
                        'unit_type' => $unit['unit_type'] ?? null,
                        'floor' => intval($unit['floor'] ?? 0),
                        'area_m2' => floatval($unit['area_m2'] ?? 0),
                        'rooms' => floatval($unit['rooms_amount'] ?? 0),
                        'price' => floatval($unit['price'] ?? 0),
                        'status' => $unit['status'] ?? 'frei',
                        'balcony_terrace_m2' => floatval($unit['balcony_terrace_m2'] ?? 0) ?: null,
                        'garden_m2' => floatval($unit['garden_m2'] ?? 0) ?: null,
                        'updated_at' => now(),
                    ];
                    $existing = DB::table('property_units')
                        ->where('property_id', $propId)
                        ->where('unit_number', $unitNumber)
                        ->first();
                    if ($existing) {
                        DB::table('property_units')->where('id', $existing->id)->update($unitData);
                        $unitsUpdated++;
                    } else {
                        $unitData['created_at'] = now();
                        DB::table('property_units')->insert($unitData);
                        $unitsCreated++;
                    }
                }
                if ($unitsCreated || $unitsUpdated) {
                    Log::info("parseExpose: units created={$unitsCreated} updated={$unitsUpdated} for property {$propId}");
                }
            }

            return response()->json([
                'success' => true,
                'extracted' => $result,
                'source' => $source,
                'pages_analyzed' => count($images),
                'fields_saved' => $savedCount,
                'units_created' => $unitsCreated,
                'units_updated' => $unitsUpdated,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            Log::error("parseExpose error: " . $e->getMessage());
            return response()->json(['error' => 'Fehler: ' . $e->getMessage()], 500);
        }
        } catch (\Throwable $outerEx) {
            Log::error("parseExpose outer error: " . $outerEx->getMessage() . " at " . $outerEx->getFile() . ":" . $outerEx->getLine());
            return response()->json(['error' => 'Unerwarteter Fehler: ' . $outerEx->getMessage()], 500);
        }
    }

    /**
     * POST: Bulk create parking spots
     */
    public function bulkCreateParking(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);
        $data = $request->json()->all();
        $propId = intval($data['property_id'] ?? 0);
        $prefix = trim($data['prefix'] ?? 'Stellplatz');
        $type = trim($data['type'] ?? 'Stellplatz');
        $from = intval($data['from'] ?? 1);
        $to = intval($data['to'] ?? 10);
        $price = floatval($data['purchase_price'] ?? 0) ?: null;

        if (!$propId || $to < $from) return response()->json(['error' => 'Invalid params'], 400);

        $created = 0;
        for ($i = $from; $i <= $to; $i++) {
            $number = $prefix . ' ' . $i;
            $exists = DB::table('property_units')->where('property_id', $propId)->where('unit_number', $number)->exists();
            if (!$exists) {
                DB::table('property_units')->insert([
                    'property_id' => $propId,
                    'unit_number' => $number,
                    'unit_type' => $type,
                    'is_parking' => 1,
                    'price' => $price,
                    'status' => 'frei',
                    'floor' => -1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }
        return response()->json(['success' => true, 'created' => $created]);
    }

    /**
     * POST: Upload Kaufanbot PDF for a unit
     */
    public function uploadKaufanbotPdf(Request $request): JsonResponse
    {
        $unitId = intval($request->input('unit_id', 0));
        if (!$unitId) return response()->json(['error' => 'unit_id required'], 400);

        $unit = DB::table('property_units')->where('id', $unitId)->first();
        if (!$unit) return response()->json(['error' => 'Unit not found'], 404);

        if (!$request->hasFile('file')) return response()->json(['error' => 'No file uploaded'], 400);

        $file = $request->file('file');
        $dir = 'kaufanbote/' . $unit->property_id;
        $filename = 'KA_' . str_replace(' ', '_', $unit->unit_number) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $filename, 'public');

        DB::table('property_units')->where('id', $unitId)->update([
            'kaufanbot_pdf' => $path,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'path' => $path]);
    }

    /**
     * POST: Link a Kaufanbot activity to a specific unit
     */
    public function linkOfferToUnit(Request $request): JsonResponse
    {
        $activityId = intval($request->json('activity_id', 0));
        $unitId = intval($request->json('unit_id', 0));

        if (!$activityId) return response()->json(['error' => 'activity_id required'], 400);

        $activity = DB::table('activities')->where('id', $activityId)->first();
        if (!$activity) return response()->json(['error' => 'Activity not found'], 404);
        if ($activity->category !== 'kaufanbot') {
            return response()->json(['error' => 'Activity is not a Kaufanbot'], 400);
        }

        // unitId = 0 means unlink
        $update = ['unit_id' => $unitId ?: null, 'updated_at' => now()];

        if ($unitId) {
            $unit = DB::table('property_units')->where('id', $unitId)->first();
            if (!$unit) return response()->json(['error' => 'Unit not found'], 404);
            if ($unit->property_id != $activity->property_id) {
                return response()->json(['error' => 'Unit does not belong to this property'], 400);
            }
        }

        DB::table('activities')->where('id', $activityId)->update($update);

        return response()->json(['success' => true]);
    }

    /**
     * Recalculate unit stats on the properties table
     */

    /**
     * POST: Split a unit into two entries (for shared ownership)
     */
    public function splitUnit(Request $request): JsonResponse
    {
        if (!$request->isMethod("post")) return response()->json(["error" => "POST required"], 405);

        $unitId = intval($request->json("unit_id", 0));
        if (!$unitId) return response()->json(["error" => "unit_id required"], 400);

        $unit = DB::table("property_units")->where("id", $unitId)->first();
        if (!$unit) return response()->json(["error" => "Unit not found"], 404);

        // Create a copy with empty buyer info
        $newId = DB::table("property_units")->insertGetId([
            "property_id"        => $unit->property_id,
            "unit_number"        => $unit->unit_number,
            "unit_label"         => $unit->unit_label,
            "unit_type"          => $unit->unit_type,
            "floor"              => $unit->floor,
            "area_m2"            => $unit->area_m2,
            "rooms"              => $unit->rooms,
            "price"              => $unit->price,
            "status"             => "frei",
            "is_parking"         => $unit->is_parking ?? 0,
            "buyer_name"         => null,
            "buyer_email"        => null,
            "buyer_share"        => "Anteil",
            "created_at"         => now(),
            "updated_at"         => now(),
        ]);

        // Mark original as shared too if not already
        if (!$unit->buyer_share) {
            DB::table("property_units")->where("id", $unitId)->update([
                "buyer_share" => "Anteil",
                "updated_at" => now(),
            ]);
        }

        return response()->json(["success" => true, "new_unit_id" => $newId]);
    }

    /**
     * POST: Create a single parking spot
     */
    public function createSingleParking(Request $request): JsonResponse
    {
        if (!$request->isMethod("post")) return response()->json(["error" => "POST required"], 405);

        $data = $request->json()->all();
        $propId = intval($data["property_id"] ?? 0);
        $number = trim($data["unit_number"] ?? "");
        $type = trim($data["unit_type"] ?? "Stellplatz");
        $price = isset($data["price"]) ? floatval($data["price"]) : null;

        if (!$propId || !$number) return response()->json(["error" => "property_id and unit_number required"], 400);

        $exists = DB::table("property_units")->where("property_id", $propId)->where("unit_number", $number)->exists();
        if ($exists) return response()->json(["error" => "Stellplatz existiert bereits"], 400);

        $id = DB::table("property_units")->insertGetId([
            "property_id" => $propId,
            "unit_number"  => $number,
            "unit_type"    => $type,
            "is_parking"   => 1,
            "price"        => $price,
            "status"       => "frei",
            "floor"        => -1,
            "created_at"   => now(),
            "updated_at"   => now(),
        ]);

        $this->recalcUnitStats($propId);

        return response()->json(["success" => true, "parking_id" => $id]);
    }

    /**
     * POST: Upload image for a unit
     */
    public function uploadUnitImage(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $unitId = intval($request->input('unit_id'));
        if (!$unitId) return response()->json(['error' => 'unit_id required'], 400);

        $file = $request->file('file');
        if (!$file) return response()->json(['error' => 'No file uploaded'], 400);

        $unit = DB::table('property_units')->where('id', $unitId)->first();
        if (!$unit) return response()->json(['error' => 'Unit not found'], 404);

        try {
            $filename = 'unit_' . $unitId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file->getClientOriginalName());
            $path = $file->storeAs('units', $filename, 'public');
            $url = url(\Storage::disk('public')->url($path));

            // Append to images JSON array
            $images = $unit->images ? json_decode($unit->images, true) : [];
            if (!is_array($images)) $images = [];
            $images[] = $url;

            DB::table('property_units')->where('id', $unitId)->update([
                'images' => json_encode($images),
                'updated_at' => now(),
            ]);

            return response()->json(['success' => true, 'url' => $url, 'images' => $images]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST: Delete image from a unit
     */
    public function deleteUnitImage(Request $request): JsonResponse
    {
        if (!$request->isMethod('post')) return response()->json(['error' => 'POST required'], 405);

        $data = $request->json()->all();
        $unitId = intval($data['unit_id'] ?? 0);
        $imageUrl = $data['image_url'] ?? '';

        if (!$unitId || !$imageUrl) return response()->json(['error' => 'unit_id and image_url required'], 400);

        $unit = DB::table('property_units')->where('id', $unitId)->first();
        if (!$unit) return response()->json(['error' => 'Unit not found'], 404);

        $images = $unit->images ? json_decode($unit->images, true) : [];
        $images = array_values(array_filter($images, fn($img) => $img !== $imageUrl));

        DB::table('property_units')->where('id', $unitId)->update([
            'images' => count($images) ? json_encode($images) : null,
            'updated_at' => now(),
        ]);

        // Try to delete the actual file
        $storagePath = str_replace(url('/storage/'), '', $imageUrl);
        if ($storagePath && \Storage::disk('public')->exists($storagePath)) {
            \Storage::disk('public')->delete($storagePath);
        }

        return response()->json(['success' => true, 'images' => $images]);
    }

    /**
     * Public wrapper for recalcUnitStats — callable from DocumentParserService.
     */
    public function recalcUnitStatsPublic(int $propId): void
    {
        $this->recalcUnitStats($propId);
    }

    private function recalcUnitStats(int $propId): void
    {
        // total_units = residential apartments only (is_parking = 0).
        // Parking spaces (Stellplatz, Tiefgarage, Carportplatz) are NOT counted as Wohneinheiten.
        $stats = DB::selectOne("
            SELECT
                SUM(CASE WHEN is_parking = 0 THEN 1 ELSE 0 END) as total,
                SUM(CASE WHEN is_parking = 0 AND status = 'frei' THEN 1 ELSE 0 END) as frei,
                SUM(CASE WHEN is_parking = 0 AND status = 'reserviert' THEN 1 ELSE 0 END) as reserviert,
                SUM(CASE WHEN is_parking = 0 AND status = 'verkauft' THEN 1 ELSE 0 END) as verkauft,
                SUM(CASE WHEN is_parking = 0 THEN COALESCE(area_m2, 0) ELSE 0 END) as sum_area
            FROM property_units WHERE property_id = ?
        ", [$propId]);

        $update = [
            'total_units' => $stats->total ?? 0,
            'updated_at'  => now(),
        ];

        // For newbuild projects: auto-calculate living_area from unit areas
        $property = DB::table('properties')->where('id', $propId)->first();
        if ($property && $property->property_category === 'newbuild') {
            $sumArea = floatval($stats->sum_area ?? 0);
            if ($sumArea > 0) {
                $update['living_area'] = round($sumArea, 2);
            }
        }

        DB::table('properties')->where('id', $propId)->update($update);
    }

    /**
     * GET: Sales volume data across all properties with time filtering
     */
    public function getSalesVolume(Request $request): JsonResponse
    {
        $period = $request->query('period', 'year');
        $dateFrom = null;
        if ($period === 'week') $dateFrom = now()->subWeek();
        elseif ($period === 'month') $dateFrom = now()->subMonth();
        elseif ($period === 'year') $dateFrom = now()->subYear();

        // Always scoped to logged-in broker
        $brokerId = \Auth::id();
        $user = \Auth::user();
        $isAdmin = $user && $user->user_type === 'admin';
        $properties = DB::table('properties')->where('broker_id', $brokerId ?: 0)->get();

        $result = [];
        $totalVolume = 0;
        $totalSold = 0;
        $totalUnits = 0;
        $totalArea = 0;
        $soldArea = 0;

        foreach ($properties as $prop) {
            $allItems = DB::table('property_units')
                ->where('property_id', $prop->id)->get()
                ->map(fn($r) => (array) $r)->toArray();

            $parkingLookup = [];
            foreach ($allItems as $item) {
                if ($item['is_parking'] ?? 0) $parkingLookup[$item['id']] = $item;
            }

            $units = array_filter($allItems, fn($u) => !($u['is_parking'] ?? 0));
            $propVolume = 0; $propSold = 0; $propTotal = count($units);
            $propTotalArea = 0; $propSoldArea = 0; $soldEntries = [];

            foreach ($units as $u) {
                $propTotalArea += floatval($u['area_m2'] ?? 0);
                if ($u['status'] === 'verkauft') {
                    $propSoldArea += floatval($u['area_m2'] ?? 0);
                    if ($dateFrom && $u['updated_at'] && $u['updated_at'] < $dateFrom->toDateTimeString()) continue;
                    $unitPrice = floatval($u['price'] ?? 0);
                    $parkingTotal = 0;
                    if (!empty($u['assigned_parking'])) {
                        $pids = json_decode($u['assigned_parking'], true) ?: [];
                        foreach ($pids as $pid) {
                            if (isset($parkingLookup[$pid])) $parkingTotal += floatval($parkingLookup[$pid]['price'] ?? 0);
                        }
                    }
                    $total = $unitPrice + $parkingTotal;
                    $propVolume += $total;
                    $propSold++;
                    $soldEntries[] = [
                        'unit_number' => $u['unit_number'],
                        'buyer_name' => $u['buyer_name'] ?? null,
                        'total_price' => $total,
                        'area_m2' => $u['area_m2'] ?? null,
                        'updated_at' => $u['updated_at'],
                    ];
                }
            }

            $allSold = count(array_filter($units, fn($u) => $u['status'] === 'verkauft'));

            if ($propTotal > 0) {
                $result[] = [
                    'property_id' => $prop->id,
                    'broker_id' => $prop->broker_id ?? null,
                    'address' => $prop->address ?? $prop->ref_id,
                    'city' => $prop->city ?? '',
                    'ref_id' => $prop->ref_id ?? '',
                    'volume' => $propVolume,
                    'sold' => $propSold,
                    'total' => $propTotal,
                    'all_sold' => $allSold,
                    'total_area' => $propTotalArea,
                    'sold_area' => $propSoldArea,
                    'sold_entries' => $soldEntries,
                ];
                $totalVolume += $propVolume;
                $totalSold += $propSold;
                $totalUnits += $propTotal;
                $totalArea += $propTotalArea;
                $soldArea += $propSoldArea;
            }
        }

        return response()->json([
            'total_volume' => $totalVolume,
            'total_sold' => $totalSold,
            'total_units' => $totalUnits,
            'total_area' => $totalArea,
            'sold_area' => $soldArea,
            'period' => $period,
            'properties' => $result,
            'is_admin' => $isAdmin,
            'per_broker' => $this->aggregateSalesByBroker($result),
        ]);
    }

    private function aggregateSalesByBroker(array $properties): array
    {
        $brokers = [];
        foreach ($properties as $p) {
            $bid = $p['broker_id'] ?? 0;
            if (!isset($brokers[$bid])) {
                $brokerUser = $bid ? DB::table('users')->where('id', $bid)->first() : null;
                $brokers[$bid] = ['broker_id' => $bid, 'name' => $brokerUser->name ?? 'Unbekannt', 'volume' => 0, 'sold' => 0];
            }
            $brokers[$bid]['volume'] += $p['volume'] ?? 0;
            $brokers[$bid]['sold'] += $p['sold'] ?? 0;
        }
        return array_values($brokers);
    }


    public function getCommissionSummary(Request $request): JsonResponse
    {
        $brokerId = \Auth::id();
        $user = \Auth::user();
        $isAdmin = $user && $user->user_type === 'admin';

        // Always scoped to logged-in broker
        $properties = DB::table('properties')->where('broker_id', $brokerId ?: 0)->get();

        $totalMakler = 0;
        $totalGesamt = 0;
        $propertiesWithCommission = 0;
        $details = [];
        $perBroker = []; // broker_id => {name, total_makler, total_volume}

        foreach ($properties as $prop) {
            $commTotal = floatval($prop->commission_total ?? 0);
            $commMakler = floatval($prop->commission_makler ?? 0);
            if (!$commTotal && !$commMakler) continue;

            // Calculate sold volume for this property
            $allItems = DB::table('property_units')->where('property_id', $prop->id)->get();
            $parkingLookup = [];
            foreach ($allItems as $item) {
                if ($item->is_parking) $parkingLookup[$item->id] = $item;
            }

            $volume = 0;
            $soldCount = 0;
            foreach ($allItems as $u) {
                if ($u->is_parking || $u->status !== 'verkauft') continue;
                $unitPrice = floatval($u->price ?? 0);
                $parkingTotal = 0;
                if (!empty($u->assigned_parking)) {
                    $pids = json_decode($u->assigned_parking, true) ?: [];
                    foreach ($pids as $pid) {
                        if (isset($parkingLookup[$pid])) $parkingTotal += floatval($parkingLookup[$pid]->price ?? 0);
                    }
                }
                $volume += $unitPrice + $parkingTotal;
                $soldCount++;
            }

            $gesamtAmount = $volume * $commTotal / 100;
            $maklerAmount = $volume * $commMakler / 100;
            $totalGesamt += $gesamtAmount;
            $totalMakler += $maklerAmount;
            if ($volume > 0) $propertiesWithCommission++;

            $details[] = [
                'property_id' => $prop->id,
                'address' => $prop->address,
                'ref_id' => $prop->ref_id ?? null,
                'broker_id' => $prop->broker_id ?? null,
                'volume' => $volume,
                'sold_count' => $soldCount,
                'commission_total' => $commTotal,
                'commission_makler' => $commMakler,
                'gesamt_amount' => round($gesamtAmount, 2),
                'makler_amount' => round($maklerAmount, 2),
            ];

            // Per-broker aggregation
            $bid = $prop->broker_id ?? 0;
            if (!isset($perBroker[$bid])) {
                $brokerUser = $bid ? DB::table('users')->where('id', $bid)->first() : null;
                $perBroker[$bid] = ['broker_id' => $bid, 'name' => $brokerUser->name ?? 'Unbekannt', 'total_makler' => 0, 'total_volume' => 0, 'sold_count' => 0];
            }
            $perBroker[$bid]['total_makler'] += $maklerAmount;
            $perBroker[$bid]['total_volume'] += $volume;
            $perBroker[$bid]['sold_count'] += $soldCount;
        }

        // Round per-broker values
        foreach ($perBroker as &$b) {
            $b['total_makler'] = round($b['total_makler'], 2);
            $b['total_volume'] = round($b['total_volume'], 2);
        }

        return response()->json([
            'total_makler' => round($totalMakler, 2),
            'total_gesamt' => round($totalGesamt, 2),
            'properties_with_commission' => $propertiesWithCommission,
            'total_sold' => DB::table('property_units')->where('is_parking', 0)->where('status', 'verkauft')->count(),
            'details' => $details,
            'per_broker' => array_values($perBroker),
            'is_admin' => $isAdmin,
        ]);
    }


    public function getKaufanbotPdfs(Request $request): JsonResponse
    {
        $properties = DB::table('properties')->get()->keyBy('id');
        $units = DB::table('property_units')
            ->whereNotNull('kaufanbot_pdf')
            ->where('kaufanbot_pdf', '!=', '')
            ->get();

        $parkingAll = DB::table('property_units')->where('is_parking', 1)->get()->keyBy('id');

        $result = [];
        foreach ($units as $u) {
            $unitPrice = floatval($u->price ?? 0);
            $parkingTotal = 0;
            if (!empty($u->assigned_parking)) {
                $pids = json_decode($u->assigned_parking, true) ?: [];
                foreach ($pids as $pid) {
                    if (isset($parkingAll[$pid])) $parkingTotal += floatval($parkingAll[$pid]->price ?? 0);
                }
            }
            $prop = $properties[$u->property_id] ?? null;
            $result[] = [
                'unit_id' => $u->id,
                'unit_number' => $u->unit_number,
                'buyer_name' => $u->buyer_name,
                'status' => $u->status,
                'total_price' => $unitPrice + $parkingTotal,
                'area_m2' => $u->area_m2,
                'kaufanbot_pdf' => $u->kaufanbot_pdf,
                'property_id' => $u->property_id,
                'property_address' => $prop ? $prop->address : '?',
            ];
        }

        return response()->json(['kaufanbote' => $result]);
    }


    /**
     * Create portal access for a property owner
     */
    public function createPortalAccess(Request $request): JsonResponse
    {
        $propertyId = $request->input('property_id');
        $name = trim($request->input('name', ''));
        $email = trim($request->input('email', ''));
        $password = $request->input('password', '');

        if (!$propertyId || !$name || !$email || !$password) {
            return response()->json(['error' => 'property_id, name, email und password sind Pflichtfelder.'], 400);
        }

        // Check property exists
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property) {
            return response()->json(['error' => 'Immobilie nicht gefunden.'], 404);
        }

        // Check if user with this email already exists — if so, link to this property
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            // User exists — just make sure property is linked to their customer
            if ($existing->user_type === 'eigentuemer') {
                // Link property to this customer if not already
                if (!$property->customer_id) {
                    $custId = DB::table('customers')->where('email', $email)->value('id');
                    if ($custId) {
                        DB::table('properties')->where('id', $propertyId)->update(['customer_id' => $custId, 'updated_at' => now()]);
                    }
                }
                return response()->json([
                    'success' => true,
                    'message' => 'Portalzugang existiert bereits — Eigentümer wurde verknüpft.',
                    'user' => ['id' => $existing->id, 'name' => $existing->name, 'email' => $existing->email],
                ]);
            }
            return response()->json(['error' => 'Ein Benutzer mit dieser E-Mail existiert bereits (Typ: ' . $existing->user_type . ').'], 409);
        }

        // Get or create customer
        $customerId = $property->customer_id;
        if (!$customerId) {
            $customerId = DB::table('customers')->insertGetId([
                'name' => $name,
                'email' => $email,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('properties')->where('id', $propertyId)->update([
                'customer_id' => $customerId,
                'updated_at' => now(),
            ]);
        }

        // Create user
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt($password),
            'user_type' => 'eigentuemer',
            'customer_id' => $customerId,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Portalzugang für {$name} ({$email}) wurde erstellt.",
            'user_id' => $userId,
            'login_url' => url('/login'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Check portal access status for a property
     */
    public function checkPortalAccess(Request $request): JsonResponse
    {
        $propertyId = $request->input('property_id');
        $property = DB::table('properties')->where('id', $propertyId)->first();
        
        if (!$property) {
            return response()->json(['error' => 'Immobilie nicht gefunden.'], 404);
        }

        $portalUser = null;
        // Check by customer_id first
        if ($property->customer_id) {
            $portalUser = DB::table('users')
                ->where('customer_id', $property->customer_id)
                ->where('user_type', 'eigentuemer')
                ->select('id', 'name', 'email', 'created_at')
                ->first();
        }
        // Also check by email parameter (for newly selected customers)
        if (!$portalUser) {
            $email = $request->input('email');
            if ($email) {
                $portalUser = DB::table('users')
                    ->where('email', $email)
                    ->where('user_type', 'eigentuemer')
                    ->select('id', 'name', 'email', 'created_at')
                    ->first();
            }
        }
        // Also check by owner_email on property
        if (!$portalUser && $property->owner_email) {
            $portalUser = DB::table('users')
                ->where('email', $property->owner_email)
                ->where('user_type', 'eigentuemer')
                ->select('id', 'name', 'email', 'created_at')
                ->first();
        }

        return response()->json([
            'has_access' => $portalUser !== null,
            'portal_user' => $portalUser ? (array) $portalUser : null,
            'property' => $property->ref_id . ' - ' . $property->address,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function removeKaufanbotPdf(Request $request): JsonResponse
    {
        $unitId = intval($request->json("unit_id", 0));
        if (!$unitId) return response()->json(["error" => "unit_id required"], 400);

        $unit = DB::table("property_units")->where("id", $unitId)->first();
        if (!$unit) return response()->json(["error" => "Unit not found"], 404);

        if ($unit->kaufanbot_pdf) {
            $filePath = storage_path("app/public/" . $unit->kaufanbot_pdf);
            if (file_exists($filePath)) @unlink($filePath);
        }

        DB::table("property_units")->where("id", $unitId)->update([
            "kaufanbot_pdf" => null,
            "updated_at" => now(),
        ]);

        return response()->json(["success" => true]);
    }
}
