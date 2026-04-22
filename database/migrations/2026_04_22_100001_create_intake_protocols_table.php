<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_id')->constrained('users');
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name', 200)->nullable();
            $table->string('signature_png_path', 500)->nullable();
            $table->text('disclaimer_text');
            $table->string('pdf_path', 500)->nullable();
            $table->timestamp('owner_email_sent_at')->nullable();
            $table->timestamp('portal_email_sent_at')->nullable();
            $table->boolean('portal_access_granted')->default(false);
            $table->text('broker_notes')->nullable();
            $table->json('open_fields')->nullable();
            $table->longText('form_snapshot')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index('property_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_protocols');
    }
};
