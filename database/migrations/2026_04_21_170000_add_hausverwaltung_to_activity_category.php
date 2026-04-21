<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM(
            'email-in','email-out','expose','besichtigung','kaufanbot','update',
            'absage','sonstiges','anfrage','eigentuemer','partner','bounce',
            'intern','makler','feedback_positiv','feedback_negativ',
            'feedback_besichtigung','nachfassen','link_opened','objekt_edit',
            'hausverwaltung'
        ) NULL DEFAULT 'sonstiges'");
    }

    public function down(): void
    {
        DB::statement("UPDATE activities SET category = 'sonstiges' WHERE category = 'hausverwaltung'");
        DB::statement("ALTER TABLE activities MODIFY COLUMN category ENUM(
            'email-in','email-out','expose','besichtigung','kaufanbot','update',
            'absage','sonstiges','anfrage','eigentuemer','partner','bounce',
            'intern','makler','feedback_positiv','feedback_negativ',
            'feedback_besichtigung','nachfassen','link_opened','objekt_edit'
        ) NULL DEFAULT 'sonstiges'");
    }
};
