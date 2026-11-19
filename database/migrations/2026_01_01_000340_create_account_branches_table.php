<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The account_branches table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_branches')) {
            return;
        }

        Schema::create('account_branches', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('spa_branch_id');
            $table->string('level', 12)->default('operation');
            $table->timestamps();
            $table->unique(['user_id', 'spa_branch_id'], 'account_branches_user_id_spa_branch_id_unique');
            $table->index(['spa_branch_id', 'level'], 'account_branches_spa_branch_id_level_index');
            $table->foreign('spa_branch_id', 'account_branches_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
            $table->foreign('user_id', 'account_branches_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_branches');
    }
};
