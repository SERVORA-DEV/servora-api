<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer_program_branch table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_program_branch')) {
            return;
        }

        Schema::create('customer_program_branch', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_program_id');
            $table->bigInteger('spa_branch_id');
            $table->timestamps();
            $table->unique(['customer_program_id', 'spa_branch_id'], 'customer_program_branch_customer_program_id_spa_branch_id_uniqu');
            $table->foreign('customer_program_id', 'customer_program_branch_customer_program_id_foreign')->references('id')->on('customer_programs')->onDelete('cascade');
            $table->foreign('spa_branch_id', 'customer_program_branch_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_program_branch');
    }
};
