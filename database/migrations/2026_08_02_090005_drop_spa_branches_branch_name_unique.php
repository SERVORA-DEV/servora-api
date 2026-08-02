<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Branch names are allowed to repeat, even within the same business
    // (e.g. multiple unnamed/placeholder branches during setup, or two
    // branches sharing a franchise name) — see
    // SpaBranchService::createSpaBranch/updateSpaBranch, which no longer
    // check for an existing name before saving.
    public function up(): void
    {
        // The spa_business_id -> spa_businesses foreign key has no index of
        // its own — it's been relying on the composite unique index below
        // (InnoDB requires *some* index with the FK column leading). Add a
        // plain one first so dropping the unique index doesn't fail with
        // "needed in a foreign key constraint".
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->index('spa_business_id');
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'branch_name']);
        });
    }

    public function down(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->unique(['spa_business_id', 'branch_name']);
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropIndex(['spa_business_id']);
        });
    }
};
