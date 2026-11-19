<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The branch_packages table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_packages')) {
            return;
        }

        Schema::create('branch_packages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_branch_id');
            $table->bigInteger('package_id');
            $table->decimal('custom_price', 10, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->decimal('home_custom_price', 10, 2)->nullable();
            $table->unique(['spa_branch_id', 'package_id'], 'branch_packages_spa_branch_id_package_id_unique');
            $table->foreign('package_id', 'branch_packages_package_id_foreign')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('spa_branch_id', 'branch_packages_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_packages');
    }
};
