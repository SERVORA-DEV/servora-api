<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // appointment_id was left loose (no FK) since appointments didn't exist
    // yet — it does now. Stays nullable (billings.billing_type is also used
    // for Subscription billing, which has no appointment_id at all).
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->foreign('appointment_id')
                ->references('id')->on('appointments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
        });
    }
};
