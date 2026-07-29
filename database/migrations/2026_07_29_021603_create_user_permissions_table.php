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

            // Administrative Accounts
            $table->boolean('admin_view')->default(false);
            $table->boolean('admin_create')->default(false);
            $table->boolean('admin_update')->default(false);
            $table->boolean('admin_delete')->default(false);

            $table->boolean('permission_update')->default(false);

            // Subscription Plans
            $table->boolean('subscription_plan_view')->default(false);
            $table->boolean('subscription_plan_create')->default(false);
            $table->boolean('subscription_plan_update')->default(false);
            $table->boolean('subscription_plan_delete')->default(false);

            // Spa Businesses
            $table->boolean('spa_business_view')->default(false);
            $table->boolean('spa_business_create')->default(false);
            $table->boolean('spa_business_update')->default(false);
            $table->boolean('spa_business_approve')->default(false);
            $table->boolean('spa_business_reject')->default(false);
            $table->boolean('spa_business_suspend')->default(false);

            // Branches
            $table->boolean('branch_view')->default(false);
            $table->boolean('branch_create')->default(false);
            $table->boolean('branch_update')->default(false);
            $table->boolean('branch_delete')->default(false);
            $table->boolean('branch_approve')->default(false);
            $table->boolean('branch_reject')->default(false);
            $table->boolean('branch_suspend')->default(false);

            // Services
            $table->boolean('service_view')->default(false);
            $table->boolean('service_create')->default(false);
            $table->boolean('service_update')->default(false);
            $table->boolean('service_delete')->default(false);

            // Packages
            $table->boolean('package_view')->default(false);
            $table->boolean('package_create')->default(false);
            $table->boolean('package_update')->default(false);
            $table->boolean('package_delete')->default(false);

            // Facilities
            $table->boolean('facility_view')->default(false);
            $table->boolean('facility_create')->default(false);
            $table->boolean('facility_update')->default(false);
            $table->boolean('facility_delete')->default(false);

            // Staff
            $table->boolean('staff_view')->default(false);
            $table->boolean('staff_create')->default(false);
            $table->boolean('staff_update')->default(false);
            $table->boolean('staff_delete')->default(false);

            // Attendance
            $table->boolean('attendance_view')->default(false);
            $table->boolean('attendance_create')->default(false);
            $table->boolean('attendance_update')->default(false);
            $table->boolean('attendance_delete')->default(false);

            // Commissions
            $table->boolean('commission_view')->default(false);
            $table->boolean('commission_update')->default(false);

            // Clients
            $table->boolean('client_view')->default(false);
            $table->boolean('client_create')->default(false);
            $table->boolean('client_update')->default(false);
            $table->boolean('client_delete')->default(false);

            // Appointments
            $table->boolean('appointment_view')->default(false);
            $table->boolean('appointment_create')->default(false);
            $table->boolean('appointment_update')->default(false);
            $table->boolean('appointment_confirm')->default(false);
            $table->boolean('appointment_checkin')->default(false);
            $table->boolean('appointment_complete')->default(false);
            $table->boolean('appointment_cancel')->default(false);

            // Queue
            $table->boolean('queue_view')->default(false);
            $table->boolean('queue_create')->default(false);
            $table->boolean('queue_update')->default(false);

            // Billings
            $table->boolean('billing_view')->default(false);
            $table->boolean('billing_create')->default(false);
            $table->boolean('billing_update')->default(false);
            $table->boolean('billing_delete')->default(false);

            // Payments
            $table->boolean('payment_view')->default(false);
            $table->boolean('payment_create')->default(false);
            $table->boolean('payment_refund')->default(false);

            // Rewards
            $table->boolean('reward_view')->default(false);
            $table->boolean('reward_create')->default(false);
            $table->boolean('reward_update')->default(false);
            $table->boolean('reward_delete')->default(false);

            // Reviews
            $table->boolean('review_view')->default(false);
            $table->boolean('review_hide')->default(false);
            $table->boolean('review_delete')->default(false);

            // Reports
            $table->boolean('report_view')->default(false);
            $table->boolean('report_export')->default(false);

            // Notifications
            $table->boolean('notification_view')->default(false);
            $table->boolean('notification_send')->default(false);

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
