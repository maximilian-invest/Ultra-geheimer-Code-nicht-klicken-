<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend users.user_type enum with 'customer' — required for portal accounts
 * created via the Aufnahmeprotokoll wizard (Task 9). Pre-existing code in
 * AiChatController::toolCreatePortalAccess bereits 'customer' als Wert
 * verwendet; diese Migration bringt das Schema in Einklang mit dem Code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM("
                . "'admin','makler','backoffice','eigentuemer','customer'"
                . ") NOT NULL DEFAULT 'makler'");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite erzwingt enum via CHECK constraint; Tabelle neu aufbauen.
            // Wir inspizieren die aktuellen Spalten via PRAGMA, damit die
            // Migration egal welche Spalten-Kombination vorfindet — test-env
            // vs prod-Replay könnten sich unterscheiden.
            $cols = DB::select('PRAGMA table_info(users)');
            $colDefs = [];
            $colNames = [];
            foreach ($cols as $c) {
                $name = $c->name;
                $type = $c->type ?: 'VARCHAR';
                $nn = $c->notnull ? ' NOT NULL' : '';
                $default = $c->dflt_value !== null ? ' DEFAULT ' . $c->dflt_value : '';
                $pk = $c->pk ? ' PRIMARY KEY AUTOINCREMENT' : '';

                if ($name === 'user_type') {
                    // Neue CHECK-Constraint mit 'customer'
                    $colDefs[] = "user_type VARCHAR NOT NULL DEFAULT 'makler' "
                        . "CHECK (user_type IN ('admin','makler','backoffice','eigentuemer','customer'))";
                } elseif ($c->pk) {
                    $colDefs[] = "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
                } else {
                    $colDefs[] = "{$name} {$type}{$nn}{$default}";
                }
                $colNames[] = $name;
            }

            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE users_new (' . implode(', ', $colDefs) . ')');
            $colList = implode(', ', $colNames);
            DB::statement("INSERT INTO users_new ({$colList}) SELECT {$colList} FROM users");
            DB::statement('DROP TABLE users');
            DB::statement('ALTER TABLE users_new RENAME TO users');
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users(email)');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("UPDATE users SET user_type = 'eigentuemer' WHERE user_type = 'customer'");
            DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM("
                . "'admin','makler','backoffice','eigentuemer'"
                . ") NOT NULL DEFAULT 'makler'");
        }
        // No-op on sqlite; destructive rebuild not worth it for down-migration.
    }
};
