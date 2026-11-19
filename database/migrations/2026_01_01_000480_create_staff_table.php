<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff')) {
            return;
        }

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_branch_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->enum('role', ['therapist', 'manager', 'frontdesk']);
            $table->enum('employment_type', ['Full-Time', 'Part-Time', 'Contractual']);
            $table->enum('status', ['active', 'inactive', 'on-leave', 'terminated'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->string('employee_number', 50)->nullable();
            $table->string('suffix', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_number', 20)->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->json('specialties')->nullable();
            $table->decimal('commission_rate', 10, 2)->nullable();
            $table->bigInteger('commission_plan_id')->nullable();
            $table->boolean('home_service_available')->default(false);
            $table->unique(['spa_branch_id', 'employee_number'], 'staff_spa_branch_id_employee_number_unique');
            $table->unique('user_id', 'staff_user_id_unique');
            $table->unique('uuid', 'staff_uuid_unique');
            $table->index(['spa_branch_id', 'role'], 'staff_spa_branch_id_role_index');
            $table->foreign('commission_plan_id', 'staff_commission_plan_id_foreign')->references('id')->on('commission_plans')->onDelete('set null');
            $table->foreign('spa_branch_id', 'staff_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
            $table->foreign('user_id', 'staff_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
