<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The user_permissions table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->boolean('dashboard_view')->default(false);
            $table->boolean('admin_view')->default(false);
            $table->boolean('admin_create')->default(false);
            $table->boolean('admin_update')->default(false);
            $table->boolean('admin_delete')->default(false);
            $table->boolean('permission_update')->default(false);
            $table->boolean('subscription_plan_view')->default(false);
            $table->boolean('subscription_plan_create')->default(false);
            $table->boolean('subscription_plan_update')->default(false);
            $table->boolean('subscription_plan_delete')->default(false);
            $table->boolean('spa_business_view')->default(false);
            $table->boolean('spa_business_create')->default(false);
            $table->boolean('spa_business_update')->default(false);
            $table->boolean('spa_business_approve')->default(false);
            $table->boolean('spa_business_reject')->default(false);
            $table->boolean('spa_business_suspend')->default(false);
            $table->boolean('branch_view')->default(false);
            $table->boolean('branch_create')->default(false);
            $table->boolean('branch_update')->default(false);
            $table->boolean('branch_delete')->default(false);
            $table->boolean('branch_approve')->default(false);
            $table->boolean('branch_reject')->default(false);
            $table->boolean('branch_suspend')->default(false);
            $table->boolean('service_view')->default(false);
            $table->boolean('service_create')->default(false);
            $table->boolean('service_update')->default(false);
            $table->boolean('service_delete')->default(false);
            $table->boolean('package_view')->default(false);
            $table->boolean('package_create')->default(false);
            $table->boolean('package_update')->default(false);
            $table->boolean('package_delete')->default(false);
            $table->boolean('facility_view')->default(false);
            $table->boolean('facility_create')->default(false);
            $table->boolean('facility_update')->default(false);
            $table->boolean('facility_delete')->default(false);
            $table->boolean('staff_view')->default(false);
            $table->boolean('staff_create')->default(false);
            $table->boolean('staff_update')->default(false);
            $table->boolean('staff_delete')->default(false);
            $table->boolean('attendance_view')->default(false);
            $table->boolean('attendance_create')->default(false);
            $table->boolean('attendance_update')->default(false);
            $table->boolean('attendance_delete')->default(false);
            $table->boolean('commission_view')->default(false);
            $table->boolean('commission_update')->default(false);
            $table->boolean('client_view')->default(false);
            $table->boolean('client_create')->default(false);
            $table->boolean('client_update')->default(false);
            $table->boolean('client_delete')->default(false);
            $table->boolean('appointment_view')->default(false);
            $table->boolean('appointment_create')->default(false);
            $table->boolean('appointment_update')->default(false);
            $table->boolean('appointment_checkin')->default(false);
            $table->boolean('appointment_complete')->default(false);
            $table->boolean('appointment_cancel')->default(false);
            $table->boolean('queue_view')->default(false);
            $table->boolean('queue_create')->default(false);
            $table->boolean('queue_update')->default(false);
            $table->boolean('billing_view')->default(false);
            $table->boolean('billing_create')->default(false);
            $table->boolean('billing_update')->default(false);
            $table->boolean('billing_delete')->default(false);
            $table->boolean('payment_view')->default(false);
            $table->boolean('payment_create')->default(false);
            $table->boolean('payment_refund')->default(false);
            $table->boolean('reward_view')->default(false);
            $table->boolean('reward_create')->default(false);
            $table->boolean('reward_update')->default(false);
            $table->boolean('reward_delete')->default(false);
            $table->boolean('review_view')->default(false);
            $table->boolean('review_hide')->default(false);
            $table->boolean('review_delete')->default(false);
            $table->boolean('report_view')->default(false);
            $table->boolean('report_export')->default(false);
            $table->boolean('notification_view')->default(false);
            $table->boolean('notification_send')->default(false);
            $table->boolean('audit_log_view')->default(false);
            $table->timestamps();
            $table->boolean('client_therapist_manage')->default(false);
            $table->boolean('attendance_checkin')->default(false);
            $table->boolean('service_template_view')->default(false);
            $table->boolean('service_template_create')->default(false);
            $table->boolean('service_template_update')->default(false);
            $table->boolean('service_template_delete')->default(false);
            $table->boolean('setting_update')->default(false);
            $table->boolean('policy_override')->default(false);
            $table->boolean('payment_adjust')->default(false);
            $table->unique('user_id', 'user_permissions_user_id_unique');
            $table->foreign('user_id', 'user_permissions_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
