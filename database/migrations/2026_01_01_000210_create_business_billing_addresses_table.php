<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business_billing_addresses table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_billing_addresses')) {
            return;
        }

        Schema::create('business_billing_addresses', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_business_id');
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20);
            $table->string('country', 2)->default('PH');
            $table->boolean('is_business_purchase')->default(false);
            $table->string('business_name', 150)->nullable();
            $table->string('tin', 30)->nullable();
            $table->timestamps();
            $table->unique('spa_business_id', 'business_billing_addresses_spa_business_id_unique');
            $table->foreign('spa_business_id', 'business_billing_addresses_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_billing_addresses');
    }
};
