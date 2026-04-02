<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Allgemeines
            $table->string('construction_type', 100)->nullable()->after('object_subtype');
            $table->string('ownership_type', 100)->nullable()->after('construction_type');
            $table->string('subtitle', 255)->nullable()->after('title');
            $table->string('ad_tag', 100)->nullable()->after('subtitle');
            $table->date('closing_date')->nullable()->after('ad_tag');
            $table->decimal('internal_rating', 2, 1)->nullable()->after('closing_date');

            // Adresse
            $table->string('house_number', 20)->nullable()->after('address');
            $table->string('staircase', 20)->nullable()->after('house_number');
            $table->string('door', 20)->nullable()->after('staircase');
            $table->string('entrance', 50)->nullable()->after('door');
            $table->string('address_floor', 20)->nullable()->after('entrance');

            // Kosten
            $table->decimal('heating_costs', 12, 2)->nullable()->after('maintenance_reserves');
            $table->decimal('warm_water_costs', 12, 2)->nullable()->after('heating_costs');
            $table->decimal('cooling_costs', 12, 2)->nullable()->after('warm_water_costs');
            $table->decimal('admin_costs', 12, 2)->nullable()->after('cooling_costs');
            $table->decimal('elevator_costs', 12, 2)->nullable()->after('admin_costs');
            $table->decimal('parking_costs_monthly', 12, 2)->nullable()->after('elevator_costs');
            $table->decimal('other_costs', 12, 2)->nullable()->after('parking_costs_monthly');
            $table->decimal('monthly_costs', 12, 2)->nullable()->after('other_costs');
            $table->decimal('land_register_fee_pct', 5, 2)->nullable()->after('monthly_costs');
            $table->decimal('land_transfer_tax_pct', 5, 2)->nullable()->after('land_register_fee_pct');
            $table->decimal('contract_fee_pct', 5, 2)->nullable()->after('land_transfer_tax_pct');
            $table->boolean('buyer_commission_free')->default(false)->after('contract_fee_pct');

            // Gebaeude
            $table->json('building_details')->nullable()->after('buyer_commission_free');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                // Allgemeines
                'construction_type',
                'ownership_type',
                'subtitle',
                'ad_tag',
                'closing_date',
                'internal_rating',
                // Adresse
                'house_number',
                'staircase',
                'door',
                'entrance',
                'address_floor',
                // Kosten
                'heating_costs',
                'warm_water_costs',
                'cooling_costs',
                'admin_costs',
                'elevator_costs',
                'parking_costs_monthly',
                'other_costs',
                'monthly_costs',
                'land_register_fee_pct',
                'land_transfer_tax_pct',
                'contract_fee_pct',
                'buyer_commission_free',
                // Gebaeude
                'building_details',
            ]);
        });
    }
};
