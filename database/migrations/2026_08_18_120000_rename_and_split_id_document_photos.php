<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_identity_verifications', function (Blueprint $table) {
            // Preserves any existing draft's already-uploaded photo — the ID
            // capture now collects both sides, not just one.
            $table->renameColumn('id_document_path', 'id_document_front_path');
        });

        Schema::table('owner_identity_verifications', function (Blueprint $table) {
            $table->string('id_document_back_path')->nullable()->after('id_document_front_path');
        });
    }

    public function down(): void
    {
        Schema::table('owner_identity_verifications', function (Blueprint $table) {
            $table->dropColumn('id_document_back_path');
        });

        Schema::table('owner_identity_verifications', function (Blueprint $table) {
            $table->renameColumn('id_document_front_path', 'id_document_path');
        });
    }
};
