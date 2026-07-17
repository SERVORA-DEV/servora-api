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
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('staff_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('day_of_week', [
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday',
                'Saturday',
                'Sunday',
            ]);

            $table->time('start_time');
            $table->time('end_time');

            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            $table->boolean('is_day_off')->default(false);

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->timestamps();

            $table->unique([
                'staff_id',
                'day_of_week',
                'effective_from',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};