<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('email_accounts', 'user_id')) {
            Schema::table('email_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('is_active');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_accounts', 'user_id')) {
            Schema::table('email_accounts', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
