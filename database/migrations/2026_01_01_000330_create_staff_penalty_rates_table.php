<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_penalty_rates table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_penalty_rates')) {
            return;
        }

        Schema::create('staff_penalty_rates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_business_id');
            $table->boolean('late_enabled')->default(false);
            $table->decimal('late_amount', 10, 2)->default(0);
            $table->boolean('absent_enabled')->default(false);
            $table->decimal('absent_amount', 10, 2)->default(0);
            $table->date('effective_from');
            $table->timestamps();
            $table->string('late_mode', 8)->default('day');
            $table->index(['spa_business_id', 'effective_from'], 'staff_penalty_rates_spa_business_id_effective_from_index');
            $table->foreign('spa_business_id', 'staff_penalty_rates_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_penalty_rates');
    }
};
