<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_images', function (Blueprint $table) {
            $table->string('immoji_source', 255)->nullable()->after('sort_order');
        });
    }
    public function down(): void
    {
        Schema::table('property_images', function (Blueprint $table) {
            $table->dropColumn('immoji_source');
        });
    }
};
