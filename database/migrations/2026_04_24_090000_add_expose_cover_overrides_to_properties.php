<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um drei optionale Text-Overrides für die Cover-Seite
 * des Exposés. Wenn leer, fällt das Template auf die Property-Defaults
 * zurück (object_type, city, address). Gibt dem Makler Kontrolle über
 * den ersten Eindruck ohne die Basis-Property-Daten zu verändern.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'expose_cover_kicker')) {
                $table->string('expose_cover_kicker', 120)->nullable()->after('expose_captions_pool');
            }
            if (!Schema::hasColumn('properties', 'expose_cover_title')) {
                $table->string('expose_cover_title', 120)->nullable()->after('expose_cover_kicker');
            }
            if (!Schema::hasColumn('properties', 'expose_cover_subtitle')) {
                $table->string('expose_cover_subtitle', 200)->nullable()->after('expose_cover_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            foreach (['expose_cover_kicker', 'expose_cover_title', 'expose_cover_subtitle'] as $col) {
                if (Schema::hasColumn('properties', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
