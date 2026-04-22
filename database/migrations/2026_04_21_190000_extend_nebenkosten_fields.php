<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Neues Feld: Pfandrecht-Eintragung in Prozent (Standard: 1,2% vom Hypothekenbetrag)
            if (!Schema::hasColumn('properties', 'mortgage_register_fee_pct')) {
                $table->decimal('mortgage_register_fee_pct', 5, 2)->nullable()->after('contract_fee_pct');
            }
            // Notizfeld fuer Nebenkosten (z.B. "inkl. Notar", "Vertrag durch Dr. Mustermann")
            if (!Schema::hasColumn('properties', 'nebenkosten_note')) {
                $table->text('nebenkosten_note')->nullable()->after('mortgage_register_fee_pct');
            }
            // Anzeige der Nebenkosten-Box auf der Website aktivieren (Default: an)
            if (!Schema::hasColumn('properties', 'show_nebenkosten_on_website')) {
                $table->boolean('show_nebenkosten_on_website')->default(true)->after('nebenkosten_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['mortgage_register_fee_pct', 'nebenkosten_note', 'show_nebenkosten_on_website']);
        });
    }
};
