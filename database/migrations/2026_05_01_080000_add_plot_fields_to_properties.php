<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Widmung & Bebauungsplan-Bezug
            $table->string('plot_zoning', 100)->nullable()->after('plot_developed');
            $table->string('plot_buildplan_id', 80)->nullable()->after('plot_zoning');

            // Kennzahlen aus dem Bebauungsplan
            $table->decimal('plot_gfz', 5, 2)->nullable()->after('plot_buildplan_id');
            $table->decimal('plot_grz', 5, 2)->nullable()->after('plot_gfz');
            $table->decimal('plot_bmz', 5, 2)->nullable()->after('plot_grz');
            $table->decimal('plot_max_building_area', 10, 2)->nullable()->after('plot_bmz');
            $table->unsignedSmallInteger('plot_max_units_per_building')->nullable()->after('plot_max_building_area');

            // Hoehen / Bautiefen
            $table->decimal('plot_max_height_first', 6, 2)->nullable()->after('plot_max_units_per_building');
            $table->decimal('plot_max_height_eaves', 6, 2)->nullable()->after('plot_max_height_first');
            $table->decimal('plot_max_length', 6, 2)->nullable()->after('plot_max_height_eaves');
            $table->decimal('plot_max_width', 6, 2)->nullable()->after('plot_max_length');

            // Bauweise & Dach
            $table->string('plot_construction_type', 60)->nullable()->after('plot_max_width');
            $table->string('plot_roof_form', 60)->nullable()->after('plot_construction_type');
            $table->decimal('plot_min_roof_pitch', 4, 1)->nullable()->after('plot_roof_form');
            $table->decimal('plot_max_roof_pitch', 4, 1)->nullable()->after('plot_min_roof_pitch');

            // Erschliessung-Details
            $table->boolean('plot_utility_water')->default(false)->after('plot_max_roof_pitch');
            $table->boolean('plot_utility_sewage')->default(false)->after('plot_utility_water');
            $table->boolean('plot_utility_electricity')->default(false)->after('plot_utility_sewage');
            $table->boolean('plot_utility_gas')->default(false)->after('plot_utility_electricity');
            $table->boolean('plot_utility_fiber')->default(false)->after('plot_utility_gas');

            // Gefahrenzonen / Auflagen
            $table->string('plot_hazard_zone', 30)->nullable()->after('plot_utility_fiber');
            $table->boolean('plot_wlv_reserve')->default(false)->after('plot_hazard_zone');
            $table->boolean('plot_wlv_hint')->default(false)->after('plot_wlv_reserve');
            $table->boolean('plot_flood_risk')->default(false)->after('plot_wlv_hint');
            $table->boolean('plot_landscape_protection')->default(false)->after('plot_flood_risk');
            $table->boolean('plot_planting_obligation')->default(false)->after('plot_landscape_protection');

            // Topografie + Freitext
            $table->string('plot_topography', 200)->nullable()->after('plot_planting_obligation');
            $table->text('plot_notes')->nullable()->after('plot_topography');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'plot_zoning', 'plot_buildplan_id',
                'plot_gfz', 'plot_grz', 'plot_bmz',
                'plot_max_building_area', 'plot_max_units_per_building',
                'plot_max_height_first', 'plot_max_height_eaves',
                'plot_max_length', 'plot_max_width',
                'plot_construction_type', 'plot_roof_form',
                'plot_min_roof_pitch', 'plot_max_roof_pitch',
                'plot_utility_water', 'plot_utility_sewage', 'plot_utility_electricity',
                'plot_utility_gas', 'plot_utility_fiber',
                'plot_hazard_zone', 'plot_wlv_reserve', 'plot_wlv_hint',
                'plot_flood_risk', 'plot_landscape_protection', 'plot_planting_obligation',
                'plot_topography', 'plot_notes',
            ]);
        });
    }
};
