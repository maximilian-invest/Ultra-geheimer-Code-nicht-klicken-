<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('contact_email');
            $table->string('stakeholder')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->enum('status', [
                'offen',
                'beantwortet',
                'nachfassen_1',
                'nachfassen_2',
                'nachfassen_3',
                'erledigt',
            ])->default('offen');
            $table->dateTime('first_contact_at')->nullable();
            $table->dateTime('last_inbound_at')->nullable();
            $table->dateTime('last_outbound_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('auto_replied_at')->nullable();
            $table->string('source_platform', 50)->nullable();
            $table->string('category', 50)->nullable();
            $table->integer('inbound_count')->default(0);
            $table->integer('outbound_count')->default(0);
            $table->integer('followup_count')->default(0);
            $table->text('draft_body')->nullable();
            $table->string('draft_subject', 500)->nullable();
            $table->string('draft_to')->nullable();
            $table->dateTime('draft_generated_at')->nullable();
            $table->unsignedBigInteger('last_email_id')->nullable();
            $table->unsignedBigInteger('last_activity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->unique(['contact_email', 'property_id']);
            $table->index('status');
            $table->index('property_id');
            $table->index('last_inbound_at');
            $table->index('stakeholder');

            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
