<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Adds `code` — the short area/locality label an owner actually uses for a
    // branch day to day ("Mandug", "Acacia", "Jacinto"), kept separate from
    // branch_name so the name doesn't have to carry the location. The base
    // table deliberately leaves branch_name non-unique, so this is the first
    // stable short identifier a branch has.
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('branch_name');

            // MySQL/InnoDB allows multiple NULLs in a unique index, so this
            // naturally enforces "unique per business only when set" without
            // extra conditions — same reasoning as services.code.
            $table->unique(['spa_business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
