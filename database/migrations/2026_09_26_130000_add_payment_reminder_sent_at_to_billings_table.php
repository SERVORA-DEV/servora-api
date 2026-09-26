<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stamped by billings:send-payment-reminders so an unpaid bill is chased
    // at most once.
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->timestamp('payment_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_sent_at');
        });
    }
};
