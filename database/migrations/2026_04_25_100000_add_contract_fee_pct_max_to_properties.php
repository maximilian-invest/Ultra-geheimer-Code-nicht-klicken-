<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um `contract_fee_pct_max` — die Obergrenze fuer
 * Vertragserrichtungs-Kosten. In Oesterreich liegt der Anwaltsanteil
 * fuer den Kaufvertrag in der Regel zwischen 1,5 % und 2,0 %; der genaue
 * Wert haengt vom Kanzlei-Tarif und Komplexitaet ab und steht zum
 * Zeitpunkt der Exposé-Erstellung typischerweise noch nicht fest.
 *
 * Mit Min/Max kann der Makler eine Range pflegen ("1,5–2 %") und
 * Berechnungen koennen weiterhin funktionieren (Min als Default).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'contract_fee_pct_max')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->decimal('contract_fee_pct_max', 5, 2)->nullable()->after('contract_fee_pct');
            });
        }

        // One-time backfill: bestehende Properties mit dem Default-Wert 1,5
        // bekommen die Obergrenze 2,0, damit der Range-Default „1,5–2 %"
        // sofort wirkt. Properties mit individuell gepflegten Werten bleiben
        // unangetastet.
        \DB::table('properties')
            ->where('contract_fee_pct', 1.5)
            ->whereNull('contract_fee_pct_max')
            ->update(['contract_fee_pct_max' => 2.0]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'contract_fee_pct_max')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('contract_fee_pct_max');
            });
        }
    }
};
