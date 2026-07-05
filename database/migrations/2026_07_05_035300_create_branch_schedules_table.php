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
        Schema::create('branch_schedules', function (Blueprint $table) {
            $table->id();

            // Public Identifier
            $table->uuid('uuid')->unique();

            // Branch
            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Schedule
            $table->enum('day_of_week', [
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday',
                'Saturday',
                'Sunday',
            ]);

            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();

            $table->boolean('is_closed')->default(false);

            // Laravel
            $table->timestamps();
            $table->softDeletes();

            // One schedule per day per branch
            $table->unique(['spa_branch_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_schedules');
    }
};
