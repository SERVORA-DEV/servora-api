<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_schedules table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_schedules')) {
            return;
        }

        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('staff_id');
            $table->enum('day_of_week', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_day_off')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'day_of_week'], 'staff_schedules_staff_id_day_of_week_index');
            $table->foreign('staff_id', 'staff_schedules_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
