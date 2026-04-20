<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drei neue Felder auf properties:
 *   - common_areas      : Freitext, Allgemeinräume (Keller, Fahrradraum,
 *                         Waschküche etc.). Wird auf der Website angezeigt,
 *                         aber NICHT zu Immoji gepusht.
 *   - has_photovoltaik  : Boolean, PV-Anlage vorhanden. Nur Website.
 *   - has_charging_station : Boolean, E-Auto-Ladestation. Nur Website.
 *
 * Alle drei sind 'website-only' — der ImmojiUploadService ignoriert sie
 * bewusst, weil Immoji dafuer entweder kein Feld hat oder wir den Inhalt
 * nicht strukturiert an die Portale durchreichen wollen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('common_areas')->nullable()->after('condition_note');
            $table->boolean('has_photovoltaik')->default(false)->after('has_sauna');
            $table->boolean('has_charging_station')->default(false)->after('has_photovoltaik');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['common_areas', 'has_photovoltaik', 'has_charging_station']);
        });
    }
};
