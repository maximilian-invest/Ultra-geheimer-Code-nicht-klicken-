<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('match_reason')->nullable();
            $table->json('criteria_json')->nullable();
            $table->string('cross_match_intent', 20)->nullable();
            $table->enum('status', ['pending', 'selected', 'sent', 'dismissed'])->default('pending');
            $table->timestamps();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'status']);
            $table->unique(['conversation_id', 'property_id'], 'uq_conv_prop');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedSmallInteger('match_count')->default(0)->after('is_read');
            $table->boolean('match_dismissed')->default(false)->after('match_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_matches');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['match_count', 'match_dismissed']);
        });
    }
};
