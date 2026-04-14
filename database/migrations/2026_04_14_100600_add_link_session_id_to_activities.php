<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('link_session_id')->nullable()->after('source_email_id');
            $table->index('link_session_id', 'idx_activities_link_session');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL: modify the enum column definition in-place
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen','link_opened'"
                . ") DEFAULT 'sonstiges'");
        } elseif ($driver === 'sqlite') {
            // SQLite: recreate the table to extend the CHECK constraint on category.
            // We use the "12-step" rename-copy approach since SQLite doesn't support ALTER COLUMN.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("CREATE TABLE activities_new AS SELECT * FROM activities WHERE 0");
            // Drop the old CHECK constraint by recreating the table with an updated schema
            DB::statement("DROP TABLE activities_new");
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
                        'nachfassen','link_opened'
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
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('idx_activities_link_session');
            $table->dropColumn('link_session_id');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen'"
                . ") DEFAULT 'sonstiges'");
        }
    }
};
