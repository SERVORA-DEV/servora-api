<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer_programs table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_programs')) {
            return;
        }

        Schema::create('customer_programs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('type', 20);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->string('audience', 10)->default('all');
            $table->json('values')->nullable();
            $table->string('condition_kind', 20)->nullable();
            $table->integer('condition_value')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->bigInteger('only_branch_id')->nullable();
            $table->unique('uuid', 'customer_programs_uuid_unique');
            $table->index(['spa_business_id', 'type'], 'customer_programs_spa_business_id_type_index');
            $table->foreign('only_branch_id', 'customer_programs_only_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'customer_programs_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_programs');
    }
};
