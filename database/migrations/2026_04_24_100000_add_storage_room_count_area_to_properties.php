<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um storage_room_count + area_storage_room analog zu
 * basement_count/area_basement, damit im Exposé "Abstellraum" mit Anzahl
 * und m² angezeigt werden kann.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'storage_room_count')) {
                $table->unsignedSmallInteger('storage_room_count')->nullable()->after('has_storage_room');
            }
            if (!Schema::hasColumn('properties', 'area_storage_room')) {
                $table->decimal('area_storage_room', 8, 2)->nullable()->after('storage_room_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            foreach (['area_storage_room', 'storage_room_count'] as $col) {
                if (Schema::hasColumn('properties', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
