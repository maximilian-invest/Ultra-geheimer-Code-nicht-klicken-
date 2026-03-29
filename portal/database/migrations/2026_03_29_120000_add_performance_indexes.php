<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index('customer_id', 'idx_properties_customer_id');
            $table->index('ref_id', 'idx_properties_ref_id');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['property_id', 'activity_date'], 'idx_activities_property_date');
            $table->index('stakeholder', 'idx_activities_stakeholder');
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->index('user_id', 'idx_email_accounts_user_id');
        });

        Schema::table('portal_emails', function (Blueprint $table) {
            $table->index('account_id', 'idx_portal_emails_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('idx_properties_customer_id');
            $table->dropIndex('idx_properties_ref_id');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('idx_activities_property_date');
            $table->dropIndex('idx_activities_stakeholder');
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_email_accounts_user_id');
        });

        Schema::table('portal_emails', function (Blueprint $table) {
            $table->dropIndex('idx_portal_emails_account_id');
        });
    }
};
