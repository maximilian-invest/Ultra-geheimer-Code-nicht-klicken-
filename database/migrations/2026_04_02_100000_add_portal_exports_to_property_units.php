<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->json('portal_exports')->nullable()->after('notes');
            $table->string('immoji_id', 100)->nullable()->after('portal_exports');
        });
    }

    public function down(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->dropColumn(['portal_exports', 'immoji_id']);
        });
    }
};
