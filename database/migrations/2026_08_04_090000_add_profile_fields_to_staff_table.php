<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Rounds out the staff profile (Module 4). Deliberately does not touch
    // user_id — that link already exists from 2026_08_03_110000_create_staff_table.php.
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('employee_number', 50)->nullable()->after('spa_branch_id');
            $table->string('suffix', 20)->nullable()->after('middle_name');

            $table->date('birth_date')->nullable()->after('gender');
            $table->date('hire_date')->nullable()->after('birth_date');

            $table->string('profile_photo')->nullable()->after('phone_number');

            $table->string('emergency_contact_name', 150)->nullable()->after('status');
            $table->string('emergency_contact_number', 20)->nullable()->after('emergency_contact_name');

            $table->text('notes')->nullable()->after('emergency_contact_number');

            $table->unique(['spa_branch_id', 'employee_number']);
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropUnique(['spa_branch_id', 'employee_number']);

            $table->dropColumn([
                'employee_number',
                'suffix',
                'birth_date',
                'hire_date',
                'profile_photo',
                'emergency_contact_name',
                'emergency_contact_number',
                'notes',
            ]);
        });
    }
};
