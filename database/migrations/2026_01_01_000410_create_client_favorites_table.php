<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The client_favorites table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_favorites')) {
            return;
        }

        Schema::create('client_favorites', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('spa_branch_id');
            $table->timestamps();
            $table->unique(['user_id', 'spa_branch_id'], 'client_favorites_user_id_spa_branch_id_unique');
            $table->foreign('spa_branch_id', 'client_favorites_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
            $table->foreign('user_id', 'client_favorites_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_favorites');
    }
};
