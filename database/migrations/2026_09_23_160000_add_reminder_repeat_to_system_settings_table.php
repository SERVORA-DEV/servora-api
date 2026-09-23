<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings > Billing: keep reminding an owner every N days until they pay
// (through the grace period), instead of a single reminder per subscription.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('almost_due_repeat_enabled')->default(true);
            $table->unsignedInteger('almost_due_repeat_every_days')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['almost_due_repeat_enabled', 'almost_due_repeat_every_days']);
        });
    }
};
