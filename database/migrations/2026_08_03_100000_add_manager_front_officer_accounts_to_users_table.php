<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Manager and Front Officer are business-side login roles (business
    // account management — see AccountService) — distinct from the generic
    // 'staff' value already in this enum, which nothing in the app creates.
    private array $newRoles = [
        'system_administrator',
        'business_owner',
        'manager',
        'front_officer',
        'staff',
        'client',
    ];

    private array $oldRoles = [
        'system_administrator',
        'business_owner',
        'staff',
        'client',
    ];

    public function up(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->newRoles));
        DB::statement("ALTER TABLE users MODIFY role ENUM({$values}) NOT NULL");

        Schema::table('users', function (Blueprint $table) {
            // Which branch this Manager/Front Officer account works at —
            // null for every other role. Business scoping for account
            // management is derived through this relation (user -> branch ->
            // spa_business_id) instead of a redundant business_id column on
            // users, so moving an account to a different branch can never
            // leave it pointing at the wrong business.
            $table->foreignId('spa_branch_id')
                ->nullable()
                ->after('role')
                ->constrained('spa_branches')
                ->nullOnDelete();

            $table->index('spa_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['spa_branch_id']);
            $table->dropColumn('spa_branch_id');
        });

        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->oldRoles));
        DB::statement("ALTER TABLE users MODIFY role ENUM({$values}) NOT NULL");
    }
};
