<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_expose_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200)->nullable();
            $table->longText('config_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['property_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_expose_versions');
    }
};
