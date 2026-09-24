<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Branch Settings (owner web): the per-branch values that until now only
// lived in the browser.
//
// - Social links (Branch Details).
// - Marketplace listing: whether the branch is listed at all, the promo
//   banner, highlight tags and client-app display options.
// - booking_overrides: the rules this branch sets instead of following
//   Business Settings → Business Defaults (key → value; absent = follow).
// - spa_branch_photos: the listing's photo gallery. The photo marked as
//   cover is the branch's cover image everywhere; with no gallery the
//   older spa_branches.cover_photo is used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->string('facebook_url')->nullable()->after('phone_number');
            $table->string('instagram_handle', 100)->nullable()->after('facebook_url');
            $table->string('website_url')->nullable()->after('instagram_handle');

            $table->boolean('listing_visible')->default(true)->after('operating_status');
            $table->string('promo_text', 80)->nullable()->after('listing_visible');
            $table->json('highlights')->nullable()->after('promo_text');
            $table->json('display_settings')->nullable()->after('highlights');
            $table->json('booking_overrides')->nullable()->after('display_settings');
        });

        Schema::create('spa_branch_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['spa_branch_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_branch_photos');

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropColumn([
                'facebook_url', 'instagram_handle', 'website_url',
                'listing_visible', 'promo_text', 'highlights', 'display_settings', 'booking_overrides',
            ]);
        });
    }
};
