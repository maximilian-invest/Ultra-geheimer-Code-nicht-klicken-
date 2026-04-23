<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt die Pivot-Tabelle `property_link_documents` um `expose_version_id`,
 * sodass ein Freigabelink nicht nur Property-Dateien, sondern auch eine
 * Exposé-Version als virtuelles Attachment referenzieren kann. Entweder
 * property_file_id ODER expose_version_id ist gesetzt — nie beide.
 * Zusätzlich wird property_file_id auf nullable gesetzt, damit die
 * Entweder/Oder-Semantik umsetzbar ist.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_link_documents', function (Blueprint $table) {
            $table->foreignId('expose_version_id')
                ->nullable()
                ->after('property_file_id')
                ->constrained('property_expose_versions')
                ->nullOnDelete();
            $table->unsignedBigInteger('property_file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('property_link_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expose_version_id');
        });
    }
};
