<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A spa's staff can take a client's profile picture down on their side only
// (the picture is the client's own, on their account, shared with every spa and
// their app). `dismissed_photo` keeps the picture that was hidden, so the same
// one stays hidden and a newer upload shows again. Until this has run nothing
// is hidden (App\Models\Client::hasPhotoDismissal).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->longText('dismissed_photo')->nullable()->after('notes');
            $table->timestamp('photo_dismissed_at')->nullable()->after('dismissed_photo');
        });

        Cache::forget('schema:client_photo_dismissal');
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['dismissed_photo', 'photo_dismissed_at']);
        });

        Cache::forget('schema:client_photo_dismissal');
    }
};
