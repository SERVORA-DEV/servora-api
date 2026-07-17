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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('spa_branch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('position', [
                'Manager',
                'Front Officer',
                'Therapist',
                'Cashier',
            ]);

            $table->enum('staff_type', [
                'Regular',
                'Part-time',
                'Contract',
                'Trainee',
            ]);

            $table->string('employee_number', 50);

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->enum('gender', [
                'Male',
                'Female',
            ]);

            $table->date('birth_date')->nullable();

            $table->string('phone_number', 20);
            $table->string('email')->nullable();

            $table->string('profile_photo')->nullable();

            $table->date('hire_date');

            $table->enum('employment_status', [
                'Active',
                'Inactive',
                'On Leave',
                'Resigned',
                'Terminated',
            ])->default('Active');

            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_number', 20)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spa_branch_id', 'employee_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};