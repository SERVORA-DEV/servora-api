<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Staff is the source of truth for "who works at which branch" — a
    // Manager/Front Officer login account (see AccountService) is granted
    // to an existing staff row via user_id, rather than the account
    // carrying its own branch assignment. This is what
    // 2026_08_03_120000_move_branch_assignment_from_users_to_staff.php
    // removes users.spa_branch_id in favor of.
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnDelete();

            // Null until this staff member is granted a login (see
            // AccountService::createAccount) — a therapist, for instance,
            // never needs one. unique() so a login can only ever back one
            // staff row.
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();

            // Nullable — only required once this staff member is granted a
            // login (the account's email is theirs, not a separate field;
            // see AccountService::createAccount).
            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();

            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();

            // Job title — distinct from the login role stored on
            // users.role once an account exists (manager/frontdesk map to
            // manager/front_officer there; therapist never gets a login).
            $table->enum('role', ['therapist', 'manager', 'frontdesk']);

            $table->enum('employment_type', ['Full-Time', 'Part-Time', 'Contractual']);

            $table->enum('status', ['active', 'inactive', 'on-leave', 'terminated'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_branch_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
