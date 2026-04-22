<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bringt die test-env SQLite-Properties-Tabelle auf den aktuellen Stand der
 * Prod-MySQL-Spalten, die über Jahre via Raw-SQL (nicht via Migrationen)
 * hinzugefügt wurden. Jede Spalte ist hasColumn-guarded, dadurch no-op auf
 * prod. Nötig ab Task 9 (Aufnahmeprotokoll-Submit), weil dieser erstmalig
 * per Test einen vollwertigen Property-Insert ausführt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('properties')) return;

        Schema::table('properties', function (Blueprint $table) {
            $cols = [
                // FKs / meta
                'broker_id' => fn($t) => $t->unsignedBigInteger('broker_id')->nullable(),
                'broker_name_override' => fn($t) => $t->string('broker_name_override', 255)->nullable(),
                'project_group_id' => fn($t) => $t->unsignedBigInteger('project_group_id')->nullable(),
                'parent_id' => fn($t) => $t->unsignedBigInteger('parent_id')->nullable(),
                'openimmo_id' => fn($t) => $t->string('openimmo_id', 100)->nullable(),
                'openimmo_anbieter_id' => fn($t) => $t->string('openimmo_anbieter_id', 100)->nullable(),
                'project_name' => fn($t) => $t->string('project_name', 255)->nullable(),
                'title' => fn($t) => $t->string('title', 500)->nullable(),
                'subtitle' => fn($t) => $t->string('subtitle', 255)->nullable(),
                'ad_tag' => fn($t) => $t->string('ad_tag', 100)->nullable(),
                'closing_date' => fn($t) => $t->date('closing_date')->nullable(),
                'internal_rating' => fn($t) => $t->decimal('internal_rating', 2, 1)->nullable(),

                // Address
                'house_number' => fn($t) => $t->string('house_number', 20)->nullable(),
                'staircase' => fn($t) => $t->string('staircase', 20)->nullable(),
                'door' => fn($t) => $t->string('door', 20)->nullable(),
                'entrance' => fn($t) => $t->string('entrance', 50)->nullable(),
                'address_floor' => fn($t) => $t->string('address_floor', 20)->nullable(),
                'latitude' => fn($t) => $t->decimal('latitude', 10, 7)->nullable(),
                'longitude' => fn($t) => $t->decimal('longitude', 10, 7)->nullable(),
                'geo_precision' => fn($t) => $t->string('geo_precision', 50)->nullable(),

                // Object
                'object_type' => fn($t) => $t->string('object_type', 50)->nullable(),
                'property_category' => fn($t) => $t->string('property_category', 50)->nullable(),
                'object_subtype' => fn($t) => $t->string('object_subtype', 100)->nullable(),
                'construction_type' => fn($t) => $t->string('construction_type', 100)->nullable(),
                'ownership_type' => fn($t) => $t->string('ownership_type', 100)->nullable(),
                'marketing_type' => fn($t) => $t->string('marketing_type', 20)->nullable(),

                // Owner / contact
                'owner_name' => fn($t) => $t->string('owner_name', 255)->nullable(),
                'owner_phone' => fn($t) => $t->string('owner_phone', 100)->nullable(),
                'owner_email' => fn($t) => $t->string('owner_email', 255)->nullable(),
                'contact_person' => fn($t) => $t->string('contact_person', 255)->nullable(),
                'contact_phone' => fn($t) => $t->string('contact_phone', 100)->nullable(),
                'contact_email' => fn($t) => $t->string('contact_email', 255)->nullable(),

                // Commission
                'commission_percent' => fn($t) => $t->decimal('commission_percent', 5, 2)->nullable(),
                'commission_note' => fn($t) => $t->string('commission_note', 255)->nullable(),
                'commission_total' => fn($t) => $t->decimal('commission_total', 5, 2)->nullable(),
                'commission_makler' => fn($t) => $t->decimal('commission_makler', 5, 2)->nullable(),
                'buyer_commission_percent' => fn($t) => $t->decimal('buyer_commission_percent', 5, 2)->nullable(),
                'buyer_commission_text' => fn($t) => $t->string('buyer_commission_text', 255)->nullable(),
                'commission_incl_vat' => fn($t) => $t->boolean('commission_incl_vat')->nullable()->default(true),

                // Energy
                'energy_certificate' => fn($t) => $t->string('energy_certificate', 100)->nullable(),
                'heating_demand_value' => fn($t) => $t->decimal('heating_demand_value', 8, 2)->nullable(),
                'energy_type' => fn($t) => $t->string('energy_type', 30)->nullable(),
                'heating_demand_class' => fn($t) => $t->string('heating_demand_class', 5)->nullable(),
                'energy_efficiency_value' => fn($t) => $t->decimal('energy_efficiency_value', 6, 2)->nullable(),
                'energy_primary_source' => fn($t) => $t->string('energy_primary_source', 100)->nullable(),
                'energy_valid_until' => fn($t) => $t->date('energy_valid_until')->nullable(),

                // Floor / parking
                'floor_count' => fn($t) => $t->integer('floor_count')->nullable(),
                'floor_number' => fn($t) => $t->integer('floor_number')->nullable(),
                'garage_spaces' => fn($t) => $t->integer('garage_spaces')->nullable(),
                'parking_spaces' => fn($t) => $t->integer('parking_spaces')->nullable(),
                'parking_type' => fn($t) => $t->string('parking_type', 100)->nullable(),
                'parking_price' => fn($t) => $t->decimal('parking_price', 10, 2)->nullable(),

                // Features
                'has_basement' => fn($t) => $t->boolean('has_basement')->nullable(),
                'has_garden' => fn($t) => $t->boolean('has_garden')->nullable(),
                'has_elevator' => fn($t) => $t->boolean('has_elevator')->nullable(),
                'has_balcony' => fn($t) => $t->boolean('has_balcony')->nullable(),
                'has_terrace' => fn($t) => $t->boolean('has_terrace')->nullable(),
                'has_loggia' => fn($t) => $t->boolean('has_loggia')->nullable(),

                // Costs
                'operating_costs' => fn($t) => $t->decimal('operating_costs', 10, 2)->nullable(),
                'maintenance_reserves' => fn($t) => $t->decimal('maintenance_reserves', 10, 2)->nullable(),
                'heating_costs' => fn($t) => $t->decimal('heating_costs', 12, 2)->nullable(),
                'warm_water_costs' => fn($t) => $t->decimal('warm_water_costs', 12, 2)->nullable(),
                'cooling_costs' => fn($t) => $t->decimal('cooling_costs', 12, 2)->nullable(),
                'admin_costs' => fn($t) => $t->decimal('admin_costs', 12, 2)->nullable(),
                'elevator_costs' => fn($t) => $t->decimal('elevator_costs', 12, 2)->nullable(),
                'parking_costs_monthly' => fn($t) => $t->decimal('parking_costs_monthly', 12, 2)->nullable(),
                'other_costs' => fn($t) => $t->decimal('other_costs', 12, 2)->nullable(),
                'monthly_costs' => fn($t) => $t->decimal('monthly_costs', 12, 2)->nullable(),
                'land_register_fee_pct' => fn($t) => $t->decimal('land_register_fee_pct', 5, 2)->nullable(),
                'land_transfer_tax_pct' => fn($t) => $t->decimal('land_transfer_tax_pct', 5, 2)->nullable(),
                'contract_fee_pct' => fn($t) => $t->decimal('contract_fee_pct', 5, 2)->nullable(),
                'mortgage_register_fee_pct' => fn($t) => $t->decimal('mortgage_register_fee_pct', 5, 2)->nullable(),
                'nebenkosten_note' => fn($t) => $t->text('nebenkosten_note')->nullable(),
                'show_nebenkosten_on_website' => fn($t) => $t->boolean('show_nebenkosten_on_website')->default(true),
                'buyer_commission_free' => fn($t) => $t->boolean('buyer_commission_free')->default(false),

                // Building
                'building_details' => fn($t) => $t->json('building_details')->nullable(),
                'plot_dedication' => fn($t) => $t->string('plot_dedication', 255)->nullable(),
                'plot_buildable' => fn($t) => $t->boolean('plot_buildable')->nullable(),
                'plot_developed' => fn($t) => $t->boolean('plot_developed')->nullable(),
                'construction_start' => fn($t) => $t->date('construction_start')->nullable(),
                'construction_end' => fn($t) => $t->date('construction_end')->nullable(),
                'move_in_date' => fn($t) => $t->date('move_in_date')->nullable(),
                'available_from' => fn($t) => $t->date('available_from')->nullable(),
                'available_text' => fn($t) => $t->string('available_text', 255)->nullable(),
                'builder_company' => fn($t) => $t->string('builder_company', 255)->nullable(),
                'property_manager' => fn($t) => $t->string('property_manager', 255)->nullable(),
                'property_manager_id' => fn($t) => $t->unsignedBigInteger('property_manager_id')->nullable(),
                'total_units' => fn($t) => $t->integer('total_units')->nullable(),
                'condition_note' => fn($t) => $t->string('condition_note', 255)->nullable(),
                'common_areas' => fn($t) => $t->text('common_areas')->nullable(),

                // Realty
                'realty_condition' => fn($t) => $t->string('realty_condition', 50)->nullable(),
                'quality' => fn($t) => $t->string('quality', 30)->nullable(),
                'flooring' => fn($t) => $t->string('flooring', 255)->nullable(),
                'bathroom_equipment' => fn($t) => $t->string('bathroom_equipment', 255)->nullable(),
                'kitchen_type' => fn($t) => $t->string('kitchen_type', 30)->nullable(),
                'has_fitted_kitchen' => fn($t) => $t->boolean('has_fitted_kitchen')->default(false),
                'has_air_conditioning' => fn($t) => $t->boolean('has_air_conditioning')->default(false),
                'has_pool' => fn($t) => $t->boolean('has_pool')->default(false),
                'has_sauna' => fn($t) => $t->boolean('has_sauna')->default(false),
                'has_photovoltaik' => fn($t) => $t->boolean('has_photovoltaik')->default(false),
                'has_charging_station' => fn($t) => $t->boolean('has_charging_station')->default(false),
                'charging_station_status' => fn($t) => $t->string('charging_station_status', 20)->nullable(),
                'has_fireplace' => fn($t) => $t->boolean('has_fireplace')->default(false),
                'has_alarm' => fn($t) => $t->boolean('has_alarm')->default(false),
                'has_barrier_free' => fn($t) => $t->boolean('has_barrier_free')->default(false),
                'has_guest_wc' => fn($t) => $t->boolean('has_guest_wc')->default(false),
                'has_storage_room' => fn($t) => $t->boolean('has_storage_room')->default(false),
                'has_washing_connection' => fn($t) => $t->boolean('has_washing_connection')->default(false),
                'has_cellar' => fn($t) => $t->boolean('has_cellar')->default(false),
                'furnishing' => fn($t) => $t->string('furnishing', 100)->nullable(),
                'orientation' => fn($t) => $t->string('orientation', 100)->nullable(),
                'noise_level' => fn($t) => $t->string('noise_level', 100)->nullable(),
                'last_expose_parsed_at' => fn($t) => $t->timestamp('last_expose_parsed_at')->nullable(),

                // Prices
                'purchase_price' => fn($t) => $t->decimal('purchase_price', 12, 2)->nullable(),
                'rental_price' => fn($t) => $t->decimal('rental_price', 10, 2)->nullable(),
                'rent_warm' => fn($t) => $t->decimal('rent_warm', 10, 2)->nullable(),
                'rent_deposit' => fn($t) => $t->decimal('rent_deposit', 10, 2)->nullable(),
                'price_per_m2' => fn($t) => $t->decimal('price_per_m2', 10, 2)->nullable(),

                // Areas
                'total_area' => fn($t) => $t->decimal('total_area', 10, 2)->nullable(),
                'living_area' => fn($t) => $t->decimal('living_area', 10, 2)->nullable(),
                'free_area' => fn($t) => $t->decimal('free_area', 10, 2)->nullable(),
                'realty_area' => fn($t) => $t->decimal('realty_area', 10, 2)->nullable(),
                'area_balcony' => fn($t) => $t->decimal('area_balcony', 10, 2)->nullable(),
                'balcony_count' => fn($t) => $t->integer('balcony_count')->nullable(),
                'area_terrace' => fn($t) => $t->decimal('area_terrace', 10, 2)->nullable(),
                'terrace_count' => fn($t) => $t->integer('terrace_count')->nullable(),
                'area_garden' => fn($t) => $t->decimal('area_garden', 10, 2)->nullable(),
                'garden_count' => fn($t) => $t->integer('garden_count')->nullable(),
                'area_basement' => fn($t) => $t->decimal('area_basement', 10, 2)->nullable(),
                'basement_count' => fn($t) => $t->unsignedInteger('basement_count')->nullable(),
                'area_loggia' => fn($t) => $t->decimal('area_loggia', 10, 2)->nullable(),
                'loggia_count' => fn($t) => $t->integer('loggia_count')->nullable(),
                'area_garage' => fn($t) => $t->decimal('area_garage', 10, 2)->nullable(),
                'office_space' => fn($t) => $t->decimal('office_space', 10, 2)->nullable(),

                // Rooms / year
                'rooms_amount' => fn($t) => $t->decimal('rooms_amount', 4, 1)->nullable(),
                'bedrooms' => fn($t) => $t->integer('bedrooms')->nullable(),
                'bathrooms' => fn($t) => $t->integer('bathrooms')->nullable(),
                'toilets' => fn($t) => $t->integer('toilets')->nullable(),
                'construction_year' => fn($t) => $t->integer('construction_year')->nullable(),

                // Descriptions
                'realty_description' => fn($t) => $t->text('realty_description')->nullable(),
                'show_on_website' => fn($t) => $t->boolean('show_on_website')->default(false),
                'main_image_id' => fn($t) => $t->integer('main_image_id')->nullable(),
                'external_image_url' => fn($t) => $t->string('external_image_url', 500)->nullable(),
                'website_gallery_ids' => fn($t) => $t->json('website_gallery_ids')->nullable(),
                'location_description' => fn($t) => $t->text('location_description')->nullable(),
                'equipment_description' => fn($t) => $t->text('equipment_description')->nullable(),
                'other_description' => fn($t) => $t->text('other_description')->nullable(),
                'property_history' => fn($t) => $t->json('property_history')->nullable(),

                // Status (muss nullable() sein, damit SQLite es nachträglich adden kann)
                'realty_status' => fn($t) => $t->string('realty_status', 20)->nullable()->default('aktiv'),
                'sold_at' => fn($t) => $t->timestamp('sold_at')->nullable(),
                'is_published' => fn($t) => $t->boolean('is_published')->default(false),
                'published_at' => fn($t) => $t->timestamp('published_at')->nullable(),
                'expose_path' => fn($t) => $t->string('expose_path', 500)->nullable(),
                'nebenkosten_path' => fn($t) => $t->string('nebenkosten_path', 500)->nullable(),
            ];

            foreach ($cols as $name => $adder) {
                if (!Schema::hasColumn('properties', $name)) {
                    $adder($table);
                }
            }
        });
    }

    public function down(): void
    {
        // No-op — columns may have been added via raw SQL on prod and we
        // don't want a down-migration to drop user data.
    }
};
