<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stamped by the subscriptions:notify-almost-due scheduled command once
    // it sends the reminder for this subscription, so a daily run doesn't
    // re-notify the same subscription every day it stays within the window.
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_sent_at');
        });
    }
};
