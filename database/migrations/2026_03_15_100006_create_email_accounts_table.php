<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('email_address');
            $table->string('from_name')->default('SR-Homes');
            $table->string('imap_host');
            $table->integer('imap_port')->default(993);
            $table->enum('imap_encryption', ['ssl', 'tls', 'none'])->default('ssl');
            $table->string('imap_username');
            $table->string('imap_password');
            $table->string('smtp_host');
            $table->integer('smtp_port')->default(587);
            $table->enum('smtp_encryption', ['ssl', 'tls', 'none'])->default('tls');
            $table->string('smtp_username');
            $table->string('smtp_password');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_fetch_at')->nullable();
            $table->integer('last_uid')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
