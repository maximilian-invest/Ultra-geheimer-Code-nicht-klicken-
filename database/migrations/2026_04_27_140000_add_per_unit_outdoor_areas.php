<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt property_units um getrennte Aussenflaechen pro Einheit.
 *
 * Hintergrund: bei Neubauprojekten ist jede TOP eine eigene Inserats-Einheit
 * mit ggf. unterschiedlichem Balkon/Terrasse/Garten. Das Master-Projekt
 * sollte die Range (X bis Y m²) aus den Units lesen statt eigene Werte.
 *
 * Alte Spalten balcony_terrace_m2 + garden_m2 bleiben als Legacy erhalten;
 * wir migrieren ihre Werte einmalig auf die neuen Felder.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            if (!Schema::hasColumn('property_units', 'area_balcony')) {
                $table->decimal('area_balcony', 8, 2)->nullable()->after('garden_m2');
            }
            if (!Schema::hasColumn('property_units', 'area_terrace')) {
                $table->decimal('area_terrace', 8, 2)->nullable()->after('area_balcony');
            }
            if (!Schema::hasColumn('property_units', 'area_garden')) {
                $table->decimal('area_garden', 8, 2)->nullable()->after('area_terrace');
            }
        });

        // Best-effort backfill: bestehende balcony_terrace_m2-Werte sind
        // (laut UI-Historie) ueberwiegend Balkon-Werte; wir spielen sie
        // defensiv auf area_balcony. garden_m2 → area_garden ist
        // semantisch klar.
        DB::table('property_units')
            ->whereNotNull('balcony_terrace_m2')
            ->whereNull('area_balcony')
            ->update(['area_balcony' => DB::raw('balcony_terrace_m2')]);

        DB::table('property_units')
            ->whereNotNull('garden_m2')
            ->whereNull('area_garden')
            ->update(['area_garden' => DB::raw('garden_m2')]);
    }

    public function down(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            foreach (['area_garden', 'area_terrace', 'area_balcony'] as $col) {
                if (Schema::hasColumn('property_units', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
