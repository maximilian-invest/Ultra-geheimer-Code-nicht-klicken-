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

        // Extend the category enum only on MySQL. SQLite already stores any string.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM("
                . "'email-in','email-out','expose','besichtigung','kaufanbot',"
                . "'update','absage','sonstiges','anfrage','eigentuemer','partner','bounce',"
                . "'intern','makler','feedback_positiv','feedback_negativ','feedback_besichtigung',"
                . "'nachfassen','link_opened'"
                . ") DEFAULT 'sonstiges'");
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
