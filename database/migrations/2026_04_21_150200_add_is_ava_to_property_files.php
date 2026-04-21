<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            $table->boolean('is_ava')->default(false)->after('is_website_download');
            $table->index('is_ava');
        });

        // Backfill: bestehende AVA-getaggte Dateien markieren
        DB::table('property_files')
            ->where(function ($q) {
                $q->where('label', 'like', '%Alleinvermittlungsauftrag%')
                  ->orWhere('label', 'like', '%AVA%')
                  ->orWhere('label', 'like', '%Alleinvermittler%');
            })
            ->update(['is_ava' => 1]);
    }

    public function down(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            $table->dropIndex(['is_ava']);
            $table->dropColumn('is_ava');
        });
    }
};
