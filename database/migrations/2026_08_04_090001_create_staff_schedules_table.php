<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->constrained('staff')
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

            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            $table->boolean('is_day_off')->default(false);

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestamps();

            $table->index(['staff_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
