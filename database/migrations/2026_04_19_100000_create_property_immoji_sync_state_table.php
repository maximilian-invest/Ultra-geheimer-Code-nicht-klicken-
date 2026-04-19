<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_immoji_sync_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('immoji_id', 64);
            $table->char('general_hash', 64)->nullable();
            $table->char('costs_hash', 64)->nullable();
            $table->char('areas_hash', 64)->nullable();
            $table->char('descriptions_hash', 64)->nullable();
            $table->char('building_hash', 64)->nullable();
            $table->char('files_signature', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('property_id');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_immoji_sync_state');
    }
};
