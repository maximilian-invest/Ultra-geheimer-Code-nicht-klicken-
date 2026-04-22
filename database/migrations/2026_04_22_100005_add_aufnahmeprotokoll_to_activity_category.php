<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend activities.category enum/CHECK mit 'Aufnahmeprotokoll'. Wird vom
 * Submit-Endpoint des Aufnahmeprotokoll-Wizards (Task 9) befüllt, damit die
 * durchgeführte Protokollaufnahme im Objekt-Zeitstrahl auftaucht.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot','update',"
                . "'absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ',"
                . "'feedback_besichtigung','nachfassen','link_opened','objekt_edit',"
                . "'hausverwaltung','Aufnahmeprotokoll'"
                . ") NULL DEFAULT 'sonstiges'");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite: Tabelle neu bauen, CHECK constraint ist unveränderlich.
            // Spalten dynamisch aus PRAGMA ermitteln, damit die Migration
            // unabhängig von Zwischenschritten arbeitet.
            $cols = DB::select('PRAGMA table_info(activities)');
            $colDefs = [];
            $colNames = [];
            foreach ($cols as $c) {
                $name = $c->name;
                $type = $c->type ?: 'VARCHAR';
                $nn = $c->notnull ? ' NOT NULL' : '';
                $default = $c->dflt_value !== null ? ' DEFAULT ' . $c->dflt_value : '';

                if ($name === 'category') {
                    $colDefs[] = "category VARCHAR CHECK(category IN ("
                        . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                        . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                        . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                        . "'nachfassen','link_opened','objekt_edit','hausverwaltung','Aufnahmeprotokoll'"
                        . ")) DEFAULT 'sonstiges'";
                } elseif ($c->pk) {
                    $colDefs[] = "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
                } else {
                    $colDefs[] = "{$name} {$type}{$nn}{$default}";
                }
                $colNames[] = $name;
            }

            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE activities_new (' . implode(', ', $colDefs)
                . ', FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE)');
            $colList = implode(', ', $colNames);
            DB::statement("INSERT INTO activities_new ({$colList}) SELECT {$colList} FROM activities");
            DB::statement('DROP TABLE activities');
            DB::statement('ALTER TABLE activities_new RENAME TO activities');
            DB::statement('CREATE INDEX idx_activities_link_session ON activities (link_session_id)');
            DB::statement('CREATE INDEX idx_activities_property_date ON activities (property_id, activity_date)');
            DB::statement('CREATE INDEX idx_activities_stakeholder ON activities (stakeholder)');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("UPDATE activities SET category = 'sonstiges' WHERE category = 'Aufnahmeprotokoll'");
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot','update',"
                . "'absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ',"
                . "'feedback_besichtigung','nachfassen','link_opened','objekt_edit',"
                . "'hausverwaltung'"
                . ") NULL DEFAULT 'sonstiges'");
        }
        // SQLite: no-op; destructive down-rebuild not worth it.
    }
};
