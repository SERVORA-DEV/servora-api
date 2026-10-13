<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Add-ons (App\Models\Addon, App\Support\ServiceAddon): extras put on a
// service during the visit. Their own entries on the owner's menu — a name, a
// price, the time they add, and the categories (optionally only some services)
// they go with — and, on a visit, a line of their own under a main service.
// Until this has run there are no add-ons and nothing else changes
// (App\Models\Addon::ready).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            // Loose reference, like services.created_by.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            // What it adds to its main service's time (0 for one that takes none).
            $table->unsignedInteger('duration_minutes')->default(0);
            // The service categories it goes with (config/service_categories.php).
            $table->json('categories');
            // true: every service in those categories. false: only addon_services.
            $table->boolean('all_services')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_business_id', 'is_active']);
        });

        // The specific services an add-on is narrowed to.
        Schema::create('addon_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['addon_id', 'service_id']);
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            // An add-on line: the main line it rides with, and which add-on it
            // is. It is not a length of a service, so it has no variant.
            $table->foreignId('parent_appointment_service_id')
                ->nullable()
                ->after('source_appointment_package_id')
                ->constrained('appointment_services')
                ->nullOnDelete();
            $table->foreignId('addon_id')
                ->nullable()
                ->after('parent_appointment_service_id')
                ->constrained('addons')
                ->nullOnDelete();
            $table->unsignedBigInteger('service_variant_id')->nullable()->change();
        });

        Cache::forget('schema:addons');
    }

    public function down(): void
    {
        // service_variant_id stays nullable: add-on lines already on visits
        // have none, and they are kept as history.
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('addon_id');
            $table->dropConstrainedForeignId('parent_appointment_service_id');
        });

        Schema::dropIfExists('addon_services');
        Schema::dropIfExists('addons');

        Cache::forget('schema:addons');
    }
};
