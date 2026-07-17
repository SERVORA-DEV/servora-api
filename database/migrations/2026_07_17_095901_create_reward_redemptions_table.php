<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('client_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('reward_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('points_used');

            $table->enum('status', [
                'Pending',
                'Redeemed',
                'Cancelled',
            ])->default('Pending');

            $table->timestamp('redeemed_at')->nullable();

            $table->foreignId('redeemed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index('client_id');
            $table->index('reward_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};