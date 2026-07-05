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
        Schema::create('branch_user_assignments', function (Blueprint $table) {
            $table->id();

            // Public Identifier
            $table->uuid('uuid')->unique();

            // Relationships
            $table->foreignId('business_member_id')
                ->constrained('business_members')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Assignment Status
            $table->enum('assignment_status', [
                'Active',
                'Inactive'
            ])->default('Active');

            $table->timestamp('assigned_at')->nullable();

            // Laravel
            $table->timestamps();

            // Prevent duplicate assignments
            $table->unique(['business_member_id', 'spa_branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_user_assignments');
    }
};
