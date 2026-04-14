<?php
// database/migrations/2026_03_15_100012_create_legacy_tables_for_testing.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('property_files')) {
            Schema::create('property_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('property_id');
                $table->string('label', 100)->default('');
                $table->string('filename', 500);
                $table->string('path', 500);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedInteger('file_size')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_website_download')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->index('property_id', 'idx_property_id');
            });
        }

        if (!Schema::hasTable('property_images')) {
            Schema::create('property_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('property_id');
                $table->string('filename', 500);
                $table->string('original_name', 500)->nullable();
                $table->string('path', 500);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedInteger('file_size')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_title_image')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('property_id');
            });
        }

        if (!Schema::hasTable('property_units')) {
            Schema::create('property_units', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('property_id');
                $table->string('unit_number', 50)->nullable();
                $table->string('unit_type', 100)->nullable();
                $table->decimal('rooms', 4, 1)->nullable();
                $table->decimal('area_m2', 10, 2)->nullable();
                $table->integer('floor')->nullable()->default(0);
                $table->decimal('price', 12, 2)->nullable();
                $table->string('status', 30)->default('frei');
                $table->decimal('balcony_terrace_m2', 10, 2)->nullable();
                $table->decimal('garden_m2', 10, 2)->nullable();
                $table->boolean('is_parking')->default(false);
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('property_id');
            });
        }

        if (!Schema::hasTable('activities')) {
            Schema::create('activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained()->onDelete('cascade');
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->date('activity_date');
                $table->string('stakeholder');
                $table->text('activity');
                $table->text('result')->nullable();
                $table->integer('duration')->nullable();
                $table->string('category', 40)->default('sonstiges');
                $table->tinyInteger('followup_stage')->nullable();
                $table->integer('source_email_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->dateTime('snooze_until')->nullable();
                $table->boolean('viewing_alert_dismissed')->default(false);
                $table->string('kaufanbot_status', 30)->nullable();
                $table->index(['property_id', 'activity_date']);
                $table->index('stakeholder');
            });
        }
    }

    public function down(): void
    {
        // No-op — we never drop legacy tables.
    }
};
