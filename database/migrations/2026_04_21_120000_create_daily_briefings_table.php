<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('briefing_date');
            $table->longText('data'); // JSON: {preview, narrative, threads, agenda, anomalies}
            $table->string('model_used')->nullable(); // z.B. 'claude-haiku-4-5' oder 'fallback'
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'briefing_date']);
            $table->index('briefing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefings');
    }
};
