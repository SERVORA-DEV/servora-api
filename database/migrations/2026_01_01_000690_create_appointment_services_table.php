<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The appointment_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointment_services')) {
            return;
        }

        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('appointment_id');
            $table->bigInteger('service_variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2);
            $table->integer('points_earned')->default(0);
            $table->enum('status', ['Pending', 'In Progress', 'Completed', 'Cancelled'])->default('Pending');
            $table->timestamps();
            $table->uuid('uuid');
            $table->bigInteger('source_appointment_package_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->decimal('extra_charge', 10, 2)->default(0);
            $table->string('service_name')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('service_type', 20)->nullable();
            $table->string('service_category')->nullable();
            $table->integer('extended_minutes')->default(0);
            $table->bigInteger('parent_appointment_service_id')->nullable();
            $table->bigInteger('addon_id')->nullable();
            $table->boolean('facility_required')->nullable();
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->unique('uuid', 'appointment_services_uuid_unique');
            $table->index(['appointment_id', 'source_appointment_package_id'], 'appointment_services_appt_pkg_idx');
            $table->index('service_variant_id', 'appointment_services_service_variant_id_index');
            $table->foreign('addon_id', 'appointment_services_addon_id_foreign')->references('id')->on('addons')->onDelete('set null');
            $table->foreign('appointment_id', 'appointment_services_appointment_id_foreign')->references('id')->on('appointments')->onDelete('cascade');
            $table->foreign('parent_appointment_service_id', 'appointment_services_parent_appointment_service_id_foreign')->references('id')->on('appointment_services')->onDelete('set null');
            $table->foreign('service_variant_id', 'appointment_services_service_variant_id_foreign')->references('id')->on('service_variants')->onDelete('restrict');
            $table->foreign('source_appointment_package_id', 'appointment_services_source_appointment_package_id_foreign')->references('id')->on('appointment_packages')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};
