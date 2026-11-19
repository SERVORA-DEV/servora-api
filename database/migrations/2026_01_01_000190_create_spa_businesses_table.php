<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The spa_businesses table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spa_businesses')) {
            return;
        }

        Schema::create('spa_businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('owner_id')->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone', 20)->nullable();
            $table->string('business_logo')->nullable();
            $table->text('business_description')->nullable();
            $table->enum('verification_status', ['Unregistered', 'Pending', 'Verified', 'Rejected', 'Suspended'])->default('Unregistered');
            $table->enum('operating_status', ['Active', 'Inactive'])->nullable();
            $table->bigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->enum('business_type', ['Sole Proprietorship', 'Corporation', 'Partnership'])->nullable();
            $table->enum('registration_document_type', ['DTI', 'SEC'])->nullable();
            $table->string('registration_document_path')->nullable();
            $table->string('registered_business_name')->nullable();
            $table->string('registered_owner_name')->nullable();
            $table->string('authorized_representative_name')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('legal_name', 150)->nullable();
            $table->string('spa_type', 60)->nullable();
            $table->string('tagline', 160)->nullable();
            $table->string('head_office_address')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_handle', 100)->nullable();
            $table->string('website_url')->nullable();
            $table->string('xendit_customer_id', 64)->nullable();
            $table->timestamp('business_email_verified_at')->nullable();
            $table->bigInteger('main_branch_id')->nullable();
            $table->unique('business_email', 'spa_businesses_business_email_unique');
            $table->unique('uuid', 'spa_businesses_uuid_unique');
            $table->index('created_at', 'spa_businesses_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_businesses');
    }
};
