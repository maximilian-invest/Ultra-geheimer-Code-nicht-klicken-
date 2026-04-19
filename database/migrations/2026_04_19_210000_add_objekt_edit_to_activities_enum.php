<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend activities.category enum with 'objekt_edit' — separate Kategorie fuer
 * automatisch erzeugte Aktivitaeten bei Bearbeitung der Inserats-Daten. Wir
 * nutzen bewusst NICHT die existierende Kategorie 'update', weil der Portal-
 * Sanitizer diese generisch auf "Status aktualisiert" ueberschreibt; fuer
 * 'objekt_edit' wollen wir den konkret kuratierten Text ("Objektdaten
 * aktualisiert: Beschreibung, Kaufpreis") im Kundenportal sehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen','link_opened','objekt_edit'"
                . ") DEFAULT 'sonstiges'");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite hat keine ALTER-CHECK-Moeglichkeit — Tabelle neu bauen.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("
                CREATE TABLE activities_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    property_id INTEGER NOT NULL,
                    activity_date DATE NOT NULL,
                    stakeholder VARCHAR NOT NULL,
                    activity TEXT NOT NULL,
                    result TEXT,
                    duration INTEGER,
                    category VARCHAR CHECK(category IN (
                        'email-in','email-out','expose','besichtigung','kaufanbot',
                        'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',
                        'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',
                        'nachfassen','link_opened','objekt_edit'
                    )) DEFAULT 'sonstiges',
                    source_email_id INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    link_session_id INTEGER,
                    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
                )
            ");
            DB::statement("INSERT INTO activities_new SELECT id, property_id, activity_date, stakeholder, activity, result, duration, category, source_email_id, created_at, updated_at, link_session_id FROM activities");
            DB::statement("DROP TABLE activities");
            DB::statement("ALTER TABLE activities_new RENAME TO activities");
            DB::statement("CREATE INDEX idx_activities_link_session ON activities (link_session_id)");
            DB::statement("CREATE INDEX idx_activities_property_date ON activities (property_id, activity_date)");
            DB::statement("CREATE INDEX idx_activities_stakeholder ON activities (stakeholder)");
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Bestehende objekt_edit-Eintraege auf 'update' mappen, damit der
            // ALTER nicht an Werten scheitert, die nicht mehr im Enum sind.
            DB::table('activities')->where('category', 'objekt_edit')->update(['category' => 'update']);

            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen','link_opened'"
                . ") DEFAULT 'sonstiges'");
        }
    }
};
