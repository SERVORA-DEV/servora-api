<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Money handed back for one service of a visit (the checkout's per-service
// refund, App\Support\ServiceRefunds). The bill's payments hold the total
// refunded; this says which service it was for, so the same service is not
// refunded twice. Until this has been run a refund can only be for the bill
// as a whole (AppointmentServiceItem::tracksRefunds).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
