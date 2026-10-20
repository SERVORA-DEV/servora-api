<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A manager's request now carries the whole form the owner would fill in to
// create the service, package or add-on (lengths and prices, what a package
// includes, where an add-on applies), not just a name — the owner reads it as
// the manager wrote it and the Add window opens with it filled in. Requests
// made before this have none and keep working with their name and note.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_requests', function (Blueprint $table) {
            // The form as the site holds it (camelCase keys), validated per kind in
            // CatalogRequestRequest. Null for a request made before this.
            $table->json('payload')->nullable()->after('details');
        });

        Cache::forget('schema:catalog_request_payload');
    }

    public function down(): void
    {
        Schema::table('catalog_requests', function (Blueprint $table) {
            $table->dropColumn('payload');
        });

        Cache::forget('schema:catalog_request_payload');
    }
};
