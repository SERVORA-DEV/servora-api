<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-code wrong-guess counter so a 6-digit OTP can't be brute-forced by
// spreading guesses across IPs. UserService deletes the row once it hits
// MAX_OTP_ATTEMPTS, forcing the user to request a fresh code.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['password_reset_tokens', 'email_verification_otps'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedSmallInteger('attempts')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['password_reset_tokens', 'email_verification_otps'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('attempts');
            });
        }
    }
};
