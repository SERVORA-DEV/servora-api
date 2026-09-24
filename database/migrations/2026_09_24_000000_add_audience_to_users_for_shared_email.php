<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The same email may now back one owner-side account ('web': business
    // owner, manager, front officer, admin) AND one client account ('mobile'),
    // each with its own password. Uniqueness is per (email, audience).
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('audience', 10)->nullable()->after('role');
        });

        DB::table('users')->where('role', 'client')->update(['audience' => 'mobile']);
        DB::table('users')->where('role', '!=', 'client')->update(['audience' => 'web']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('audience', 10)->nullable(false)->change();
            $table->dropUnique('users_email_unique');
            $table->unique(['email', 'audience']);
        });

        // Both are short-lived OTP tables keyed by email alone, which would
        // let an owner's reset/verification code collide with a client's.
        // Pending codes are simply dropped; users can request a new one.
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email');
            $table->string('audience', 10);
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->primary(['email', 'audience']);
        });

        Schema::dropIfExists('email_verification_otps');
        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->string('email');
            $table->string('audience', 10);
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
            $table->primary(['email', 'audience']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_otps');
        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email', 'audience']);
            $table->unique('email');
            $table->dropColumn('audience');
        });
    }
};
