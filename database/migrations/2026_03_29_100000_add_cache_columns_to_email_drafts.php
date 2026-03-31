<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_drafts', function (Blueprint $table) {
            $table->string('thread_hash', 32)->nullable()->after('tone');
            $table->text('call_script')->nullable()->after('thread_hash');
            $table->string('preferred_action', 50)->nullable()->after('call_script');
            $table->string('lead_phase', 100)->nullable()->after('preferred_action');
            $table->string('mail_type', 100)->nullable()->after('lead_phase');
            $table->string('lead_status', 100)->nullable()->after('mail_type');
            $table->string('mail_goal', 255)->nullable()->after('lead_status');

            $table->index(['property_id', 'stakeholder', 'thread_hash'], 'drafts_cache_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('email_drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_cache_lookup');
            $table->dropColumn(['thread_hash', 'call_script', 'preferred_action', 'lead_phase', 'mail_type', 'lead_status', 'mail_goal']);
        });
    }
};
