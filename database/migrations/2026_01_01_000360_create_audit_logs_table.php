<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The audit_logs table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('table_name', 100);
            $table->bigInteger('record_id')->nullable();
            $table->enum('action', ['Create', 'Update', 'Delete', 'Login', 'Logout', 'Register', 'Verify Email', 'Reset Password', 'Change Password', 'Approve', 'Reject', 'Suspend', 'Restore', 'Assign', 'Unassign', 'Check In', 'Check Out', 'Complete', 'Cancel', 'Refund', 'Redeem', 'Export', 'Activate', 'Deactivate', 'Upload', 'Submit', 'Replace', 'Login Failed']);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->bigInteger('spa_business_id')->nullable();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->string('event', 80)->nullable();
            $table->string('area', 20)->nullable();
            $table->string('summary')->nullable();
            $table->string('subject_label', 160)->nullable();
            $table->string('actor_name', 160)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('actor_level', 20)->nullable();
            $table->string('branch_name', 160)->nullable();
            $table->index('action', 'audit_logs_action_index');
            $table->index('area', 'audit_logs_area_index');
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index('record_id', 'audit_logs_record_id_index');
            $table->index('spa_branch_id', 'audit_logs_spa_branch_id_index');
            $table->index(['spa_business_id', 'created_at'], 'audit_logs_spa_business_id_created_at_index');
            $table->index('table_name', 'audit_logs_table_name_index');
            $table->index('user_id', 'audit_logs_user_id_index');
            $table->foreign('spa_branch_id', 'audit_logs_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'audit_logs_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('set null');
            $table->foreign('user_id', 'audit_logs_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
