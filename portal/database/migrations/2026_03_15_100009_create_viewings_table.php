<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->date('viewing_date');
            $table->time('viewing_time')->nullable();
            $table->string('person_name');
            $table->string('person_email')->nullable();
            $table->string('person_phone', 50)->nullable();
            $table->enum('status', ['geplant', 'bestaetigt', 'abgesagt', 'durchgefuehrt'])->default('geplant');
            $table->text('notes')->nullable();
            $table->string('calendar_event_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewings');
    }
};
