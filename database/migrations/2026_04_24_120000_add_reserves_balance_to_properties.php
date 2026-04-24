<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt properties um `reserves_balance` — der aktuelle Stand der
 * Instandhaltungsrücklage (Einmalbetrag in €). Wird bewusst NUR ins
 * Exposé ausgegeben, nicht auf die Website und nicht an Immoji/Portale,
 * da dies eine sensible Information für den Kaufinteressenten im
 * persönlichen Gespräch ist.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'reserves_balance')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->decimal('reserves_balance', 12, 2)->nullable()->after('maintenance_reserves');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'reserves_balance')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('reserves_balance');
            });
        }
    }
};
