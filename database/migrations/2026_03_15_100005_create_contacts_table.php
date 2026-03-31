<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->json('aliases')->nullable();
            $table->json('property_ids')->nullable();
            $table->string('source', 100)->nullable();
            $table->text('notes')->nullable();
            $table->enum('role', [
                'kunde', 'partner', 'bautraeger', 'intern', 'makler', 'eigentuemer'
            ])->default('kunde');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
