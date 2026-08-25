<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Packages are exploded into individual appointment_services rows when
    // booked (one row per package_services line x quantity), so every
    // service gets the same multi-therapist/room assignment path a
    // standalone service does. source_appointment_package_id links an
    // exploded row back to the appointment_packages purchase record purely
    // for billing/display grouping ("part of Relaxation Package") —
    // appointment_packages itself is no longer written to beyond its
    // Pending default; these exploded rows are the single source of
    // execution truth. sort_order is a display-ordering hint only (services
    // still progress independently, no enforced sequencing).
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->foreignId('source_appointment_package_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('appointment_packages')
                ->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0)->after('quantity');
            $table->text('notes')->nullable()->after('status');

            $table->index(['appointment_id', 'source_appointment_package_id'], 'appointment_services_appt_pkg_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropIndex('appointment_services_appt_pkg_idx');
            $table->dropForeign(['source_appointment_package_id']);
            $table->dropColumn(['source_appointment_package_id', 'sort_order', 'notes']);
        });
    }
};
