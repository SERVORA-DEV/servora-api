<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The voucher a client booked with in the app: it is held for that booking
// (no other booking can use it) and put on the bill when the visit is billed
// (BillingService::applyReservedVoucher). Until this has been run a voucher
// sent with a booking is ignored (Appointment::holdsVouchers).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('client_voucher_id')->nullable()->constrained('client_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_voucher_id');
        });
    }
};
