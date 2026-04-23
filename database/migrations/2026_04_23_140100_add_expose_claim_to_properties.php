<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fügt das Feld `expose_claim` auf `properties` hinzu — ein optionaler
 * kurzer Text-Claim (z. B. „Wo Tageslicht den Raum formt.") der auf der
 * Exposé-Titelseite und in Editorial-Spreads angezeigt wird. Nullable,
 * weil nicht jede Property einen Claim gesetzt hat; Fallback im Generator
 * ist dann der erste Satz der Beschreibung.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('expose_claim', 200)->nullable()->after('highlights');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('expose_claim');
        });
    }
};
