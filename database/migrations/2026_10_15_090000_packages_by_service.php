<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A package names services, not menu lengths (App\Support\PackagePlan): each
// service in it has its own minutes in that package, the package's duration is
// their total, and it can come with add-ons at no extra charge. Until this has
// run a package still pins menu lengths and holds no add-ons
// (App\Models\Package::byService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_services', function (Blueprint $table) {
            // Which service, and how long it runs in this package.
            $table->foreignId('service_id')->nullable()->after('package_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedInteger('duration_minutes')->nullable()->after('service_id');
            // The menu length it used to pin: no longer written.
            $table->unsignedBigInteger('service_variant_id')->nullable()->change();
        });

        // Every package keeps what it had: the service of each pinned length,
        // for the minutes of that length.
        DB::statement('
            UPDATE package_services
            SET service_id = v.service_id, duration_minutes = v.duration_minutes
            FROM service_variants v
            WHERE v.id = package_services.service_variant_id AND package_services.service_id IS NULL
        ');

        // The add-ons that come with a package, each on one of its services.
        Schema::create('package_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['package_id', 'addon_id', 'service_id']);
        });

        // A package's duration is the total of its parts.
        DB::statement('
            UPDATE packages
            SET duration_minutes = t.minutes
            FROM (
                SELECT package_id, SUM(COALESCE(duration_minutes, 0) * GREATEST(quantity, 1)) AS minutes
                FROM package_services
                GROUP BY package_id
            ) t
            WHERE t.package_id = packages.id AND t.minutes > 0
        ');

        Cache::forget('schema:packages_by_service');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_addons');

        Schema::table('package_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn('duration_minutes');
        });

        Cache::forget('schema:packages_by_service');
    }
};
