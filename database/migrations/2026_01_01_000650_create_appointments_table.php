<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The appointments table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            return;
        }

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_branch_id');
            $table->bigInteger('client_id');
            $table->string('appointment_number', 50);
            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->enum('appointment_type', ['Reservation', 'Walk-in']);
            $table->enum('source', ['Mobile', 'Front Desk']);
            $table->enum('status', ['scheduled', 'checked_in', 'in_service', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('service_location', 20)->default('In Spa');
            $table->string('home_address', 500)->nullable();
            $table->string('home_address_notes', 500)->nullable();
            $table->decimal('home_latitude', 10, 7)->nullable();
            $table->decimal('home_longitude', 10, 7)->nullable();
            $table->decimal('home_distance_km', 6, 2)->nullable();
            $table->decimal('home_service_fee', 10, 2)->default(0);
            $table->bigInteger('requested_staff_id')->nullable();
            $table->json('policy_overrides')->nullable();
            $table->string('home_start_code', 12)->nullable();
            $table->timestamp('home_scanned_at')->nullable();
            $table->string('preferred_staff_gender', 10)->nullable();
            $table->smallInteger('client_reschedule_count')->default(0);
            $table->bigInteger('client_voucher_id')->nullable();
            $table->unique('appointment_number', 'appointments_appointment_number_unique');
            $table->unique('home_start_code', 'appointments_home_start_code_unique');
            $table->unique('uuid', 'appointments_uuid_unique');
            $table->index(['spa_branch_id', 'appointment_date'], 'appointments_spa_branch_id_appointment_date_index');
            $table->index('status', 'appointments_status_index');
            $table->foreign('client_id', 'appointments_client_id_foreign')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('requested_staff_id', 'appointments_requested_staff_id_foreign')->references('id')->on('staff')->onDelete('set null');
            $table->foreign('spa_branch_id', 'appointments_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
