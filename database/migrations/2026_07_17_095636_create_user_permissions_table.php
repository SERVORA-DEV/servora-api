<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Dashboard
            $table->boolean('dashboard_view')->default(false);

            // Administrative
            $table->boolean('admin_manage')->default(false);
            $table->boolean('permission_manage')->default(false);

            // Subscription
            $table->boolean('subscription_plan_manage')->default(false);
            $table->boolean('subscription_manage')->default(false);

            // Spa Management
            $table->boolean('spa_business_manage')->default(false);
            $table->boolean('spa_branch_manage')->default(false);

            // Staff & Workforce
            $table->boolean('staff_manage')->default(false);
            $table->boolean('attendance_manage')->default(false);

            // Services
            $table->boolean('service_manage')->default(false);
            $table->boolean('package_manage')->default(false);
            $table->boolean('facility_manage')->default(false);

            // Clients
            $table->boolean('client_manage')->default(false);

            // Reservations
            $table->boolean('appointment_manage')->default(false);
            $table->boolean('queue_manage')->default(false);

            // Financial
            $table->boolean('billing_manage')->default(false);
            $table->boolean('payment_manage')->default(false);
            $table->boolean('commission_manage')->default(false);

            // Loyalty
            $table->boolean('loyalty_manage')->default(false);
            $table->boolean('review_manage')->default(false);

            // Reports
            $table->boolean('report_view')->default(false);
            $table->boolean('report_export')->default(false);

            // Notifications
            $table->boolean('notification_manage')->default(false);

            // Audit Logs
            $table->boolean('audit_log_view')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};