<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt die Pivot-Tabelle `property_link_documents` um `expose_version_id`,
 * sodass ein Freigabelink nicht nur Property-Dateien, sondern auch eine
 * Exposé-Version als virtuelles Attachment referenzieren kann. Entweder
 * property_file_id ODER expose_version_id ist gesetzt — nie beide.
 *
 * Die Umschaltung auf nullable für property_file_id + Einführung eines
 * Auto-Increment-Primary-Keys (composite PK aufbrechen) passiert in der
 * Folge-Migration 2026_04_23_140250_fix_property_link_documents_pk.php.
 * Hier nur die neue Spalte (idempotent) — damit die Migration auch dann
 * weiterlaufen kann, wenn die Spalte nach Teil-Ausführung bereits existiert.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('property_link_documents', 'expose_version_id')) {
            Schema::table('property_link_documents', function (Blueprint $table) {
                $table->foreignId('expose_version_id')
                    ->nullable()
                    ->after('property_file_id')
                    ->constrained('property_expose_versions')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('property_link_documents', 'expose_version_id')) {
            Schema::table('property_link_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('expose_version_id');
            });
        }
    }
};
