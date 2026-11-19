<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The email_verification_otps table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_verification_otps')) {
            return;
        }

        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->string('email');
            $table->string('audience', 10);
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->primary(['email', 'audience'], 'email_verification_otps_pkey');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_otps');
    }
};
