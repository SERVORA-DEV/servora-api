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
        Schema::create('business_members', function (Blueprint $table) {
            $table->id();

            // Public Identifier
            $table->uuid('uuid')->unique();

            // Relationships
            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Membership Status
            $table->enum('account_status', [
                'Active',
                'Inactive',
                'Suspended'
            ])->default('Active');

            $table->timestamp('joined_at')->nullable();

            // Laravel
            $table->timestamps();
            $table->softDeletes();

            // A user can only join the same business once
            $table->unique(['spa_business_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_members');
    }

};
