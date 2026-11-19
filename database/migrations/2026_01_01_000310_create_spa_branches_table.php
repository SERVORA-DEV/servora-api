<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The spa_branches table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spa_branches')) {
            return;
        }

        Schema::create('spa_branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('branch_name', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('cover_photo')->nullable();
            $table->text('description')->nullable();
            $table->enum('verification_status', ['Unregistered', 'Pending', 'Verified', 'Rejected', 'Suspended'])->default('Unregistered');
            $table->enum('operating_status', ['Active', 'Inactive', 'Temporarily Closed'])->nullable();
            $table->text('closure_note')->nullable();
            $table->date('reopens_at')->nullable();
            $table->bigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('formatted_address')->nullable();
            $table->string('permit_document_path')->nullable();
            $table->string('permit_number')->nullable();
            $table->string('permit_business_name')->nullable();
            $table->string('permit_branch_location')->nullable();
            $table->date('permit_issue_date')->nullable();
            $table->date('permit_expiration_date')->nullable();
            $table->boolean('permit_confirmed')->default(false);
            $table->string('code', 50)->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_handle', 100)->nullable();
            $table->string('website_url')->nullable();
            $table->boolean('listing_visible')->default(true);
            $table->string('promo_text', 80)->nullable();
            $table->json('highlights')->nullable();
            $table->json('display_settings')->nullable();
            $table->json('booking_overrides')->nullable();
            $table->timestamp('plan_hold_at')->nullable();
            $table->string('scanner_token', 64)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unique('scanner_token', 'spa_branches_scanner_token_unique');
            $table->unique('uuid', 'spa_branches_uuid_unique');
            $table->index(['latitude', 'longitude'], 'spa_branches_latitude_longitude_index');
            $table->index('spa_business_id', 'spa_branches_spa_business_id_index');
            $table->index('verification_status', 'spa_branches_verification_status_index');
            $table->foreign('spa_business_id', 'spa_branches_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });

        // Tables made before this one that point here.
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->foreign('main_branch_id', 'spa_businesses_main_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropForeign('spa_businesses_main_branch_id_foreign');
        });
        Schema::dropIfExists('spa_branches');
    }
};
