<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->date('activity_date');
            $table->string('stakeholder');
            $table->text('activity');
            $table->text('result')->nullable();
            $table->integer('duration')->nullable();
            $table->enum('category', [
                'email-in', 'email-out', 'expose', 'besichtigung',
                'kaufanbot', 'update', 'absage', 'sonstiges', 'anfrage'
            ])->default('sonstiges');
            $table->integer('source_email_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
