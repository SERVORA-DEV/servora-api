<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A manager's change to a staff member's weekly schedule, waiting for
        // the owner — only used while Staff Policies → "Owner approval for
        // schedule changes" is on (see StaffScheduleService).
        Schema::create('staff_schedule_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The proposed week: the same rows StaffScheduleRepository::
            // replaceCurrentForStaff takes.
            $table->json('days');

            // pending | approved | rejected | withdrawn | superseded
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
            $table->index(['staff_id', 'status']);
        });

        // This policy was stored as "on" by default but never did anything.
        // Now that it is enforced, start every business with it off so
        // approval only begins when an owner switches it on. The retired
        // self check-in flag is dropped at the same time.
        DB::table('spa_business_settings')->whereNotNull('staff_policy')->orderBy('id')->each(function ($row) {
            $policy = json_decode($row->staff_policy, true);
            if (! is_array($policy)) {
                return;
            }

            $policy['require_manager_approval'] = false;
            unset($policy['allow_self_check_in']);

            DB::table('spa_business_settings')->where('id', $row->id)->update(['staff_policy' => json_encode($policy)]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedule_change_requests');
    }
};
