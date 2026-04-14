<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            if (!Schema::hasColumn('property_files', 'is_website_download')) {
                $table->boolean('is_website_download')->default(false)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_files', function (Blueprint $table) {
            $table->dropColumn('is_website_download');
        });
    }
};
