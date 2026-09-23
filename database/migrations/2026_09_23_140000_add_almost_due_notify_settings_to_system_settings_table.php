<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('almost_due_notify_enabled')->default(true);
            $table->unsignedInteger('almost_due_notify_days_before')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['almost_due_notify_enabled', 'almost_due_notify_days_before']);
        });
    }
};
