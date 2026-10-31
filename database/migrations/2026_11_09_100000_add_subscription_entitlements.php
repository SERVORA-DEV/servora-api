<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Null preserves legacy terms. New catalog versions explicitly set these.
            $table->unsignedInteger('app_booking_limit')->nullable();
            $table->string('export_mode', 10)->nullable();
            $table->unsignedInteger('export_days')->nullable();
            $table->decimal('extra_branch_price', 12, 2)->nullable();
            $table->decimal('extra_account_price', 12, 2)->nullable();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('extra_branches')->default(0);
            $table->unsignedInteger('extra_accounts')->default(0);
            $table->decimal('base_paid', 12, 2)->nullable();
            $table->decimal('extras_paid', 12, 2)->default(0);
            $table->json('scheduled_extras')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['extra_branches', 'extra_accounts', 'base_paid', 'extras_paid', 'scheduled_extras']));
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn(['app_booking_limit', 'export_mode', 'export_days', 'extra_branch_price', 'extra_account_price']));
    }
};
