<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The one_time_codes table as it stands (one file per table): the emailed codes a person types in
// (App\Support\Access\OneTimeCodes) — the sign-up email check and the forgotten-password reset, one
// row per purpose, email and audience. It replaces email_verification_otps and
// password_reset_tokens: any code still in those is copied over, so one in use while this runs
// still works. Those two tables are left as they are until a later migration drops them.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('one_time_codes')) {
            Schema::create('one_time_codes', function (Blueprint $table) {
                $table->string('purpose', 32);
                $table->string('email');
                $table->string('audience', 10);
                $table->string('code_hash');
                $table->smallInteger('attempts')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->primary(['purpose', 'email', 'audience'], 'one_time_codes_pkey');
            });
        }

        $from = [
            'email_verification' => ['email_verification_otps', 'otp'],
            'password_reset' => ['password_reset_tokens', 'token'],
        ];
        foreach ($from as $purpose => [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            // A row already copied (this run twice) is left as it is.
            DB::statement(
                "insert into one_time_codes (purpose, email, audience, code_hash, attempts, created_at)
                 select ?, email, audience, {$column}, attempts, created_at from {$table}
                 on conflict do nothing",
                [$purpose],
            );
        }
    }

    public function down(): void
    {
        // The two old tables were never touched: they still hold what they held.
        Schema::dropIfExists('one_time_codes');
    }
};
