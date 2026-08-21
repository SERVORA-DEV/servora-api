<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            // Nominatim's display_name for the confirmed pin — the map step
            // no longer asks the owner to type an address, it derives one
            // from the selected location instead (see SpaBranchService::
            // saveLocation). address/city/province/postal_code stay as
            // best-effort parsed components, filled from the same step.
            $table->string('formatted_address')->nullable()->after('postal_code');

            // Private-disk relative path (Cloudinary public_id) — never
            // public. See DocumentUploadService, same pattern as
            // spa_businesses.registration_document_path.
            $table->string('permit_document_path')->nullable()->after('cover_photo');

            $table->string('permit_number')->nullable()->after('permit_document_path');
            $table->string('permit_business_name')->nullable()->after('permit_number');

            // The "Branch/Location" field printed on the permit itself —
            // compared against the branch's registered address/map pin
            // during admin review (spec: Branch Verification Rules).
            $table->string('permit_branch_location')->nullable()->after('permit_business_name');

            $table->date('permit_issue_date')->nullable()->after('permit_branch_location');
            $table->date('permit_expiration_date')->nullable()->after('permit_issue_date');

            // The owner's "The information provided is accurate" checkbox —
            // submit() requires this to be true before verification_status
            // can move to Pending.
            $table->boolean('permit_confirmed')->default(false)->after('permit_expiration_date');
        });
    }

    public function down(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropColumn([
                'formatted_address',
                'permit_document_path',
                'permit_number',
                'permit_business_name',
                'permit_branch_location',
                'permit_issue_date',
                'permit_expiration_date',
                'permit_confirmed',
            ]);
        });
    }
};
