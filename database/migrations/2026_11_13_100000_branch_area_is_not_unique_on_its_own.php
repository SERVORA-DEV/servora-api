<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A branch is told apart by its name and its area together (App\Support\BranchIdentity), so the area
    // (`code`) is no longer unique on its own: two branches with different names may be in the same
    // area. The check that no business has the same name in the same area twice is made when a branch is
    // saved (the branch requests), as the name's own check always was.
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'code']);
        });
    }

    public function down(): void
    {
        // Back to one branch per area: two branches that now share one cannot be kept.
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->unique(['spa_business_id', 'code']);
        });
    }
};
