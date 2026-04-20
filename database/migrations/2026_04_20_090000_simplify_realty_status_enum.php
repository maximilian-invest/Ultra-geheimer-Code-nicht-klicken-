<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Simplify properties.realty_status ENUM to three states:
 *   aktiv | inaktiv | verkauft
 *
 * Vorher: auftrag, inserat, anfragen, besichtigungen, angebote,
 *         verhandlung, verkauft, inaktiv (8 Werte, teils legacy).
 *
 * Das Frontend bot die 5 Werte Auftrag/Aktiv/Verkauft/Reserviert/Inaktiv
 * an. "Aktiv" war im ENUM gar nicht vorhanden — beim Speichern kam
 * MySQL Data truncated-Error oder schlimmer: stillschweigender Reset
 * auf leer ("" bei 6 Objekten in der DB).
 *
 * Zweistufiges ALTER, damit wir die Daten vor dem Shrink migrieren
 * koennen:
 *   1) ENUM erweitert um 'aktiv'
 *   2) Legacy-Werte auf 'aktiv' mappen
 *   3) ENUM auf (aktiv, inaktiv, verkauft) shrinken
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;

        // 1) Enum erweitern, damit wir auf 'aktiv' mappen koennen.
        DB::statement("ALTER TABLE properties MODIFY COLUMN realty_status ENUM("
            . "'auftrag','inserat','anfragen','besichtigungen','angebote',"
            . "'verhandlung','verkauft','inaktiv','aktiv'"
            . ") NOT NULL DEFAULT 'aktiv'");

        // 2) Legacy-Werte auf 'aktiv' mappen. Leere Strings (Folgeschaden aus
        // Data-truncated) werden ebenfalls 'aktiv'.
        DB::table('properties')
            ->whereIn('realty_status', ['auftrag', 'inserat', 'anfragen', 'besichtigungen', 'angebote', 'verhandlung'])
            ->update(['realty_status' => 'aktiv']);
        DB::table('properties')
            ->where('realty_status', '')
            ->update(['realty_status' => 'aktiv']);

        // 3) Enum auf drei Zustaende einschraenken.
        DB::statement("ALTER TABLE properties MODIFY COLUMN realty_status ENUM("
            . "'aktiv','inaktiv','verkauft'"
            . ") NOT NULL DEFAULT 'aktiv'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;

        // Altes ENUM wiederherstellen. Daten koennen wir nicht zurueck-
        // migrieren (Info ueber vorherigen State ist verloren), aktive
        // Objekte bleiben als 'auftrag' stehen — der Default des alten
        // Schemas war keiner der drei neuen Werte.
        DB::statement("ALTER TABLE properties MODIFY COLUMN realty_status ENUM("
            . "'auftrag','inserat','anfragen','besichtigungen','angebote',"
            . "'verhandlung','verkauft','inaktiv','aktiv'"
            . ") NOT NULL");

        DB::table('properties')->where('realty_status', 'aktiv')->update(['realty_status' => 'auftrag']);

        DB::statement("ALTER TABLE properties MODIFY COLUMN realty_status ENUM("
            . "'auftrag','inserat','anfragen','besichtigungen','angebote',"
            . "'verhandlung','verkauft','inaktiv'"
            . ") NOT NULL");
    }
};
