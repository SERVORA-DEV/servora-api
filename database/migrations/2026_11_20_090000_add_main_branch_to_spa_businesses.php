<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The branch an owner marks as their main one (Branches page → "Set as main
// branch"). Nullable: every existing business starts with none chosen, which
// behaves exactly as before. Nothing is filled in for existing rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->foreignId('main_branch_id')->nullable()->after('id')
                ->constrained('spa_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_branch_id');
        });
    }
};
