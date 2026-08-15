<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A room/suite within a branch (e.g. Room 3, Couple's Suite) — tracked
    // separately from Staff since a facility isn't a person and has its own
    // capacity/amenities/occupancy status. status is set manually via the
    // facility actions menu (Mark Available/Under Maintenance), not derived
    // from a booking — there's no Appointment model yet to derive it from.
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnDelete();

            $table->string('name', 150);
            // "Couples Room" (no apostrophe) — Laravel's enum() SQL generation
            // doesn't escape an embedded apostrophe on this MariaDB driver, so
            // the stored value avoids it entirely; the frontend still labels
            // it "Couple's Room" for display.
            $table->enum('type', ['Room', 'Suite', 'Couples Room', 'VIP Room'])->default('Room');
            $table->unsignedInteger('capacity')->default(1);
            $table->enum('status', ['Available', 'Occupied', 'Under Maintenance'])->default('Available');

            // Free-form tags (e.g. "Shower", "Sound System") — small enough
            // per room that a json column is simpler than a join table, same
            // reasoning as amenities lists elsewhere in the app.
            $table->json('amenities')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
