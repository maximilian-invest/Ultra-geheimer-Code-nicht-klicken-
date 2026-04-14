<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('property_link_sessions')->onDelete('cascade');
            $table->unsignedBigInteger('property_file_id')->nullable();
            $table->string('event_type', 20); // link_opened | doc_viewed | doc_downloaded
            $table->unsignedInteger('duration_s')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id', 'idx_property_link_events_session');
            $table->index('created_at', 'idx_property_link_events_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_events');
    }
};
