<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_schedule_change_requests table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_schedule_change_requests')) {
            return;
        }

        Schema::create('staff_schedule_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('staff_id');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('spa_branch_id')->nullable();
            $table->bigInteger('requested_by_user_id')->nullable();
            $table->json('days');
            $table->string('status', 20)->default('pending');
            $table->bigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->unique('uuid', 'staff_schedule_change_requests_uuid_unique');
            $table->index(['spa_business_id', 'status'], 'staff_schedule_change_requests_spa_business_id_status_index');
            $table->index(['staff_id', 'status'], 'staff_schedule_change_requests_staff_id_status_index');
            $table->foreign('decided_by_user_id', 'staff_schedule_change_requests_decided_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('requested_by_user_id', 'staff_schedule_change_requests_requested_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'staff_schedule_change_requests_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'staff_schedule_change_requests_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('staff_id', 'staff_schedule_change_requests_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedule_change_requests');
    }
};
