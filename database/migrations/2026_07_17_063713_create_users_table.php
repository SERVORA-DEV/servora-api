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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->enum('role', [
                'system_administrator',
                'business_owner',
                'staff',
                'client'
            ]);

            $table->string('username', 50)->nullable()->unique();

            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('suffix', 20)->nullable();

            $table->enum('gender', [
                'Male',
                'Female',
                'Prefer not to say'
            ])->nullable();

            $table->date('birth_date')->nullable();

            $table->string('phone_number', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('email')->unique();

            $table->string('password');

            $table->longText('profile_photo')->nullable();

            $table->timestamp('email_verified_at')->nullable();

            $table->enum('account_status', [
                'Pending',
                'Active',
                'Inactive',
                'Suspended'
            ])->default('Pending');

            $table->timestamp('onboarding_completed_at')->nullable();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'account_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};