<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors password_reset_tokens' shape, kept as its own table since
        // this is registration verification, not password reset — reusing
        // password_reset_tokens would conflate two unrelated flows that
        // happen to share "email" as a key.
        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_otps');
    }
};
