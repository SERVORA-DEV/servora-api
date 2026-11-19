<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The attendances table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendances')) {
            return;
        }

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('staff_id');
            $table->date('attendance_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->enum('status', ['Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday', 'Fill In'])->default('Present');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->uuid('uuid');
            $table->bigInteger('created_by')->nullable();
            $table->bigInteger('updated_by')->nullable();
            $table->boolean('left_early')->default(false);
            $table->bigInteger('covering_for_staff_id')->nullable();
            $table->string('scheduled_start', 5)->nullable();
            $table->string('scheduled_end', 5)->nullable();
            $table->integer('late_minutes')->nullable();
            $table->integer('work_minutes')->nullable();
            $table->integer('overtime_minutes')->nullable();
            $table->string('resolved_status', 30)->nullable();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->unique(['staff_id', 'attendance_date'], 'attendances_staff_date_unique');
            $table->unique('uuid', 'attendances_uuid_unique');
            $table->foreign('covering_for_staff_id', 'attendances_covering_for_staff_id_foreign')->references('id')->on('staff')->onDelete('set null');
            $table->foreign('created_by', 'attendances_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'attendances_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('staff_id', 'attendances_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
            $table->foreign('updated_by', 'attendances_updated_by_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
