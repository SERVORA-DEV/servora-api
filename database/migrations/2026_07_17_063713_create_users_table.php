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
                'Female'
            ])->nullable();

            $table->date('birth_date')->nullable();

            $table->string('phone_number', 20)->nullable()->unique();
            $table->string('email')->unique();

            $table->string('password');

            $table->string('profile_photo')->nullable();

            $table->timestamp('email_verified_at')->nullable();

            $table->enum('account_status', [
                'Pending',
                'Active',
                'Inactive',
                'Suspended'
            ])->default('Pending');

            $table->rememberToken();

            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
            $table->index('account_status');
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