<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A manager's request to move a staff member to another branch,
        // waiting for the owner (see StaffTransferService). The owner's own
        // move needs no request: it applies at once.
        Schema::create('staff_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();

            // The branch names as they were asked, so the record still reads
            // right if a branch is renamed or removed later.
            $table->string('from_branch_name', 255)->nullable();
            $table->string('to_branch_name', 255)->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // pending | approved | declined | withdrawn | superseded
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
            $table->index(['staff_id', 'status']);
        });

        // An attendance record keeps the branch where it happened, so a staff
        // member who moves keeps their old days at the old branch (see
        // App\Support\History). Until now the branch was read through the
        // person's current one.
        if (! Schema::hasColumn('attendances', 'spa_branch_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            });

            DB::statement('UPDATE attendances SET spa_branch_id = (SELECT s.spa_branch_id FROM staff s WHERE s.id = attendances.staff_id)');
        }

        Cache::forget('schema:staff_transfers');
        Cache::forget('schema:attendance_branch');
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendances', 'spa_branch_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('spa_branch_id');
            });
        }
        Schema::dropIfExists('staff_transfer_requests');

        Cache::forget('schema:staff_transfers');
        Cache::forget('schema:attendance_branch');
    }
};
