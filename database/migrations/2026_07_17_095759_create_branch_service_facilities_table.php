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
        Schema::create('branch_service_facilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_service_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('facility_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique([
                'branch_service_id',
                'facility_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_service_facilities');
    }
};