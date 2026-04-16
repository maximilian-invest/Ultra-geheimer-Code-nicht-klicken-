<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_sender_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('pattern', 255);
            $table->enum('action', ['exclude_anfragen'])->default('exclude_anfragen');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_sender_rules');
    }
};

