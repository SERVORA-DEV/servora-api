<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The refund_policies table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refund_policies')) {
            return;
        }

        Schema::create('refund_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->string('mode', 20);
            $table->decimal('value', 12, 2)->nullable();
            $table->boolean('allow_less')->default(true);
            $table->boolean('needs_approval')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->string('charge_to', 20)->default('business');
            $table->decimal('staff_share', 5, 2)->nullable();
            $table->unique('uuid', 'refund_policies_uuid_unique');
            $table->index(['spa_business_id', 'deleted_at'], 'refund_policies_spa_business_id_deleted_at_index');
            $table->foreign('spa_business_id', 'refund_policies_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_policies');
    }
};
