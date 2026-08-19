<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // appointments.reward_redemption_id was left as a loose column when the
    // appointments table was created, since reward_redemptions (Module 7)
    // didn't exist yet. Now that it does, wire up the real constraint.
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('reward_redemption_id')
                ->references('id')->on('reward_redemptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['reward_redemption_id']);
        });
    }
};
