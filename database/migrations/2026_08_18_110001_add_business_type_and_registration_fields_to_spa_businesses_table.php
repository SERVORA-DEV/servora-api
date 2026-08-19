<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->enum('business_type', ['Sole Proprietorship', 'Corporation', 'Partnership'])
                ->nullable()->after('business_description');

            // 'DTI' for Sole Proprietorship, 'SEC' for Corporation/Partnership
            // — stored explicitly (not derived from business_type) so a
            // later business_type change doesn't retroactively relabel an
            // already-reviewed document.
            $table->enum('registration_document_type', ['DTI', 'SEC'])
                ->nullable()->after('business_type');

            // Private-disk relative path — never public. See
            // DocumentUploadService.
            $table->string('registration_document_path')->nullable()->after('registration_document_type');

            $table->string('registered_business_name')->nullable()->after('registration_document_path');

            // Sole Proprietorship only (DTI registered owner — compared
            // against the account owner's verified identity).
            $table->string('registered_owner_name')->nullable()->after('registered_business_name');

            // Corporation/Partnership only.
            $table->string('authorized_representative_name')->nullable()->after('registered_owner_name');

            $table->string('registration_number', 100)->nullable()->after('authorized_representative_name');
        });

        // Repurpose the existing (previously unused — nothing wrote to it
        // before this feature) verification_status column as this feature's
        // Business Verification status, rather than adding a redundant
        // second status column. 'Unregistered' becomes the new not-started
        // state; 'Suspended' is kept intact (unrelated to this feature, used
        // by the separate spa_business_suspend permission).
        //
        // Done in two steps: widen the enum while it's still nullable and
        // backfill existing NULL rows first, THEN tighten to NOT NULL
        // DEFAULT — doing it in one ALTER fails under strict SQL mode
        // (existing NULL rows can't be coerced into a NOT NULL column in
        // the same statement that adds the constraint).
        DB::statement("ALTER TABLE spa_businesses MODIFY verification_status
            ENUM('Unregistered','Pending','Verified','Rejected','Suspended') NULL");

        DB::table('spa_businesses')->whereNull('verification_status')->update(['verification_status' => 'Unregistered']);

        DB::statement("ALTER TABLE spa_businesses MODIFY verification_status
            ENUM('Unregistered','Pending','Verified','Rejected','Suspended')
            NOT NULL DEFAULT 'Unregistered'");
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropColumn([
                'business_type',
                'registration_document_type',
                'registration_document_path',
                'registered_business_name',
                'registered_owner_name',
                'authorized_representative_name',
                'registration_number',
            ]);
        });

        DB::statement("ALTER TABLE spa_businesses MODIFY verification_status
            ENUM('Pending','Verified','Rejected','Suspended') NULL");
    }
};
