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
        // A staff login (Manager / Front Officer) is given branches by the
        // owner, each at a level: 'management' (the Manager permissions) or
        // 'operation' (the Front Desk permissions). It no longer belongs to
        // an employee record (see App\Support\AccountScope).
        Schema::create('account_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->string('level', 12)->default('operation');
            $table->timestamps();

            $table->unique(['user_id', 'spa_branch_id']);
            $table->index(['spa_branch_id', 'level']);
        });

        // Every login that exists now keeps working: it gets the branch of
        // the employee it was made from, at the level of that employee's
        // role, and keeps that employee's name as its own.
        // (staff.user_id stays, unread, so this can be rolled back.)
        $rows = DB::table('staff')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->whereNotNull('staff.user_id')
            ->whereNull('staff.deleted_at')
            ->get(['staff.user_id', 'staff.spa_branch_id', 'staff.role', 'staff.first_name', 'staff.last_name', 'users.first_name as u_first', 'users.last_name as u_last']);

        foreach ($rows as $r) {
            DB::table('account_branches')->insertOrIgnore([
                'user_id' => $r->user_id,
                'spa_branch_id' => $r->spa_branch_id,
                'level' => $r->role === 'manager' ? 'management' : 'operation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! trim((string) $r->u_first) && ! trim((string) $r->u_last)) {
                DB::table('users')->where('id', $r->user_id)->update(['first_name' => $r->first_name, 'last_name' => $r->last_name]);
            }
        }

        Cache::forget('schema:account_branches');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_branches');
        Cache::forget('schema:account_branches');
    }
};
