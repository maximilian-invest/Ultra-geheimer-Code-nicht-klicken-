<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bricht den composite Primary Key (property_link_id, property_file_id) auf
 * property_link_documents auf und ersetzt ihn durch eine klassische
 * auto-increment `id`-Spalte. Danach wird property_file_id auf nullable
 * gesetzt, damit die Entweder/Oder-Semantik (file_id ODER expose_version_id)
 * umsetzbar ist.
 *
 * Die Idempotenz-Checks unten erlauben, dass diese Migration auch auf einer
 * frisch erzeugten Tabelle läuft (SQLite-Tests erzeugen die Tabelle in einer
 * jüngeren Migration; je nach Reihenfolge gibt es evtl. keinen composite PK
 * zu droppen).
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL: composite PK aufbrechen + id-Spalte FIRST einfügen als neuen PK.
            $hasIdColumn = Schema::hasColumn('property_link_documents', 'id');
            if (! $hasIdColumn) {
                // property_file_id ist Teil des composite PK — und gleichzeitig Target
                // einer outgoing FK auf property_files. MySQL verweigert den PK-Drop,
                // wenn die FK keinen anderen Index nutzen kann. Deshalb erst einen
                // normalen Index auf property_file_id anlegen, dann PK ersetzen.
                if (! $this->hasIndex('property_link_documents', 'plink_file_fk_idx')) {
                    DB::statement('ALTER TABLE property_link_documents ADD INDEX plink_file_fk_idx (property_file_id)');
                }
                // Gleiches für property_link_id — nach dem PK-Drop braucht dessen FK
                // einen eigenen Index.
                if (! $this->hasIndex('property_link_documents', 'plink_link_fk_idx')) {
                    DB::statement('ALTER TABLE property_link_documents ADD INDEX plink_link_fk_idx (property_link_id)');
                }

                // DROP PRIMARY KEY + ADD id AUTO_INCREMENT PK in einem Statement, damit
                // MySQL nicht zwischenzeitlich ohne PK steht.
                DB::statement('ALTER TABLE property_link_documents DROP PRIMARY KEY, ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
            }

            // property_file_id nullable machen. Erst jetzt möglich, nachdem der
            // composite PK gedroppt wurde.
            $col = DB::selectOne('SHOW COLUMNS FROM property_link_documents LIKE \'property_file_id\'');
            if ($col && $col->Null === 'NO') {
                DB::statement('ALTER TABLE property_link_documents MODIFY COLUMN property_file_id BIGINT UNSIGNED NULL');
            }

            // Unique-Index: dieselbe Datei soll nicht mehrfach im selben Link auftauchen.
            // Wir lassen NULL-Duplikate zu (MySQL default), damit mehrere Exposé-Rows
            // pro Link möglich sind (theoretisch). Zur Spec: wir haben eh höchstens 1.
            if (! $this->hasIndex('property_link_documents', 'plink_file_unique')) {
                Schema::table('property_link_documents', function (Blueprint $table) {
                    $table->unique(['property_link_id', 'property_file_id'], 'plink_file_unique');
                });
            }
        } else {
            // SQLite (Tests): kein composite PK Problem. Just make nullable if not yet.
            if (Schema::hasColumn('property_link_documents', 'property_file_id')) {
                // Auf SQLite funktioniert `change()` über doctrine/dbal — falls verfügbar.
                // Falls nicht, kein Blocker für Tests (SQLite erlaubt NULL inserts auch
                // ohne Schema-Change in den meisten Fällen weil Dynamic Typing).
                try {
                    Schema::table('property_link_documents', function (Blueprint $table) {
                        $table->unsignedBigInteger('property_file_id')->nullable()->change();
                    });
                } catch (\Throwable $e) {
                    // Best-effort: wenn doctrine/dbal fehlt, tolerieren wir das in Tests.
                }
            }
        }
    }

    public function down(): void
    {
        // Rollback nur für MySQL sinnvoll — und riskant (kann Daten verlieren, falls
        // property_file_id-NULL-Rows existieren). Bewusst als No-Op implementiert.
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$index]);
            return count($rows) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
