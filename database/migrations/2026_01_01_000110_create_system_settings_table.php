<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The system_settings table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('subscription_grace_period_days')->default(7);
            $table->timestamps();
            $table->boolean('almost_due_notify_enabled')->default(true);
            $table->integer('almost_due_notify_days_before')->default(3);
            $table->boolean('almost_due_repeat_enabled')->default(true);
            $table->integer('almost_due_repeat_every_days')->default(1);
            $table->boolean('plan_changes_enabled')->default(true);
            $table->integer('upgrade_cutoff_days')->default(1);
            $table->integer('downgrade_notice_days')->default(3);
            $table->integer('plan_change_grace_days')->default(7);
            $table->json('listing_policy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
