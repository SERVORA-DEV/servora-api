<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Business Settings → Business Identity. business_name/email/phone/logo/
// description already exist (set during verification onboarding); these are
// the remaining fields that page edits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->string('legal_name', 150)->nullable()->after('business_name');

            // The kind of spa ("Day Spa", "Medical Spa", ...) shown on the
            // marketplace — not business_type, which is the legal structure
            // (Sole Proprietorship/Corporation/Partnership) used for
            // verification.
            $table->string('spa_type', 60)->nullable()->after('legal_name');
            $table->string('tagline', 160)->nullable()->after('spa_type');

            $table->string('head_office_address')->nullable()->after('business_phone');
            $table->string('facebook_url')->nullable()->after('head_office_address');
            $table->string('instagram_handle', 100)->nullable()->after('facebook_url');
            $table->string('website_url')->nullable()->after('instagram_handle');
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'spa_type',
                'tagline',
                'head_office_address',
                'facebook_url',
                'instagram_handle',
                'website_url',
            ]);
        });
    }
};
