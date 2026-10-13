<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A manager's own notification switches: which of the owner's changes
        // at their branch they want to hear about (ManagerNotificationPrefs).
        // Empty means every switch is at its default (on).
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_prefs');
        });
    }
};
