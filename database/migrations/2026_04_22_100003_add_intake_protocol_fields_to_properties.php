<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('encumbrances')->nullable()->after('property_manager_id');
            $table->enum('parking_assignment', ['assigned', 'shared'])->nullable()->after('parking_type');
            $table->json('documents_available')->nullable()->after('encumbrances');
            $table->enum('approvals_status', ['complete', 'partial', 'unknown'])->nullable()->after('documents_available');
            $table->text('approvals_notes')->nullable()->after('approvals_status');
            $table->text('internal_notes')->nullable()->after('approvals_notes');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'encumbrances',
                'parking_assignment',
                'documents_available',
                'approvals_status',
                'approvals_notes',
                'internal_notes',
            ]);
        });
    }
};
