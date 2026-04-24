<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um ein Freitextfeld `conversions_additions` —
 * fuer „Um- oder Zubauten". Wird direkt unter Baujahr gepflegt und
 * sowohl auf der Website als auch im Exposé ausgegeben. Abgrenzung:
 * `last_renovation_note` meint die letzte Kernsanierung, `conversions_additions`
 * beschreibt bauliche Erweiterungen/Umgestaltungen (z. B. "Zubau 1997
 * mit zusätzlichem Schlafzimmer, Umbau 2015 im OG").
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'conversions_additions')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->text('conversions_additions')->nullable()->after('construction_year');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'conversions_additions')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('conversions_additions');
            });
        }
    }
};
