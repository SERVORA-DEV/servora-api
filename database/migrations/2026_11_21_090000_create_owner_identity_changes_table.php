<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An owner's request to change the name or email on their verified account.
// A new table only: nothing already stored changes. The new name and email
// take effect when a system administrator approves the request against a
// fresh ID photo and face scan (OwnerIdentityChangeService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_identity_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What the owner asks for.
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('email');

            // A new email is proved by a code sent to it before the request goes in.
            $table->string('email_code_hash')->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->timestamp('email_confirmed_at')->nullable();

            // The fresh ID and face scan the administrator compares.
            $table->string('id_type')->nullable();
            $table->string('id_document_front_path')->nullable();
            $table->string('id_document_back_path')->nullable();
            $table->json('face_scan_paths')->nullable();
            $table->json('liveness_sequence')->nullable();

            // Draft, Pending, Approved, Rejected, Cancelled.
            $table->string('status', 20)->default('Draft');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_identity_changes');
    }
};
