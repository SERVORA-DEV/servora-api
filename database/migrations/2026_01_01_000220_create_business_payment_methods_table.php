<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business_payment_methods table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_payment_methods')) {
            return;
        }

        Schema::create('business_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('type', 16);
            $table->string('xendit_payment_token_id', 64)->nullable();
            $table->string('label', 120);
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->smallInteger('expiry_month')->nullable();
            $table->smallInteger('expiry_year')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('uuid', 'business_payment_methods_uuid_unique');
            $table->unique('xendit_payment_token_id', 'business_payment_methods_xendit_payment_token_id_unique');
            $table->index(['spa_business_id', 'status'], 'business_payment_methods_spa_business_id_status_index');
            $table->foreign('spa_business_id', 'business_payment_methods_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_methods');
    }
};
