<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Platform-wide configuration a system administrator can edit — a
    // singleton table (one row, created on first read via
    // SystemSetting::current()) rather than per-business, unlike
    // loyalty_settings.
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // Days after a subscription's expires_at before it's counted as
            // "Overdue" on the admin Transaction page — display/reporting
            // only, doesn't affect EnsureBusinessSubscribed's access cutoff.
            $table->unsignedInteger('subscription_grace_period_days')->default(7);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
