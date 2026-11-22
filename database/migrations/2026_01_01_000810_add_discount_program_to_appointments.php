<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The member discount a client booked with in the app (appointments.discount_program_id): held
// on the booking like a voucher (client_voucher_id) and put on the bill when it is made. A booking
// carries one or the other, never both. Empty for every booking made before; safe to run again.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'discount_program_id')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->bigInteger('discount_program_id')->nullable();
            $table->foreign('discount_program_id', 'appointments_discount_program_id_foreign')->references('id')->on('customer_programs')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'discount_program_id')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign('appointments_discount_program_id_foreign');
            $table->dropColumn('discount_program_id');
        });
    }
};
