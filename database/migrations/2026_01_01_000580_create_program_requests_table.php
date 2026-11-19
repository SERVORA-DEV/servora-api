<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The program_requests table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('program_requests')) {
            return;
        }

        Schema::create('program_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('spa_branch_id')->nullable();
            $table->bigInteger('requested_by_user_id')->nullable();
            $table->string('type', 20);
            $table->string('name', 120);
            $table->json('payload');
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->bigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->bigInteger('customer_program_id')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'program_requests_uuid_unique');
            $table->index(['spa_branch_id', 'status'], 'program_requests_spa_branch_id_status_index');
            $table->index(['spa_business_id', 'status'], 'program_requests_spa_business_id_status_index');
            $table->foreign('customer_program_id', 'program_requests_customer_program_id_foreign')->references('id')->on('customer_programs')->onDelete('set null');
            $table->foreign('decided_by_user_id', 'program_requests_decided_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('requested_by_user_id', 'program_requests_requested_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'program_requests_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'program_requests_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_requests');
    }
};
