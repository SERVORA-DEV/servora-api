<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A manager asking the owner for a new customer program (a voucher, a
// discount or a membership) for their branch. The manager fills in the whole
// program; nothing is created until the owner approves, and then it is
// offered at the branch that asked, and only there
// (App\Service\Business\ProgramRequestService).
//
//   program_requests               the request, and the owner's answer
//   customer_programs.only_branch_id
//       the branch a program was made for. While set, only that branch's
//       manager can switch it on; the owner offering it at another branch
//       clears it (the program then behaves like any other).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // voucher | discount | membership
            $table->string('type', 20);
            $table->string('name', 120);
            // The program as the manager filled it in, in the shape the
            // owner's own save takes: values, condition, audience, includes.
            $table->json('payload');
            // Why the branch needs it, in the manager's words.
            $table->string('note', 500)->nullable();

            // pending | approved | declined | withdrawn
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            // The program an approval created.
            $table->foreignId('customer_program_id')->nullable()->constrained('customer_programs')->nullOnDelete();

            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
            $table->index(['spa_branch_id', 'status']);
        });

        Schema::table('customer_programs', function (Blueprint $table) {
            $table->foreignId('only_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('only_branch_id');
        });
        Schema::dropIfExists('program_requests');
    }
};
