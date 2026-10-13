<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings > Marketplace Policy: which "listing polish" items a branch must
// have before it is shown in the client app (see App\Support\ListingPolicy).
// Null means the built-in defaults, so nothing changes for a platform that
// has never opened the page.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->json('listing_policy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('listing_policy');
        });
    }
};
