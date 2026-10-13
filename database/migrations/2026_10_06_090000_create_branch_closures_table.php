<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stretch of time a branch is closed outside its weekly hours — a
        // holiday, repairs, a private event. Nothing can be booked inside it,
        // and the branch reopens by itself when it ends (see BranchClosures).
        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();

            // The branch's own wall-clock time. ends_at is the moment it
            // reopens; an all-day closure runs midnight to midnight.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);

            $table->string('reason', 40);
            // Shown to clients in the app.
            $table->string('note', 200)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['spa_branch_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_closures');
    }
};
