<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_no_show_waivers', function (Blueprint $table) {
            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->foreignId('appointment_id')->index()->constrained('appointments')->cascadeOnDelete();
            $table->primary(['spa_branch_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_no_show_waivers');
    }
};
