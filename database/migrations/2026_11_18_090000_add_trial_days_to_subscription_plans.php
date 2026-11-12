<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->nullable();
        });
        // Retain each existing offer's configured duration before making it editable.
        DB::table('subscription_plans')->where('category', 'Trial')
            ->update(['trial_days' => max(1, (int) config('trial.days', 14))]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn('trial_days'));
    }
};
