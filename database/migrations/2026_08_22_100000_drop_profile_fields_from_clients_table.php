<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // clients was duplicating a full profile shape (middle_name/suffix/
    // gender/birth_date/profile_photo) that already exists on `users` for
    // anyone who actually has a Servora account (clients.user_id links to
    // it). Trimmed to simple operational attributes — name, contact, notes,
    // loyalty points — so registering a walk-in with no account stays a
    // quick, minimal front-desk step. An account holder's fuller profile is
    // still available, just read through the user() relation instead of
    // duplicated here (see ClientResource's `account` block).
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['middle_name', 'suffix', 'gender', 'birth_date', 'profile_photo']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('suffix', 20)->nullable()->after('last_name');
            $table->enum('gender', ['Male', 'Female'])->nullable()->after('suffix');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('profile_photo')->nullable()->after('email');
        });
    }
};
