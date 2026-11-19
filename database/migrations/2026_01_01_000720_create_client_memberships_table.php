<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The client_memberships table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_memberships')) {
            return;
        }

        Schema::create('client_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('client_id');
            $table->bigInteger('customer_program_id');
            $table->bigInteger('spa_branch_id')->nullable();
            $table->bigInteger('billing_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('active');
            $table->decimal('price', 10, 2)->default(0);
            $table->bigInteger('sold_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->string('program_name')->nullable();
            $table->json('perks')->nullable();
            $table->unique('uuid', 'client_memberships_uuid_unique');
            $table->index(['client_id', 'status'], 'client_memberships_client_id_status_index');
            $table->index(['spa_branch_id', 'status'], 'client_memberships_spa_branch_id_status_index');
            $table->foreign('billing_id', 'client_memberships_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('client_id', 'client_memberships_client_id_foreign')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('customer_program_id', 'client_memberships_customer_program_id_foreign')->references('id')->on('customer_programs')->onDelete('cascade');
            $table->foreign('sold_by', 'client_memberships_sold_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'client_memberships_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_memberships');
    }
};
