<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // The service-area taxonomy — same eleven values as
            // facilities.category (see config/service_categories.php for why
            // this is a validated string rather than a DB enum).
            $table->string('category', 50)->nullable()->after('code');

            // A template is an admin-authored catalog row: no owning business
            // (spa_business_id is made nullable below) and no branch_services,
            // which is what keeps it invisible to every existing owner- and
            // client-facing query without retrofitting a single scope.
            $table->boolean('is_template')->default(false)->after('is_active');

            // Provenance only, never a sync link — an admin editing a template
            // must never move a business's live prices. nullOnDelete so
            // retiring a template leaves every adopted service untouched.
            $table->foreignId('source_template_id')->nullable()->after('is_template')
                ->constrained('services')->nullOnDelete();

            // Serves the admin catalog listing and the owner's category filter.
            $table->index(['is_template', 'category']);

            // Dead since the original schema: declared in $fillable and casts,
            // set by ServiceFactory, but never read or written anywhere else
            // and never exposed by ServiceResource. Dropped here so it can't be
            // mistaken for is_template, which is the feature it was presumably
            // a first pass at.
            $table->dropColumn('is_default');
        });

        // Templates have no owning business. Laravel 12 alters this natively —
        // no doctrine/dbal, and no raw driver-specific SQL.
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('spa_business_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Templates would violate the NOT NULL below, so clear them out first.
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['is_template', 'category']);
            $table->dropForeign(['source_template_id']);
            $table->dropColumn(['category', 'is_template', 'source_template_id']);
            $table->boolean('is_default')->default(false)->after('image_path');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('spa_business_id')->nullable(false)->change();
        });
    }
};
