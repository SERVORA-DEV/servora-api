<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Whether the email an owner or manager puts in Settings (the business's, a
// branch's) has been confirmed by clicking the button in the email Servora sent
// to it. An email that is the owner's own sign-up address never needs it.
//
// Emails already on file are taken as confirmed (they were entered during the
// registration review). Until this has been run no email is held to it
// (App\Support\ContactEmail::ready).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->timestamp('business_email_verified_at')->nullable();
        });
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
        });

        DB::table('spa_businesses')->whereNotNull('business_email')->update(['business_email_verified_at' => now()]);
        DB::table('spa_branches')->whereNotNull('email')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropColumn('business_email_verified_at');
        });
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
