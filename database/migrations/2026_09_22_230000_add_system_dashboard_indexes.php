<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Indexes for the platform-wide queries behind the system admin dashboard
    // (App\Service\System\DashboardService). The existing indexes on these
    // tables all lead with spa_business_id / spa_branch_id, which the
    // platform-wide filters don't use, so every one of these was a seq scan.
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['payment_status', 'paid_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['starts_at', 'expires_at']);
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->index('verification_status');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->index(['billing_type', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'paid_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['starts_at', 'expires_at']);
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex(['billing_type', 'issued_at']);
        });
    }
};
