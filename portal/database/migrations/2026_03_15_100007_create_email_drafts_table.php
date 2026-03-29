<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('to_email')->default('');
            $table->string('subject', 500)->default('');
            $table->text('body')->nullable();
            $table->foreignId('property_id')->nullable()->constrained()->onDelete('set null');
            $table->string('stakeholder')->default('');
            $table->integer('account_id')->nullable();
            $table->string('tone', 50)->default('professional');
            $table->integer('source_email_id')->nullable();
            $table->integer('imap_uid')->nullable();
            $table->string('imap_folder', 100)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_drafts');
    }
};
