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
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 150);

            $table->text('description')->nullable();

            $table->boolean('is_available')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'spa_branch_id',
                'name',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};