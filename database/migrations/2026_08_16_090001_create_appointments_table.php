<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A booking (reservation or walk-in) for a client at a branch. The
    // services/packages actually booked live in appointment_services /
    // appointment_packages — this row is the header (status, totals,
    // timeline) they hang off of.
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnDelete();

            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            $table->string('appointment_number', 50)->unique();

            $table->date('appointment_date');
            $table->time('appointment_time');

            $table->enum('appointment_type', ['Reservation', 'Walk-in']);
            $table->enum('source', ['Mobile', 'Front Desk']);

            $table->enum('status', [
                'Pending',
                'Confirmed',
                'Checked In',
                'Completed',
                'Cancelled',
                'No Show',
            ])->default('Pending');

            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('cancellation_reason')->nullable();

            $table->text('remarks')->nullable();

            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2)->default(0.00);

            // Loose reference, no FK constraint — reward_redemptions
            // (Module 7 - Loyalty & Customer Feedback) doesn't exist yet.
            $table->unsignedBigInteger('reward_redemption_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_branch_id', 'appointment_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
