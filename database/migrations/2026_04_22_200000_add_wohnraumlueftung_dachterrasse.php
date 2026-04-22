<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'has_wohnraumlueftung')) {
                $table->boolean('has_wohnraumlueftung')->default(false)->after('has_photovoltaik');
            }
            if (!Schema::hasColumn('properties', 'has_dachterrasse')) {
                $table->boolean('has_dachterrasse')->default(false)->after('has_terrace');
            }
            if (!Schema::hasColumn('properties', 'area_dachterrasse')) {
                $table->decimal('area_dachterrasse', 8, 2)->nullable()->after('area_terrace');
            }
            if (!Schema::hasColumn('properties', 'dachterrasse_count')) {
                $table->unsignedTinyInteger('dachterrasse_count')->nullable()->after('area_dachterrasse');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['has_wohnraumlueftung', 'has_dachterrasse', 'area_dachterrasse', 'dachterrasse_count']);
        });
    }
};
