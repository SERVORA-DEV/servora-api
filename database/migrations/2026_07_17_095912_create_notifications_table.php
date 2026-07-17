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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title', 150);

            $table->text('message');

            $table->enum('type', [
                'System',
                'Appointment',
                'Payment',
                'Reward',
                'Subscription',
            ])->default('System');

            $table->boolean('is_read')
                ->default(false);

            $table->timestamp('read_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('type');
            $table->index('is_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};