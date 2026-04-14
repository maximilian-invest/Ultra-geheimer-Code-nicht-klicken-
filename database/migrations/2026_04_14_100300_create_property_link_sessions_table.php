<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_link_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_link_id')->constrained('property_links')->onDelete('cascade');
            $table->string('email');
            $table->timestamp('dsgvo_accepted_at');
            $table->char('ip_hash', 64);
            $table->char('user_agent_hash', 64);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['property_link_id', 'email'], 'idx_property_link_sessions_link_email');
            $table->index('last_seen_at', 'idx_property_link_sessions_last_seen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_link_sessions');
    }
};
