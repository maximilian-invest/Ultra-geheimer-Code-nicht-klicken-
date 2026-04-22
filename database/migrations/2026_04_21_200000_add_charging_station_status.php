<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'charging_station_status')) {
                // Werte: 'none' (keine), 'prepared' (Vorkehrung/Leerverrohrung), 'installed' (vorhanden)
                $table->string('charging_station_status', 20)->nullable()->after('has_charging_station');
            }
        });

        // Backfill: Wer bisher has_charging_station=1 hatte, bekommt 'installed'.
        DB::table('properties')
            ->where('has_charging_station', 1)
            ->whereNull('charging_station_status')
            ->update(['charging_station_status' => 'installed']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('charging_station_status');
        });
    }
};
