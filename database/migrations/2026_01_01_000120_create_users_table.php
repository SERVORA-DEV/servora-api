<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The users table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->enum('role', ['system_administrator', 'business_owner', 'manager', 'front_officer', 'staff', 'client']);
            $table->string('username', 50)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('suffix', 20)->nullable();
            $table->enum('gender', ['Male', 'Female', 'Prefer not to say'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email');
            $table->string('password');
            $table->text('profile_photo')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->enum('account_status', ['Pending', 'Active', 'Inactive', 'Suspended'])->default('Pending');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('personal_email')->nullable();
            $table->timestamp('personal_email_verified_at')->nullable();
            $table->string('personal_email_otp_hash')->nullable();
            $table->timestamp('personal_email_otp_created_at')->nullable();
            $table->string('audience', 10);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->json('notification_prefs')->nullable();
            $table->timestamp('plan_hold_at')->nullable();
            $table->boolean('is_primary_admin')->default(false);
            $table->string('home_address', 500)->nullable();
            $table->string('home_address_notes', 500)->nullable();
            $table->decimal('home_latitude', 10, 7)->nullable();
            $table->decimal('home_longitude', 10, 7)->nullable();
            $table->unique(['email', 'audience'], 'users_email_audience_unique');
            $table->unique('personal_email', 'users_personal_email_unique');
            $table->unique('phone_number', 'users_phone_number_unique');
            $table->unique('username', 'users_username_unique');
            $table->unique('uuid', 'users_uuid_unique');
            $table->index(['role', 'account_status'], 'users_role_account_status_index');
        });

        DB::statement('CREATE UNIQUE INDEX users_one_primary_admin ON users (is_primary_admin) WHERE (is_primary_admin = true)');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
