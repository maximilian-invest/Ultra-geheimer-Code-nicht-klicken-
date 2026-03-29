<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->string('author_name');
            $table->enum('author_role', ['admin', 'customer'])->default('admin');
            $table->text('message');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });

        Schema::create('portal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('original_name');
            $table->integer('file_size');
            $table->string('mime_type', 100);
            $table->string('uploaded_by', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_documents');
        Schema::dropIfExists('portal_messages');
    }
};
