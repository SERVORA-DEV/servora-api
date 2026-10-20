<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business's own Audit Log (Business → Audit Log): what the owner and the
// accounts they created did, at which branch. One row per action, written by
// App\Http\Middleware\RecordBusinessActivity; sign-ins are added by
// AuditLogRepository::recordAuthEvent.
//
// A row keeps its own copy of the names (App\Support\History): who did it and
// in which role, the branch, what it was about, and the sentence itself — so
// the log reads the same after a rename or a removal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('spa_business_id')->nullable()->constrained('spa_businesses')->nullOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();

            // 'appointment.check_in', 'billing.refund', 'sign_in.login' …
            $table->string('event', 80)->nullable();
            // bookings | billing | clients | staff | services | settings | accounts | sign_in
            $table->string('area', 20)->nullable();
            $table->string('summary', 255)->nullable();
            $table->string('subject_label', 160)->nullable();

            $table->string('actor_name', 160)->nullable();
            // business_owner | manager | front_officer
            $table->string('actor_role', 30)->nullable();
            // owner | management | operation
            $table->string('actor_level', 20)->nullable();
            $table->string('branch_name', 160)->nullable();

            $table->index(['spa_business_id', 'created_at']);
            $table->index('spa_branch_id');
            $table->index('area');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['spa_business_id', 'created_at']);
            $table->dropIndex(['spa_branch_id']);
            $table->dropIndex(['area']);
            $table->dropConstrainedForeignId('spa_business_id');
            $table->dropConstrainedForeignId('spa_branch_id');
            $table->dropColumn(['event', 'area', 'summary', 'subject_label', 'actor_name', 'actor_role', 'actor_level', 'branch_name']);
        });
    }
};
