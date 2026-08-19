<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // One identity verification record per account.
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('id_type', [
                'Philippine National ID',
                'Passport',
                'Drivers License',
                'UMID',
                'Other',
            ])->nullable();

            // Private-disk (storage/app/private) relative path — never a
            // public URL. See DocumentUploadService.
            $table->string('id_document_path')->nullable();

            // Ordered array of captured live-scan frame paths, private disk.
            $table->json('face_scan_paths')->nullable();

            // The guided-capture step sequence actually completed, e.g.
            // ["center","look_left","look_right","blink"] — flow metadata,
            // not biometric data.
            $table->json('liveness_sequence')->nullable();

            // "Passed" means the client-side guided sequence was completed —
            // a completion flag, not a cryptographic liveness proof. Actual
            // identity confidence comes from the admin visually comparing
            // the captures to the ID during manual review.
            $table->enum('liveness_result', ['Pending', 'Passed', 'Failed'])->default('Pending');

            // Reserved for a future real face-match provider — always null
            // today, no automated matching exists in this codebase.
            $table->enum('face_match_result', ['Passed', 'Failed'])->nullable();

            $table->enum('status', ['Unregistered', 'Pending', 'Verified', 'Rejected'])
                ->default('Unregistered');

            // UX hint only for which sub-item to highlight on reject — not
            // enforced server-side (owner can always resubmit either/both
            // while Rejected).
            $table->enum('rejected_field', ['id_document', 'face_scan', 'both'])->nullable();

            $table->text('rejection_reason')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_identity_verifications');
    }
};
