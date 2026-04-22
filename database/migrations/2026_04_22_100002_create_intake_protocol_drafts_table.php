<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_protocol_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('users');
            $table->string('draft_key', 100);
            $table->longText('form_data');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->timestamp('last_saved_at')->useCurrent();
            $table->timestamps();
            $table->unique(['broker_id', 'draft_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_protocol_drafts');
    }
};
