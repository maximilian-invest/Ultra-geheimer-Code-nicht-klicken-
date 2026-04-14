<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_documents', function (Blueprint $table) {
            $table->foreignId('property_link_id')->constrained('property_links')->onDelete('cascade');
            $table->unsignedBigInteger('property_file_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['property_link_id', 'property_file_id']);

            // NOTE: no FK to property_files because it's unsignedBigInteger here vs unsignedInteger there.
            // Integrity is enforced in PropertyLinkService via existence checks.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_documents');
    }
};
