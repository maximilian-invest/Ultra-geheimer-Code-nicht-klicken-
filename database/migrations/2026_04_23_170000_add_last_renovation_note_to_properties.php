<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um ein Freitextfeld `last_renovation_note` — der
 * Makler schreibt hier einen Satz zum letzten größeren Umbau/Sanierung,
 * z.B. „Umfassender Zubau 1997 inkl. Komplettsanierung". Wird im Exposé
 * direkt unter dem Baujahr ausgegeben und ist nullable (nicht jedes
 * Objekt hat eine Renovierungshistorie).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'last_renovation_note')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->text('last_renovation_note')->nullable()->after('year_renovated');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'last_renovation_note')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('last_renovation_note');
            });
        }
    }
};
