<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->string('name', 120);
            $table->char('token', 43)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('token', 'idx_property_links_token');
            $table->index(['property_id', 'revoked_at', 'expires_at'], 'idx_property_links_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_links');
    }
};
