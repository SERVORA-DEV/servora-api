<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The password_reset_tokens table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email');
            $table->string('audience', 10);
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->primary(['email', 'audience'], 'password_reset_tokens_pkey');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
