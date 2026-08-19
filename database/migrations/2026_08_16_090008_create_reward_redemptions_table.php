<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A client cashing in points for a reward — points_used is captured
    // here (not just looked up from rewards.points_required) so a later
    // change to the reward's cost doesn't rewrite redemption history.
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            $table->foreignId('reward_id')
                ->constrained('rewards')
                ->cascadeOnDelete();

            $table->unsignedInteger('points_used');

            $table->enum('status', ['Pending', 'Redeemed', 'Cancelled'])->default('Pending');

            $table->timestamp('redeemed_at')->nullable();

            // Loose reference, no FK constraint — the staff member who
            // processed the redemption; same convention as
            // spa_businesses.verified_by.
            $table->unsignedBigInteger('redeemed_by')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};
