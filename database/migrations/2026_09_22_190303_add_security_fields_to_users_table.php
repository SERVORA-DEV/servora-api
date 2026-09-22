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
        Schema::table('users', function (Blueprint $table) {
            // A separate address for account recovery/verification — deliberately
            // distinct from the main sign-in `email` column (see PersonalEmailRequest,
            // which enforces `different:email`).
            $table->string('personal_email')->nullable()->unique()->after('phone_verified_at');
            $table->timestamp('personal_email_verified_at')->nullable()->after('personal_email');

            // Pending OTP for the personal-email verify flow — same hash+expiry
            // technique as password_reset_tokens/email_verification_otps, but kept
            // on the user row instead of a shared email-keyed table since this flow
            // is always reached by an authenticated user (see SecurityService).
            $table->string('personal_email_otp_hash')->nullable()->after('personal_email_verified_at');
            $table->timestamp('personal_email_otp_created_at')->nullable()->after('personal_email_otp_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'personal_email',
                'personal_email_verified_at',
                'personal_email_otp_hash',
                'personal_email_otp_created_at',
            ]);
        });
    }
};
