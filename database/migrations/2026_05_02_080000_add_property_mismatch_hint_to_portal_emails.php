<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_emails', function (Blueprint $table) {
            // Wenn der Mail-Body eine Ref-ID erwaehnt, die zu einem ANDEREN
            // Objekt gehoert als das aktuell zugeordnete, speichern wir die
            // ref_id hier als "Hinweis: koennte falsch zugeordnet sein".
            // Wird in der Inbox als gelbes Banner ausgespielt mit einem
            // One-Click-Button zum Umhaengen.
            $table->string('property_mismatch_ref_id', 80)->nullable()->after('matched_ref_id');
            $table->index('property_mismatch_ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('portal_emails', function (Blueprint $table) {
            $table->dropIndex(['property_mismatch_ref_id']);
            $table->dropColumn('property_mismatch_ref_id');
        });
    }
};
