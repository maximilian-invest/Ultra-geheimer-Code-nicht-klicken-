<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_published');
            }
            if (!Schema::hasColumn('properties', 'featured_order')) {
                // Niedrigere Zahl = weiter vorne. NULL = nicht sortiert (ans Ende).
                $table->unsignedSmallInteger('featured_order')->nullable()->after('is_featured');
            }
            if (!Schema::hasColumn('properties', 'badge')) {
                // Freitext-Label fuer Card-Overlay auf der Website.
                // Typische Werte: "NEU", "EXKLUSIV", "REDUZIERT", "TOP", "DEMNAECHST"
                $table->string('badge', 30)->nullable()->after('featured_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'featured_order', 'badge']);
        });
    }
};
