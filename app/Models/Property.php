<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;
    protected $fillable = [
        // Core
        'customer_id', 'broker_id', 'ref_id', 'openimmo_id', 'openimmo_anbieter_id',
        'project_name', 'title', 'subtitle', 'ad_tag', 'closing_date', 'internal_rating',
        'address', 'house_number', 'staircase', 'door', 'entrance', 'address_floor',
        'latitude', 'longitude', 'geo_precision',
        'city', 'zip', 'object_type', 'property_category', 'object_subtype',
        'construction_type', 'ownership_type', 'marketing_type',

        // Pricing
        'price', 'rental_price', 'rent_warm', 'rent_deposit', 'price_per_m2',
        'operating_costs', 'maintenance_reserves',
        'heating_costs', 'warm_water_costs', 'cooling_costs', 'admin_costs',
        'elevator_costs', 'parking_costs_monthly', 'other_costs', 'monthly_costs',
        'land_register_fee_pct', 'land_transfer_tax_pct', 'contract_fee_pct',
        'mortgage_register_fee_pct', 'nebenkosten_note', 'show_nebenkosten_on_website',
        'buyer_commission_free',

        // Areas
        'total_area', 'living_area', 'free_area', 'realty_area', 'area_balcony',
        'area_terrace', 'area_garden', 'area_basement', 'area_loggia',
        'area_garage', 'office_space',

        // Rooms
        'rooms_amount', 'bedrooms', 'bathrooms', 'toilets',
        'floor_count', 'floor_number',

        // Energy
        'energy_certificate', 'heating_demand_value', 'energy_type', 'heating_demand_class',
        'energy_efficiency_value', 'energy_primary_source', 'energy_valid_until',

        // Condition & Equipment
        'construction_year', 'year_renovated', 'heating', 'condition_note',
        'realty_condition', 'quality', 'flooring', 'bathroom_equipment',
        'kitchen_type', 'furnishing', 'orientation', 'noise_level',

        // Boolean features
        'has_basement', 'has_garden', 'has_elevator', 'has_balcony',
        'has_terrace', 'has_loggia', 'has_fitted_kitchen', 'has_air_conditioning',
        'has_pool', 'has_sauna', 'has_fireplace', 'has_alarm',
        'has_barrier_free', 'has_guest_wc', 'has_storage_room',
        'has_washing_connection', 'has_cellar',
        'has_photovoltaik', 'has_charging_station', 'charging_station_status',
        'has_wohnraumlueftung', 'has_dachterrasse', 'area_dachterrasse', 'dachterrasse_count',
        'common_areas',

        // Parking
        'garage_spaces', 'parking_spaces', 'parking_type', 'parking_price',

        // Descriptions
        'realty_description', 'location_description', 'equipment_description',
        'other_description', 'highlights',

        // Commission
        'commission_percent', 'commission_note', 'commission_total',
        'commission_makler', 'buyer_commission_percent',
        'buyer_commission_text', 'commission_incl_vat',

        // Owner & Contact
        'owner_name', 'owner_phone', 'owner_email',
        'contact_person', 'contact_phone', 'contact_email',

        // Construction / Neubau
        'builder_company', 'property_manager', 'property_manager_id', 'construction_start',
        'construction_end', 'move_in_date', 'available_from', 'available_text',
        'total_units',

        // Plot
        'plot_dedication', 'plot_buildable', 'plot_developed',

        // Status & Publishing
        'realty_status', 'platforms', 'inserat_since',
        'on_hold', 'on_hold_note', 'on_hold_since',
        'is_published', 'published_at',
        'is_featured', 'featured_order', 'badge',

        // Files
        'expose_path', 'nebenkosten_path', 'last_expose_parsed_at',

        // Building details (Gebaeude)
        'building_details',

        // Parent-Child Hierarchy
        'parent_id',

        // Aufnahmeprotokoll
        'encumbrances', 'parking_assignment', 'documents_available',
        'approvals_status', 'approvals_notes', 'internal_notes',

        // Pricing + Sanierungen (benutzt von Aufnahmeprotokoll)
        'purchase_price', 'property_history',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'rental_price' => 'decimal:2',
            'rent_warm' => 'decimal:2',
            'rent_deposit' => 'decimal:2',
            'price_per_m2' => 'decimal:2',
            'operating_costs' => 'decimal:2',
            'maintenance_reserves' => 'decimal:2',
            'parking_price' => 'decimal:2',
            'total_area' => 'decimal:2',
            'living_area' => 'decimal:2',
            'free_area' => 'decimal:2',
            'realty_area' => 'decimal:2',
            'area_balcony' => 'decimal:2',
            'area_terrace' => 'decimal:2',
            'area_garden' => 'decimal:2',
            'area_basement' => 'decimal:2',
            'area_loggia' => 'decimal:2',
            'area_garage' => 'decimal:2',
            'office_space' => 'decimal:2',
            'rooms_amount' => 'decimal:1',
            'heating_demand_value' => 'decimal:2',
            'energy_efficiency_value' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'commission_percent' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'commission_makler' => 'decimal:2',
            'buyer_commission_percent' => 'decimal:2',
            // New decimal cost fields
            'heating_costs' => 'decimal:2',
            'warm_water_costs' => 'decimal:2',
            'cooling_costs' => 'decimal:2',
            'admin_costs' => 'decimal:2',
            'elevator_costs' => 'decimal:2',
            'parking_costs_monthly' => 'decimal:2',
            'other_costs' => 'decimal:2',
            'monthly_costs' => 'decimal:2',
            'land_register_fee_pct' => 'decimal:2',
            'land_transfer_tax_pct' => 'decimal:2',
            'contract_fee_pct' => 'decimal:2',
            'mortgage_register_fee_pct' => 'decimal:2',
            'show_nebenkosten_on_website' => 'boolean',
            'internal_rating' => 'decimal:1',
            // New boolean fields
            'buyer_commission_free' => 'boolean',
            // Existing booleans
            'on_hold' => 'boolean',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'plot_buildable' => 'boolean',
            'plot_developed' => 'boolean',
            'has_basement' => 'boolean',
            'has_garden' => 'boolean',
            'has_elevator' => 'boolean',
            'has_balcony' => 'boolean',
            'has_terrace' => 'boolean',
            'has_loggia' => 'boolean',
            'has_fitted_kitchen' => 'boolean',
            'has_air_conditioning' => 'boolean',
            'has_pool' => 'boolean',
            'has_sauna' => 'boolean',
            'has_fireplace' => 'boolean',
            'has_alarm' => 'boolean',
            'has_barrier_free' => 'boolean',
            'has_guest_wc' => 'boolean',
            'has_storage_room' => 'boolean',
            'has_washing_connection' => 'boolean',
            'has_cellar' => 'boolean',
            'has_wohnraumlueftung' => 'boolean',
            'has_dachterrasse' => 'boolean',
            'area_dachterrasse' => 'decimal:2',
            'commission_incl_vat' => 'boolean',
            // New date field
            'closing_date' => 'date',
            // Existing dates
            'inserat_since' => 'date',
            'construction_start' => 'date',
            'construction_end' => 'date',
            'move_in_date' => 'date',
            'available_from' => 'date',
            'energy_valid_until' => 'date',
            'on_hold_since' => 'datetime',
            'published_at' => 'datetime',
            'last_expose_parsed_at' => 'datetime',
            // New json field
            'building_details' => 'array',
            'documents_available' => 'array',
        ];
    }

    /**
     * Oesterreich-Standardsaetze fuer Nebenkosten beim Kauf.
     * Werden beim Anlegen einer neuen Property automatisch gesetzt,
     * wenn der Aufrufer nichts explizit uebergibt. Pro Objekt kann dann
     * im Cockpit ueberschrieben oder geleert werden.
     */
    protected static function booted(): void
    {
        static::creating(function (self $property): void {
            $defaults = [
                'land_transfer_tax_pct' => 3.5,
                'land_register_fee_pct' => 1.1,
                'mortgage_register_fee_pct' => 1.2,
                'contract_fee_pct' => 1.5,
                'buyer_commission_percent' => 3.0,
            ];
            foreach ($defaults as $col => $val) {
                // Nur Default setzen, wenn der Aufrufer das Feld gar nicht gesetzt hat.
                // Wenn explizit null/0 uebergeben wurde, bleibt das wie es ist.
                if (!array_key_exists($col, $property->getAttributes())) {
                    $property->{$col} = $val;
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(PortalEmail::class);
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(PropertyKnowledge::class);
    }

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PortalMessage::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PortalDocument::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function propertyLinks(): HasMany
    {
        return $this->hasMany(PropertyLink::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('sort_order');
    }

    public function titleImage()
    {
        return $this->hasOne(PropertyImage::class)->where('is_title_image', true);
    }

    public function portals(): HasMany
    {
        return $this->hasMany(PropertyPortal::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Property::class, 'parent_id');
    }

    public function latestIntakeProtocol()
    {
        return $this->hasOne(\App\Models\IntakeProtocol::class)->latestOfMany();
    }

    public function intakeProtocols(): HasMany
    {
        return $this->hasMany(\App\Models\IntakeProtocol::class);
    }
}
