<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The branch_closures table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_closures')) {
            return;
        }

        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_branch_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('reason', 40);
            $table->string('note', 200)->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'branch_closures_uuid_unique');
            $table->index(['spa_branch_id', 'ends_at'], 'branch_closures_spa_branch_id_ends_at_index');
            $table->foreign('created_by_user_id', 'branch_closures_created_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'branch_closures_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_closures');
    }
};
