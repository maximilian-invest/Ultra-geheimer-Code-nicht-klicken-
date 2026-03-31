<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('completed_by')->nullable()->after('is_done');
            $table->timestamp('completed_at')->nullable()->after('completed_by');
        });
    }
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['completed_by', 'completed_at']);
        });
    }
};
