<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('ref_id', 50)->unique();
            $table->string('address');
            $table->string('city', 100)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('type', 50)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('size_m2', 10, 2)->nullable();
            $table->decimal('rooms', 4, 1)->nullable();
            $table->integer('year_built')->nullable();
            $table->string('heating', 100)->nullable();
            $table->text('description')->nullable();
            $table->text('highlights')->nullable();
            $table->string('platforms')->nullable();
            $table->enum('status', [
                'auftrag', 'inserat', 'anfragen', 'besichtigungen',
                'angebote', 'verhandlung', 'verkauft'
            ])->default('auftrag');
            $table->date('inserat_since')->nullable();
            $table->decimal('area_living', 10, 2)->nullable();
            $table->decimal('area_land', 10, 2)->nullable();
            $table->integer('year_renovated')->nullable();
            $table->boolean('on_hold')->default(false);
            $table->text('on_hold_note')->nullable();
            $table->dateTime('on_hold_since')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
