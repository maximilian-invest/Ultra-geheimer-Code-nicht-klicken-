<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_managers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('address_street')->nullable();
            $table->string('address_zip', 20)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('email');
            $table->string('phone', 100)->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_name');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_managers');
    }
};
