<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add parking_spaces JSON to property_units so each unit (Einheit in einem
 * Neubauprojekt) kann ihre eigenen Stellplaetze fuehren — mit Art
 * (Tiefgarage / Carport / Außen / etc.), Anzahl, Flaeche, Fahrzeug-Eignung,
 * Beschreibung. Analog zu property.building_details.parking_spaces, aber
 * per Einheit statt am Master-Projekt.
 *
 * Das existierende assigned_parking-Feld bleibt unangetastet — das referen-
 * ziert weiterhin Parking-Unit-IDs (is_parking=1). parking_spaces ist ein
 * separates Konzept: strukturierte Stellplatz-Beschreibung pro Einheit, die
 * 1:1 zu Immojis buildingInput.parkingSpaces durchgereicht wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->json('parking_spaces')->nullable()->after('assigned_parking');
        });
    }

    public function down(): void
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->dropColumn('parking_spaces');
        });
    }
};
