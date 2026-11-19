<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The spa_branch_photos table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spa_branch_photos')) {
            return;
        }

        Schema::create('spa_branch_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_branch_id');
            $table->string('path');
            $table->boolean('is_cover')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique('uuid', 'spa_branch_photos_uuid_unique');
            $table->index(['spa_branch_id', 'sort_order'], 'spa_branch_photos_spa_branch_id_sort_order_index');
            $table->foreign('spa_branch_id', 'spa_branch_photos_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_branch_photos');
    }
};
