<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business_registration_changes table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_registration_changes')) {
            return;
        }

        Schema::create('business_registration_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('user_id');
            $table->string('registration_document_type', 10)->default('DTI');
            $table->string('registration_number', 100);
            $table->string('registered_business_name');
            $table->string('registered_owner_name')->nullable();
            $table->string('registration_document_path');
            $table->text('note')->nullable();
            $table->json('previous')->nullable();
            $table->string('status', 20)->default('Pending');
            $table->text('rejection_reason')->nullable();
            $table->bigInteger('reviewed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'business_registration_changes_uuid_unique');
            $table->index(['spa_business_id', 'status'], 'business_registration_changes_spa_business_id_status_index');
            $table->index(['status', 'submitted_at'], 'business_registration_changes_status_submitted_at_index');
            $table->foreign('reviewed_by', 'business_registration_changes_reviewed_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_business_id', 'business_registration_changes_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('user_id', 'business_registration_changes_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_registration_changes');
    }
};
