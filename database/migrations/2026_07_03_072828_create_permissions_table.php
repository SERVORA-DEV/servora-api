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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->string('module', 100);
            $table->string('action', 100);
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['module', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
