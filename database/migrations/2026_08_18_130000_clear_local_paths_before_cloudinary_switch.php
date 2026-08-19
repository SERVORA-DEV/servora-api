<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Uploads now go to Cloudinary (public_ids), not the local disk (relative
// paths) — any value already stored in these columns predates that switch
// and can never resolve through the new Cloudinary-only upload/retrieval
// code. Confirmed via a read-only check before writing this migration:
// exactly one owner_identity_verifications row and one spa_businesses row
// have real data here, both already status/verification_status =
// 'Rejected' from the account owner's own testing, so they need to
// re-upload regardless of this migration. services.image_path has zero
// rows today, nothing to clear there.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owner_identity_verifications')->update([
            'id_document_front_path' => null,
            'id_document_back_path' => null,
            'face_scan_paths' => null,
        ]);

        DB::table('spa_businesses')->update([
            'registration_document_path' => null,
        ]);

        DB::table('services')->update([
            'image_path' => null,
        ]);
    }

    // Not reversible — the original local paths are not recoverable once
    // cleared (and the files they pointed to are no longer served by the
    // app regardless).
    public function down(): void
    {
        //
    }
};
