<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner_identity_changes table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owner_identity_changes')) {
            return;
        }

        Schema::create('owner_identity_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('user_id');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('email');
            $table->string('email_code_hash')->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->timestamp('email_confirmed_at')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_document_front_path')->nullable();
            $table->string('id_document_back_path')->nullable();
            $table->json('face_scan_paths')->nullable();
            $table->json('liveness_sequence')->nullable();
            $table->string('status', 20)->default('Draft');
            $table->text('rejection_reason')->nullable();
            $table->bigInteger('reviewed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'owner_identity_changes_uuid_unique');
            $table->index(['status', 'submitted_at'], 'owner_identity_changes_status_submitted_at_index');
            $table->index(['user_id', 'status'], 'owner_identity_changes_user_id_status_index');
            $table->foreign('reviewed_by', 'owner_identity_changes_reviewed_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('user_id', 'owner_identity_changes_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_identity_changes');
    }
};
