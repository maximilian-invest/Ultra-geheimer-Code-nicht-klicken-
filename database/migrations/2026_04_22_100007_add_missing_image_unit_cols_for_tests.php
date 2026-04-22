<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bringt property_images / property_units / property_files test-SQLite-Schemas
 * auf den Stand der prod-MySQL-Spalten, die dort ueber Jahre per Raw-SQL (nicht
 * via Migration) dazugekommen sind. hasColumn-guarded -> no-op auf prod.
 *
 * Ermoeglicht WebsiteApiControllers Tests (property/{id} + properties list),
 * ohne prod-Schema anzufassen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_images')) {
            Schema::table('property_images', function (Blueprint $table) {
                if (!Schema::hasColumn('property_images', 'is_public')) {
                    $table->boolean('is_public')->default(true);
                }
                if (!Schema::hasColumn('property_images', 'category')) {
                    $table->string('category', 100)->nullable();
                }
                if (!Schema::hasColumn('property_images', 'title')) {
                    $table->string('title', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('property_units')) {
            Schema::table('property_units', function (Blueprint $table) {
                if (!Schema::hasColumn('property_units', 'images')) {
                    $table->json('images')->nullable();
                }
                if (!Schema::hasColumn('property_units', 'assigned_parking')) {
                    $table->json('assigned_parking')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // No-op — columns exist on prod via raw SQL, don't drop.
    }
};
