<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um `expose_highlights` (JSON-Array von Tag-Strings).
 * Wird im Admin ueber Multi-Select-Checkboxes gepflegt und dient dem
 * Exposé-Beschreibungs-Generator als *Priorisierung*: Die ausgewaehlten
 * Highlights werden in die ersten Absaetze der Objektbeschreibung
 * eingewoben, konkret und begriffsstark (Dachterrasse, Seeblick,
 * Einbauküche, etc.).
 *
 * Abgrenzung zum bestehenden Freitext-Feld `highlights`: dieses bleibt
 * fuer Legacy-/Marketing-Stichworte, expose_highlights ist die strukturierte
 * Tag-Liste fuer den Prompt.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'expose_highlights')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->json('expose_highlights')->nullable()->after('highlights');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'expose_highlights')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('expose_highlights');
            });
        }
    }
};
