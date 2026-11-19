<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The service_variants table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_variants')) {
            return;
        }

        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('service_id');
            $table->integer('duration_minutes');
            $table->decimal('price', 10, 2);
            $table->integer('loyalty_points')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('service_location', 20)->default('In Spa');
            $table->unique('uuid', 'service_variants_uuid_unique');
            $table->foreign('service_id', 'service_variants_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX service_variant_location_duration_unique ON service_variants (service_id, service_location, duration_minutes) WHERE (deleted_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_variants');
    }
};
