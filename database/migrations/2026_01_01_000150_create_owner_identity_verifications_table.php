<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner_identity_verifications table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owner_identity_verifications')) {
            return;
        }

        Schema::create('owner_identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('user_id');
            $table->enum('id_type', ['Philippine National ID', 'Passport', 'Drivers License', 'UMID', 'Other'])->nullable();
            $table->string('id_document_front_path')->nullable();
            $table->jsonb('face_scan_paths')->nullable();
            $table->jsonb('liveness_sequence')->nullable();
            $table->enum('liveness_result', ['Pending', 'Passed', 'Failed'])->default('Pending');
            $table->enum('face_match_result', ['Passed', 'Failed'])->nullable();
            $table->enum('status', ['Unregistered', 'Pending', 'Verified', 'Rejected'])->default('Unregistered');
            $table->enum('rejected_field', ['id_document', 'face_scan', 'both'])->nullable();
            $table->text('rejection_reason')->nullable();
            $table->bigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->string('id_document_back_path')->nullable();
            $table->unique('user_id', 'owner_identity_verifications_user_id_unique');
            $table->unique('uuid', 'owner_identity_verifications_uuid_unique');
            $table->index('status', 'owner_identity_verifications_status_index');
            $table->foreign('user_id', 'owner_identity_verifications_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_identity_verifications');
    }
};
