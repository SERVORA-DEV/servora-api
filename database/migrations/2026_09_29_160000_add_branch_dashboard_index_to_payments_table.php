<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner/manager and front-desk dashboards (Business\DashboardService,
// FrontOfficeDashboardService) sum a branch's paid payments over a date
// range. The existing payments indexes lead with spa_business_id or
// payment_status, so the branch filter couldn't use them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['spa_branch_id', 'payment_status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['spa_branch_id', 'payment_status', 'paid_at']);
        });
    }
};
