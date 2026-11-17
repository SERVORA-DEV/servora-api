<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An owner's request to change the company registration of a verified
// business (DTI number, registered name, registered owner, the certificate).
// A new table only: nothing already stored changes. The business keeps its
// registration, and stays verified and open, until a system administrator
// approves the request (BusinessRegistrationChangeService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_registration_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What the owner asks for.
            $table->string('registration_document_type', 10)->default('DTI');
            $table->string('registration_number', 100);
            $table->string('registered_business_name');
            $table->string('registered_owner_name')->nullable();
            $table->string('registration_document_path');
            $table->text('note')->nullable();

            // What was on the business when it was asked, kept with the request.
            $table->json('previous')->nullable();

            // Pending, Approved, Rejected, Cancelled.
            $table->string('status', 20)->default('Pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_registration_changes');
    }
};
