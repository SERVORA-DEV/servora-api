<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages several clients share (a Couples Massage for Client A and Client B).
 *
 * - packages.client_count: how many clients one booking of it is for (1 = as before).
 * - package_services.client_slots: which of them get that service ([1], [2], [1,2]); null = all.
 * - appointment_packages.client_slot: which client of the package this appointment is (1 = A).
 * - appointment_packages.shared_from_id: on Client B's (C's…) appointment, the package Client A
 *   bought and paid for; B's own row carries no price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('client_count')->default(1)->after('duration_minutes');
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->json('client_slots')->nullable()->after('quantity');
        });
        Schema::table('appointment_packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('client_slot')->nullable()->after('quantity');
            $table->foreignId('shared_from_id')->nullable()->after('client_slot')->constrained('appointment_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shared_from_id');
            $table->dropColumn('client_slot');
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropColumn('client_slots');
        });
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('client_count');
        });
    }
};
