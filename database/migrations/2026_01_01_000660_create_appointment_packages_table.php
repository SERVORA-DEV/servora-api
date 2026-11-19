<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The appointment_packages table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointment_packages')) {
            return;
        }

        Schema::create('appointment_packages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('appointment_id');
            $table->bigInteger('package_id');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2);
            $table->enum('status', ['Pending', 'In Progress', 'Completed', 'Cancelled'])->default('Pending');
            $table->timestamps();
            $table->uuid('uuid');
            $table->string('package_name')->nullable();
            $table->smallInteger('client_slot')->nullable();
            $table->bigInteger('shared_from_id')->nullable();
            $table->unique('uuid', 'appointment_packages_uuid_unique');
            $table->index(['appointment_id', 'package_id'], 'appointment_packages_appointment_id_package_id_index');
            $table->foreign('appointment_id', 'appointment_packages_appointment_id_foreign')->references('id')->on('appointments')->onDelete('cascade');
            $table->foreign('package_id', 'appointment_packages_package_id_foreign')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('shared_from_id', 'appointment_packages_shared_from_id_foreign')->references('id')->on('appointment_packages')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_packages');
    }
};
