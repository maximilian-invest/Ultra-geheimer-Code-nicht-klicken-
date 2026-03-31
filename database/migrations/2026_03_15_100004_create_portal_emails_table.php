<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique()->nullable();
            $table->string('thread_id')->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('to_email');
            $table->string('subject', 500)->nullable();
            $table->mediumText('body_text')->nullable();
            $table->mediumText('body_html')->nullable();
            $table->boolean('has_attachment')->default(false);
            $table->text('attachment_names')->nullable();
            $table->dateTime('email_date');
            $table->foreignId('property_id')->nullable()->constrained()->onDelete('set null');
            $table->string('matched_ref_id', 50)->nullable();
            $table->string('stakeholder')->nullable();
            $table->string('category', 50)->nullable();
            $table->text('ai_summary')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->integer('imap_uid')->nullable();
            $table->string('imap_folder', 100)->default('INBOX');
            $table->integer('account_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'email_date']);
            $table->index(['stakeholder']);
            $table->index(['thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_emails');
    }
};
