<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um `expose_captions_pool` — ein Multi-Line-Text-Feld,
 * in dem der Makler kurze poetische Sätze für die Editorial-Mixed-
 * Impressionen-Seiten hinterlegen kann (eine Zeile = ein Satz).
 * Der Generator verteilt die Sätze rotierend auf die Editorial-Pages.
 * Wenn leer, fällt er auf eine Vorschlagsliste zurück.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'expose_captions_pool')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->text('expose_captions_pool')->nullable()->after('expose_claim');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'expose_captions_pool')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('expose_captions_pool');
            });
        }
    }
};
