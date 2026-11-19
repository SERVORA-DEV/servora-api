<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The commission_plans table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_plans')) {
            return;
        }

        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('name', 40);
            $table->decimal('rate', 10, 2);
            $table->timestamps();
            $table->unique(['spa_business_id', 'name'], 'commission_plans_spa_business_id_name_unique');
            $table->unique('uuid', 'commission_plans_uuid_unique');
            $table->foreign('spa_business_id', 'commission_plans_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_plans');
    }
};
