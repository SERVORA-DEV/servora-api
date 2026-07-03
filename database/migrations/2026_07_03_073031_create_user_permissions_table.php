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
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->boolean('is_allowed')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
