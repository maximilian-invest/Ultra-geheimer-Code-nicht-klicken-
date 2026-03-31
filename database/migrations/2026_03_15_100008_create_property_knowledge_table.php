<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->enum('category', [
                'objektbeschreibung', 'ausstattung', 'lage_umgebung',
                'preis_markt', 'rechtliches', 'energetik',
                'feedback_positiv', 'feedback_negativ', 'feedback_besichtigung',
                'verhandlung', 'eigentuemer_info', 'vermarktung',
                'dokument_extrakt', 'sonstiges'
            ]);
            $table->string('title');
            $table->text('content');
            $table->enum('source_type', [
                'email_ingest', 'email_out', 'document', 'manual', 'ai_extract', 'expose'
            ]);
            $table->integer('source_id')->nullable();
            $table->string('source_description', 500)->nullable();
            $table->enum('confidence', ['high', 'medium', 'low'])->default('medium');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('expires_at')->nullable();
            $table->string('created_by', 100)->default('system');
            $table->integer('mention_count')->default(0);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->timestamps();

            $table->index('property_id');
            $table->index(['property_id', 'category']);
            $table->index(['property_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_knowledge');
    }
};
