<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The therapist a booking asked for by name (the front desk's "requested
// therapist", or the one a client picked in the app). Until now that was
// only used to make the first assignment and then forgotten, so a therapist
// the client asked for couldn't be told apart from one the front desk simply
// assigned. Kept on the appointment, and it stays even if someone else ends
// up doing the service — it records the request, not the assignment.
// Bookings made before this column existed have none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('requested_staff_id')->nullable()->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_staff_id');
        });
    }
};
